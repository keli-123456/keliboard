<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

trait InteractsWithInMemoryDatabase
{
    private Capsule $database;

    protected function setUpInMemoryDatabase(): void
    {
        $this->database = new Capsule(app());
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();

        app()->instance('db', $this->database->getDatabaseManager());
        app()->instance('db.connection', $this->database->getConnection());

        Model::setConnectionResolver($this->database->getDatabaseManager());
        Model::unsetEventDispatcher();

        app()->instance('log', new class {
            public function warning(...$arguments): void {}
            public function error(...$arguments): void {}
        });
    }

    protected function bindSynchronousBusDispatcher(): void
    {
        app()->instance(Dispatcher::class, new class implements Dispatcher {
            public function dispatch($command)
            {
                return $this->dispatchSync($command);
            }

            public function dispatchSync($command, $handler = null)
            {
                return $command->handle();
            }

            public function dispatchNow($command, $handler = null)
            {
                return $this->dispatchSync($command, $handler);
            }

            public function hasCommandHandler($command)
            {
                return false;
            }

            public function getCommandHandler($command)
            {
                return null;
            }

            public function pipeThrough(array $pipes)
            {
                return $this;
            }

            public function map(array $map)
            {
                return $this;
            }
        });
    }

    protected function bindJsonResponseFactory(): void
    {
        app()->instance(ResponseFactory::class, new class implements ResponseFactory {
            public function make($content = '', $status = 200, array $headers = [])
            {
                return new Response($content, $status, $headers);
            }

            public function noContent($status = 204, array $headers = [])
            {
                return $this->make('', $status, $headers);
            }

            public function view($view, $data = [], $status = 200, array $headers = [])
            {
                return $this->make('', $status, $headers);
            }

            public function json($data = [], $status = 200, array $headers = [], $options = 0)
            {
                return new JsonResponse($data, $status, $headers, $options);
            }

            public function jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0)
            {
                return (new JsonResponse($data, $status, $headers, $options))->setCallback($callback);
            }

            public function stream($callback, $status = 200, array $headers = [])
            {
                return new \Symfony\Component\HttpFoundation\StreamedResponse($callback, $status, $headers);
            }

            public function streamJson($data, $status = 200, $headers = [], $encodingOptions = 15)
            {
                return new \Symfony\Component\HttpFoundation\StreamedJsonResponse($data, $status, $headers, $encodingOptions);
            }

            public function streamDownload($callback, $name = null, array $headers = [], $disposition = 'attachment')
            {
                return $this->stream($callback, 200, $headers);
            }

            public function download($file, $name = null, array $headers = [], $disposition = 'attachment')
            {
                return new \Symfony\Component\HttpFoundation\BinaryFileResponse($file, 200, $headers);
            }

            public function file($file, array $headers = [])
            {
                return new \Symfony\Component\HttpFoundation\BinaryFileResponse($file, 200, $headers);
            }

            public function redirectTo($path, $status = 302, $headers = [], $secure = null)
            {
                throw new \BadMethodCallException('Redirect responses are not available in unit tests.');
            }

            public function redirectToRoute($route, $parameters = [], $status = 302, $headers = [])
            {
                throw new \BadMethodCallException('Redirect responses are not available in unit tests.');
            }

            public function redirectToAction($action, $parameters = [], $status = 302, $headers = [])
            {
                throw new \BadMethodCallException('Redirect responses are not available in unit tests.');
            }

            public function redirectGuest($path, $status = 302, $headers = [], $secure = null)
            {
                throw new \BadMethodCallException('Redirect responses are not available in unit tests.');
            }

            public function redirectToIntended($default = '/', $status = 302, $headers = [], $secure = null)
            {
                throw new \BadMethodCallException('Redirect responses are not available in unit tests.');
            }
        });
    }

    protected function bindTestUrlGenerator(string $baseUrl = 'https://example.test'): void
    {
        $generator = new class($baseUrl) {
            public function __construct(private string $baseUrl) {}

            public function route($name, $parameters = [], $absolute = true): string
            {
                if ($name === 'client.subscribe') {
                    $token = is_array($parameters) ? (string) ($parameters['token'] ?? '') : (string) $parameters;
                    $path = '/s/' . rawurlencode($token);

                    return $absolute ? $this->to($path) : $path;
                }

                $path = '/' . trim((string) $name, '/');

                return $absolute ? $this->to($path) : $path;
            }

            public function to($path, $extra = [], $secure = null): string
            {
                $path = '/' . ltrim((string) $path, '/');

                return rtrim($this->baseUrl, '/') . $path;
            }
        };

        app()->instance('url', $generator);
        app()->instance(\Illuminate\Contracts\Routing\UrlGenerator::class, $generator);
    }

    protected function bindTestRouter(string $baseUrl = 'https://example.test'): void
    {
        $router = new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher(app()), app());
        $request = \Illuminate\Http\Request::create($baseUrl);
        $router->get('/api/v2/ticket/attachment/{id}/preview', fn () => response('ok'))
            ->whereNumber('id')
            ->name('api.v2.ticket.attachment.preview');
        $router->getRoutes()->refreshNameLookups();
        $url = new \Illuminate\Routing\UrlGenerator(
            $router->getRoutes(),
            $request
        );
        $url->setKeyResolver(fn (): string => 'unit-test-signing-key');

        app()->instance('request', $request);
        app()->instance(\Illuminate\Http\Request::class, $request);
        app()->instance('router', $router);
        app()->instance(\Illuminate\Routing\Router::class, $router);
        app()->instance(\Illuminate\Contracts\Routing\Registrar::class, $router);
        app()->instance('url', $url);
        app()->instance(\Illuminate\Contracts\Routing\UrlGenerator::class, $url);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('router');
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('url');
    }

    protected function bindTestSettings(array $settings = []): void
    {
        app()->instance(\App\Support\Setting::class, new class($settings) {
            public function __construct(private array $settings) {}

            public function get(string $key): mixed
            {
                return $this->settings[$key] ?? null;
            }

            public function save(array $settings): void
            {
                $this->settings = array_merge($this->settings, $settings);
            }

            public function toArray(): array
            {
                return $this->settings;
            }

            public function getBatch(array $keys): array
            {
                $result = [];
                foreach ($keys as $key) {
                    $result[$key] = $this->get($key);
                }

                return $result;
            }
        });
    }

    protected function bindTestHasher(): void
    {
        app()->instance('hash', new class {
            public function make($value, array $options = []): string
            {
                return password_hash((string) $value, PASSWORD_BCRYPT);
            }

            public function check($value, $hashedValue, array $options = []): bool
            {
                return password_verify((string) $value, (string) $hashedValue);
            }

            public function needsRehash($hashedValue, array $options = []): bool
            {
                return false;
            }
        });
    }

    protected function createUserTable(): void
    {
        $this->database->schema()->create('v2_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('site_id')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('password_algo')->nullable();
            $table->string('password_salt')->nullable();
            $table->string('token')->nullable();
            $table->string('uuid')->nullable();
            $table->integer('invite_user_id')->nullable();
            $table->integer('plan_id')->nullable();
            $table->integer('group_id')->nullable();
            $table->bigInteger('transfer_enable')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->bigInteger('expired_at')->nullable();
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->boolean('banned')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->boolean('remind_expire')->nullable()->default(true);
            $table->boolean('remind_traffic')->nullable()->default(true);
            $table->boolean('auto_renew_enable')->nullable()->default(false);
            $table->string('auto_renew_period')->nullable();
            $table->integer('last_login_at')->nullable();
            $table->integer('last_login_ip')->nullable();
            $table->integer('next_reset_at')->nullable();
            $table->integer('last_reset_at')->nullable();
            $table->integer('reset_count')->default(0);
            $table->integer('balance')->default(0);
            $table->integer('commission_balance')->default(0);
            $table->integer('commission_rate')->default(0);
            $table->integer('commission_type')->default(0);
            $table->integer('discount')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    protected function createSiteTenantTables(): void
    {
        $this->database->schema()->create('v2_site', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('status', 20)->default('active');
            $table->boolean('is_default')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_site_domain', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('site_id')->index();
            $table->string('domain', 255)->unique();
            $table->string('status', 20)->default('active');
            $table->boolean('is_primary')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    protected function createSiteCommerceTables(): void
    {
        $this->database->schema()->create('v2_site_setting', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('site_id')->unique();
            $table->string('site_name', 120)->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->string('landing_theme', 64)->nullable();
            $table->string('accent_color', 16)->nullable();
            $table->string('support_name', 120)->nullable();
            $table->string('support_url', 500)->nullable();
            $table->string('announcement', 1000)->nullable();
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 255)->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_site_plan_price', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('site_id')->index();
            $table->unsignedInteger('plan_id')->index();
            $table->string('period', 32);
            $table->integer('sale_price')->default(0);
            $table->boolean('enabled')->default(true)->index();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->unique(['site_id', 'plan_id', 'period'], 'uniq_site_plan_period');
        });

        $this->database->schema()->create('v2_site_plan_override', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('site_id')->index();
            $table->unsignedInteger('plan_id')->index();
            $table->string('display_name', 120)->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->unique(['site_id', 'plan_id'], 'uniq_site_plan_override');
        });

        $this->database->schema()->create('v2_site_payment', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('site_id')->index();
            $table->unsignedInteger('payment_id')->index();
            $table->boolean('enabled')->default(true)->index();
            $table->integer('sort')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->unique(['site_id', 'payment_id'], 'uniq_site_payment');
        });

        $this->database->schema()->create('v2_site_order_context', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('order_id')->unique();
            $table->string('trade_no', 64)->unique();
            $table->unsignedInteger('site_id')->index();
            $table->unsignedInteger('site_domain_id')->nullable()->index();
            $table->integer('sale_amount')->default(0);
            $table->integer('platform_plan_price')->default(0);
            $table->json('pricing_snapshot')->nullable();
            $table->json('domain_snapshot')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    protected function createAgentCenterTables(): void
    {
        $this->database->schema()->create('v2_agent_profile', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->unique();
            $table->string('status', 32)->default('pending');
            $table->string('level', 64)->default('default');
            $table->string('remark')->nullable();
            $table->integer('enabled_at')->nullable();
            $table->integer('disabled_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_agent_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id')->index();
            $table->integer('sub_user_id')->unique();
            $table->string('remark')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_agent_ledger', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id')->index();
            $table->integer('target_user_id')->nullable()->index();
            $table->string('type', 64)->index();
            $table->integer('amount')->default(0);
            $table->integer('balance_before')->default(0);
            $table->integer('balance_after')->default(0);
            $table->integer('plan_id')->nullable();
            $table->string('period', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();
        });
    }

    protected function createPlanTable(): void
    {
        $this->database->schema()->create('v2_plan', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('group_id')->nullable();
            $table->integer('transfer_enable')->default(0);
            $table->string('name');
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->boolean('show')->default(true);
            $table->boolean('renew')->default(true);
            $table->boolean('sell')->default(true);
            $table->integer('sort')->default(0);
            $table->text('content')->nullable();
            $table->json('prices')->nullable();
            $table->json('tags')->nullable();
            $table->json('upgrade_to_plan_ids')->nullable();
            $table->integer('reset_traffic_method')->nullable();
            $table->integer('capacity_limit')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    protected function createGiftCardTables(): void
    {
        $this->database->schema()->create('v2_gift_card_template', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->tinyInteger('type');
            $table->tinyInteger('status')->default(1);
            $table->string('scope_type', 16)->default(\App\Models\GiftCardTemplate::SCOPE_GLOBAL)->index();
            $table->integer('site_id')->nullable()->index();
            $table->integer('agent_user_id')->nullable()->index();
            $table->integer('agent_domain_id')->nullable()->index();
            $table->json('conditions')->nullable();
            $table->json('rewards');
            $table->json('limits')->nullable();
            $table->json('special_config')->nullable();
            $table->string('icon')->nullable();
            $table->string('background_image')->nullable();
            $table->string('theme_color', 7)->default('#1890ff');
            $table->integer('sort')->default(0);
            $table->integer('admin_id');
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        $this->database->schema()->create('v2_gift_card_code', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('template_id')->index();
            $table->string('code', 32)->unique();
            $table->string('batch_id', 32)->nullable();
            $table->tinyInteger('status')->default(\App\Models\GiftCardCode::STATUS_UNUSED);
            $table->integer('user_id')->nullable();
            $table->integer('used_at')->nullable();
            $table->integer('expires_at')->nullable();
            $table->json('actual_rewards')->nullable();
            $table->integer('usage_count')->default(0);
            $table->integer('max_usage')->default(1);
            $table->json('metadata')->nullable();
            $table->string('scope_type', 16)->default(\App\Models\GiftCardTemplate::SCOPE_GLOBAL)->index();
            $table->integer('site_id')->nullable()->index();
            $table->integer('agent_user_id')->nullable()->index();
            $table->integer('agent_domain_id')->nullable()->index();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        $this->database->schema()->create('v2_gift_card_usage', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('code_id')->index();
            $table->integer('template_id')->index();
            $table->integer('user_id')->index();
            $table->integer('invite_user_id')->nullable();
            $table->json('rewards_given');
            $table->json('invite_rewards')->nullable();
            $table->integer('user_level_at_use')->nullable();
            $table->integer('plan_id_at_use')->nullable();
            $table->decimal('multiplier_applied', 3, 2)->default(1.00);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('notes')->nullable();
            $table->string('scope_type', 16)->default(\App\Models\GiftCardTemplate::SCOPE_GLOBAL)->index();
            $table->integer('site_id')->nullable()->index();
            $table->integer('agent_user_id')->nullable()->index();
            $table->integer('agent_domain_id')->nullable()->index();
            $table->integer('created_at');
        });
    }

    protected function createPersonalAccessTokenTable(): void
    {
        $this->database->schema()->create('personal_access_tokens', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('tokenable_type');
            $table->integer('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    protected function createOrderTable(): void
    {
        $this->database->schema()->create('v2_order', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('site_id')->nullable()->index();
            $table->integer('invite_user_id')->nullable();
            $table->integer('user_id');
            $table->integer('plan_id')->default(0);
            $table->integer('payment_id')->nullable();
            $table->integer('type')->default(1);
            $table->string('period');
            $table->string('trade_no', 64)->unique();
            $table->string('callback_no')->nullable();
            $table->integer('total_amount')->default(0);
            $table->integer('handling_amount')->nullable();
            $table->integer('discount_amount')->nullable();
            $table->integer('balance_amount')->nullable();
            $table->integer('bonus_amount')->default(0);
            $table->integer('upgrade_quote_id')->nullable();
            $table->integer('upgrade_credit_amount')->nullable();
            $table->json('upgrade_source_order_ids')->nullable();
            $table->json('upgrade_pricing_snapshot')->nullable();
            $table->integer('status')->default(0);
            $table->integer('commission_balance')->default(0);
            $table->integer('paid_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    protected function createOrderUpgradeQuoteTable(): void
    {
        $this->database->schema()->create('v2_order_upgrade_quote', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('source_order_id');
            $table->integer('source_plan_id');
            $table->integer('target_plan_id');
            $table->string('target_period', 32);
            $table->integer('target_price');
            $table->integer('source_paid_basis');
            $table->decimal('time_ratio', 8, 4);
            $table->decimal('traffic_ratio', 8, 4);
            $table->decimal('base_credit_coeff', 8, 4);
            $table->decimal('usage_penalty_coeff', 8, 4);
            $table->integer('credit_cap_amount');
            $table->integer('min_pay_amount');
            $table->integer('upgrade_credit_amount');
            $table->integer('final_pay_amount');
            $table->string('token', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->json('snapshot')->nullable();
            $table->integer('expires_at');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    protected function createPaymentTable(): void
    {
        $this->database->schema()->create('v2_payment', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('owner_type', 20)->default('platform');
            $table->integer('owner_id')->nullable()->index();
            $table->integer('owner_domain_id')->nullable();
            $table->string('uuid', 32);
            $table->string('payment', 32);
            $table->string('name');
            $table->string('icon')->nullable();
            $table->text('config')->nullable();
            $table->string('notify_domain', 128)->nullable();
            $table->integer('handling_fee_fixed')->nullable();
            $table->decimal('handling_fee_percent', 5, 2)->nullable();
            $table->boolean('enable')->default(false);
            $table->integer('sort')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->index(['owner_type', 'owner_id']);
        });
    }

    protected function createAgentCommerceTables(): void
    {
        $this->database->schema()->create('v2_agent_domain', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id')->index();
            $table->string('domain', 255)->unique();
            $table->string('status', 20)->default('active');
            $table->boolean('is_primary')->default(false);
            $table->string('remark')->nullable();
            $table->string('verification_token', 128)->nullable();
            $table->string('verification_type', 16)->nullable();
            $table->integer('verified_at')->nullable();
            $table->integer('last_checked_at')->nullable();
            $table->string('verification_error', 255)->nullable();
            $table->integer('created_by_admin_id')->nullable();
            $table->unsignedInteger('created_by_agent_id')->nullable()->index();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->index(['agent_user_id', 'status']);
        });

        $this->database->schema()->create('v2_agent_plan_price', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id')->index();
            $table->integer('plan_id')->index();
            $table->string('period', 32);
            $table->integer('sale_price')->default(0);
            $table->boolean('enabled')->default(true);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->unique(['agent_user_id', 'plan_id', 'period'], 'uniq_agent_plan_period');
        });

        $this->database->schema()->create('v2_agent_plan_override', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id')->index();
            $table->integer('plan_id')->index();
            $table->string('display_name', 120)->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->unique(['agent_user_id', 'plan_id'], 'uniq_agent_plan_override');
        });

        $this->database->schema()->create('v2_agent_balance_hold', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id')->index();
            $table->integer('order_id')->unique();
            $table->string('trade_no', 64)->unique();
            $table->integer('amount')->default(0);
            $table->string('status', 20)->default('pending');
            $table->integer('expires_at')->nullable();
            $table->integer('captured_at')->nullable();
            $table->integer('released_at')->nullable();
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->index(['agent_user_id', 'status']);
        });

        $this->database->schema()->create('v2_agent_order_context', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('order_id')->unique();
            $table->string('trade_no', 64)->unique();
            $table->integer('agent_user_id')->index();
            $table->integer('agent_domain_id')->nullable()->index();
            $table->integer('payment_id')->nullable();
            $table->integer('sale_amount')->default(0);
            $table->integer('cost_amount')->default(0);
            $table->integer('hold_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('pricing_snapshot')->nullable();
            $table->json('domain_snapshot')->nullable();
            $table->json('payment_snapshot')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->index(['agent_user_id', 'status']);
        });
    }

    protected function createAgentSiteSettingTable(): void
    {
        $this->database->schema()->create('v2_agent_site_setting', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('agent_user_id')->index();
            $table->unsignedInteger('agent_domain_id')->nullable()->index();
            $table->string('setting_scope', 16)->default('default');
            $table->string('setting_key', 64)->default('default');
            $table->string('site_name', 80)->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->string('landing_theme', 32)->nullable();
            $table->string('accent_color', 16)->nullable();
            $table->string('support_name', 80)->nullable();
            $table->string('support_url', 500)->nullable();
            $table->string('customer_service_type', 32)->nullable();
            $table->string('customer_service_id', 255)->nullable();
            $table->string('announcement_title', 120)->nullable();
            $table->string('announcement', 500)->nullable();
            $table->string('seo_title', 120)->nullable();
            $table->string('seo_description', 255)->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->unique(['agent_user_id', 'setting_scope', 'setting_key'], 'uniq_agent_site_setting_scope');
        });
    }

    protected function createTicketTables(): void
    {
        $this->database->schema()->create('v2_ticket', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('site_id')->nullable()->index();
            $table->integer('user_id');
            $table->integer('agent_user_id')->nullable()->index();
            $table->integer('agent_domain_id')->nullable()->index();
            $table->string('subject')->nullable();
            $table->integer('level')->default(0);
            $table->integer('status')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_ticket_message', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('ticket_id');
            $table->integer('user_id')->nullable();
            $table->text('message')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_ticket_message_attachment', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('ticket_id')->index();
            $table->integer('ticket_message_id')->index();
            $table->integer('user_id')->index();
            $table->string('disk', 32)->default('local');
            $table->string('path', 255);
            $table->string('mime', 64)->default('image/webp');
            $table->integer('size')->default(0);
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }
}
