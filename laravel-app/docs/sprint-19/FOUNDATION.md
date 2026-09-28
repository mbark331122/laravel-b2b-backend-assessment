# Sprint 19 — Organization & Approval Workflows

Company remains the tenant. Existing RBAC unchanged. Sprint 17–18 preserved.

## Architecture

```
Company
  └── ApprovalPolicy (type, amount/currency rules, priority, active)
        └── ApprovalPolicyStep (ordered approver_role)
  └── ApprovalRequest (snapshot of policy steps + target context)
        └── ApprovalDecision (per step)
```

## Lifecycle

`pending` → `approved` | `rejected` | `cancelled` (terminal; no reopen)

- Server advances `current_step_order`; clients cannot skip steps
- Self-approval by requester is prohibited
- Approver must match the **snapshot** role for the current step (or platform admin)
- Concurrent decisions use `lockForUpdate`

## Policy matching (server-derived)

Active policies for company + `approval_type`, highest `priority` first:

- `min_amount` / `currency` compared to **server** target context
- Client `amount` / `company_id` / ownership fields ignored

## Integrations (gates only)

| Action | Type | Behavior |
| --- | --- | --- |
| `POST /api/rfqs/{rfq}/submit` | `rfq.submit` | If matched → create/return pending request (422); if approved → submit proceeds |
| `POST /api/purchase-orders/{po}/submit` | `purchase_order.submit` | Same; amount from PO `total` |

No payment approval. No second PO lifecycle. Existing confirm/reject unchanged.

## API

| Resource | Routes |
| --- | --- |
| Policies | `GET/POST /api/approval-policies`, show/update, activate/deactivate |
| Requests | `GET /api/approval-requests`, show, approve/reject/cancel |

## Permissions

`approval.policy.read|manage`, `approval.request.read|decide|cancel`  
Granted to company_user, supplier_user, intermediary_user.

## Audit

`approval.policy.created|updated|activated|deactivated`,  
`approval.request.created|approved|rejected|cancelled`,  
`approval.step.decided`

## Migration

`2026_09_24_190000_add_sprint_19_approval_workflows`

## Explicit exclusions

Departments, budgets, payment approval, notifications, Sprint 20+.
