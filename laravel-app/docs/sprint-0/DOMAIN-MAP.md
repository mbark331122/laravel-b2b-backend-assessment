# Domain Map

## 1. Commercial lifecycle (fixed)

```
Buyer Company
    │
    ▼
   RFQ ──(AI extraction proposals; optional)──► Approval (field proposals)
    │
    ▼
Supplier Matching / RFQ Distribution
    │
    ▼
Quotations (supplier commercial offers)
    │
    ▼
Negotiation (offers / counter-offers + history)
    │
    ▼
Approval (commercial / workflow approvals as defined)
    │
    ▼
Purchase Order
    │
    ▼
Payment
    │
    ▼
Shipment
    │
    ▼
Delivery (completion)
```

Cross-cutting domains:

- Messaging (tied to transactions)
- Disputes (tied to transactions)
- Supplier banking (change-request approval)
- Permissions / Policies
- Audit logs
- Reports (read models)
- Platform administration

## 2. Domain boundaries

### Identity & Tenancy (Sprint 1)

| Entity | Responsibility |
| --- | --- |
| Company | Tenant root; classified as buyer and/or supplier |
| User | Authenticated actor; belongs to at most one company (admin may have none) |
| Role | Named role (`admin`, buyer/supplier company roles as extended) |
| Permission | Fine-grained backend permission strings |
| AuditLog | Foundation for mutation audit |

**Boundary:** Identity does not own commercial documents. It owns who may act and under which tenant.

### Supplier & Catalog (Sprint 2)

| Entity | Responsibility |
| --- | --- |
| Supplier profile | Public/operational supplier identity linked to supplier company |
| Verification status | Platform trust state for supplier |
| Product | Catalog item |
| Product specification | Structured product attributes |
| Wholesale pricing / MOQ | Commercial catalog terms |

**Boundary:** Catalog describes what a supplier can sell. It does not create RFQs, quotations, or orders.

### RFQ (Sprint 3)

| Entity | Responsibility |
| --- | --- |
| RFQ | Buyer demand: commodity, specification, quantity, unit, incoterm, destination, status, tenant |
| RFQ items | Optional line structure if required by the RFQ model in Sprint 3 |

**Boundary:** RFQ is the official buyer demand record. AI may propose field changes but does not own the RFQ.

### AI Extraction (Sprint 4)

| Entity | Responsibility |
| --- | --- |
| AiExtraction | Stored proposed extraction (confidence, source, fields) |
| RfqProposal | Field-level conflict: current vs proposed; pending/approved/rejected |

**Boundary:** Advisory only. Official RFQ fields change only after approval.

### Matching & Distribution (Sprint 5)

| Responsibility | Notes |
| --- | --- |
| Eligibility | Which suppliers may see/respond to an RFQ |
| Distribution / visibility | Supplier RFQ access based on catalog/profile data |
| Response eligibility | Gate before quotation creation |

**Boundary:** Matching creates visibility/eligibility, not commercial quotes.

### Quotations (Sprint 6)

| Entity | Responsibility |
| --- | --- |
| Quotation | Supplier commercial response to an RFQ |
| Quotation items | Line pricing/qty/terms |
| Quotation status | Lifecycle of the offer |

**Boundary:** Distinct from `RfqProposal` (AI). Buyer comparison uses quotation data.

### Negotiation (Sprint 7)

| Entity | Responsibility |
| --- | --- |
| Negotiation / offers | Buyer–supplier commercial back-and-forth |
| History | Immutable trail of offers/counter-offers |

**Boundary:** Negotiation changes proposed commercial terms; official conversion to PO requires defined approval.

### Purchase Orders (Sprint 8)

| Entity | Responsibility |
| --- | --- |
| PurchaseOrder | Binding commercial order after approved transaction |
| PO items | Ordered lines and terms |

**Boundary:** Created from approved commercial outcome — not from raw AI output.

### Banking (Sprint 9)

| Entity | Responsibility |
| --- | --- |
| SupplierBankAccount | Official beneficiary, bank name, IBAN, status |
| BankChangeRequest | Proposed IBAN change |
| BankAccountHistory | Snapshot of prior official values |

**Boundary:** Banking master data ≠ payment execution (Sprint 10).

### Payments (Sprint 10)

| Entity | Responsibility |
| --- | --- |
| Payment | Payment record linked to PO; internal states only (no external provider required) |

### Shipment & Delivery (Sprint 11)

| Entity | Responsibility |
| --- | --- |
| Shipment | Shipping record + tracking/status for a PO |
| Delivery | Delivery status and completion |

Lifecycle coupling: `PO → Payment → Shipment → Delivery`.

### Messaging (Sprint 12)

Transaction-scoped buyer/supplier messages with authorization by participation/tenant.

### Disputes (Sprint 13)

Transaction-scoped dispute cases, status, participants, resolution, audit.

### Reports (Sprint 14)

Read models / report endpoints over RFQ, supplier, quotation, order, payment, shipment/delivery — tenant-scoped.

### Administration (Sprint 15)

Authorized platform admin operations over companies, users, suppliers, products, RFQs, quotations, orders, permissions, approvals, audits.

## 3. Existing vs planned domain ownership

| Domain | Existing code | Sprint that owns build-out |
| --- | --- | --- |
| Companies / users / roles / permissions | Present (buyer/supplier classification missing) | Sprint 1 |
| Audit foundation | Present | Sprint 1 (extend) |
| Supplier profile / products / MOQ / pricing | Partial supplier stub only | Sprint 2 |
| RFQ core | Present (`draft` status) | Sprint 3 (extend lifecycle as required) |
| AI extraction + field proposals | Present | Sprint 4 (harden/align; already largely done) |
| Matching / distribution | Missing | Sprint 5 |
| Commercial quotations | Missing | Sprint 6 |
| Negotiation | Missing | Sprint 7 |
| Purchase orders | Missing | Sprint 8 |
| Banking change control | Present | Sprint 9 (align/verify against full supplier model) |
| Payments | Missing | Sprint 10 |
| Shipment / delivery | Missing | Sprint 11 |
| Messaging | Missing | Sprint 12 |
| Disputes | Missing | Sprint 13 |
| Reports | Missing | Sprint 14 |
| Admin APIs | Missing | Sprint 15 |
| Full security suite pass | Partial Security suite exists | Sprint 16 |
| Hardening & docs | Assessment README exists | Sprint 17 |

## 4. Actor map

| Actor | Tenant | Typical capabilities |
| --- | --- | --- |
| Platform admin | None (or platform) | Cross-tenant operations allowed by admin permissions; approvals |
| Buyer company user | Buyer company | RFQ, review quotes, negotiate, PO side, payments initiation as defined, messaging, disputes |
| Supplier company user | Supplier company | Catalog, view eligible RFQs, quote, negotiate, fulfill PO/shipment sides as defined |

Exact permission grants are defined in [PERMISSION-MODEL.md](./PERMISSION-MODEL.md) and expanded per sprint without breaking the minimum RFQ/bank set.
