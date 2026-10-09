<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">
@include('mail-layouts.responsive', ['darkAccent' => '#ced8e4'])
</head>
<body class="mail-background" style="background:#f3f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Microsoft YaHei',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="mail-background" style="table-layout:fixed;background:#f3f4f5;"><tr><td class="mail-outer" align="center" style="padding:36px 16px;">
<!--[if mso]><table role="presentation" width="600" align="center"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;max-width:600px;">
    <tr><td style="padding:0 4px 20px;"><div class="mail-brand" style="color:#24282e;font-size:21px;font-weight:700;line-height:1.5;overflow-wrap:anywhere;word-break:break-word;">{{ $name }}</div></td></tr>
    <tr><td class="mail-card mail-padding" style="background:#ffffff;border:1px solid #dfe2e6;border-radius:4px;padding:36px;">
        <p class="mail-muted" style="margin:0 0 24px;color:#6c737d;font-size:12px;line-height:1.6;">账户来信</p>
        @include('mail-layouts.message', ['ink' => '#272b32', 'muted' => '#626a75', 'accent' => '#303841', 'panel' => '#f6f7f8', 'line' => '#dde1e6', 'buttonRadius' => '3px', 'buttonInk' => '#ffffff'])
        <div class="mail-divider" style="margin-top:28px;padding-top:20px;border-top:1px solid #e6e8eb;">
            <p class="mail-text" style="margin:0 0 4px;font-size:14px;line-height:1.7;color:#272b32;overflow-wrap:anywhere;">{{ $name }}</p>
            <p class="mail-muted" style="margin:0;font-size:12px;line-height:1.7;color:#626a75;">感谢您的信任与使用。</p>
        </div>
    </td></tr>
    <tr><td style="padding:18px 4px 0;">
        <p class="mail-muted" style="margin:0;color:#737a83;font-size:12px;line-height:1.8;">此邮件由系统自动发送，请勿直接回复。</p>
        <a class="mail-link" href="{{ $url }}" style="color:#505c6b;font-size:12px;line-height:1.8;text-decoration:underline;">访问账户</a>
    </td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table></body></html>
