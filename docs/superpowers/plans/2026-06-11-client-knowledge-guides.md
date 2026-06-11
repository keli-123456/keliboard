# Client Knowledge Guides Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a repository-managed HTML knowledge pack for client setup tutorials that can be safely rendered by `keli-user`.

**Architecture:** The pack is content-first: a manifest defines article metadata, HTML files hold sanitized guide bodies, asset folders are reserved for future local assets, and a local PHP validator checks the pack before commit. The first deliverable does not write to the database; it creates import-ready source files for a separate import command.

**Tech Stack:** Laravel repository, JSON manifest, HTML article bodies, PHP validation script, Git.

---

## File Structure

- Create: `database/knowledge-packs/client-guides/manifest.json`
  - Owns pack metadata, article order, titles, language, body paths, and asset paths.
- Create: `database/knowledge-packs/client-guides/articles/*.html`
  - One sanitized HTML article per client.
- Create: `database/knowledge-packs/client-guides/assets/clients/.gitkeep`
  - Placeholder directory for future local assets. The current pack intentionally contains no images.
- Create: `database/knowledge-packs/client-guides/assets/sources.md`
  - Records the no-image policy and future local-asset rules.
- Create: `database/knowledge-packs/client-guides/validate.php`
  - Validates manifest shape, article files, asset references, required subscription variables, and unsafe HTML.

## Article Inventory

Use category `客户端教程`, language `zh-CN`, and these sort values:

| Sort | Slug | Title | File |
| ---: | --- | --- | --- |
| 10 | `karing` | `Karing 使用教程` | `articles/karing.html` |
| 20 | `clash-verge-rev` | `Clash Verge Rev 使用教程` | `articles/clash-verge-rev.html` |
| 30 | `flclash` | `FlClash 使用教程` | `articles/flclash.html` |
| 40 | `hiddify` | `Hiddify 使用教程` | `articles/hiddify.html` |
| 50 | `v2rayn` | `v2rayN 使用教程` | `articles/v2rayn.html` |
| 60 | `v2rayng` | `v2rayNG 使用教程` | `articles/v2rayng.html` |
| 70 | `shadowrocket` | `Shadowrocket 使用教程` | `articles/shadowrocket.html` |
| 80 | `stash` | `Stash 使用教程` | `articles/stash.html` |

Every article must contain:

- A short platform paragraph.
- A subscription import block.
- A manual import section.
- A refresh subscription section.
- A troubleshooting `details` block.
- A manual fallback using `<code>{{subscribeUrl}}</code>`.

Use `{{subscribeUrl}}` for visible text. The user API replaces it with the current user's real subscription link.

---

### Task 1: Create Pack Skeleton

**Files:**
- Create: `database/knowledge-packs/client-guides/manifest.json`
- Create: `database/knowledge-packs/client-guides/articles/.gitkeep`
- Create: `database/knowledge-packs/client-guides/assets/clients/.gitkeep`
- Create: `database/knowledge-packs/client-guides/assets/sources.md`

- [ ] **Step 1: Create directories**

Run:

```powershell
New-Item -ItemType Directory -Force -Path database\knowledge-packs\client-guides\articles | Out-Null
New-Item -ItemType Directory -Force -Path database\knowledge-packs\client-guides\assets\clients | Out-Null
```

Expected: both directories exist.

- [ ] **Step 2: Add manifest**

Create `database/knowledge-packs/client-guides/manifest.json` with:

