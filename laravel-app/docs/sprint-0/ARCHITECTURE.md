# Architecture Document

## 1. Purpose

This document freezes the technical architecture for the B2B platform within the fixed product scope and sprint roadmap. It is derived from inspection of the existing `laravel-app/` repository and defines what must be reused, what must be extended, and what must not be invented outside the roadmap.

## 2. System shape

| Decision | Choice |
| --- | --- |
| Application | Single Laravel API application in `laravel-app/` |
| Product surface | HTTP JSON API (`routes/api.php`) |
| Frontend | Out of scope for this backend product |
| Framework | Laravel 13 / PHP 8.3+ |
| Auth | Laravel Sanctum personal access tokens |
| Default database | SQLite (local + tests); schema must remain portable to MySQL/PostgreSQL |
| Test runner | PHPUnit (`php artisan test`), in-memory SQLite |

**Do not** introduce a second application, microservice, package monorepo, or frontend app unless the product owner explicitly changes scope.

## 3. Existing repository (inspect result)

### 3.1 Layout to preserve

```
laravel-app/
  app/Http/Controllers/Api/
  app/Http/Requests/
  app/Models/
  app/Policies/
  app/Services/
  database/migrations/
  database/seeders/
  routes/api.php
  tests/Feature/
  tests/Feature/Security/
  tests/Unit/
```

### 3.2 Reusable building blocks

| Area | Existing assets | Reuse rule |
| --- | --- | --- |
| Auth | `AuthController`, Sanctum tokens, `LoginRequest` | Keep; extend only if Sprint 1 needs company-type claims in `/me` |
| Tenancy | `Company`, `User.company_id`, `visibleTo`, policies | Keep pattern; extend Company for buyer/supplier classification in Sprint 1 |
| RBAC | `Role`, `Permission`, seeders | Keep; extend permission catalog sprint-by-sprint |
| RFQ | `Rfq`, `RfqController`, `RfqPolicy` | Keep fields; extend status lifecycle in Sprint 3 without breaking AI fields |
| AI safety | `MockAiExtractor`, `AiExtractionService`, `RfqProposal` | Keep separation: AI never writes official RFQ |
| Banking | `SupplierBankAccount`, `BankChangeRequest`, history | Keep change-request → approve → update pattern for Sprint 9 |
| Audit | `AuditLogger`, `AuditLog` | Keep API; extend action catalog as domains land |
| Tests | Feature + Security suites | Preserve isolation/authorization patterns; add domain tests per sprint |

### 3.3 Naming collision to respect

`RfqProposal` is an **AI field-conflict proposal**, not a commercial supplier quotation.

Sprint 6 must introduce a separate commercial **Quotation** domain (e.g. `Quotation` / `quotation_items`). Do not overload `RfqProposal`.

### 3.4 Current Supplier meaning

Today `Supplier` is a **tenant-owned record** under a buyer `Company`, used for bank-account control. Sprint 1–2 will introduce **supplier companies** and supplier profiles/catalog. Architecture rule:

- Preserve existing bank-account tables and workflows.
- Sprint 1 classifies companies as buyer and/or supplier.
- Sprint 2 attaches supplier profile, verification, and products to the supplier company identity.
- Migration of the current seeded `Supplier` rows must keep bank-account integrity when Sprint 2 lands (link supplier profile to company without breaking Sprint 9 banking model).

## 4. Architecture boundaries

### 4.1 Layers (request path)

```
HTTP /api/*
  → auth:sanctum (except login)
    → Form Request validation
      → Policy / Gate authorization (permission + tenant)
        → Controller (orchestration only)
          → Model / small Service
            → Database transaction when mutating official records
              → AuditLogger on important mutations
```

### 4.2 What belongs where

| Concern | Location |
| --- | --- |
| Route map | `routes/api.php` |
| Input validation | `app/Http/Requests` |
| Authorization | `app/Policies` (+ Gate in form requests when needed) |
| Official state mutation with approval | Model methods (e.g. `approve()` / `reject()`) inside `DB::transaction` |
| Pure computation / parsing | `app/Services` (e.g. mock AI extractor) |
| Cross-cutting audit write | `App\Services\AuditLogger` |
| Tenant list filtering | Eloquent `scopeVisibleTo` (or domain-equivalent) |

