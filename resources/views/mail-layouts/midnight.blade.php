<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><meta name="supported-color-schemes" content="dark">
@include('mail-layouts.responsive', ['darkAccent' => '#84d9ce'])
</head>
<body class="mail-background" style="background:#121517;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Microsoft YaHei',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="mail-background" style="table-layout:fixed;background:#121517;"><tr><td class="mail-outer" align="center" style="padding:36px 16px;">
<!--[if mso]><table role="presentation" width="600" align="center"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="mail-card" style="table-layout:fixed;max-width:600px;background:#202529;border:1px solid #384249;border-radius:8px;overflow:hidden;">
    <tr><td class="mail-padding mail-divider" style="padding:24px 32px;border-bottom:1px solid #384249;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;"><tr><td style="border-left:3px solid #84d9ce;padding-left:16px;">
            <div class="mail-brand" style="font-size:21px;font-weight:700;line-height:1.5;color:#f1f5f5;overflow-wrap:anywhere;word-break:break-word;">{{ $name }}</div>
            <div class="mail-muted" style="margin-top:4px;font-size:12px;line-height:1.6;color:#a9bbc0;">账户与服务通知</div>
        </td></tr></table>
    </td></tr>
    <tr><td class="mail-padding" style="padding:32px;">
        @include('mail-layouts.message', ['ink' => '#eef4f5', 'muted' => '#b0bdc4', 'accent' => '#84d9ce', 'panel' => '#141c21', 'line' => '#39454d', 'buttonRadius' => '4px', 'buttonInk' => '#15282c'])
    </td></tr>
    <tr><td class="mail-padding mail-divider" style="padding:20px 32px;border-top:1px solid #384249;">
        <a class="mail-link" href="{{ $url }}" style="color:#84d9ce;font-size:12px;line-height:1.8;text-decoration:none;overflow-wrap:anywhere;">{{ $name }}</a>
        <p class="mail-muted" style="margin:6px 0 0;color:#a9bbc0;font-size:12px;line-height:1.7;">此邮件由系统自动发送，请勿直接回复。</p>
    </td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table></body></html>
