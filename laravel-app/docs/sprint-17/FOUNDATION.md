# Sprint 17 — Production Foundation & Technical Debt

Foundation-only sprint. No Scope 2 business features.

## Baseline

See [BASELINE.md](./BASELINE.md). Pre-change suite: **252 / 3428**, all green.

## Issues found and fixed

| Issue | Fix |
| --- | --- |
| `serializePurchaseOrder` locked PO/parties on every GET | Read path uses `ensureCoreParties(..., lock: false)` and skips work when cores exist; uses loaded `parties` when present |
| Quotation create race (exists outside txn) | Eligibility + active check + create under `lockForUpdate` on distribution/quotations |
| PO / parties list N+1 | Eager-load `parties.company` on PO index/show; `with('company')` on parties list |
| Unbounded commercial indexes | Optional `per_page` (1–100) via `OptionallyPaginates`; absent `per_page` preserves Sprint 1–16 response shape |
| Missing indexes for known queries | Migration `2026_09_24_170000_add_sprint_17_query_indexes` |
| Quotation/negotiation auto-expire unaudited | `refreshExpiration()` records `quotation.expired` / `negotiation.expired` |

## Intentionally unchanged

- Assessment 2 confidentiality + mutation `serializePurchaseOrder` responses
- Sprint 16 concurrency hardening (CN void, invoice void, second negotiation/return)
- `denyAsNotFound` tenant convention
- Spoof fields remain “ignored by service” (not `prohibited`) to preserve established security-test contracts
- Downstream invoice/shipment identity fields (PO-centric Assessment 2 scope)
- No new product domains

## Optional pagination contract

```
GET /api/products                 → { "products": [...] }
GET /api/products?per_page=25     → { "products": [...], "meta": { current_page, per_page, total, last_page } }
```

Same pattern for RFQs, POs, invoices, payments, shipments, RMAs, credit notes, refunds, delivery confirmations.
