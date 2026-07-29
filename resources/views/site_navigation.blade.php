<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title }}</title>
    <style>
        :root {
            color-scheme: light;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #14231c;
            background: #f4f7f5;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-width: 280px; background: #f4f7f5; }
        main { width: min(720px, calc(100% - 32px)); margin: 0 auto; padding: 52px 0 40px; }
        header { padding-bottom: 26px; border-bottom: 1px solid #cfd8d3; }
        .brand { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .logo, .monogram {
            width: 52px;
            height: 52px;
            flex: 0 0 52px;
            border-radius: 8px;
        }
        .logo { display: block; object-fit: contain; background: #fff; border: 1px solid #d8e0dc; }
        .monogram { display: grid; place-items: center; color: #fff; background: #087a52; font-size: 24px; font-weight: 700; }
        h1 { margin: 0; font-size: 30px; line-height: 1.2; letter-spacing: 0; overflow-wrap: anywhere; }
        .description { margin: 8px 0 0; color: #586a61; font-size: 15px; line-height: 1.7; }
        .announcement { margin: 20px 0 0; padding: 13px 15px; border-left: 3px solid #d49a18; background: #fff9e8; color: #4d3c13; line-height: 1.65; }
        .entries { display: grid; gap: 10px; margin-top: 26px; }
        .entry {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 16px;
            min-height: 76px;
            padding: 15px 16px;
            color: inherit;
            text-decoration: none;
            background: #fff;
            border: 1px solid #d8e0dc;
            border-radius: 8px;
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }
        .entry:hover, .entry:focus-visible {
            border-color: #087a52;
            box-shadow: 0 8px 22px rgba(20, 35, 28, .08);
            transform: translateY(-1px);
            outline: none;
        }
        .label { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 650; }
        .badge { padding: 2px 7px; border-radius: 999px; color: #086141; background: #e2f4eb; font-size: 11px; font-weight: 600; }
        .url { display: block; margin-top: 6px; color: #687970; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; line-height: 1.5; overflow-wrap: anywhere; }
        .arrow { color: #087a52; font-size: 22px; line-height: 1; }
        .empty { margin-top: 26px; padding: 28px 18px; text-align: center; color: #687970; background: #fff; border: 1px solid #d8e0dc; border-radius: 8px; }
        footer { margin-top: 24px; color: #839088; font-size: 12px; text-align: center; }
        @media (max-width: 520px) {
            main { width: min(100% - 24px, 720px); padding-top: 28px; }
            h1 { font-size: 25px; }
            .entry { min-height: 70px; padding: 13px 14px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .entry { transition: none; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <div class="brand">
            @if ($logo_url)
                <img class="logo" src="{{ $logo_url }}" alt="">
            @else
                <span class="monogram">{{ mb_substr($title, 0, 1) }}</span>
            @endif
            <div>
                <h1>{{ $title }}</h1>
                <p class="description">{{ $description }}</p>
            </div>
        </div>
        @if ($announcement)
            <div class="announcement">{{ $announcement }}</div>
        @endif
    </header>

    @if (count($destinations))
        <section class="entries" aria-label="可用地址">
            @foreach ($destinations as $destination)
                <a class="entry" href="{{ $destination['url'] }}" rel="noreferrer">
                    <span>
                        <span class="label">
                            {{ $destination['label'] }}
                            @if ($destination['recommended'])
                                <span class="badge">推荐</span>
                            @endif
                        </span>
                        <span class="url">{{ $destination['url'] }}</span>
                    </span>
                    <span class="arrow" aria-hidden="true">›</span>
                </a>
            @endforeach
        </section>
    @else
        <div class="empty">暂时没有可用地址</div>
    @endif

    @if ($updated_at)
        <footer>地址更新于 {{ date('Y-m-d H:i', $updated_at) }}</footer>
    @endif
</main>
</body>
</html>
