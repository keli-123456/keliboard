<?php

namespace Plugin\Telegram;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Utils\Helper;
use App\Jobs\ProcessTelegramTicketMediaGroupReplyJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class Plugin extends AbstractPlugin
{
  private const LOGIN_SESSION_PREFIX = 'tg_login:session:';

  protected array $commands = [];
  protected TelegramService $telegramService;

  protected array $commandConfigs = [
    '/start' => ['description' => '开始使用', 'handler' => 'handleStartCommand'],
    '/bind' => ['description' => '绑定账号', 'handler' => 'handleBindCommand'],
    '/traffic' => ['description' => '查看流量', 'handler' => 'handleTrafficCommand'],
    '/getlatesturl' => ['description' => '获取订阅链接', 'handler' => 'handleGetLatestUrlCommand'],
    '/unbind' => ['description' => '解绑账号', 'handler' => 'handleUnbindCommand'],
  ];

  public function boot(): void
  {
    $this->telegramService = new TelegramService();
    $this->registerDefaultCommands();

    $this->filter('telegram.message.handle', [$this, 'handleMessage'], 10);
    $this->listen('telegram.message.unhandled', [$this, 'handleUnknownCommand'], 10);
    $this->listen('telegram.message.error', [$this, 'handleError'], 10);
    $this->filter('telegram.bot.commands', [$this, 'addBotCommands'], 10);
    $this->listen('ticket.create.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('ticket.reply.user.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('payment.notify.success', [$this, 'sendPaymentNotify'], 10);
    $this->filter('guest_comm_config', [$this, 'injectGuestConfig'], 10);
  }

  public function injectGuestConfig(array $config): array
  {
    $loginEnabled = $this->getConfig('enable_login', true);
    if (!($loginEnabled === true || $loginEnabled === 1 || $loginEnabled === '1' || $loginEnabled === 'true')) {
      $config['telegram_login_enable'] = 0;
      $config['telegram_bot_username'] = '';
      return $config;
    }

    $botEnabled = (int) admin_setting('telegram_bot_enable', 0) ? 1 : 0;
    $botToken = trim((string) admin_setting('telegram_bot_token', ''));

    if (!$botEnabled || $botToken === '') {
      $config['telegram_login_enable'] = 0;
      $config['telegram_bot_username'] = '';
      return $config;
    }

    $username = $this->resolveBotUsernameForLogin($botToken);
    if ($username === '') {
      $config['telegram_login_enable'] = 0;
      $config['telegram_bot_username'] = '';
      return $config;
    }

    $config['telegram_login_enable'] = 1;
    $config['telegram_bot_username'] = '@' . $username;
    return $config;
  }

  protected function resolveBotUsernameForLogin(string $botToken): string
  {
    $fromConfig = trim((string) $this->getConfig('bot_username', ''));
    $fromConfig = ltrim($fromConfig, '@');
    if ($this->isValidBotUsername($fromConfig)) {
      return $fromConfig;
    }

    $cacheKey = 'telegram:bot_username:' . md5($botToken);
    return (string) Cache::remember($cacheKey, 3600, function () {
      try {
        $resp = $this->telegramService->getMe();
        $username = isset($resp->result?->username) ? (string) $resp->result->username : '';
        $username = ltrim(trim($username), '@');
        return $this->isValidBotUsername($username) ? $username : '';
      } catch (\Throwable) {
        return '';
      }
    });
  }

  protected function isValidBotUsername(string $value): bool
  {
    $raw = trim($value);
    if ($raw === '') return false;
    if (strlen($raw) > 64) return false;
    return (bool) preg_match('/^[a-zA-Z0-9_]{5,64}$/', $raw);
  }

  public function sendPaymentNotify(Order $order): void
  {
    if (!$this->getConfig('enable_payment_notify', true)) {
      return;
    }

    $payment = $order->payment;
    if (!$payment) {
      Log::warning('支付通知失败：订单关联的支付方式不存在', ['order_id' => $order->id]);
      return;
    }

    $message = sprintf(
      "💰成功收款%s元\n" .
      "———————————————\n" .
      "支付接口：%s\n" .
      "支付渠道：%s\n" .
      "本站订单：`%s`",
      $order->total_amount / 100,
      $payment->payment,
      $payment->name,
      $order->trade_no
    );
    $this->telegramService->sendMessageWithAdmin($message, false);
  }

  public function sendTicketNotify(Ticket $ticket): void
  {
    if (!$this->getConfig('enable_ticket_notify', true)) {
      return;
    }

    $message = $ticket->messages()->latest()->first();
    if (!$message) {
      return;
    }
    $user = User::find($ticket->user_id);
    if (!$user)
      return;
    $user->load('plan');
    $transfer_enable = $this->transferToGBString($user->transfer_enable);
    $remaining_traffic = $this->transferToGBString($user->transfer_enable - $user->u - $user->d);
    $u = $this->transferToGBString($user->u);
    $d = $this->transferToGBString($user->d);
    $expired_at = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效';
    $money = $user->balance / 100;
    $affmoney = $user->commission_balance / 100;
    $plan = $user->plan;
    $ip = request()?->ip() ?? '';
    $region = $ip ? (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? (new \Ip2Region())->simple($ip) : 'NULL') : '';
    $TGmessage = "工单ID: {$ticket->id}\n";
    $TGmessage .= "━━━━━━━━━━━━━━━━━━━━\n";
    $TGmessage .= "📧 邮箱: {$user->email}\n";
    $TGmessage .= "📍 位置: {$region}\n";

    if ($plan) {
      $TGmessage .= "📦 套餐: {$plan->name}\n";
      $TGmessage .= "📊 流量: `{$remaining_traffic}G / {$transfer_enable}G` (剩余/总计)\n";
      $TGmessage .= "⬆️⬇️ 已用: `{$u}G / {$d}G`\n";
      $TGmessage .= "⏰ 到期: `{$expired_at}`\n";
    } else {
      $TGmessage .= "📦 套餐: 未订购任何套餐\n";
    }

    $TGmessage .= "💰 余额: {$money}元\n";
    $TGmessage .= "💸 佣金: {$affmoney}元\n";
    $TGmessage .= "━━━━━━━━━━━━━━━━━━━━\n";
    $TGmessage .= "📝 主题: {$ticket->subject}\n";
    $TGmessage .= "💬 内容: {$message->message}";

    $message->load('attachments');
    $attachments = $message->attachments ?? collect();
    $maxImages = (int) config('tickets.attachments.max_images', 3);
    $attachments = $attachments->take($maxImages);

    $files = [];
    foreach ($attachments as $att) {
      $disk = $att->disk ?: (string) config('tickets.attachments.disk', 'local');
      $path = $att->path;
      if (!$path || !Storage::disk($disk)->exists($path)) {
        continue;
      }

      $absolute = Storage::disk($disk)->path($path);
      $filename = basename($path);
      $files[] = [
        'path' => $absolute,
        'filename' => $filename,
      ];
    }

    if (!empty($files)) {
      $this->telegramService->sendMediaGroupPhotosWithAdmin($files, $TGmessage, true, (int) $ticket->id);
      return;
    }

    $this->telegramService->sendMessageWithAdmin($TGmessage, true);
  }

  protected function registerDefaultCommands(): void
  {
    foreach ($this->commandConfigs as $command => $config) {
      $this->registerTelegramCommand($command, [$this, $config['handler']]);
    }

    $this->registerReplyHandler('/(📮.*?工单提醒.*?#?|工单ID: ?)(\\d+)/', [$this, 'handleTicketReply']);
  }

  public function registerTelegramCommand(string $command, callable $handler): void
  {
    $this->commands['commands'][$command] = $handler;
  }

  public function registerReplyHandler(string $regex, callable $handler): void
  {
    $this->commands['replies'][$regex] = $handler;
  }

  /**
   * 发送消息给用户
   */
  protected function sendMessage(object $msg, string $message, array $options = []): void
  {
    $this->telegramService->sendMessage($msg->chat_id, $message, 'markdown', $options);
  }

  /**
   * 检查是否为私聊
   */
  protected function checkPrivateChat(object $msg): bool
  {
    if (!$msg->is_private) {
      $this->sendMessage($msg, '请在私聊中使用此命令');
      return false;
    }
    return true;
  }

  /**
   * 获取绑定的用户
   */
  protected function getBoundUser(object $msg): ?User
  {
    $user = User::where('telegram_id', $msg->chat_id)->first();
    if (!$user) {
      $this->sendMessage($msg, '请先绑定账号');
      return null;
    }
    return $user;
  }

  public function handleStartCommand(object $msg): void
  {
    if ($msg->is_private && isset($msg->args) && is_array($msg->args) && isset($msg->args[0]) && is_string($msg->args[0])) {
      $payload = trim((string) $msg->args[0]);
      if ($payload !== '' && $this->handleLoginStartPayload($msg, $payload)) {
        return;
      }
    }

    $welcomeTitle = $this->getConfig('start_welcome_title', '🎉 欢迎使用 XBoard Telegram Bot！');
    $botDescription = $this->getConfig('start_bot_description', '🤖 我是您的专属助手，可以帮助您：\\n• 绑定您的 XBoard 账号\\n• 查看流量使用情况\\n• 获取最新订阅链接\\n• 管理账号绑定状态');
    $footer = $this->getConfig('start_footer', '💡 提示：所有命令都需要在私聊中使用');

    $welcomeText = $welcomeTitle . "\n\n" . $botDescription . "\n\n";

    $user = User::where('telegram_id', $msg->chat_id)->first();
    if ($user) {
      $welcomeText .= "✅ 您已绑定账号：{$user->email}\n\n";
      $welcomeText .= $this->getConfig('start_unbind_guide', '📋 可用命令：\\n/traffic - 查看流量使用情况\\n/getlatesturl - 获取订阅链接\\n/unbind - 解绑账号');
    } else {
      $welcomeText .= $this->getConfig('start_bind_guide', '🔗 请先绑定您的 XBoard 账号：\\n1. 登录您的 XBoard 账户\\n2. 复制您的订阅 Token\\n3. 发送 /bind + 订阅 Token') . "\n\n";
      $welcomeText .= $this->getConfig('start_bind_commands', '📋 可用命令：\\n/bind [订阅 Token] - 绑定账号');
    }

    $welcomeText .= "\n\n" . $footer;
    $welcomeText = str_replace('\\n', "\n", $welcomeText);

    $this->sendMessage($msg, $welcomeText);
  }

  protected function handleLoginStartPayload(object $msg, string $payload): bool
  {
    if (!str_starts_with($payload, 'login_')) {
      return false;
    }

    $session = substr($payload, strlen('login_'));
    $session = trim((string) $session);
    if (!$this->isValidLoginSessionId($session)) {
      $this->sendMessage($msg, '登录参数无效，请回到网页重新发起 Telegram 登录');
      return true;
    }

    $cacheKey = self::LOGIN_SESSION_PREFIX . $session;
    $record = Cache::get($cacheKey);
    if (!is_array($record)) {
      $this->sendMessage($msg, '登录已过期，请回到网页重新发起 Telegram 登录');
      return true;
    }

    $user = User::where('telegram_id', $msg->chat_id)->first();
    if (!$user) {
      $guide = $this->getConfig(
        'start_bind_guide',
        '🔗 请先绑定您的 XBoard 账号：\\n1. 登录您的 XBoard 账户\\n2. 复制您的订阅 Token\\n3. 发送 /bind + 订阅 Token'
      );
      $guide = str_replace('\\n', "\n", $guide);
      $this->sendMessage($msg, "❌ 该 Telegram 账号未绑定\n\n{$guide}", ['disable_web_page_preview' => true]);
      return true;
    }
    if ((bool) $user->banned) {
      $this->sendMessage($msg, '账号已被封禁');
      return true;
    }

    if (($record['approved'] ?? false) && isset($record['user_id']) && is_numeric($record['user_id'])) {
      $this->sendMessage($msg, "✅ 已确认登录\n\n请回到网页继续完成登录", ['disable_web_page_preview' => true]);
      return true;
    }

    $record['approved'] = true;
    $record['approved_at'] = time();
    $record['user_id'] = $user->id;
    $record['telegram_id'] = $msg->chat_id;
    $expiresAt = (int) ($record['expires_at'] ?? 0);
    $ttl = $expiresAt > 0 ? $expiresAt - time() : 300;
    if ($ttl <= 0) $ttl = 60;
    Cache::put($cacheKey, $record, $ttl);

    $this->sendMessage($msg, "✅ 已确认登录\n\n请回到网页继续完成登录", ['disable_web_page_preview' => true]);
    return true;
  }

  protected function isValidLoginSessionId(string $value): bool
  {
    $raw = trim($value);
    if ($raw === '') return false;
    if (strlen($raw) < 16 || strlen($raw) > 48) return false;
    return (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $raw);
  }

  public function handleMessage(bool $handled, array $data): bool
  {
    list($msg) = $data;
    if ($handled)
      return $handled;

    try {
      return match ($msg->message_type) {
        'message' => $this->handleCommandMessage($msg),
        'reply_message' => $this->handleReplyMessage($msg),
        default => false
      };
    } catch (\Exception $e) {
      Log::error('Telegram 命令处理意外错误', [
        'command' => $msg->command ?? 'unknown',
        'chat_id' => $msg->chat_id ?? 'unknown',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
      ]);

      if (isset($msg->chat_id)) {
        $this->telegramService->sendMessage($msg->chat_id, '系统繁忙，请稍后重试');
      }

      return true;
    }
  }

  protected function handleCommandMessage(object $msg): bool
  {
    if (!isset($this->commands['commands'][$msg->command])) {
      return false;
    }

    call_user_func($this->commands['commands'][$msg->command], $msg);
    return true;
  }

  protected function handleReplyMessage(object $msg): bool
  {
    if (!isset($this->commands['replies'])) {
      return false;
    }

    foreach ($this->commands['replies'] as $regex => $handler) {
      if (preg_match($regex, $msg->reply_text, $matches)) {
        call_user_func($handler, $msg, $matches);
        return true;
      }
    }

    // Fallback: media-group messages might not contain caption/text on the replied item,
    // so we also map reply_to_message_id -> ticket id.
    $replyMessageId = $msg->reply_message_id ?? null;
    if (is_numeric($replyMessageId)) {
      $ticketId = Cache::get("tg_ticket_reply:{$msg->chat_id}:" . (int) $replyMessageId);
      if (is_numeric($ticketId) && (int) $ticketId > 0) {
        $this->handleTicketReply($msg, [null, '工单ID: ', (string) $ticketId]);
        return true;
      }
    }

    return false;
  }

  public function handleUnknownCommand(array $data): void
  {
    list($msg) = $data;
    if (!$msg->is_private || $msg->message_type !== 'message')
      return;

    $helpText = $this->getConfig('help_text', '未知命令，请查看帮助');
    $this->telegramService->sendMessage($msg->chat_id, $helpText);
  }

  public function handleError(array $data): void
  {
    list($msg, $e) = $data;
    Log::error('Telegram 消息处理错误', [
      'chat_id' => $msg->chat_id ?? 'unknown',
      'command' => $msg->command ?? 'unknown',
      'message_type' => $msg->message_type ?? 'unknown',
      'error' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine()
    ]);
  }

  public function handleBindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $subscribeUrl = $msg->args[0] ?? null;
    if (!$subscribeUrl) {
      $this->sendMessage($msg, '参数有误，请携带订阅 Token 发送');
      return;
    }

    $token = $this->extractTokenFromUrl($subscribeUrl);
    if (!$token) {
      $this->sendMessage($msg, '订阅 Token 无效');
      return;
    }

    $user = User::where('token', $token)->first();
    if (!$user) {
      $this->sendMessage($msg, '用户不存在');
      return;
    }

    if ($user->telegram_id) {
      $this->sendMessage($msg, '该账号已经绑定了Telegram账号');
      return;
    }

    $user->telegram_id = $msg->chat_id;
    if (!$user->save()) {
      $this->sendMessage($msg, '设置失败');
      return;
    }

    HookManager::call('user.telegram.bind.after', [$user]);
    $this->sendMessage($msg, '绑定成功');
  }

  protected function extractTokenFromUrl(string $url): ?string
  {
    $parsedUrl = parse_url($url);

    if (isset($parsedUrl['query'])) {
      parse_str($parsedUrl['query'], $query);
      if (isset($query['token'])) {
        return $query['token'];
      }
    }

    if (isset($parsedUrl['path'])) {
      $pathParts = explode('/', trim($parsedUrl['path'], '/'));
      $lastPart = end($pathParts);
      return $lastPart ?: null;
    }

    return null;
  }

  public function handleTrafficCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    $transferUsed = $user->u + $user->d;
    $transferTotal = $user->transfer_enable;
    $transferRemaining = $transferTotal - $transferUsed;
    $usagePercentage = $transferTotal > 0 ? ($transferUsed / $transferTotal) * 100 : 0;

    $text = sprintf(
      "📊 流量使用情况\n\n已用流量：%sG\n总流量：%sG\n剩余流量：%sG\n使用率：%.2f%%",
      $this->transferToGBString($transferUsed),
      $this->transferToGBString($transferTotal),
      $this->transferToGBString($transferRemaining),
      $usagePercentage
    );

    $this->sendMessage($msg, $text);
  }

  public function handleGetLatestUrlCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    $subscribeUrl = Helper::getSubscribeUrl($user->token);
    $text = sprintf("🔗 您的订阅链接：\n\n%s", $subscribeUrl);

    $this->sendMessage($msg, $text, ['disable_web_page_preview' => true]);
  }

  public function handleUnbindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    $user->telegram_id = null;
    if (!$user->save()) {
      $this->sendMessage($msg, '解绑失败');
      return;
    }

    $this->sendMessage($msg, '解绑成功');
  }

  public function handleTicketReply(object $msg, array $matches): void
  {
    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    if (!$user->is_admin && !$user->is_staff) {
      $this->sendMessage($msg, '无权限');
      return;
    }

    if (!isset($matches[2]) || !is_numeric($matches[2])) {
      Log::warning('Telegram 工单回复正则未匹配到工单ID', ['matches' => $matches, 'msg' => $msg]);
      $this->sendMessage($msg, '未能识别工单ID，请直接回复工单提醒消息。');
      return;
    }

    $ticketId = (int) $matches[2];
    $ticket = Ticket::where('id', $ticketId)->first();
    if (!$ticket) {
      $this->sendMessage($msg, '工单不存在');
      return;
    }

    $ticketService = new TicketService();
    $maxImages = (int) config('tickets.attachments.max_images', 3);
    $maxKb = (int) config('tickets.attachments.max_kb', 5120);

    $text = is_string($msg->text ?? null) ? (string) $msg->text : '';
    $imageMetas = $msg->images ?? [];
    $imageMetas = is_array($imageMetas) ? $imageMetas : [];
    $imageMetas = array_slice($imageMetas, 0, max(0, $maxImages));

    $mediaGroupId = isset($msg->media_group_id) && is_string($msg->media_group_id) ? trim($msg->media_group_id) : '';
    if ($mediaGroupId !== '') {
      $key = "tg_ticket_media_group_reply:{$msg->chat_id}:{$mediaGroupId}";
      $data = Cache::get($key);
      if (!is_array($data) || (int) ($data['ticket_id'] ?? 0) !== $ticketId || (int) ($data['user_id'] ?? 0) !== (int) $user->id) {
        $data = [
          'ticket_id' => $ticketId,
          'user_id' => (int) $user->id,
          'text' => '',
          'images' => [],
          'updated_at' => 0,
        ];
      }

      if (trim($text) !== '' && trim((string) ($data['text'] ?? '')) === '') {
        $data['text'] = $text;
      }

      $existingImages = $data['images'] ?? [];
      $existingImages = is_array($existingImages) ? array_values($existingImages) : [];
      $existingIds = [];
      foreach ($existingImages as $it) {
        if (is_array($it) && isset($it['file_id']) && is_string($it['file_id'])) {
          $existingIds[$it['file_id']] = true;
        }
      }

      foreach ($imageMetas as $meta) {
        if (!is_array($meta)) {
          continue;
        }
        $fileId = $meta['file_id'] ?? null;
        if (!is_string($fileId) || $fileId === '' || isset($existingIds[$fileId])) {
          continue;
        }
        $existingImages[] = [
          'file_id' => $fileId,
          'file_size' => $meta['file_size'] ?? null,
          'file_name' => $meta['file_name'] ?? null,
        ];
        $existingIds[$fileId] = true;
        if ($maxImages > 0 && count($existingImages) >= $maxImages) {
          break;
        }
      }

      $data['images'] = $existingImages;
      $data['updated_at'] = time();
      Cache::put($key, $data, now()->addMinutes(10));
      ProcessTelegramTicketMediaGroupReplyJob::dispatch((int) $msg->chat_id, $mediaGroupId)->delay(now()->addSeconds(2));
      return;
    }

    $tempPaths = [];
    $files = [];
    try {
      foreach ($imageMetas as $meta) {
        if (!is_array($meta)) {
          continue;
        }
        $fileId = $meta['file_id'] ?? null;
        if (!is_string($fileId) || $fileId === '') {
          continue;
        }
        $fileSize = $meta['file_size'] ?? null;
        if (is_numeric($fileSize) && (int) $fileSize > ($maxKb * 1024)) {
          $this->sendMessage($msg, '图片过大，已忽略');
          continue;
        }

        $preferredName = isset($meta['file_name']) && is_string($meta['file_name']) ? $meta['file_name'] : null;
        $downloaded = $this->telegramService->downloadFileToTemp($fileId, $preferredName);
        $downloadedSize = @filesize($downloaded['path']);
        if (is_int($downloadedSize) && $downloadedSize > ($maxKb * 1024)) {
          $this->sendMessage($msg, '图片过大，已忽略');
          @unlink($downloaded['path']);
          continue;
        }
        $tempPaths[] = $downloaded['path'];
        $files[] = new UploadedFile($downloaded['path'], $downloaded['filename'], null, null, true);
      }

      if (trim($text) === '' && empty($files)) {
        $this->sendMessage($msg, '请发送文字或图片');
        return;
      }

      $ticketService->replyByAdmin(
        $ticketId,
        $text,
        $user->id,
        $files
      );
    } finally {
      foreach ($tempPaths as $p) {
        try {
          if (is_string($p) && $p !== '' && is_file($p)) {
            @unlink($p);
          }
        } catch (\Exception) {
        }
      }
    }

    $this->sendMessage($msg, "工单 #{$ticketId} 回复成功");
  }

  /**
   * 添加 Bot 命令到命令列表
   */
  public function addBotCommands(array $commands): array
  {
    foreach ($this->commandConfigs as $command => $config) {
      $commands[] = [
        'command' => $command,
        'description' => $config['description']
      ];
    }

    return $commands;
  }

  private function transferToGBString(float $transfer_enable, int $decimals = 2): string
  {
    return number_format(Helper::transferToGB($transfer_enable), $decimals, '.', '');
  }

}
