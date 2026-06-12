# Machine Release Management Design

## Goal

Let administrators host and manage `kelinode-rs` and `keli-core-rs` Linux release binaries from the panel, so private GitHub repositories do not block node installation or upgrades.

## Scope

The first version adds panel-hosted release management for `linux-x86_64`:

- list uploaded releases
- upload a release archive plus manifest
- mark one release as default/latest per component and platform
- delete inactive releases
- keep existing machine install and upgrade commands using `/server/machine/releases`

The first version does not add CI upload, arm64, staged rollout, or automatic scheduled upgrades.

## Architecture

The existing server-machine release download protocol remains unchanged. Node machines continue to fetch:

- `/server/machine/releases/{component}/{platform}/latest`
- `/server/machine/releases/{component}/{version}/{platform}/manifest.json`
- `/server/machine/releases/{component}/{version}/{platform}/archive.tar.gz`

Admin-side management is added on top of the current local storage layout:

```text
storage/app/kelinode-rs/releases/{component}/{version}/{platform}/
```

A database table records uploaded releases and default status. `MachineReleaseDistributionService` uses the database default first and falls back to scanning storage for compatibility with existing manually placed files.

## Data Model

`v2_server_machine_release` stores:

- `component`: `kelinode-rs` or `keli-core-rs`
- `version`: normalized `v...` release version
- `platform`: `linux-x86_64`
- `manifest_path` and `archive_path`: local storage paths
- `sha256` and `size`: archive metadata
- `is_default`: current preferred version for the component/platform
- `status`: `active` or `disabled`

Only one active default can exist per component/platform.

## UI

`keli-admin` adds a release management panel inside Server Machine Management:

- upload archive and manifest
- show component, version, platform, size, sha256, default state
- set default
- delete non-default releases

This keeps version management next to machines and upgrade actions.

## Security

Machine downloads remain protected by machine ID plus machine token. Admin upload endpoints require existing admin middleware. Upload validation uses component/platform/version allowlists and stores files only under the release storage directory.