```json
{
  "name": "client-guides",
  "version": "2026.06.11",
  "description": "HTML client setup guides for the Keli user knowledge base.",
  "category": "客户端教程",
  "language": "zh-CN",
  "asset_base_url": "/knowledge-assets/clients",
  "articles": [
    {
      "slug": "karing",
      "title": "Karing 使用教程",
      "category": "客户端教程",
      "language": "zh-CN",
      "sort": 10,
      "show": true,
      "body": "articles/karing.html",
      "assets": []
    },
    {
      "slug": "clash-verge-rev",
      "title": "Clash Verge Rev 使用教程",
      "category": "客户端教程",
      "language": "zh-CN",
      "sort": 20,
      "show": true,
      "body": "articles/clash-verge-rev.html",
      "assets": []
    },
    {
      "slug": "flclash",
      "title": "FlClash 使用教程",
      "category": "客户端教程",
      "language": "zh-CN",
      "sort": 30,
      "show": true,
      "body": "articles/flclash.html",
      "assets": []
    },
    {
      "slug": "hiddify",
      "title": "Hiddify 使用教程",
      "category": "客户端教程",
      "language": "zh-CN",
      "sort": 40,
      "show": true,
      "body": "articles/hiddify.html",
      "assets": []
    },
    {
      "slug": "v2rayn",
      "title": "v2rayN 使用教程",
      "category": "客户端教程",
      "language": "zh-CN",
      "sort": 50,
      "show": true,
      "body": "articles/v2rayn.html",
      "assets": []
    },
    {
      "slug": "v2rayng",
      "title": "v2rayNG 使用教程",
      "category": "客户端教程",
      "language": "zh-CN",
      "sort": 60,
      "show": true,
      "body": "articles/v2rayng.html",
      "assets": []
    },
    {
      "slug": "shadowrocket",
      "title": "Shadowrocket 使用教程",
      "category": "客户端教程",
      "language": "zh-CN",
      "sort": 70,
      "show": true,
      "body": "articles/shadowrocket.html",
      "assets": []
    },
    {
      "slug": "stash",
      "title": "Stash 使用教程",
      "category": "客户端教程",
      "language": "zh-CN",
      "sort": 80,
      "show": true,
      "body": "articles/stash.html",
      "assets": []
    }
  ]
}
```

- [ ] **Step 3: Add source log**

Create `database/knowledge-packs/client-guides/assets/sources.md` with:

```markdown
# Client Guide Asset Policy

This pack intentionally ships without images. Client UI changes often, and pure HTML text keeps the knowledge base easier to maintain across Keli deployments.

## Rules

- Do not add screenshots or hotlinked remote images to the articles.
- Keep the tutorials focused on stable flows: copy subscription, import URL, update subscription, select node, connect, and troubleshoot.
- If images are added in the future, only use local assets verified from official public screenshots or real captured client usage.
```

- [ ] **Step 4: Commit skeleton**

Run:

```powershell
git add database\knowledge-packs\client-guides
git commit -m "Add client knowledge guide pack skeleton"
```

Expected: commit succeeds with only new pack skeleton files.

---

### Task 2: Keep Pack Image-Free

**Files:**
- Modify: `database/knowledge-packs/client-guides/assets/sources.md`

- [ ] **Step 1: Record no-image policy**

Record that this pack intentionally ships without images.

Expected: `assets/sources.md` says not to add screenshots or hotlinked remote images to articles.

- [ ] **Step 2: Commit policy update**

Run:

```powershell
git add database\knowledge-packs\client-guides\assets\sources.md docs\superpowers\specs\2026-06-11-client-knowledge-guides-design.md docs\superpowers\plans\2026-06-11-client-knowledge-guides.md
git commit -m "Document image-free client guide policy"
```

Expected: policy files describe the no-image direction.

---

### Task 3: Write HTML Articles

**Files:**
- Create: `database/knowledge-packs/client-guides/articles/karing.html`
- Create: `database/knowledge-packs/client-guides/articles/clash-verge-rev.html`
- Create: `database/knowledge-packs/client-guides/articles/flclash.html`
- Create: `database/knowledge-packs/client-guides/articles/hiddify.html`
- Create: `database/knowledge-packs/client-guides/articles/v2rayn.html`
- Create: `database/knowledge-packs/client-guides/articles/v2rayng.html`
- Create: `database/knowledge-packs/client-guides/articles/shadowrocket.html`
- Create: `database/knowledge-packs/client-guides/articles/stash.html`

- [ ] **Step 1: Use these fixed article rules**

Write each HTML file with the client title and file path from the Article Inventory table. Use these platform descriptions:

| Slug | Platform text |
| --- | --- |
| `karing` | `Windows、macOS、iOS、Android` |
| `clash-verge-rev` | `Windows、macOS、Linux` |
| `flclash` | `Windows、macOS、Linux、Android` |
| `hiddify` | `Windows、macOS、Linux、iOS、Android` |
| `v2rayn` | `Windows` |
| `v2rayng` | `Android` |
| `shadowrocket` | `iOS` |
| `stash` | `iOS、macOS` |

