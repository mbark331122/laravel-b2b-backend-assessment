# Audit Model

## 1. Definition

An audit record is a dedicated `audit_logs` row capturing who changed what, for which tenant, with before/after payloads.

**`updated_at` alone is not an audit log.**

## 2. Existing mechanism (reuse)

| Piece | Location |
| --- | --- |
| Writer | `App\Services\AuditLogger::record(...)` |
| Model | `App\Models\AuditLog` |
| Table | `audit_logs` |

### Writer contract

```php
AuditLogger::record(
    string $action,
    Model $auditable,
    ?int $companyId,
    ?array $before,
    ?array $after,
    ?string $reason = null,
);
```

| Field | Source rule |
| --- | --- |
| `actor_id` | `auth()->user()` — never client `actor_id` |
| `company_id` | Authorized resource tenant — never request `company_id` |
| `action` | Stable string constant |
| `auditable_type/id` | Morph to the affected model |
| `before_value` / `after_value` | JSON arrays of values actually read/written |
| `reason` | Optional; used on approve/reject when provided |
| timestamps | Created at write time |

## 3. Existing action catalog

| Action | When |
| --- | --- |
| `rfq.created` | RFQ created |
| `rfq.updated` | RFQ updated |
| `rfq.approved` | Official RFQ field changed via proposal approval |
| `proposal.created` | Conflicting AI proposal stored |
| `proposal.approved` | Proposal approved |
| `proposal.rejected` | Proposal rejected |
| `bank.change_requested` | Bank change request created |
| `bank.change_approved` | Bank change approved |
| `bank.change_rejected` | Bank change rejected |
| `bank.account.changed` | Official IBAN updated |

Reads are not audited.

## 4. Extension rules

1. Add new action constants on `AuditLog` (or a dedicated catalog) in the sprint that introduces the mutation.
2. Naming: `{domain}.{verb}` / `{domain}.{object}.{verb}` lowercase dotted.
3. Call `AuditLogger` inside the same DB transaction as the official mutation whenever approval applies an official change.
4. Before/after must reflect backend-applied values (stored proposals), not spoofed request bodies.
5. Do not invent a second audit system.

## 5. What must be audited (platform-wide)

Important business changes, including at minimum:

- Creates/updates of core commercial records where material
- Approval and rejection decisions
- Official value applications after approval
- Bank account official changes
- Permission/role administration changes (Sprint 15)
- Dispute resolution transitions (Sprint 13)
- Payment/shipment/delivery status transitions that complete lifecycle steps (Sprints 10–11)

Exact event lists are finalized per sprint; Sprint 16 verifies coverage.

## 6. What is out of audit scope (default)

- Successful reads / list fetches
- Failed auth attempts (unless a later sprint explicitly adds security event logging)
- Health checks

## 7. Query / administration

- No audit query API exists today.
- Sprint 14/15 may expose tenant-authorized or admin audit read endpoints.
- Until then, audits remain write-only integrity records.

## 8. Testing

Security/feature tests must assert for important flows:

- actor matches authenticated user
- company matches resource tenant
- action name
- before/after payloads
- presence of audit row in the same logical operation as the mutation
