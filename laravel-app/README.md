# Laravel Mini B2B Backend

Assessment backend for multi-tenant RFQs, mocked AI extraction with human approval, supplier bank-change approval, and auditability.

There is no frontend. The API is the product.

## Architecture

This is a single Laravel 13 API application.

**Auth.** Laravel Sanctum issues API tokens. Login does not accept `company_id`. Tenant context is taken from the authenticated user (or from the owned resource for admin actions).

**Tenancy.** `Company` is the tenant. Company users have a `company_id`. Admin has no company and can operate across tenants when a permission allows it. Queries and policies scope company users to their own company.

**Authorization.** Roles (`admin`, `company_user`) own permissions. Policies enforce permission + tenant on every sensitive operation.

**RFQ.** Official RFQ rows are the system of record. Company users can create, read, and update their own RFQs. They cannot approve AI proposals.

**AI.** `MockAiExtractor` parses text only. It never receives or writes an RFQ. `AiExtractionService` stores an extraction and, when a field differs, a pending `RfqProposal`. Official RFQ fields change only in `RfqProposal::approve()`, using the stored proposed value.

**Bank.** Each seeded supplier has one official bank account. IBAN is not mass-assignable and has no update endpoint. Changes go through `BankChangeRequest` → admin approval → official IBAN update + history row, in one transaction.

**Audit.** `AuditLogger` writes `audit_logs` for important mutations. Actor comes from `auth()->user()`. Company comes from the authorized resource.

```
Auth (Sanctum)
  → Policies (permission + tenant)
    → Controllers / form requests
      → Models / small services
        → SQLite (default)
```

## Setup

Requirements: PHP 8.3+, Composer, SQLite (default).

From this directory (`laravel-app`):

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

If Composer is not installed globally, use `php ..\composer.phar` from this folder.

Tests use an in-memory SQLite database (`phpunit.xml`). They do not need the file database.

### Seeded credentials (development only)

| User | Email | Password | Company | Role |
| --- | --- | --- | --- | --- |
| User A | user.a@example.com | password | Company A | company_user |
| User B | user.b@example.com | password | Company B | company_user |
| Admin User | admin@example.com | password | none | admin |

Seeded suppliers: Supplier A (Company A) and Supplier B (Company B), each with one active bank account.

### Auth

- `POST /api/login` `{ "email", "password" }` → token
- `GET /api/me` Bearer token
- `POST /api/logout` Bearer token

## Database Structure

```
Company 1──* User
Company 1──* Rfq
Company 1──* Supplier
Role 1──* User
Role *──* Permission

Rfq 1──* AiExtraction
Rfq 1──* RfqProposal
AiExtraction 1──* RfqProposal

Supplier 1──1 SupplierBankAccount
SupplierBankAccount 1──* BankChangeRequest
SupplierBankAccount 1──* BankAccountHistory

AuditLog → actor (User), company (Company), auditable (morph)
```

- **Companies** — tenants. Seeded: Company A, Company B.
- **Users** — `company_id` nullable (admin is null). `role_id` required. `company_id` / `role_id` are not fillable.
- **Roles / Permissions** — `admin` has all permissions. `company_user` has operational RFQ/bank permissions, not `rfq.approve` or `bank.approve`.
- **RFQs** — official commodity, specification, quantity, unit, incoterm, destination, status (`draft`), `company_id`.
- **AI Extractions** — proposed fields, confidence, source (`AI/mock`), status. Belongs to an RFQ. Does not replace the official RFQ.
- **RFQ Proposals** — field-level conflict: current value, proposed value, source, confidence, status (`pending` / `approved` / `rejected`).
- **Suppliers** — minimal tenant-owned record for bank data.
- **Supplier Bank Accounts** — official beneficiary, bank name, IBAN, status (`active`). IBAN is not fillable.
- **Bank Change Requests** — current IBAN, proposed IBAN, requester, company (copied from supplier), status.
- **Bank Account History** — snapshot of the official account taken on approval, before the IBAN is overwritten.
- **Audit Logs** — actor, company, action, auditable type/id, before/after JSON, optional reason, timestamps.

## Multi-Tenant Security

Tenant context is never taken from a client `company_id`.

- For create: RFQ `company_id` is `$request->user()->company`. Bank change `company_id` is `$supplier->company`.
- For read/update/approve: the resource’s stored company is compared to the authenticated user’s company. Admin skips the company check after permission succeeds.
- List queries use `visibleTo($user)` so company users only see their tenant.
- Cross-tenant access uses `denyAsNotFound()` (`404`) so the API does not confirm that another company’s resource exists. Missing permission on your own tenant is `403`.
- Extra body/query IDs (`company_id`, `rfq_id`, `supplier_id`, `bank_account_id`) are ignored for assignment.

## AI Safety

```
Input text
  → MockAiExtractor (parse only; no RFQ dependency)
  → AiExtraction stored
  → RfqProposal stored if a field differs
  → Human approval (rfq.approve)
  → Official RFQ field updated from the stored proposed_value
```

AI cannot directly modify official RFQ data. The extractor does not load or save RFQs. The extraction service only inserts extraction/proposal rows. Official writes happen only in `RfqProposal::approve()`.

The provider is mocked. There is no external AI API.

## Approval Workflow

**RFQ proposal**