Every article must use the same section order:

1. `<h2>` title.
2. Platform paragraph.
3. Subscription import block with `<code>{{subscribeUrl}}</code>`.
4. Manual import section.
5. Refresh subscription section.
6. Troubleshooting `details` block.

Do not add one-click import buttons in the first version. Manual import is more stable across client versions.

For `articles/karing.html`, the structure should look like this:

```html
<h2>Karing 使用教程</h2>

<p>适用平台：Windows、macOS、iOS、Android。建议优先使用订阅链接导入，导入后再更新一次订阅。</p>

<blockquote>
  <p>如果导入失败，请复制订阅链接后使用手动导入。</p>
</blockquote>

<h3 id="manual-import-karing">手动导入订阅</h3>
<ol>
  <li>复制你的订阅链接：<code>{{subscribeUrl}}</code></li>
  <li>打开 Karing，进入配置或订阅页面。</li>
  <li>选择从 URL、链接或远程配置导入。</li>
  <li>粘贴订阅链接并保存。</li>
  <li>返回首页，选择可用节点后开启连接。</li>
</ol>

<h3>更新订阅</h3>
<p>节点变化、套餐续费或流量重置后，请在客户端里手动更新订阅。更新后如果仍看不到节点，退出客户端后重新打开。</p>

<details>
  <summary>常见问题</summary>
  <p><strong>导入后没有节点：</strong>确认套餐未过期、订阅链接没有被空格截断，并在客户端里执行一次更新订阅。</p>
  <p><strong>能导入但不能连接：</strong>切换另一个节点测试；如果全部失败，请复制错误提示并提交工单。</p>
  <p><strong>导入入口名称不同：</strong>不同客户端版本的按钮名称可能不同，优先寻找 URL、订阅、远程配置或 Profile Link。</p>
</details>
```

- [ ] **Step 2: Confirm no image blocks exist**

Expected: articles contain no `<img>` tags and no Markdown image syntax.

- [ ] **Step 3: Commit articles**

Run:

```powershell
git add database\knowledge-packs\client-guides\articles database\knowledge-packs\client-guides\manifest.json
git commit -m "Add HTML client knowledge articles"
```

Expected: commit contains the eight HTML article files and any manifest article asset updates.

---

### Task 4: Add Pack Validator

**Files:**
- Create: `database/knowledge-packs/client-guides/validate.php`

- [ ] **Step 1: Create validator script**

Create `database/knowledge-packs/client-guides/validate.php` with:

```php
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
```

- [ ] **Step 2: Run validator**

Run:

```powershell
php database\knowledge-packs\client-guides\validate.php
```

Expected:

```text
[client-guides] OK: 8 image-free articles validated
```

- [ ] **Step 3: Commit validator**

Run:

```powershell
git add database\knowledge-packs\client-guides\validate.php
git commit -m "Add client knowledge guide validator"
```

Expected: validator is committed after passing locally.

---

### Task 5: Final Verification

**Files:**
- Read: `database/knowledge-packs/client-guides/manifest.json`
- Read: `database/knowledge-packs/client-guides/articles/*.html`
- Read: `database/knowledge-packs/client-guides/assets/sources.md`

- [ ] **Step 1: Validate pack**

Run:

```powershell
php database\knowledge-packs\client-guides\validate.php
```

Expected:

```text
[client-guides] OK: 8 articles validated
```

- [ ] **Step 2: Check Git whitespace**

Run:

```powershell
git diff --check
```

Expected: no output.

- [ ] **Step 3: Inspect final status**

Run:

```powershell
git status --short
```

Expected: no output.

- [ ] **Step 4: Push if requested**

Run:

```powershell
git push origin main
```

Expected: remote `main` receives all tutorial pack commits.

## Self-Review

- Spec coverage: The plan creates a versioned image-free HTML knowledge pack, the exact article inventory, asset policy, and validation. It keeps database import and admin image upload outside this first deliverable.
- No unresolved implementation tokens: The plan uses fixed file paths, exact manifest shape, exact validation code, and exact commands.
- Type consistency: Manifest fields used by the validator match the manifest JSON in Task 1.
