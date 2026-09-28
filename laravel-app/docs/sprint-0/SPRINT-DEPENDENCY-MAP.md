# Sprint Dependency Map

## 1. Rules

- Do not reorder, merge, skip, or add sprints.
- Do not implement a later sprint’s features early.
- Each sprint depends on the completed foundation of prior sprints listed below.
- After each sprint: test, report, **STOP**, wait for explicit instruction.

## 2. Dependency graph

```
Sprint 0  Architecture & Foundation
    │
    ▼
Sprint 1  Platform Identity & Multi-Tenancy
    │
    ▼
Sprint 2  Supplier & Product Catalog
    │
    ▼
Sprint 3  RFQ Core
    │
    ├──────────────────────────────┐
    ▼                              ▼
Sprint 4  AI Extraction & Approval Safety
    │                              │
    ▼                              │
Sprint 5  Supplier Matching & RFQ Distribution
    │                              │
    ▼                              │
Sprint 6  Quotations               │
    │                              │
    ▼                              │
Sprint 7  Negotiation              │
    │                              │
    ▼                              │
Sprint 8  Purchase Orders ◄────────┘ (needs approved commercial path;
    │                     RFQ+AI remain independent inputs)
    │
    ├──────────────────┐
    ▼                  ▼
Sprint 9  Banking   Sprint 10  Payments
    │                  │
    └────────┬─────────┘
             ▼
        Sprint 11  Shipment & Delivery
             │
             ├──────────────┐
             ▼              ▼
        Sprint 12      Sprint 13
        Messaging      Disputes
             │              │
             └──────┬───────┘
                    ▼
               Sprint 14  Reports & Read Models
                    │
                    ▼
               Sprint 15  Platform Administration
                    │
                    ▼
               Sprint 16  Full Security & Workflow Testing
                    │
                    ▼
               Sprint 17  Final Hardening & Documentation
```

## 3. Per-sprint dependencies

| Sprint | Name | Depends on | Why |
| --- | --- | --- | --- |
| 0 | Architecture & Foundation | — | Analysis only |
| 1 | Identity & Multi-Tenancy | 0 | Companies, users, roles, permissions, tenant isolation, audit foundation |
| 2 | Supplier & Product Catalog | 1 | Supplier companies/profiles need identity + tenancy |
| 3 | RFQ Core | 1 (2 recommended before matching, but RFQ ownership is buyer-tenant) | RFQ requires buyer company/users/permissions; catalog not required for RFQ CRUD itself |
| 4 | AI Extraction & Approval | 3 | Proposals attach to RFQs |
| 5 | Matching & Distribution | 2, 3 | Needs supplier/product data and RFQs |
| 6 | Quotations | 5 | Supplier response eligibility/visibility |
| 7 | Negotiation | 6 | Negotiates commercial quotation terms |
| 8 | Purchase Orders | 7 | Converts approved commercial outcome to PO |
| 9 | Supplier Banking | 2 (1) | Bank accounts belong to suppliers; approval/audit from 1 |
| 10 | Payments | 8 | Payments relate to POs |
| 11 | Shipment & Delivery | 8, 10 | Lifecycle PO → Payment → Shipment → Delivery |
| 12 | Messaging | 1 + transaction domains (≥6/8 as context) | Needs identity + transaction association |
| 13 | Disputes | 8+ (transaction exists) | Needs PO/payment/shipment context as applicable |
| 14 | Reports | Domains 3–13 as built | Read models over existing data |
| 15 | Administration | 1–14 | Admin surfaces over built domains |
| 16 | Full security testing | 1–15 | End-to-end security & lifecycle verification |
| 17 | Hardening & docs | 16 | No new product features |

### Sequencing notes (fixed order still)

- **Sprint 3 before 2 is not allowed** by the roadmap even though RFQ CRUD could technically start after Sprint 1 only. The fixed order is **1 → 2 → 3**.
- **Sprint 9** is placed after PO in the roadmap; banking still depends primarily on supplier identity (Sprint 2). Do not pull banking earlier.
- **Sprint 12 vs 13** both follow shipment/delivery in the fixed roadmap; messaging does not depend on disputes and vice versa, but execution order remains 12 then 13.

## 4. Reuse vs build matrix

| Sprint | Primary reuse from existing repo | Primary new build |
| --- | --- | --- |
| 0 | Entire assessment codebase (read-only) | Architecture docs only |
| 1 | Company, User, Role, Permission, AuditLogger, Sanctum, policies pattern | Buyer/supplier classification; strengthen identity/audit as required |
| 2 | Supplier stub, tenant scopes | Profiles, verification, products, pricing, MOQ |
| 3 | Rfq model/API/policy | Lifecycle/status/items as required; harden RFQ management |
| 4 | MockAiExtractor, AiExtractionService, RfqProposal | Align/harden AI safety & tests (largely present) |
| 5 | RFQ + supplier/product | Matching, distribution, supplier visibility |
| 6 | — | Commercial quotations + comparison foundation |
| 7 | Quotations | Negotiation offers/history |
| 8 | Approved commercial records | PO + items + lifecycle |
| 9 | Bank tables/workflows | Verification step alignment, full supplier linkage |
| 10 | PO | Payment records/states |
| 11 | PO + payment | Shipment + delivery |
| 12 | Auth/tenancy + transactions | Messaging |
| 13 | Transactions + audit | Disputes |
| 14 | All domain tables | Report endpoints/read models |
| 15 | Permissions + domains | Admin APIs |
| 16 | All tests | Comprehensive security/lifecycle suite |
| 17 | Docs/tests | Hardening + final documentation only |

## 5. Cross-cutting dependencies

| Cross-cutting concern | Introduced | Extended continuously |
| --- | --- | --- |
| Tenant isolation | Sprint 1 (exists now) | Every domain sprint |
| Permissions | Sprint 1 (exists now) | Each domain adds permissions |
| Approval pattern | Sprint 4 & 9 (exists now) | When workflow requires |
| Audit logging | Sprint 1 (exists now) | Each mutating domain |
| Security tests | Ongoing | Culminates Sprint 16 |

## 6. Current position

| Item | Status |
| --- | --- |
| Sprint 0 | **Complete** (this documentation set) |
| Sprint 1+ | **Not started** — waiting for explicit instruction |

## 7. Stop condition

Sprint 0 ends here. No application code changes accompany this sprint beyond documentation under `docs/sprint-0/`.
