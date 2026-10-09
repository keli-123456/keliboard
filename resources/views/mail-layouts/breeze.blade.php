<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">
@include('mail-layouts.responsive', ['darkAccent' => '#8bd9bc'])
</head>
<body class="mail-background" style="background:#eef5f2;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Microsoft YaHei',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="mail-background" style="table-layout:fixed;background:#eef5f2;"><tr><td class="mail-outer" align="center" style="padding:36px 16px;">
<!--[if mso]><table role="presentation" width="600" align="center"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="mail-card" style="table-layout:fixed;max-width:600px;background:#ffffff;border:1px solid #dce8e2;border-top:4px solid #23735a;border-radius:8px;overflow:hidden;">
    <tr><td class="mail-padding mail-header" style="padding:24px 32px;background:#f4faf6;">
        <div class="mail-brand" style="color:#205d4b;font-size:21px;font-weight:700;line-height:1.5;overflow-wrap:anywhere;word-break:break-word;">{{ $name }}</div>
        <div class="mail-muted" style="margin-top:4px;font-size:12px;line-height:1.6;color:#697e74;">账户服务通知</div>
    </td></tr>
    <tr><td class="mail-padding" style="padding:32px;">
        @include('mail-layouts.message', ['ink' => '#243a32', 'muted' => '#63786e', 'accent' => '#23735a', 'panel' => '#f1f8f4', 'line' => '#dce8e2', 'buttonRadius' => '6px', 'buttonInk' => '#ffffff'])
    </td></tr>
    <tr><td class="mail-padding mail-divider" style="padding:18px 32px;border-top:1px solid #e7eee9;">
        <p class="mail-muted" style="margin:0;font-size:12px;line-height:1.7;color:#73837b;">此邮件由系统自动发送，请勿直接回复。</p>
        <a class="mail-link" href="{{ $url }}" style="display:inline-block;margin-top:6px;color:#23735a;font-size:12px;text-decoration:none;overflow-wrap:anywhere;">{{ $name }}</a>
    </td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table></body></html>
