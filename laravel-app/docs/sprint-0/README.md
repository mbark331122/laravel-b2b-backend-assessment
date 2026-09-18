# Sprint 0 — Architecture & Foundation

**Status:** Complete (analysis only — no application code in this sprint)

**Scope:** Inspect existing repository, establish architecture boundaries, conventions, and the sprint dependency plan for the fixed B2B product roadmap.

## Deliverables

| Document | Contents |
| --- | --- |
| [ARCHITECTURE.md](./ARCHITECTURE.md) | System architecture, reuse plan, conventions, API & testing strategy |
| [DOMAIN-MAP.md](./DOMAIN-MAP.md) | Domain boundaries and lifecycle map |
| [DATABASE-PLAN.md](./DATABASE-PLAN.md) | Database / domain relationship plan |
| [SECURITY-MODEL.md](./SECURITY-MODEL.md) | Multi-tenant security model |
| [PERMISSION-MODEL.md](./PERMISSION-MODEL.md) | Roles, permissions, extensibility |
| [APPROVAL-MODEL.md](./APPROVAL-MODEL.md) | Approval workflows and official-write rules |
| [AUDIT-MODEL.md](./AUDIT-MODEL.md) | Auditability conventions |
| [SPRINT-DEPENDENCY-MAP.md](./SPRINT-DEPENDENCY-MAP.md) | Complete sprint dependency map (Sprints 0–17) |

## Product (fixed)

B2B procurement and supplier commerce platform.

Lifecycle:

```
Buyer / Company → RFQ → Supplier Matching → Quotations → Negotiation
→ Approval → Purchase Order → Payment → Shipment → Delivery
```

Plus: messaging, disputes, supplier banking (change-request approval), AI extraction (proposal-only), reports, administration.

## Existing baseline

Application root: `laravel-app/`

Already present and reusable:

- Laravel 13 API + Sanctum auth
- Companies, users, roles, permissions
- Tenant isolation patterns (`visibleTo`, policies, `denyAsNotFound`)
- RFQ CRUD
- Mock AI extraction + `RfqProposal` approval safety
- Supplier bank accounts + change-request approval + history
- `AuditLogger` + `audit_logs`

**Not present yet** (planned by later sprints): buyer/supplier company classification, product catalog, matching, commercial quotations, negotiation, purchase orders, payments, shipment/delivery, messaging, disputes, reports, admin APIs.

## Rule

Do not start Sprint 1 until explicitly instructed.
