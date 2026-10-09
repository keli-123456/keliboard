@php
    $titles = ['remindExpire' => '服务即将到期', 'remindTraffic' => '留意您的剩余流量', 'verify' => '验证您的邮箱', 'mailLogin' => '登录您的账户', 'notify' => '您有一条新通知'];
@endphp
<h1 class="mail-text" style="margin:0 0 20px;color:{{ $ink }};font-size:26px;font-weight:700;line-height:1.4;letter-spacing:0;overflow-wrap:anywhere;">{{ $titles[$kind] }}</h1>
<div class="mail-text" style="font-size:15px;line-height:1.85;color:{{ $ink }};overflow-wrap:anywhere;word-wrap:break-word;">
@switch($kind)
    @case('verify')
        <p style="margin:0 0 20px;">您好，请使用以下验证码完成邮箱验证。</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;"><tr><td class="mail-panel" align="center" style="padding:22px 12px;background:{{ $panel }};border:1px solid {{ $line }};border-radius:6px;">
            <div class="mail-muted" style="font-size:12px;color:{{ $muted }};margin-bottom:8px;">邮箱验证码</div>
            <div class="mail-code" style="color:{{ $accent }};font:700 34px/1.3 Consolas,'Courier New',monospace;letter-spacing:0;word-break:break-all;">{{ $code }}</div>
        </td></tr></table>
        <p class="mail-muted" style="margin:16px 0 0;color:{{ $muted }};font-size:13px;">验证码 5 分钟内有效。请勿将验证码提供给他人；若非本人操作，请忽略此邮件。</p>
        @break
    @case('mailLogin')
        <p style="margin:0 0 12px;">您好，您正在登录 {{ $name }}。</p>
        <p class="mail-muted" style="margin:0 0 24px;color:{{ $muted }};">请在 5 分钟内使用下方链接完成登录。若非本人操作，请忽略此邮件。</p>
        <a class="mail-button" href="{{ $link }}" style="display:inline-block;padding:12px 24px;border-radius:{{ $buttonRadius }};background:{{ $accent }};color:{{ $buttonInk }};font-size:14px;font-weight:700;line-height:24px;text-decoration:none;text-align:center;">安全登录账户</a>
        <p class="mail-muted" style="margin:22px 0 6px;color:{{ $muted }};font-size:12px;">也可复制下方链接到浏览器打开：</p>
        <a class="mail-link" href="{{ $link }}" style="font-size:12px;line-height:1.7;color:{{ $accent }};word-break:break-all;overflow-wrap:anywhere;">{{ $link }}</a>
        @break
    @case('remindExpire')
        <p style="margin:0 0 12px;">您好，您在 {{ $name }} 的服务将在 24 小时内到期。</p>
        <p class="mail-muted" style="margin:0 0 24px;color:{{ $muted }};">为避免影响使用，请及时查看套餐并续费。如果您已经完成续费，请忽略此邮件。</p>
        <a class="mail-button" href="{{ $url }}" style="display:inline-block;padding:12px 24px;border-radius:{{ $buttonRadius }};background:{{ $accent }};color:{{ $buttonInk }};font-size:14px;font-weight:700;line-height:24px;text-decoration:none;text-align:center;">查看我的套餐</a>
        @break
    @case('remindTraffic')
        <p style="margin:0 0 20px;">您好，您在 {{ $name }} 的套餐流量使用量已达到 80%。</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td class="mail-panel" style="padding:16px 20px;background:{{ $panel }};border-left:3px solid {{ $accent }};border-radius:{{ $buttonRadius }};">
            <p class="mail-text" style="margin:0 0 4px;font-weight:700;color:{{ $ink }};">流量使用提醒</p>
            <p class="mail-muted" style="margin:0;font-size:13px;color:{{ $muted }};">剩余流量及重置时间以账户页面显示为准。</p>
        </td></tr></table>
        <p class="mail-muted" style="margin:20px 0;color:{{ $muted }};">请合理安排后续使用，您也可以进入账户查看流量详情。</p>
        <a class="mail-button" href="{{ $url }}" style="display:inline-block;padding:12px 24px;border-radius:{{ $buttonRadius }};background:{{ $accent }};color:{{ $buttonInk }};font-size:14px;font-weight:700;line-height:24px;text-decoration:none;text-align:center;">查看流量详情</a>
        @break
    @case('notify')
        <div class="mail-notification" style="overflow-wrap:anywhere;word-wrap:break-word;">{!! nl2br($content) !!}</div>
        <div style="margin-top:24px;"><a class="mail-button" href="{{ $url }}" style="display:inline-block;padding:12px 24px;border-radius:{{ $buttonRadius }};background:{{ $accent }};color:{{ $buttonInk }};font-size:14px;font-weight:700;line-height:24px;text-decoration:none;text-align:center;">前往账户</a></div>
        @break
@endswitch
</div>
