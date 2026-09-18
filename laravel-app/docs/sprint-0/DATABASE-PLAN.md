# Database / Domain Relationship Plan

## 1. Principles

1. One Laravel database schema; no separate DBs per domain.
2. Tenant ownership is explicit (`company_id` or via parent aggregate).
3. Official financial/commercial values that require approval are not updated by mass assignment from clients.
4. History/audit tables preserve prior official values.
5. New tables are introduced only in the sprint that owns the domain.

## 2. Existing schema (baseline — keep)

```
companies
users ──────── company_id?, role_id
roles ◄──────► permissions (permission_role)
rfqs ───────── company_id
ai_extractions ─ rfq_id
rfq_proposals ── rfq_id, ai_extraction_id
suppliers ────── company_id          # current: tenant-owned supplier record
supplier_bank_accounts ─ supplier_id # iban not fillable
bank_change_requests ── supplier_bank_account_id, company_id, requested_by_user_id
bank_account_histories ─ supplier_bank_account_id
audit_logs ── actor_id?, company_id?, auditable morph
personal_access_tokens (Sanctum)
```

### Key existing constraints

| Table | Notes |
| --- | --- |
| `rfqs` | Official demand fields + `status`; `company_id` not fillable on model |
| `rfq_proposals` | Field-level AI conflicts; statuses pending/approved/rejected |
| `supplier_bank_accounts` | Official IBAN; change only via approved request |
| `bank_account_histories` | Snapshot before official IBAN overwrite |
| `audit_logs` | before/after JSON; not replaceable by `updated_at` |

## 3. Planned relationships by domain

Diagram uses logical names. Exact table names follow Laravel conventions when implemented.

### 3.1 Identity (Sprint 1 — extend)

```
Company
  - type flags / classification: buyer, supplier (or both)
  - 1──* User
  - 1──* Role assignments via users

Role *──* Permission
```

**Reuse:** existing `companies`, `users`, `roles`, `permissions`.  
**Extend:** company classification columns (or equivalent) without breaking current FKs.

### 3.2 Supplier & catalog (Sprint 2)

```
Company (supplier)
  └── SupplierProfile (verification_status, …)
        └── Product
              ├── specifications
              ├── wholesale pricing
              └── MOQ
```

**Compatibility rule:** existing `suppliers` + bank tables must remain coherent. Preferred approach when Sprint 2 runs:

- Evolve `suppliers` into the supplier profile aggregate linked to a supplier `company_id` representing the supplier tenant, **or**
- Introduce profile tables and migrate FK from bank accounts carefully in that sprint only.

Do not drop bank history. Do not allow direct IBAN updates.

### 3.3 RFQ (Sprint 3)

```
Company (buyer) 1──* Rfq
Rfq 1──* RfqItem?   # only if Sprint 3 model requires line items
```

Required RFQ fields (fixed): commodity, specification, quantity, unit, incoterm, destination, status, company/tenant.

### 3.4 AI (Sprint 4 — already modeled)

```
Rfq 1──* AiExtraction 1──* RfqProposal
Rfq 1──* RfqProposal
```

Official RFQ row is updated only by `RfqProposal::approve()` applying stored `proposed_value`.

### 3.5 Matching (Sprint 5)

```
Rfq ──* RfqSupplierDistribution / Match
         ├── supplier_company / supplier_profile
         ├── eligibility markers
         └── visibility for supplier users
```

Matching rows authorize supplier RFQ visibility; they are not quotations.

### 3.6 Quotations (Sprint 6)

```
Rfq 1──* Quotation
Quotation ── supplier company/profile
Quotation 1──* QuotationItem
```

Separate from `rfq_proposals`.

### 3.7 Negotiation (Sprint 7)

```
Quotation (or negotiation thread) 1──* NegotiationOffer
  - actor (buyer/supplier user)
  - proposed commercial terms
  - sequence / history (append-only)
```

### 3.8 Purchase orders (Sprint 8)

```
PurchaseOrder
  ├── buyer company
  ├── supplier company
  ├── source quotation / approved negotiation reference
  └── PO items
```

### 3.9 Banking (Sprint 9 — existing pattern)

```
SupplierProfile/Supplier 1──1 SupplierBankAccount
SupplierBankAccount 1──* BankChangeRequest
SupplierBankAccount 1──* BankAccountHistory
```

Workflow: Change Request → Verification/Approval → Official Update + history row.

### 3.10 Payments (Sprint 10)

```
PurchaseOrder 1──* Payment
Payment: amount/currency fields as required, status lifecycle, no external provider required
```

### 3.11 Shipment & delivery (Sprint 11)

```
PurchaseOrder 1──* Shipment
Shipment 1──* DeliveryEvent / Delivery status
```

Core chain: PO → Payment → Shipment → Delivery.

### 3.12 Messaging (Sprint 12)

```
Conversation (transaction context: RFQ/Quotation/PO/…)
  └── Message (sender user, body, timestamps)
```

Access limited to participating buyer/supplier tenants (and admin where permitted).

### 3.13 Disputes (Sprint 13)

```
Dispute
  ├── transaction reference (PO/shipment/payment/…)
  ├── participants
  ├── status + resolution
  └── audit via AuditLogger
```

### 3.14 Reports (Sprint 14)

Prefer query/read-model endpoints over duplicated write schemas unless a sprint proves a materialized table is required. All queries enforce tenant authorization.

### 3.15 Administration (Sprint 15)

Uses the same tables; adds authorized admin CRUD/list endpoints — not a parallel schema.

## 4. Status vocabularies (shared patterns)

| Domain | Core states |
| --- | --- |
| Approval-gated proposals/requests | `pending`, `approved`, `rejected` |
| RFQ | at minimum existing `draft`; additional lifecycle values only in Sprint 3+ as required |
| Bank account | existing `active` (+ others only if Sprint 9 requires) |
| Quotation / PO / Payment / Shipment / Delivery / Dispute | defined in owning sprint; keep lowercase snake strings |

## 5. Migration discipline

| Rule | Detail |
| --- | --- |
| Per sprint | Only migrations for that sprint’s domain |
| Non-breakage | Additive preferred; destructive changes require data migration in the same sprint |
| Tests | Feature/security tests updated in the same sprint as schema |
| Seeders | Extend without removing assessment credentials unless Sprint 1 explicitly replaces role model |

## 6. Relationship summary (target end state)

```
Company (buyer/supplier classification)
 ├─ Users / Roles / Permissions
 ├─ RFQs → AiExtractions proposals (RfqProposal)
 │    ├─ Matches / Distributions → eligible Suppliers
 │    ├─ Quotations → Negotiation history
 │    └─ PurchaseOrders → Payments → Shipments → Deliveries
 ├─ Supplier profile → Products (pricing, MOQ, specs)
 ├─ Bank accounts → change requests → history
 ├─ Conversations / Messages
 ├─ Disputes
 └─ AuditLogs (morph to auditable records)
```
