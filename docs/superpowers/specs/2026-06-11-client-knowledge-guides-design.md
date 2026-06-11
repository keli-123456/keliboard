# Client Knowledge Guides Design

## Goal

Build a versioned knowledge-base tutorial pack for client setup guides. The pack will live in the repository, use HTML article bodies that match the current `keli-user` renderer, and later be importable into the `v2_knowledge` table for one or many sites.

## Current Context

`keli-admin` already supports knowledge CRUD, category management, show/hide, sorting, and rich text editing. `keliboard` exposes user knowledge APIs and replaces subscription placeholders in article bodies. `keli-user` renders knowledge articles through `RichTextContent`, which supports sanitized HTML, images, buttons, collapsible sections, safe custom client protocols, and variables such as `{{subscribeUrl}}` and `{{subscribeUrlEncoded}}`.

Because `keli-user` does not currently parse raw Markdown image syntax as images, the pack will store article bodies as HTML instead of raw Markdown.

## Scope

The first version covers these client tutorials:

- Karing
- Clash Verge Rev
- FlClash
- Hiddify
- v2rayN
- v2rayNG
- Shadowrocket
- Stash

Each guide will include applicable platforms, manual subscription import, subscription refresh, and common troubleshooting.

## File Layout

The tutorial pack will be stored under:

```text
database/knowledge-packs/client-guides/
  manifest.json
  articles/
    karing.html
    clash-verge-rev.html
    flclash.html
    hiddify.html
    v2rayn.html
    v2rayng.html
    shadowrocket.html
    stash.html
  assets/
    clients/
```

`manifest.json` is the source of truth for category, language, title, sort order, visibility, article file path, and referenced assets. The first version intentionally has no image assets.

## Article Format

Articles will be authored as sanitized HTML compatible with `keli-user`.

Allowed patterns:

- Semantic text tags: `h2`, `h3`, `p`, `ol`, `ul`, `li`, `blockquote`, `code`, `pre`, `table`.
- Collapsible FAQ: `details` and `summary`.

Disallowed patterns:

- Inline `style` attributes.
- `script`, `iframe`, external embed code, or unsafe event handlers.
- HTML images and Markdown-only image syntax such as `![alt](url)` in final article HTML.

## Variables

The article content may use:

- `{{siteName}}`
- `{{subscribeUrl}}`

The user backend already replaces these values before rendering. Tutorials should always show a manual copy fallback.

## Images

Images are intentionally omitted from the first guide pack. Client interfaces change often, and screenshot maintenance would make the knowledge base harder to keep accurate across deployments.

If images are added later, only local assets from official public screenshots or real captured client usage should be used. Hotlinked remote images should not be used.

## Import Path

The first implementation should create the tutorial pack files only. A later implementation can add an import command or artisan command that:

1. Reads `manifest.json`.
2. Loads each HTML article body.
3. Creates or updates `v2_knowledge` rows by stable slug/title/category/language.
4. Preserves sort order and visibility from the manifest.
5. Copies assets to the public knowledge-assets directory when needed.

The import behavior must be idempotent so the pack can be re-applied after article updates.

## Error Handling

The future importer should fail clearly when:

- A manifest article file is missing.
- An asset referenced in the manifest is missing.
- A required field such as title, category, language, or body path is empty.
- A database write fails.

The pack itself should avoid relying on unsupported frontend HTML so content does not silently disappear after sanitization.

## Testing

The first content-only implementation should verify:

- `manifest.json` is valid JSON.
- Every manifest article path exists.
- Every article contains no HTML or Markdown image syntax.
- Every article includes `{{subscribeUrl}}`.
- Every article avoids `script`, `iframe`, inline `style`, and unsafe event attributes.

Future importer tests should verify idempotent create/update behavior and correct handling of missing files.

## Non-Goals

This work does not add the admin image upload feature yet. It also does not redesign the `keli-user` help center layout. Those can be follow-up improvements after the initial guide pack exists.