### 4.3 Domain modules (logical, not separate apps)

Logical domains map to models/controllers under the same app:

1. Identity & Tenancy  
2. Supplier & Catalog  
3. RFQ  
4. AI Extraction & Field Proposals  
5. Matching & Distribution  
6. Quotations  
7. Negotiation  
8. Purchase Orders  
9. Banking  
10. Payments  
11. Shipment & Delivery  
12. Messaging  
13. Disputes  
14. Reports (read models)  
15. Administration  

Implement only the domain required by the **current sprint**.

## 5. Database conventions

1. **Tenant FK:** business tables that are company-scoped store `company_id` (or derive tenant via owned parent). Never accept client `company_id` for assignment of ownership.
2. **Sensitive columns:** not mass-assignable when change requires approval (example: `iban`).
3. **Status strings:** lowercase snake values (`pending`, `approved`, `rejected`, `draft`, …).
4. **Timestamps:** all business tables use Laravel timestamps; audit requires its own table — `updated_at` is not an audit log.
5. **FKs:** prefer explicit foreign keys; cascade/restrict chosen per domain (prefer restrict on financial/official records).
6. **Morphs:** allowed for audit (`auditable`) and later polymorphic associations only when the domain requires it.
7. **Migrations:** one concern per migration file; follow existing timestamp naming under `database/migrations/`.
8. **Seeders:** Permission → Role → Company → Users → domain seed data; associate guarded FKs via Eloquent, not fillable mass-assignment.

## 6. Tenant / security conventions

See [SECURITY-MODEL.md](./SECURITY-MODEL.md). Summary:

- Tenant context comes from the authenticated user or from the authorized resource’s stored company.
- Cross-tenant denial returns **404** (`denyAsNotFound`).
- Missing permission on own tenant returns **403**.
- Admin may be company-less and cross-tenant where a permission allows it.

## 7. API conventions

| Topic | Convention |
| --- | --- |
| Base path | `/api` |
| Auth header | `Authorization: Bearer {token}` |
| Login body | `{ email, password }` only |
| Success/error | JSON; API exceptions already forced JSON in `bootstrap/app.php` |
| Resource shaping | Prefer existing `toApiArray()` pattern for consistency |
| IDs | Route-model binding; authorization after resolve |
| Mutations | POST for workflow actions (`approve`, `reject`); PUT/PATCH for direct editable fields only when policy allows |
| Destroy | Only when the sprint explicitly requires it |
| Pagination | Add when list endpoints grow; not required until a sprint needs it |
| Versioning | No URL versioning in current roadmap |

## 8. Approval / audit conventions

See [APPROVAL-MODEL.md](./APPROVAL-MODEL.md) and [AUDIT-MODEL.md](./AUDIT-MODEL.md).

Core rule: **official records change only through authorized direct edits or through an approve path that applies stored proposed values.** AI and unverified change requests never write official values directly.

## 9. Testing strategy

| Layer | Location | Purpose |
| --- | --- | --- |
| Unit | `tests/Unit` | Pure parsers/services (e.g. mock extractor) |
| Feature | `tests/Feature` | API behavior per domain |
| Security | `tests/Feature/Security` | Cross-tenant, spoofing, unauthorized approval, AI safety |

**Every implementation sprint must add tests for:**

- Happy path for new endpoints/workflows
- Permission denial
- Tenant isolation / cross-tenant 404
- Spoofed `company_id` / foreign IDs ignored
- Approval invariants where approval exists
- Audit assertions for important mutations

**Commands:**

```bash
php artisan test
php artisan migrate --seed   # local verification when schema changes
```

Do not weaken existing Security tests when extending models.

## 10. What Sprint 0 intentionally does not build

- No new migrations, models, controllers, or routes
- No product/catalog/matching/quotation/PO/payment/shipment/messaging/dispute/report/admin features
- No refactors of working RFQ/AI/bank paths beyond documentation

## 11. Sprint execution boundary

Work only on the instructed sprint. After each sprint: run tests, report files/tests/completion/blockers, and **STOP** until the next sprint is explicitly requested.