1. Pending — official RFQ unchanged.
2. Rejected — official RFQ unchanged; proposal marked rejected.
3. Approved — only `rfq.approve` (admin). Official field is set from the stored proposal. Request-body `proposed_value` is ignored.

**Bank change**

1. Company user with `bank.change_request` creates a pending request. Official IBAN unchanged.
2. Rejected — official IBAN unchanged.
3. Approved — only `bank.approve` (admin), in one transaction: write history → set official IBAN from stored `proposed_iban` → mark request approved. Request-body IBAN is ignored.

There is no `PUT`/`PATCH` bank-account endpoint.

## Auditability

Important mutations are logged. Reads are not.

| Action | When |
| --- | --- |
| `rfq.created` | RFQ created |
| `rfq.updated` | RFQ updated |
| `rfq.approved` | Official RFQ changed by proposal approval |
| `proposal.created` | A conflicting proposal is stored |
| `proposal.approved` / `proposal.rejected` | Proposal resolved |
| `bank.change_requested` | Change request created |
| `bank.change_approved` / `bank.change_rejected` | Request resolved |
| `bank.account.changed` | Official IBAN updated |

- **Actor** — authenticated user (`auth()->user()`), not a client `actor_id`.
- **Company** — authorized resource tenant, not request `company_id`.
- **Before / after** — values the backend actually read/wrote (stored proposal, not the approval body).
- **Reason** — optional on approve/reject; not required.
- Approval audits are written in the same database transaction as the official update.

There is no audit query API or UI.

## API

All routes below except login require `auth:sanctum`.

| Method | Path | Permission |
| --- | --- | --- |
| `POST` | `/api/login` | public |
| `GET` | `/api/me` | authenticated |
| `POST` | `/api/logout` | authenticated |
| `GET` | `/api/rfqs` | `rfq.read` |
| `POST` | `/api/rfqs` | `rfq.create` |
| `GET` | `/api/rfqs/{rfq}` | `rfq.read` |
| `PUT/PATCH` | `/api/rfqs/{rfq}` | `rfq.update` |
| `GET` | `/api/rfqs/{rfq}/extractions` | `rfq.read` |
| `POST` | `/api/rfqs/{rfq}/extractions` | `rfq.read` |
| `POST` | `/api/proposals/{proposal}/approve` | `rfq.approve` |
| `POST` | `/api/proposals/{proposal}/reject` | `rfq.approve` |
| `GET` | `/api/suppliers/{supplier}/bank-account` | `bank.read` |
| `POST` | `/api/suppliers/{supplier}/bank-change-requests` | `bank.change_request` |
| `GET` | `/api/bank-change-requests/{id}` | `bank.read` |
| `POST` | `/api/bank-change-requests/{id}/approve` | `bank.approve` |
| `POST` | `/api/bank-change-requests/{id}/reject` | `bank.approve` |

## Testing

```bash
php artisan test
```

Latest full run: **90 tests**, **379 assertions**, **0 failures**, **0 errors**, **0 skipped**.

Coverage includes:

- Authentication and company association
- RFQ CRUD and tenant isolation
- AI extraction without official RFQ mutation
- Proposal approve/reject using stored values
- Bank change request, approval, history, and isolation
- Permission denials for approve/read
- `company_id` / ID spoofing ignored
- Audit actor, company, before/after for create/approve/reject

Sprint 5 security tests live in `tests/Feature/Security/`.

## AI / Cursor Usage

This project was implemented in Cursor across six fixed sprints. The AI coding agent generated the Laravel application, migrations, models, policies, controllers, seeders, tests, and this README from the assessment prompts.

What was generated or assisted:

- Laravel foundation (Sanctum, companies, users, roles, permissions)
- RFQ API and tenant policies
- Mock AI extraction, proposals, and approval
- Bank change workflow and history
- Audit logger hooks
- Feature/security tests

What was manually specified and checked:

- Sprint scope boundaries (no extra features, no real AI/bank providers)
- Security rules: do not trust `company_id`, AI must not write official RFQs, IBAN only after approval
- Permission model from Sprint 1 (company users cannot approve)
- Test results after each sprint and again in Sprint 6 (90/90 passing)

Business rules were validated by those tests and by reading the write paths (`MockAiExtractor`, `AiExtractionService`, `RfqProposal::approve`, `BankChangeRequest::approve`, `AuditLogger`).

## Assumptions

- SQLite is the local and test database.
- Two roles: `admin` (all permissions, no company) and `company_user` (no approve permissions).
- Admin can read/update/approve across companies but cannot create an RFQ (no company context).
- New RFQs start as `draft`. Status is not an approval state machine.
- The mock extractor targets text like `Need 25,000 MT ICUMSA 45 Sugar, CIF Jeddah.`
- Extraction confidence is `0.9`; source is `AI/mock`.
- Proposals are created per differing field.
- One official bank account per seeded supplier.
- Cross-tenant misses return `404`; missing permission returns `403`.
- Approve/reject `reason` is optional.
- No frontend, no audit read API, no real AI or banking providers.

## What I Would Improve With More Time

These are not required for the assessment:

- A thinner application service around approval so models stay smaller
- An admin-only audit query/report endpoint
- More edge cases (concurrent double-approve, malformed IBAN formats)
- A real AI provider behind the existing extractor interface
- Tighter request validation and structured application logging
