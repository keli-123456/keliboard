# Server Machine Bulk Bind Design

## Goal

Add batch node binding to the server machine page so admins can select nodes visually instead of typing node IDs.

## Scope

The feature covers existing machines. It does not create machines in bulk yet.

Admins can open a bulk bind dialog, choose one or more machines, choose nodes for each machine, review conflicts, and submit the bindings.

## Backend

Add a `POST /api/v2/admin/server/machine/batchBindNodes` endpoint in the existing machine controller.

Request shape:

```json
{
  "mode": "replace",
  "allow_transfer": false,
  "items": [
    { "machine_id": 1, "node_ids": [101, 102] }
  ]
}
```

Rules:

- `mode=replace` clears nodes currently bound to each selected machine, then applies the selected node list.
- `mode=append` keeps current machine bindings and adds selected nodes.
- If `allow_transfer=false`, nodes already bound to another machine are skipped.
- If `allow_transfer=true`, selected nodes can move from another machine to the target machine.
- A node can appear in only one submitted machine item.
- The operation runs in a transaction and publishes config invalidation for affected machines.

Response shape:

```json
{
  "mode": "replace",
  "allow_transfer": false,
  "summary": {
    "machines": 1,
    "bound": 2,
    "unbound": 1,
    "transferred": 0,
    "skipped": 1
  },
  "items": [
    {
      "machine_id": 1,
      "requested_node_ids": [101, 102],
      "bound_node_ids": [101],
      "skipped_node_ids": [102],
      "unbound_node_ids": [103],
      "transferred_node_ids": []
    }
  ]
}
```

## Frontend

Add a bulk bind button to `MachineManage.tsx`.

The dialog has:

- Machine picker with checkboxes and search.
- One row per selected machine.
- Per-machine node selector table with search and status filters.
- Mode control: replace or append.
- Transfer control: skip conflicts by default, allow transfer when enabled.
- Preview summary before save.

Node statuses:

- Unbound.
- Bound to current machine.
- Bound to another machine.
- Disabled.

## Testing

Backend PHPUnit tests cover replace, append, conflict skip, transfer, duplicate submission validation, and invalidation target calculation.

Frontend Vitest tests cover pure helpers for bulk bind payload building, conflict summaries, mode behavior, and node filtering. The React dialog uses those helpers so the most error-prone logic stays testable without brittle UI tests.
