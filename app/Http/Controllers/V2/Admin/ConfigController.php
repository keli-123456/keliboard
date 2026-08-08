<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigSave;
use App\Jobs\RecalculateNextResetAtJob;
use App\Models\Plan;
use App\Models\SubscribeTemplate;
use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\SingBox;
use App\Protocols\Stash;
use App\Protocols\Surfboard;
use App\Protocols\Surge;
use App\Services\MailService;
use App\Services\MessageOpsSettings;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use App\Services\NodeRealtime\NodeRealtimeStatusService;
use App\Services\OrderUpgradeService;
use App\Services\RechargeBonusService;
use App\Services\TelegramService;
use App\Services\ThemeService;
use App\Services\TicketAiAssistantService;
use App\Utils\Dict;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class ConfigController extends Controller
{
    /**
     * These settings change what nodes pull from /api/v2/server/config.
     * app_url is included because realtime public URL may fallback to it.
     */
    private const NODE_CONFIG_INVALIDATION_KEYS = [
        'app_url',
        'server_pull_interval',
        'server_push_interval',
        'node_realtime_enable',
        'node_realtime_path',
        'node_realtime_public_url',
        'node_realtime_public_port',
        'node_realtime_ping_interval',
        'subscription_proxy_enable',
        'subscription_proxy_site_id',
        'subscription_proxy_https_port',
        'subscription_proxy_http_port',
        'subscription_proxy_cert_file',
        'subscription_proxy_key_file',
        'subscription_proxy_challenge_dir',
        'subscription_proxy_allow_http_fallback',
        'subscription_proxy_max_response_bytes',
        'website_proxy_enable',
        'website_proxy_path_prefix',
        'website_proxy_max_request_body_bytes',
        'website_proxy_max_response_bytes',
        'device_limit_fallback',
        'node_report_min_traffic',
        'device_online_min_traffic',
    ];


    public function getEmailTemplate()
    {
        $path = resource_path('views/mail/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return $this->success($files);
    }

    public function getThemeTemplate()
    {
        $path = public_path('theme/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return $this->success($files);
    }

    public function testSendMail(Request $request)
    {
        try {
            $mailLog = MailService::sendEmail([
                'email' => $request->user()->email,
                'subject' => 'This is xboard test email',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'content' => 'This is xboard test email',
                    'url' => admin_setting('app_url')
                ]
            ], [
                'context' => [
                    'source' => 'admin_test_mail',
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return $this->fail([500001, '测试邮件发送失败'], null, $e->getMessage());
        }

        if (!empty($mailLog['error'])) {
            return $this->fail([500001, '测试邮件发送失败'], $mailLog, $mailLog['error']);
        }

        return $this->success($mailLog);
    }
    /**
     * 获取规则模板内容
     * 
     * @param string $file 文件路径
     * @return string 文件内容
     */
    private function getTemplateContent(string $file): string
    {
        $path = base_path($file);
        return File::exists($path) ? File::get($path) : '';
    }

    public function setTelegramWebhook(Request $request)
    {
        $hookUrl = $this->resolveTelegramWebhookUrl();
        if (blank($hookUrl)) {
            return $this->fail([422, 'Telegram Webhook地址未配置']);
        }
        $hookUrl .= '?' . http_build_query([
            'access_token' => md5(admin_setting('telegram_bot_token', $request->input('telegram_bot_token')))
        ]);
        $telegramService = new TelegramService($request->input('telegram_bot_token'));
        $telegramService->getMe();
        $telegramService->setWebhook($hookUrl);
        $telegramService->registerBotCommands();
        return $this->success([
            'success' => true,
            'webhook_url' => $hookUrl,
            'webhook_base_url' => $this->getTelegramWebhookBaseUrl(),
        ]);
    }

    public function fetch(Request $request)
    {
        $key = $request->input('key');
        if ($key === 'agent') {
            return $this->success(['agent' => $this->agentConfigMappings()]);
        }

        $configMappings = $this->getConfigMappings();
        if ($key && isset($configMappings[$key])) {
            return $this->success([$key => $configMappings[$key]]);
        }

        return $this->success($configMappings);
    }

    public function realtimeStatus(NodeRealtimeStatusService $statusService)
    {
        return $this->success($statusService->getStatus());
    }

    /**
     * 获取配置映射数据
     * 
     * @return array 配置映射数组
     */
    private function getConfigMappings(): array
    {
        $nodeRealtime = app(NodeRealtimeSettings::class);

        return [
            'invite' => [
                'invite_force' => (bool) admin_setting('invite_force', 0),
                'invite_commission' => admin_setting('invite_commission', 10),
                'invite_gen_limit' => admin_setting('invite_gen_limit', 5),
                'invite_never_expire' => (bool) admin_setting('invite_never_expire', 0),
                'commission_first_time_enable' => (bool) admin_setting('commission_first_time_enable', 1),
                'commission_auto_check_enable' => (bool) admin_setting('commission_auto_check_enable', 1),
                'commission_withdraw_limit' => admin_setting('commission_withdraw_limit', 100),
                'commission_withdraw_method' => admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT),
                'withdraw_close_enable' => (bool) admin_setting('withdraw_close_enable', 0),
                'commission_distribution_enable' => (bool) admin_setting('commission_distribution_enable', 0),
                'commission_distribution_l1' => admin_setting('commission_distribution_l1'),
                'commission_distribution_l2' => admin_setting('commission_distribution_l2'),
                'commission_distribution_l3' => admin_setting('commission_distribution_l3')
            ],
            'agent' => $this->agentConfigMappings(),
            'ticket' => [
                'ticket_must_wait_reply' => (bool) admin_setting('ticket_must_wait_reply', 1),
                'ticket_auto_reply_enable' => (bool) admin_setting('ticket_auto_reply_enable', 0),
                'ticket_auto_reply_on_user_reply' => (bool) admin_setting('ticket_auto_reply_on_user_reply', 1),
                'ticket_auto_reply_reply_once_per_ticket' => (bool) admin_setting('ticket_auto_reply_reply_once_per_ticket', 1),
                'ticket_auto_reply_cooldown_seconds' => max(0, (int) admin_setting('ticket_auto_reply_cooldown_seconds', 0)),
                'ticket_auto_reply_max_per_ticket' => max(0, (int) admin_setting('ticket_auto_reply_max_per_ticket', 3)),
                'ticket_auto_reply_default_message' => (string) admin_setting('ticket_auto_reply_default_message', ''),
                'ticket_auto_reply_rules' => $this->normalizeTicketAutoReplyRules(
                    admin_setting('ticket_auto_reply_rules', [])
                ),
                ...app(TicketAiAssistantService::class)->publicSettings(),
            ],
            'site' => [
                'logo' => admin_setting('logo'),
                'force_https' => (int) admin_setting('force_https', 0),
                'stop_register' => (int) admin_setting('stop_register', 0),
                'app_name' => admin_setting('app_name', 'XBoard'),
                'app_description' => admin_setting('app_description', 'XBoard is best!'),
                'app_url' => admin_setting('app_url'),
                'subscribe_url' => admin_setting('subscribe_url'),
                'try_out_plan_id' => (int) admin_setting('try_out_plan_id', 0),
                'try_out_hour' => (int) admin_setting('try_out_hour', 1),
                'tos_url' => admin_setting('tos_url'),
                'currency' => admin_setting('currency', 'CNY'),
                'currency_symbol' => admin_setting('currency_symbol', '¥'),
            ],
            'subscribe' => [
                'plan_change_enable' => (bool) admin_setting('plan_change_enable', 1),
                'reset_traffic_method' => (int) admin_setting('reset_traffic_method', 0),
                'surplus_enable' => (bool) admin_setting('surplus_enable', 0),
                'upgrade_v2_enable' => (bool) admin_setting('upgrade_v2_enable', 0),
                'upgrade_quote_ttl_seconds' => max(60, (int) admin_setting('upgrade_quote_ttl_seconds', 300)),
                'upgrade_disable_coupon' => (bool) admin_setting('upgrade_disable_coupon', 1),
                'upgrade_disable_user_discount' => (bool) admin_setting('upgrade_disable_user_discount', 1),
                'upgrade_allow_onetime' => (bool) admin_setting('upgrade_allow_onetime', 0),
                'upgrade_min_pay_amount' => max(1, (int) admin_setting('upgrade_min_pay_amount', 300)),
                'upgrade_min_pay_ratio' => max(0, min(1, (float) admin_setting('upgrade_min_pay_ratio', 0.20))),
                'upgrade_max_credit_cap_ratio' => max(0, min(1, (float) admin_setting('upgrade_max_credit_cap_ratio', 0.70))),
                'upgrade_credit_coeffs' => OrderUpgradeService::normalizeCreditCoefficients(
                    admin_setting('upgrade_credit_coeffs', OrderUpgradeService::getDefaultCreditCoefficients())
                ),
                'upgrade_usage_penalty_rules' => OrderUpgradeService::normalizeUsagePenaltyRules(
                    admin_setting('upgrade_usage_penalty_rules', OrderUpgradeService::getDefaultUsagePenaltyRules())
                ),
                'new_order_event_id' => (int) admin_setting('new_order_event_id', 0),
                'renew_order_event_id' => (int) admin_setting('renew_order_event_id', 0),
                'change_order_event_id' => (int) admin_setting('change_order_event_id', 0),
                'show_info_to_server_enable' => (bool) admin_setting('show_info_to_server_enable', 0),
                'show_protocol_to_server_enable' => (bool) admin_setting('show_protocol_to_server_enable', 0),
                'default_remind_expire' => (bool) admin_setting('default_remind_expire', 1),
                'default_remind_traffic' => (bool) admin_setting('default_remind_traffic', 1),
                'subscribe_path' => admin_setting('subscribe_path', 's'),
                'recharge_bonus_enable' => (bool) admin_setting('recharge_bonus_enable', 0),
                'recharge_bonus_mode' => app(RechargeBonusService::class)->getMode(),
                'recharge_bonus_rules' => app(RechargeBonusService::class)->getRules(),
            ],
            'frontend' => [
                'frontend_theme' => admin_setting('frontend_theme', 'Xboard'),
                'frontend_theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
                'frontend_theme_header' => admin_setting('frontend_theme_header', 'dark'),
                'frontend_theme_color' => admin_setting('frontend_theme_color', 'default'),
                'frontend_background_url' => admin_setting('frontend_background_url'),
            ],
            'server' => [
                'server_token' => admin_setting('server_token'),
                'node_api_base_url' => (string) admin_setting('node_api_base_url', ''),
                'server_machine_default_agent' => $this->normalizeServerMachineDefaultAgent(
                    admin_setting('server_machine_default_agent', 'kelinode')
                ),
                'server_machine_distribution_source' => $this->normalizeMachineDistributionSource(
                    admin_setting('server_machine_distribution_source', 'github')
                ),
                'server_machine_distribution_base_url' => (string) admin_setting('server_machine_distribution_base_url', ''),
                'server_pull_interval' => admin_setting('server_pull_interval', 60),
                'server_push_interval' => admin_setting('server_push_interval', 60),
                'message_ops_enable' => MessageOpsSettings::enabled(),
                'node_realtime_enable' => $nodeRealtime->enabledSetting(),
                'node_realtime_path' => $nodeRealtime->path(),
                'node_realtime_public_url' => $nodeRealtime->configuredPublicUrl(),
                'node_realtime_public_port' => $nodeRealtime->publicPort(),
                'node_realtime_ping_interval' => $nodeRealtime->pingInterval(),
                'node_realtime_alert_enable' => (bool) admin_setting('node_realtime_alert_enable', false),
                'node_realtime_alert_notify_telegram' => (bool) admin_setting('node_realtime_alert_notify_telegram', false),
                'node_realtime_alert_window_minutes' => (int) admin_setting('node_realtime_alert_window_minutes', 10),
                'node_realtime_alert_cooldown_minutes' => (int) admin_setting('node_realtime_alert_cooldown_minutes', 30),
                'device_limit_mode' => (int) admin_setting('device_limit_mode', 0),
                'device_limit_fallback' => max(0, min(2147483647, (int) admin_setting('device_limit_fallback', 0))),
                'node_report_min_traffic' => (int) admin_setting('node_report_min_traffic', 0),
                'device_online_min_traffic' => (int) admin_setting('device_online_min_traffic', 0),
                'server_api_user_cache_ttl' => (int) admin_setting('server_api_user_cache_ttl', config('server_api_cache.user_ttl', 0)),
                'server_api_config_cache_ttl' => (int) admin_setting('server_api_config_cache_ttl', config('server_api_cache.config_ttl', 0)),
                'server_api_cache_lock_ttl' => (int) admin_setting('server_api_cache_lock_ttl', config('server_api_cache.lock_ttl', 10)),
                'server_api_cache_lock_wait' => (int) admin_setting('server_api_cache_lock_wait', config('server_api_cache.lock_wait', 3)),
                'user_sync_retention_days' => (int) admin_setting('user_sync_retention_days', config('user_sync.retention_days', 30)),
                'user_sync_delta_limit' => (int) admin_setting('user_sync_delta_limit', config('user_sync.delta_limit', 5000)),
            ],
            'subscription_proxy' => [
                'subscription_proxy_enable' => (bool) admin_setting('subscription_proxy_enable', false),
                'subscription_proxy_site_id' => (string) admin_setting('subscription_proxy_site_id', ''),
                'subscription_proxy_https_port' => (int) admin_setting('subscription_proxy_https_port', 443),
                'subscription_proxy_http_port' => (int) admin_setting('subscription_proxy_http_port', 80),
                'subscription_proxy_cert_file' => (string) admin_setting('subscription_proxy_cert_file', '/etc/v2node/subproxy/fullchain.pem'),
                'subscription_proxy_key_file' => (string) admin_setting('subscription_proxy_key_file', '/etc/v2node/subproxy/key.pem'),
                'subscription_proxy_challenge_dir' => (string) admin_setting('subscription_proxy_challenge_dir', '/etc/v2node/subproxy/challenges'),
                'subscription_proxy_allow_http_fallback' => (bool) admin_setting('subscription_proxy_allow_http_fallback', false),
                'subscription_proxy_max_response_bytes' => (int) admin_setting('subscription_proxy_max_response_bytes', 10485760),
                'website_proxy_enable' => (bool) admin_setting('website_proxy_enable', false),
                'website_proxy_path_prefix' => (string) admin_setting('website_proxy_path_prefix', '/'),
                'website_proxy_max_request_body_bytes' => (int) admin_setting('website_proxy_max_request_body_bytes', 104857600),
                'website_proxy_max_response_bytes' => (int) admin_setting('website_proxy_max_response_bytes', 104857600),
                'zerossl_access_key' => (string) admin_setting('zerossl_access_key', ''),
                'subscription_proxy_certificate_provider' => (string) admin_setting('subscription_proxy_certificate_provider', 'zerossl'),
                'letsencrypt_email' => (string) admin_setting('letsencrypt_email', ''),
                'letsencrypt_renew_hours' => (int) admin_setting('letsencrypt_renew_hours', 48),
                'subscription_proxy_renew_days' => (int) admin_setting('subscription_proxy_renew_days', 20),
            ],
            'email' => [
                'email_template' => admin_setting('email_template', 'default'),
                'email_host' => admin_setting('email_host'),
                'email_port' => admin_setting('email_port'),
                'email_username' => admin_setting('email_username'),
                'email_password' => admin_setting('email_password'),
                'email_encryption' => admin_setting('email_encryption'),
                'email_from_address' => admin_setting('email_from_address'),
                'remind_mail_enable' => (bool) admin_setting('remind_mail_enable', false),
                'marketing_email_enabled' => (bool) admin_setting('marketing_email_enabled', false),
                'marketing_email_host' => admin_setting('marketing_email_host'),
                'marketing_email_port' => admin_setting('marketing_email_port'),
                'marketing_email_username' => admin_setting('marketing_email_username'),
                'marketing_email_password' => admin_setting('marketing_email_password'),
                'marketing_email_encryption' => admin_setting('marketing_email_encryption'),
                'marketing_email_from_address' => admin_setting('marketing_email_from_address'),
            ],
            'telegram' => [
                'telegram_bot_enable' => (bool) admin_setting('telegram_bot_enable', 0),
                'telegram_bot_token' => admin_setting('telegram_bot_token'),
                'telegram_webhook_url' => admin_setting('telegram_webhook_url'),
                'telegram_discuss_link' => admin_setting('telegram_discuss_link')
            ],
            'app' => [
                'windows_version' => admin_setting('windows_version', ''),
                'windows_download_url' => admin_setting('windows_download_url', ''),
                'macos_version' => admin_setting('macos_version', ''),
                'macos_download_url' => admin_setting('macos_download_url', ''),
                'android_version' => admin_setting('android_version', ''),
                'android_download_url' => admin_setting('android_download_url', '')
            ],
            'safe' => [
                'email_verify' => (bool) admin_setting('email_verify', 0),
                'safe_mode_enable' => (bool) admin_setting('safe_mode_enable', 0),
                'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
                'email_whitelist_enable' => (bool) admin_setting('email_whitelist_enable', 0),
                'email_whitelist_suffix' => admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT),
                'email_gmail_limit_enable' => (bool) admin_setting('email_gmail_limit_enable', 0),
                'captcha_enable' => (bool) admin_setting('captcha_enable', 0),
                'captcha_type' => admin_setting('captcha_type', 'recaptcha'),
                'recaptcha_key' => admin_setting('recaptcha_key', ''),
                'recaptcha_site_key' => admin_setting('recaptcha_site_key', ''),
                'recaptcha_v3_secret_key' => admin_setting('recaptcha_v3_secret_key', ''),
                'recaptcha_v3_site_key' => admin_setting('recaptcha_v3_site_key', ''),
                'recaptcha_v3_score_threshold' => admin_setting('recaptcha_v3_score_threshold', 0.5),
                'turnstile_secret_key' => admin_setting('turnstile_secret_key', ''),
                'turnstile_site_key' => admin_setting('turnstile_site_key', ''),
                'register_limit_by_ip_enable' => (bool) admin_setting('register_limit_by_ip_enable', 0),
                'register_limit_count' => admin_setting('register_limit_count', 3),
                'register_limit_expire' => admin_setting('register_limit_expire', 60),
                'password_limit_enable' => (bool) admin_setting('password_limit_enable', 1),
                'password_limit_count' => admin_setting('password_limit_count', 5),
                'password_limit_expire' => admin_setting('password_limit_expire', 60),
                // 保持向后兼容
                'recaptcha_enable' => (bool) admin_setting('captcha_enable', 0)
            ],
            'subscribe_template' => [
                'subscribe_template_singbox' => $this->formatTemplateContent(
                    subscribe_template('singbox', $this->getDefaultTemplate('singbox')),
                    'json'
                ),
                'subscribe_template_clash' => subscribe_template('clash', $this->getDefaultTemplate('clash')),
                'subscribe_template_clashmeta' => subscribe_template('clashmeta', $this->getDefaultTemplate('clashmeta')),
                'subscribe_template_stash' => subscribe_template('stash', $this->getDefaultTemplate('stash')),
                'subscribe_template_surge' => subscribe_template('surge', $this->getDefaultTemplate('surge')),
                'subscribe_template_surfboard' => subscribe_template('surfboard', $this->getDefaultTemplate('surfboard'))
            ]
        ];
    }

    private function agentConfigMappings(): array
    {
        return [
            'agent_center_enable' => (bool) admin_setting('agent_center_enable', false),
            'agent_center_unlock_mode' => $this->normalizeAgentCenterUnlockMode(
                admin_setting('agent_center_unlock_mode', 'balance_threshold')
            ),
            'agent_center_unlock_balance' => max(0, (int) admin_setting('agent_center_unlock_balance', 0)),
            'agent_center_auto_activate' => (bool) admin_setting('agent_center_auto_activate', true),
            'agent_center_allowed_plan_ids' => $this->normalizeAgentCenterAllowedPlanIds(
                admin_setting('agent_center_allowed_plan_ids', '')
            ),
            'agent_center_discount_percent' => max(0, min(100, (float) admin_setting('agent_center_discount_percent', 100))),
            'agent_center_user_limit' => max(0, (int) admin_setting(
                'agent_center_user_limit',
                admin_setting('agent_center_daily_create_limit', 20)
            )),
            'agent_center_daily_create_limit' => max(0, (int) admin_setting(
                'agent_center_user_limit',
                admin_setting('agent_center_daily_create_limit', 20)
            )),
            'agent_center_allow_traffic_reset' => (bool) admin_setting('agent_center_allow_traffic_reset', true),
            'agent_center_reset_price_mode' => $this->normalizeAgentCenterResetPriceMode(
                admin_setting('agent_center_reset_price_mode', 'plan_reset_price')
            ),
            'agent_center_bonus_day_price' => max(0, (int) admin_setting('agent_center_bonus_day_price', 0)),
            'agent_center_gift_card_traffic_gb_price' => max(0, (int) admin_setting('agent_center_gift_card_traffic_gb_price', 0)),
            'agent_center_gift_card_device_price' => max(0, (int) admin_setting('agent_center_gift_card_device_price', 0)),
            'agent_center_domain_limit' => max(0, (int) admin_setting('agent_center_domain_limit', 1)),
        ];
    }

    private function normalizeAgentCenterUnlockMode(mixed $mode): string
    {
        return in_array($mode, ['balance_threshold', 'manual'], true) ? (string) $mode : 'balance_threshold';
    }

    private function normalizeAgentCenterResetPriceMode(mixed $mode): string
    {
        return in_array($mode, ['plan_reset_price', 'free'], true) ? (string) $mode : 'plan_reset_price';
    }

    private function normalizeAgentCenterAllowedPlanIds(mixed $value): string
    {
        $ids = collect(preg_split('/[\s,]+/', (string) $value) ?: [])
            ->map(static fn ($item) => (int) trim((string) $item))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return implode(',', $ids);
    }

    /**
     * 规范化工单自动回复规则，确保返回给前端的数据结构稳定。
     *
     * @param mixed $rules
     * @return array<int, array{
     *   enabled: bool,
     *   name: string,
     *   keyword: string,
     *   exclude_keyword: string,
     *   scope: string,
     *   match_mode: string,
     *   priority: int,
     *   reply: string
     * }>
     */
    private function normalizeTicketAutoReplyRules(mixed $rules): array
    {
        if (!is_array($rules)) {
            return [];
        }

        $normalized = [];
        foreach ($rules as $item) {
            if (!is_array($item)) {
                continue;
            }
            $scope = strtolower(trim((string) ($item['scope'] ?? 'both')));
            if (!in_array($scope, ['subject', 'message', 'both'], true)) {
                $scope = 'both';
            }
            $matchMode = strtolower(trim((string) ($item['match_mode'] ?? 'contains')));
            if (!in_array($matchMode, ['contains', 'exact', 'regex'], true)) {
                $matchMode = 'contains';
            }

            $normalized[] = [
                'enabled' => isset($item['enabled']) ? (bool) $item['enabled'] : true,
                'name' => trim((string) ($item['name'] ?? '')),
                'keyword' => trim((string) ($item['keyword'] ?? '')),
                'exclude_keyword' => trim((string) ($item['exclude_keyword'] ?? '')),
                'scope' => $scope,
                'match_mode' => $matchMode,
                'priority' => max(0, (int) ($item['priority'] ?? 0)),
                'reply' => trim((string) ($item['reply'] ?? '')),
            ];
        }

        return $normalized;
    }

    public function save(ConfigSave $request)
    {
        $data = app(TicketAiAssistantService::class)->prepareSettingsForSave($request->validated());
        $savedKeys = array_keys($data);
        $oldSystemResetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
        $templateKeys = [
            'subscribe_template_singbox' => 'singbox',
            'subscribe_template_clash' => 'clash',
            'subscribe_template_clashmeta' => 'clashmeta',
            'subscribe_template_stash' => 'stash',
            'subscribe_template_surge' => 'surge',
            'subscribe_template_surfboard' => 'surfboard',
        ];

        if (array_key_exists('recharge_bonus_enable', $data)) {
            $data['recharge_bonus_enable'] = (bool) $data['recharge_bonus_enable'];
        }
        if (array_key_exists('recharge_bonus_mode', $data)) {
            $data['recharge_bonus_mode'] = in_array($data['recharge_bonus_mode'], [
                RechargeBonusService::MODE_HIGHEST,
                RechargeBonusService::MODE_REPEAT,
            ], true)
                ? $data['recharge_bonus_mode']
                : RechargeBonusService::MODE_HIGHEST;
        }
        if (array_key_exists('recharge_bonus_rules', $data)) {
            $data['recharge_bonus_rules'] = app(RechargeBonusService::class)->normalizeRules($data['recharge_bonus_rules']);
        }
        if (array_key_exists('upgrade_credit_coeffs', $data)) {
            $data['upgrade_credit_coeffs'] = OrderUpgradeService::normalizeCreditCoefficients($data['upgrade_credit_coeffs']);
        }
        if (array_key_exists('upgrade_usage_penalty_rules', $data)) {
            $data['upgrade_usage_penalty_rules'] = OrderUpgradeService::normalizeUsagePenaltyRules($data['upgrade_usage_penalty_rules']);
        }

        foreach ($data as $k => $v) {
            if (isset($templateKeys[$k])) {
                SubscribeTemplate::setContent($templateKeys[$k], $v);
                continue;
            }
            if ($k == 'frontend_theme') {
                $themeService = app(ThemeService::class);
                $themeService->switch($v);
            }
            admin_setting([$k => $v]);
        }

        if (array_key_exists('reset_traffic_method', $data)) {
            $newSystemResetMethod = (int) $data['reset_traffic_method'];
            if ($newSystemResetMethod !== $oldSystemResetMethod) {
                // Recalculate users whose plan follows system reset strategy.
                RecalculateNextResetAtJob::dispatch(null, true);
            }
        }

        $this->dispatchNodeConfigInvalidation($savedKeys);

        return $this->success(true);
    }

    private function dispatchNodeConfigInvalidation(array $savedKeys): void
    {
        $affectedKeys = array_values(array_intersect($savedKeys, self::NODE_CONFIG_INVALIDATION_KEYS));
        if ($affectedKeys === []) {
            return;
        }

        sort($affectedKeys);

        app(NodeRealtimePublisher::class)->invalidateConfig('admin.config.saved', [
            'keys' => $affectedKeys,
        ]);
    }

    private function normalizeServerMachineDefaultAgent(mixed $value): string
    {
        $agent = strtolower(trim((string) ($value ?? '')));
        return in_array($agent, ['kelinode-rs', 'native-node', 'native_node'], true) ? 'kelinode-rs' : 'kelinode';
    }

    private function normalizeMachineDistributionSource(mixed $value): string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        return in_array($value, ['github', 'panel', 'custom'], true) ? $value : 'github';
    }

    /**
     * 格式化模板内容
     * 
     * @param mixed $content 模板内容
     * @param string $format 输出格式 (json|string)
     * @return string 格式化后的内容
     */
    private function formatTemplateContent(mixed $content, string $format = 'string'): string
    {
        return match ($format) {
            'json' => match (true) {
                    is_array($content) => json_encode(
                        value: $content,
                        flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),

                    is_string($content) && str($content)->isJson() => rescue(
                        callback: fn() => json_encode(
                            value: json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR),
                            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                        rescue: $content,
                        report: false
                    ),

                    default => str($content)->toString()
                },

            default => str($content)->toString()
        };
    }

    /**
     * 获取默认模板内容
     * 
     * @param string $type 模板类型
     * @return string 默认模板内容
     */
    private function getDefaultTemplate(string $type): string
    {
        $fileMap = [
            'singbox' => [SingBox::CUSTOM_TEMPLATE_FILE, SingBox::DEFAULT_TEMPLATE_FILE],
            'clash' => [Clash::CUSTOM_TEMPLATE_FILE, Clash::DEFAULT_TEMPLATE_FILE],
            'clashmeta' => [
                ClashMeta::CUSTOM_TEMPLATE_FILE,
                ClashMeta::CUSTOM_CLASH_TEMPLATE_FILE,
                ClashMeta::DEFAULT_TEMPLATE_FILE
            ],
            'stash' => [
                Stash::CUSTOM_TEMPLATE_FILE,
                Stash::CUSTOM_CLASH_TEMPLATE_FILE,
                Stash::DEFAULT_TEMPLATE_FILE
            ],
            'surge' => [Surge::CUSTOM_TEMPLATE_FILE, Surge::DEFAULT_TEMPLATE_FILE],
            'surfboard' => [Surfboard::CUSTOM_TEMPLATE_FILE, Surfboard::DEFAULT_TEMPLATE_FILE],
        ];

        if (!isset($fileMap[$type])) {
            return '';
        }

        // 按优先级查找可用的模板文件
        foreach ($fileMap[$type] as $file) {
            $content = $this->getTemplateContent($file);
            if (!empty($content)) {
                // 对于 SingBox，需要格式化 JSON
                if ($type === 'singbox') {
                    $decoded = json_decode($content, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
                return $content;
            }
        }

        return '';
    }

    private function getTelegramWebhookBaseUrl(): ?string
    {
        $customUrl = trim((string) admin_setting('telegram_webhook_url', ''));
        if ($customUrl !== '') {
            return rtrim($customUrl, '/');
        }

        $appUrl = trim((string) admin_setting('app_url', ''));
        if ($appUrl !== '') {
            return rtrim($appUrl, '/');
        }

        return null;
    }

    private function resolveTelegramWebhookUrl(): ?string
    {
        $baseUrl = $this->getTelegramWebhookBaseUrl();
        if (!$baseUrl) {
            return null;
        }

        if (str_contains($baseUrl, '/api/v1/guest/telegram/webhook')) {
            return $baseUrl;
        }

        return $baseUrl . '/api/v1/guest/telegram/webhook';
    }
}
