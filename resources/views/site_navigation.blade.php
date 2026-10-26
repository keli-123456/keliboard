<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title }}</title>
    <style>
        :root { color-scheme: light; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #172b31; background: #f5f7f9; font-synthesis: none; text-rendering: optimizeLegibility; }
        * { box-sizing: border-box; }
        body { margin: 0; min-width: 280px; letter-spacing: 0; }
        .shell { width: min(760px, calc(100% - 48px)); margin-inline: auto; }
        .site-header { background: #fff; border-bottom: 1px solid #e4e9ed; }
        .masthead { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding-block: 25px; }
        .brand { display: flex; align-items: center; gap: 13px; min-width: 0; }
        .logo, .monogram { width: 44px; height: 44px; flex: 0 0 44px; border-radius: 8px; }
        .logo { display: block; object-fit: contain; }
        .monogram { display: grid; place-items: center; color: #fff; background: #11755f; font-size: 23px; font-weight: 700; }
        h1 { margin: 0; font-size: 23px; line-height: 1.3; overflow-wrap: anywhere; }
        .masthead-label { flex-shrink: 0; padding-left: 18px; border-left: 1px solid #e0e7eb; color: #63747d; font-size: 13px; }
        main { padding-block: 38px 24px; }
        .intro { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
        h2 { margin: 0; font-size: 19px; font-weight: 650; line-height: 1.4; }
        .intro > div { min-width: 0; }
        .description { margin: 7px 0 0; color: #5e727c; font-size: 13px; line-height: 1.7; overflow-wrap: anywhere; }
        .entry-count { flex-shrink: 0; color: #5e727c; font-size: 12px; font-variant-numeric: tabular-nums; }
        .announcement { margin: 0 0 20px; padding: 12px 14px; border-left: 2px solid #d0a247; background: #fffbef; color: #76612f; font-size: 13px; line-height: 1.7; overflow-wrap: anywhere; }
        .entries { display: grid; gap: 12px; }
        .entry { color: inherit; text-decoration: none; background: #fff; border: 1px solid #e0e7eb; border-radius: 8px; transition: border-color .15s ease, box-shadow .15s ease; }
        .entry:hover { border-color: #8eafa4; box-shadow: 0 4px 14px rgb(26 55 47 / 5%); }
        .entry:focus-visible { outline: 3px solid #07846e; outline-offset: 4px; }
        .entry-primary { display: block; padding: 22px 24px; border-color: #b9d7cc; box-shadow: 0 2px 5px rgb(26 55 47 / 3%); }
        .primary-top { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 17px; }
        .primary-label { display: flex; align-items: center; gap: 8px; min-width: 0; color: #23775e; font-size: 12px; font-weight: 600; overflow-wrap: anywhere; }
        .primary-label::before { content: ""; width: 6px; height: 6px; flex: 0 0 6px; border-radius: 50%; background: #279b76; }
        .protocol { flex-shrink: 0; color: #63747d; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 11px; }
        .primary-main { display: flex; align-items: center; justify-content: space-between; gap: 24px; }
        .address { display: flex; align-items: center; gap: 14px; min-width: 0; max-width: 100%; }
        .address-mark { display: grid; place-items: center; width: 44px; height: 44px; flex: 0 0 44px; border-radius: 8px; background: #edf6f1; color: #258265; }
        .address-info { min-width: 0; }
        .address-name { display: block; font-size: 20px; font-weight: 650; line-height: 1.4; overflow-wrap: anywhere; }
        .url { display: block; margin-top: 5px; color: #63747d; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; line-height: 1.6; overflow-wrap: anywhere; }
        .visit { display: inline-flex; align-items: center; justify-content: center; gap: 10px; flex-shrink: 0; min-height: 44px; padding: 10px 15px; border-radius: 6px; color: #fff; background: #187b62; font-size: 13px; font-weight: 600; }
        .secondary { margin-top: 28px; }
        .section-heading { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; }
        h3 { margin: 0; color: #53656e; font-size: 13px; font-weight: 600; }
        .section-heading span { color: #63747d; font-size: 12px; }
        .entry-secondary { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 86px; padding: 16px 18px; }
        .entry-secondary .address { gap: 12px; }
        .entry-secondary .address-mark { width: 36px; height: 36px; flex-basis: 36px; color: #6586ac; background: #f0f4f9; }
        .entry-secondary .address-name { font-size: 15px; font-weight: 600; }
        .entry-meta { display: flex; flex-wrap: wrap; align-items: center; column-gap: 9px; row-gap: 3px; margin-top: 4px; }
        .entry-type { color: #63747d; font-size: 12px; }
        .entry-meta .url { margin-top: 0; }
        .entry-meta .url::before { content: "/"; margin-right: 9px; color: #d0d8de; }
        .entry-arrow { display: grid; place-items: center; flex: 0 0 30px; width: 30px; height: 30px; color: #63747d; }
        .entry:hover .entry-arrow { color: #187b62; }
        .icon { display: block; width: 19px; height: 19px; flex-shrink: 0; }
        .visit .icon { width: 15px; height: 15px; }
        .empty { margin-top: 14px; padding: 32px 0; border-block: 1px solid #e2e8ec; text-align: center; }
        .empty .icon { width: 25px; height: 25px; margin: 0 auto 12px; color: #9ba9b1; }
        .empty p { margin: 0; color: #5e727c; font-size: 14px; }
        .opportunities { margin-top: 30px; }
        .opportunity-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .opportunity { display: flex; flex-direction: column; align-items: flex-start; min-width: 0; padding: 18px; }
        .opportunity:only-child { grid-column: 1 / -1; }
        .opportunity-head { display: flex; align-items: center; gap: 10px; }
        .opportunity-icon { display: grid; place-items: center; width: 32px; height: 32px; flex: 0 0 32px; border-radius: 6px; color: #3c6690; background: #edf3fa; }
        .opportunity-agent .opportunity-icon { color: #916824; background: #fbf4e6; }
        .opportunity-title { font-size: 15px; font-weight: 650; line-height: 1.5; overflow-wrap: anywhere; }
        .opportunity p { margin: 12px 0 6px; color: #53656e; font-size: 13px; line-height: 1.8; overflow-wrap: anywhere; }
        .opportunity-detail { display: block; color: #63747d; font-size: 12px; line-height: 1.7; overflow-wrap: anywhere; }
        .opportunity-action { display: flex; align-items: center; gap: 7px; padding-top: 17px; margin-top: auto; color: #187b62; font-size: 12px; font-weight: 600; }
        .opportunity-action .icon { width: 15px; height: 15px; }
        .opportunity-note { margin: 10px 0 0; color: #63747d; font-size: 11px; line-height: 1.7; }
        .help { display: flex; align-items: baseline; gap: 12px 24px; flex-wrap: wrap; margin-top: 24px; padding-top: 16px; border-top: 1px solid #e2e8ec; }
        .help h3 { flex-shrink: 0; }
        .help-links { display: flex; gap: 4px 20px; flex-wrap: wrap; min-width: 0; }
        .help a { display: inline-flex; align-items: center; min-height: 40px; color: #4a6677; font-size: 12px; line-height: 1.7; text-decoration: none; overflow-wrap: anywhere; }
        .help a:hover { color: #187b62; text-decoration: underline; text-underline-offset: 4px; }
        .help a:focus-visible { outline: 3px solid #07846e; outline-offset: 3px; border-radius: 2px; }
        footer { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-top: 25px; padding-top: 16px; border-top: 1px solid #e2e8ec; color: #63747d; font-size: 12px; line-height: 1.6; }
        .help + footer { margin-top: 5px; padding-top: 6px; border-top: 0; }
        .updated { display: flex; align-items: center; gap: 6px; }
        .updated .icon { width: 12px; height: 12px; }
        .footer-brand { text-align: right; overflow-wrap: anywhere; }
        @media (max-width: 520px) {
            .shell { width: calc(100% - 32px); }
            .masthead { padding-block: 20px; gap: 12px; }
            h1 { font-size: 21px; }
            .masthead-label { padding-left: 12px; font-size: 11px; }
            .logo, .monogram { width: 38px; height: 38px; flex-basis: 38px; }
            main { padding-top: 27px; }
            .intro { align-items: flex-start; gap: 12px; }
            h2 { font-size: 18px; }
            .description { font-size: 12px; }
            .entry-count { padding-top: 5px; font-size: 11px; }
            .entry-primary { padding: 18px; }
            .primary-main { align-items: flex-start; flex-direction: column; gap: 18px; }
            .primary-top { margin-bottom: 14px; }
            .address-name { font-size: 18px; }
            .address-mark { width: 38px; height: 38px; flex-basis: 38px; }
            .address { gap: 11px; }
            .visit { width: 100%; }
            .entry-secondary { padding: 14px; gap: 8px; }
            .entry-secondary .address-name { font-size: 14px; }
            .entry-meta { display: block; }
            .entry-meta .url { margin-top: 3px; font-size: 11px; }
            .entry-meta .url::before { display: none; }
            .secondary { margin-top: 24px; }
            .opportunities { margin-top: 26px; }
            .opportunity-grid { grid-template-columns: minmax(0, 1fr); }
            .opportunity { padding: 16px; }
            .opportunity p { margin-top: 10px; }
            .help { column-gap: 18px; }
            .help-links { column-gap: 16px; }
            footer { flex-wrap: wrap; gap: 6px 16px; }
        }
        @media (prefers-reduced-motion: reduce) { .entry { transition: none; } }
    </style>
</head>
<body>
@php
    $primaryEntries = array_values(array_filter($destinations, static fn ($entry) => !empty($entry['recommended'])));
    $secondaryEntries = array_values(array_filter($destinations, static fn ($entry) => empty($entry['recommended'])));
@endphp
<header class="site-header">
    <div class="masthead shell">
        <div class="brand">
            @if ($logo_url)
                <img class="logo" src="{{ $logo_url }}" alt="" width="44" height="44" decoding="async">
            @else
                <span class="monogram" aria-hidden="true">{{ mb_substr($title, 0, 1) }}</span>
            @endif
            <h1>{{ $title }}</h1>
        </div>
        <span class="masthead-label">地址导航</span>
    </div>
</header>
<main class="shell">
    <div class="intro">
        <div>
            <h2>访问入口</h2>
            <p class="description">{{ $description }}</p>
        </div>
        <span class="entry-count">{{ count($destinations) }} 个地址</span>
    </div>
    @if ($announcement)
        <aside class="announcement">{{ $announcement }}</aside>
    @endif
    @if (count($destinations))
        @if ($primaryEntries)
            <section class="entries" aria-label="推荐入口">
                @foreach ($primaryEntries as $destination)
                    <a class="entry entry-primary" href="{{ $destination['url'] }}" rel="noreferrer">
                        <div class="primary-top">
                            <span class="primary-label">{{ $destination['label'] }}</span>
                            <span class="protocol">HTTPS</span>
                        </div>
                        <div class="primary-main">
                            <div class="address">
                                <span class="address-mark">@include('components.navigation-icon', ['name' => 'globe'])</span>
                                <div class="address-info">
                                    <span class="address-name">{{ preg_replace('#^https://#i', '', rtrim($destination['url'], '/')) }}</span>
                                    <span class="url">{{ $destination['url'] }}</span>
                                </div>
                            </div>
                            <span class="visit">进入网站 @include('components.navigation-icon', ['name' => 'arrow'])</span>
                        </div>
                    </a>
                @endforeach
            </section>
        @endif
        @if ($secondaryEntries)
            <section class="secondary" aria-labelledby="secondary-title">
                <div class="section-heading">
                    <h3 id="secondary-title">其他入口</h3>
                    <span>{{ count($secondaryEntries) }} 个地址</span>
                </div>
                <div class="entries">
                    @foreach ($secondaryEntries as $destination)
                        <a class="entry entry-secondary" href="{{ $destination['url'] }}" rel="noreferrer">
                            <div class="address">
                                <span class="address-mark">@include('components.navigation-icon', ['name' => $destination['kind'] === 'proxy' ? 'server' : 'globe'])</span>
                                <div class="address-info">
                                    <span class="address-name">{{ $destination['label'] }}</span>
                                    <span class="entry-meta">
                                        <span class="entry-type">{{ $destination['kind'] === 'proxy' ? '网站反代' : ($destination['kind'] === 'site' ? '备用域名' : '快捷入口') }}</span>
                                        <span class="url">{{ $destination['url'] }}</span>
                                    </span>
                                </div>
                            </div>
                            <span class="entry-arrow">@include('components.navigation-icon', ['name' => 'arrow'])</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @else
        <section class="empty" aria-label="可用地址">
            @include('components.navigation-icon', ['name' => 'globe'])
            <p>暂时没有可用地址</p>
        </section>
    @endif
    @if (!empty($opportunities))
        <section class="opportunities" aria-labelledby="opportunities-title">
            <div class="section-heading">
                <h3 id="opportunities-title">推广与合作</h3>
            </div>
            <div class="opportunity-grid">
                @foreach ($opportunities as $opportunity)
                    <a class="entry opportunity opportunity-{{ $opportunity['kind'] }}" href="{{ $opportunity['url'] }}" rel="noreferrer">
                        <div class="opportunity-head">
                            <span class="opportunity-icon">@include('components.navigation-icon', ['name' => $opportunity['kind'] === 'agent' ? 'users' : 'gift'])</span>
                            <span class="opportunity-title">{{ $opportunity['title'] }}</span>
                        </div>
                        <p>{{ $opportunity['description'] }}</p>
                        <span class="opportunity-detail">{{ $opportunity['detail'] }}</span>
                        <span class="opportunity-action">{{ $opportunity['action'] }} @include('components.navigation-icon', ['name' => 'arrow'])</span>
                    </a>
                @endforeach
            </div>
            <p class="opportunity-note">参与资格与结算规则以账户内展示为准。</p>
        </section>
    @endif
    @if (!empty($help_links))
        <nav class="help" aria-labelledby="help-title">
            <h3 id="help-title">服务与帮助</h3>
            <div class="help-links">
                @foreach ($help_links as $link)
                    <a href="{{ $link['url'] }}" rel="noreferrer">{{ $link['label'] }}</a>
                @endforeach
            </div>
        </nav>
    @endif
    <footer>
        @if ($updated_at)
            <span class="updated">@include('components.navigation-icon', ['name' => 'clock']) 地址更新于 {{ date('Y-m-d H:i', $updated_at) }}</span>
        @endif
        <span class="footer-brand">{{ $title }} · 地址导航</span>
    </footer>
</main>
</body>
</html>
