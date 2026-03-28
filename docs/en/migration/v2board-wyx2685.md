# wyx2685/v2board Migration Guide

This guide explains how to migrate from [`wyx2685/v2board`](https://github.com/wyx2685/v2board) to Xboard.

## 1. What This Migration Supports

`php artisan migrateFromV2b wyx2685` now migrates the database through a dedicated compatibility path.

It preserves and converts these node sources into the unified `v2_server` table:

- `v2_server_trojan`
- `v2_server_vmess`
- `v2_server_vless`
- `v2_server_shadowsocks`
- `v2_server_hysteria`
- `v2_server_tuic`
- `v2_server_anytls`
- `v2_server_v2node`

`v2_server_v2node` records are automatically mapped by their `protocol` field into:

- `trojan`
- `vmess`
- `vless`
- `shadowsocks`
- `hysteria`
- `tuic`
- `anytls`

## 2. Important Notes

- Complete the basic Xboard installation first. SQLite is not supported for this migration.
- Always import the old `wyx2685/v2board` database into a fresh Xboard database first.
- Do not run this command against the live production database of the old panel.
- If the old database contains overlapping node IDs across split tables and `v2_server_v2node`, Xboard will automatically generate a safe fallback `code` to avoid collisions.

Recommended deployment guides:

- [Docker Compose Deployment](../installation/docker-compose.md)
- [aaPanel + Docker Deployment](../installation/aapanel-docker.md)
- [aaPanel Deployment](../installation/aapanel.md)

## 3. Migration Steps

### Docker Compose Environment

```bash
# 1. Stop services
docker compose down

# 2. Clear the new Xboard database
docker compose run -it --rm web php artisan db:wipe

# 3. Import the old wyx2685/v2board database
# Import it manually into the new Xboard database

# 4. Execute database migration
docker compose run -it --rm web php artisan migrateFromV2b wyx2685
```

### aaPanel Environment

```bash
# 1. Clear the new Xboard database
php artisan db:wipe

# 2. Import the old wyx2685/v2board database
# Import it manually into the new Xboard database

# 3. Execute database migration
php artisan migrateFromV2b wyx2685
```

### aaPanel + Docker Environment

```bash
# 1. Clear the new Xboard database
docker compose run -it --rm web php artisan db:wipe

# 2. Import the old wyx2685/v2board database
# Import it manually into the new Xboard database

# 3. Execute database migration
docker compose run -it --rm web php artisan migrateFromV2b wyx2685
```

## 4. Optional Configuration Migration

If you also need to migrate the old `config/v2board.php` values into Xboard settings, follow:

- [Configuration Migration Guide](./config.md)

Command:

```bash
php artisan migrateFromV2b config
```

Docker:

```bash
docker compose run -it --rm web php artisan migrateFromV2b config
```

## 5. Post-Migration Validation Checklist

After the migration finishes, validate these items before switching traffic:

### A. Node Inventory

Check unified node counts:

```sql
SELECT type, COUNT(*) AS total
FROM v2_server
GROUP BY type
ORDER BY type;
```

Check whether duplicate `(type, code)` collisions still exist:

```sql
SELECT type, code, COUNT(*) AS total
FROM v2_server
GROUP BY type, code
HAVING COUNT(*) > 1;
```

Expected result:

- The first query returns your migrated node totals.
- The second query should return no rows.

### B. Old Split Tables

The old node split tables should be gone after migration:

```sql
SHOW TABLES LIKE 'v2_server\\_%';
```

Expected result:

- `v2_server`
- Other non-node tables such as `v2_server_group`, `v2_server_route`
- No leftover split node tables like `v2_server_trojan`, `v2_server_v2node`, `v2_server_tuic`

### C. Parent Node References

Check whether child nodes still have valid parent references:

```sql
SELECT COUNT(*) AS invalid_parent_count
FROM v2_server child
LEFT JOIN v2_server parent ON parent.id = child.parent_id
WHERE child.parent_id IS NOT NULL
  AND parent.id IS NULL;
```

Expected result:

- `invalid_parent_count = 0`

### D. Admin Panel Verification

Verify these in the admin panel:

- Server list opens normally
- `AnyTLS`, `TUIC`, and migrated `v2_server_v2node` records are visible
- Node detail editing opens without JSON/config errors
- One-click install commands are generated normally

### E. Runtime Verification

Pick one node of each important protocol and verify:

- Config can be pulled by the node
- Users can sync normally
- Realtime state is visible if you use the new websocket sync path

## 6. Recommended Smoke Tests

Run at least one real test for each protocol you use:

- `VLESS + Reality`
- `Hysteria2`
- `TUIC`
- `AnyTLS`
- `Trojan`
- `Shadowsocks`

If your old deployment used `v2_server_v2node`, test at least one node migrated from that table.

## 7. Troubleshooting

If migration fails:

- Check `storage/logs/laravel.log`
- Confirm the old database was imported completely before running `migrateFromV2b wyx2685`
- Confirm the new Xboard database was empty before import

If the admin panel shows no nodes after migration:

- Re-login to the admin panel
- Clear browser cache and cookies
- Run the validation SQL above to confirm that `v2_server` contains migrated records
