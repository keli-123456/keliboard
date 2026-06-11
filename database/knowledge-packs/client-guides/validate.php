<?php

$baseDir = __DIR__;
$manifestPath = $baseDir . DIRECTORY_SEPARATOR . 'manifest.json';

function fail(string $message): void
{
    fwrite(STDERR, "[client-guides] {$message}\n");
    exit(1);
}

if (!is_file($manifestPath)) {
    fail('manifest.json is missing');
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    fail('manifest.json is not valid JSON');
}

foreach (['name', 'version', 'category', 'language', 'asset_base_url', 'articles'] as $key) {
    if (!array_key_exists($key, $manifest)) {
        fail("manifest field {$key} is missing");
    }
}

if (!is_array($manifest['articles']) || count($manifest['articles']) === 0) {
    fail('manifest articles must be a non-empty array');
}

$seenSlugs = [];
$unsafePattern = '/<\s*(script|iframe)\b|on[a-z]+\s*=|style\s*=/i';
$htmlImagePattern = '/<\s*img\b/i';
$markdownImagePattern = '/!\[[^\]]*\]\([^)]+\)/';

foreach ($manifest['articles'] as $index => $article) {
    if (!is_array($article)) {
        fail("article at index {$index} must be an object");
    }

    foreach (['slug', 'title', 'category', 'language', 'sort', 'show', 'body', 'assets'] as $key) {
        if (!array_key_exists($key, $article)) {
            fail("article {$index} field {$key} is missing");
        }
    }

    $slug = trim((string) $article['slug']);
    if ($slug === '') {
        fail("article {$index} slug is empty");
    }

    if (isset($seenSlugs[$slug])) {
        fail("duplicate article slug {$slug}");
    }
    $seenSlugs[$slug] = true;

    $bodyPath = $baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $article['body']);
    if (!is_file($bodyPath)) {
        fail("article {$slug} body file is missing: {$article['body']}");
    }

    $body = (string) file_get_contents($bodyPath);
    if (trim($body) === '') {
        fail("article {$slug} body is empty");
    }

    if (!str_contains($body, '{{subscribeUrl}}')) {
        fail("article {$slug} must include {{subscribeUrl}}");
    }

    if (preg_match($unsafePattern, $body)) {
        fail("article {$slug} contains unsafe HTML");
    }

    if (preg_match($htmlImagePattern, $body) || preg_match($markdownImagePattern, $body)) {
        fail("article {$slug} contains image syntax; this pack is intentionally image-free");
    }

    if (!is_array($article['assets'])) {
        fail("article {$slug} assets must be an array");
    }

    if (count($article['assets']) > 0) {
        fail("article {$slug} references assets; this pack is intentionally image-free");
    }
}

fwrite(STDOUT, "[client-guides] OK: " . count($manifest['articles']) . " image-free articles validated\n");
