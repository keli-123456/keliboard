<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">
@include('mail-layouts.responsive', ['darkAccent' => '#f4adb9'])
</head>
<body class="mail-background" style="background:#fff2f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Microsoft YaHei',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="mail-background" style="table-layout:fixed;background:#fff2f4;"><tr><td class="mail-outer" align="center" style="padding:36px 16px;">
<!--[if mso]><table role="presentation" width="600" align="center"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="mail-card" style="table-layout:fixed;max-width:600px;background:#ffffff;border:1px solid #f1dce1;border-radius:8px;overflow:hidden;">
    <tr><td class="mail-header mail-padding" align="center" style="padding:28px 32px;background:#ffeaee;">
        <div class="mail-brand" style="font-size:22px;line-height:1.5;font-weight:700;color:#91394d;overflow-wrap:anywhere;word-break:break-word;">{{ $name }}</div>
        <div class="mail-muted" style="margin-top:6px;color:#94616b;font-size:12px;line-height:1.6;">您的账户消息</div>
    </td></tr>
    <tr><td class="mail-padding" style="padding:32px;">
        @include('mail-layouts.message', ['ink' => '#3b2931', 'muted' => '#7c6570', 'accent' => '#c83c60', 'panel' => '#fff3f5', 'line' => '#f0d5de', 'buttonRadius' => '6px', 'buttonInk' => '#ffffff'])
    </td></tr>
    <tr><td class="mail-padding mail-divider" align="center" style="padding:20px 32px;border-top:1px solid #f3e5e9;">
        <a class="mail-link" href="{{ $url }}" style="color:#b43555;font-size:13px;line-height:1.8;text-decoration:none;overflow-wrap:anywhere;">返回 {{ $name }}</a>
        <p class="mail-muted" style="margin:6px 0 0;color:#8a727c;font-size:12px;line-height:1.7;">此邮件由系统自动发送，请勿直接回复。</p>
    </td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table></body></html>
