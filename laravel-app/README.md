# Laravel Mini B2B Backend

B2B procurement and supplier commerce API (multi-tenant). Current codebase includes identity/tenancy, supplier profiles, wholesale product catalog, RFQs, supplier matching & distribution, quotations, negotiation with immutable counter-offers, purchase orders with supplier confirmation, commercial invoices (snapshotted from confirmed POs), mocked AI extraction with human approval, supplier bank-change approval, and auditability — with a fixed sprint roadmap for the full commercial lifecycle.

There is no frontend. The API is the product.

**Sprint 0 architecture docs:** [`docs/sprint-0/`](docs/sprint-0/)

## Architecture

This is a single Laravel 13 API application.

**Auth.** Laravel Sanctum issues API tokens. Login does not accept `company_id`. Tenant context is taken from the authenticated user (or from the owned resource for admin actions).

**Tenancy.** `Company` is the tenant. Companies are classified with `is_buyer` / `is_supplier` (set only by trusted backend/seeders — not client input). Company users have a `company_id`. Admin has no company and can operate across tenants when a permission allows it. Queries and policies scope company users to their own company. RFQ creation additionally requires a buyer company.

**Authorization.** Roles (`admin`, `company_user`, `supplier_user`) own permissions. Policies enforce permission + tenant (and buyer/supplier classification) on every sensitive operation.

**Catalog.** Supplier companies own a `SupplierProfile` and wholesale `Product` rows (brand, specs, MOQ/max/increment, base price + tiers, lifecycle). Buyers may browse/view **published** products only. New supplier products start as `draft` and require lifecycle review before publication. Product ownership always comes from the authenticated supplier company — never from client `company_id` / `supplier_id` / `tenant_id`.

**RFQ.** Official RFQ rows are the system of record for buyer demand. Buyer companies create draft RFQs (legacy commodity fields remain supported for AI enrichment), attach line items with optional published-product snapshots, then submit/cancel/close through controlled transitions. Submitted RFQs are immutable via normal update/item endpoints.

**Supplier matching & distribution.** Buyers discover eligible suppliers (`GET /api/supplier-profiles`) and preview deterministic matches for a submitted RFQ (`GET /api/rfqs/{rfq}/suppliers`). Matching uses structured catalog data only (published products overlapping RFQ product IDs and/or categories) — no AI, scores, or reputation ranking. Results are ordered by `display_name`, then `id`. Buyers distribute to selected eligible suppliers (`POST /api/rfqs/{rfq}/distributions`). Distribution lifecycle: `pending` / `sent` / `withdrawn` (server-controlled). Deduplication: unique `(rfq_id, supplier_company_id)`; withdrawing then redistributing reactivates the same row. Suppliers may **read** only RFQs explicitly distributed to their company (`GET /api/supplier/rfqs`).

**Quotations.** Suppliers create draft quotations against an **active** distribution for their own company (`POST /api/supplier/rfq-distributions/{distribution}/quotation`). Lifecycle: `draft` → `submitted` → `withdrawn` | `expired`. Totals are server-calculated (`line_total = qty × unit_price`, `subtotal = Σ line_total`, `total = subtotal + shipping + tax`). Client-provided totals are ignored. At most one active (`draft`/`submitted`) quotation per distribution via `active_lock`; withdrawn/expired rows are not reused — a new draft may be created. Submitted quotes become immutable; expiration is evaluated on read when `valid_until` is past (no scheduler required). Buyers list/compare non-draft quotations on their own RFQs. Comparison is neutral (no score, rank, or winner).

**Negotiation.** Participants open a negotiation against an active submitted quotation (`POST /api/quotations/{quotation}/negotiation`). An immutable initial offer (sequence 1, supplier side) is snapshotted from the quotation. Counter-offers are append-only (`POST /api/negotiations/{negotiation}/offers`) with server-enforced alternating turns. Offers cannot be edited or deleted. Lifecycle: `open` → `accepted` | `rejected` | `withdrawn` | `expired`. Acceptance is by the opposite party on the latest proposed unexpired offer (transaction + row locks). Original quotation is never mutated. No ranking or automatic winner selection.

**Purchase Orders.** Buyers create a PO only from an **accepted** negotiation (`POST /api/negotiations/{negotiation}/purchase-order`). Commercial terms are snapshotted from the accepted offer (immutable). One PO per negotiation (unique `negotiation_id`); idempotent re-create returns the existing PO. Server-generated unique `number` (`PO-{YEAR}-{id}`). Lifecycle: `draft` → `pending_supplier_confirmation` → `confirmed` → `completed` (or `rejected` / `cancelled`). Supplier confirms/rejects after submit; buyer cancels while draft/pending; buyer completes confirmed POs.

**Commercial Invoices.** Suppliers create an invoice only from a **confirmed** purchase order (`POST /api/purchase-orders/{purchaseOrder}/invoice`). Commercial values are snapshotted from the confirmed PO (immutable after create). One invoice per PO (unique `purchase_order_id`); idempotent re-create returns the existing invoice. Server-generated unique `number` (`INV-{YEAR}-{id}`). Lifecycle: `draft` → `issued` | `cancelled`; `issued` → `voided`. `paid` exists as a reserved bookkeeping state but is unreachable via public APIs. No payment processing, ZATCA, tax engines, credit notes, refunds, shipping, or fulfillment.

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

| User | Email | Password | Company | Classification | Role |
| --- | --- | --- | --- | --- | --- |
| User A | user.a@example.com | password | Company A | buyer | company_user |
| User B | user.b@example.com | password | Company B | buyer | company_user |
| Supplier User | supplier.user@example.com | password | Supplier Company | supplier | supplier_user |
| Supplier User B | supplier.user.b@example.com | password | Supplier Company B | supplier | supplier_user |
| Admin User | admin@example.com | password | none | none | admin |

Seeded bank records: Supplier A (Company A) and Supplier B (Company B), each with one active bank account (banking workflow — distinct from catalog `SupplierProfile`).

Seeded catalog: brands Al Osra/GrainCo; categories Sugar/Grains; published + archived + draft products; specs and price tiers on published sugar.

### Auth

- `POST /api/login` `{ "email", "password" }` → token
- `GET /api/me` Bearer token
- `POST /api/logout` Bearer token

## Database Structure

```
Company 1──* User
Company 1──* Rfq
Company 1──* Supplier                 # bank workflow record (buyer-tenant owned)
Company 1──1 SupplierProfile          # catalog supplier identity (supplier-tenant)
Company 1──* Product
Brand 1──* Product                    # platform-level brands
ProductCategory 1──* Product
Product 1──* ProductSpecification
Product 1──* ProductPriceTier
Role 1──* User
Role *──* Permission

Rfq 1──* AiExtraction
Rfq 1──* RfqProposal
Rfq 1──* RfqDistribution
RfqDistribution → supplier Company + SupplierProfile
Rfq 1──* Quotation
RfqDistribution 1──* Quotation
Quotation 1──* QuotationItem → RfqItem
Quotation 1──* Negotiation
Negotiation 1──* NegotiationOffer (append-only)
NegotiationOffer 1──* NegotiationOfferItem → RfqItem
Negotiation 1──1 PurchaseOrder
PurchaseOrder 1──* PurchaseOrderItem
PurchaseOrder 1──1 Invoice
Invoice 1──* InvoiceItem
AiExtraction 1──* RfqProposal

Supplier 1──1 SupplierBankAccount
SupplierBankAccount 1──* BankChangeRequest
SupplierBankAccount 1──* BankAccountHistory

AuditLog → actor (User), company (Company), auditable (morph)
```

- **Companies** — tenants with buyer/supplier classification (`is_buyer`, `is_supplier`). Seeded: Company A/B (buyer), Supplier Company / Supplier Company B (supplier).
- **Users** — `company_id` nullable (admin is null). `role_id` required. `company_id` / `role_id` are not fillable. Classification is read from the user's company.
- **Roles / Permissions** — `admin` has all permissions. `company_user` has buyer RFQ/quotation/negotiation permissions plus `purchase_order.read|create|submit|cancel|complete` and `invoice.read`. `supplier_user` has catalog/quotation/negotiation permissions plus `purchase_order.read|confirm|reject` and `invoice.read|create|issue|cancel|void`. Platform-only: `brand.create`, `product.review`.
- **Supplier Profiles** — one profile per supplier company (`display_name`, description, contact, status). Buyer discovery via `GET /api/supplier-profiles` returns only eligible suppliers (`status=active` + `company.is_supplier=true`), filterable by `q`, `product_category_id`, `brand_id`, `product_q`.
- **Brands** — platform-level (`name`, `slug`, status). Readable with `product.read`; create requires `brand.create`.
- **Product Categories** — platform-level categories (`name`, status).
- **Products** — wholesale catalog owned by supplier `company_id` + `supplier_profile_id`: name, sku, description, category, brand, unit, MOQ, maximum_order_quantity, quantity_increment, wholesale_price, currency, lifecycle status (`draft` / `pending_review` / `approved` / `published` / `rejected` / `archived`). Nested specs + price tiers. Buyers see `published` only.
- **RFQs** — buyer-owned demand records: title/description, legacy commodity fields (AI), destination/incoterm/currency/required_by_date, lifecycle (`draft` / `submitted` / `cancelled` / `closed`), `company_id`.
- **RFQ Items** — line items with quantity/unit, optional target price, optional published `product_id` + immutable `product_snapshot` JSON.
- **RFQ Distributions** — buyer→supplier distribution rows: `rfq_id`, `supplier_company_id`, `supplier_profile_id`, status (`pending` / `sent` / `withdrawn`), `distributed_at` / `withdrawn_at`. Unique per `(rfq_id, supplier_company_id)`. Reactivate-on-withdraw (no duplicate active rows).
- **Quotations** — supplier offers on a distribution: currency, `valid_until`, notes, shipping/tax, server totals, lifecycle (`draft` / `submitted` / `withdrawn` / `expired`). Ownership = authenticated supplier company. `active_lock` enforces one active quotation per distribution.
- **Quotation Items** — map 1:1 to RFQ items with quantity, unit price, server `line_total`, snapshot JSON captured at create time.
- **Negotiations** — one open negotiation per quotation (`active_lock`); participants = RFQ buyer company + quotation supplier company; lifecycle `open` / `accepted` / `rejected` / `withdrawn` / `expired`.
- **Negotiation Offers** — append-only sequenced offers (`buyer`/`supplier` side); statuses `proposed` / `superseded` / `accepted`; immutable after create.
- **Negotiation Offer Items** — historical commercial lines with snapshots; server-calculated totals.
- **Purchase Orders** — one per accepted negotiation; unique `number`; snapshotted currency/totals/items from accepted offer; lifecycle `draft` / `pending_supplier_confirmation` / `confirmed` / `rejected` / `cancelled` / `completed`.
- **Purchase Order Items** — immutable commercial snapshots (not live Product/Quotation joins).
- **Invoices** — one per confirmed PO; unique `number`; snapshotted buyer/supplier/currency/totals from the PO; lifecycle `draft` / `issued` / `voided` / `paid` (reserved) / `cancelled`.
- **Invoice Items** — immutable line snapshots from PO items (not live Product/PO joins after create).
- **AI Extractions** — proposed fields, confidence, source (`AI/mock`), status. Belongs to an RFQ. Does not replace the official RFQ.
- **RFQ Proposals** — field-level conflict: current value, proposed value, source, confidence, status (`pending` / `approved` / `rejected`).
- **Suppliers** — minimal tenant-owned record for bank data (not the catalog profile).
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
- Extra body/query IDs (`company_id`, `rfq_id`, `supplier_id`, `supplier_company_id`, `bank_account_id`, `tenant_id`) are ignored for assignment.
- Supplier RFQ visibility requires an **active** distribution to the authenticated supplier company. Suppliers cannot browse buyer RFQ lists (`GET /api/rfqs` remains buyer permission `rfq.read`).
- Eligible suppliers for discovery/distribution are resolved server-side (`is_supplier`, active profile, published catalog match). Client-supplied supplier classification is never trusted.
- Quotation ownership is the authenticated supplier company. Buyers only see non-draft quotations on RFQs they own. Spoofed `supplier_id` / `company_id` / `tenant_id` / `rfq_distribution_id` are ignored.
- Negotiation participants and turn side are resolved server-side from RFQ/quotation ownership. Client `side` / `party` / company IDs are ignored. Historical offers have no update/delete routes.
- Purchase order relationships and totals are derived from the accepted negotiation/offer. Client relationship IDs and money fields are ignored. Draft POs are buyer-only until submitted.
- Invoice relationships and totals are derived from the confirmed PO. Client `company_id` / buyer/supplier IDs / `number` / money fields / status are ignored. Buyers read only their company invoices; suppliers create/issue/cancel/void only their own.

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
| `rfq.submitted` / `rfq.cancelled` / `rfq.closed` | RFQ lifecycle |
| `rfq.item.created` / `updated` / `deleted` | RFQ line item mutations |
| `rfq.distributed` | RFQ distributed to a supplier |
| `rfq.distribution.withdrawn` | Distribution withdrawn |
| `quotation.created` / `updated` / `deleted` | Supplier draft quotation mutations |
| `quotation.submitted` / `withdrawn` | Quotation lifecycle |
| `quotation.item.created` / `updated` / `deleted` | Quotation line item mutations |
| `negotiation.created` | Negotiation opened |
| `negotiation.offer.created` | Offer / counter-offer appended |
| `negotiation.offer.accepted` | Offer accepted; negotiation closed |
| `negotiation.rejected` / `withdrawn` | Negotiation terminated |
| `purchase_order.created` / `submitted` / `confirmed` / `rejected` / `cancelled` / `completed` | PO lifecycle |
| `invoice.created` / `issued` / `cancelled` / `voided` | Invoice lifecycle |
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
| `GET` | `/api/rfqs` | `rfq.read` (filters: status, created/updated dates) |
| `POST` | `/api/rfqs` | `rfq.create` (always `draft`; ownership from auth company) |
| `GET` | `/api/rfqs/{rfq}` | `rfq.read` |
| `PUT/PATCH` | `/api/rfqs/{rfq}` | `rfq.update` (draft only) |
| `DELETE` | `/api/rfqs/{rfq}` | `rfq.delete` (draft only) |
| `POST` | `/api/rfqs/{rfq}/submit` | `rfq.submit` |
| `POST` | `/api/rfqs/{rfq}/cancel` | `rfq.cancel` |
| `POST` | `/api/rfqs/{rfq}/close` | `rfq.close` |
| `GET` | `/api/rfqs/{rfq}/suppliers` | `rfq.distribution.read` (deterministic match preview) |
| `GET` | `/api/rfqs/{rfq}/distributions` | `rfq.distribution.read` |
| `POST` | `/api/rfqs/{rfq}/distributions` | `rfq.distribution.create` (submitted RFQs only; body: `supplier_profile_ids[]`) |
| `POST` | `/api/rfqs/{rfq}/distributions/{distribution}/withdraw` | `rfq.distribution.withdraw` |
| `GET` | `/api/rfqs/{rfq}/quotations` | `quotation.read` (non-draft) |
| `GET` | `/api/rfqs/{rfq}/quotations/compare` | `quotation.compare` (neutral; no score/winner) |
| `GET` | `/api/rfqs/{rfq}/quotations/{quotation}` | `quotation.read` |
| `GET` | `/api/rfqs/{rfq}/negotiations` | `negotiation.read` |
| `POST` | `/api/rfqs/{rfq}/items` | `rfq.update` (draft) |
| `PUT/PATCH` | `/api/rfqs/{rfq}/items/{item}` | `rfq.update` (draft) |
| `DELETE` | `/api/rfqs/{rfq}/items/{item}` | `rfq.update` (draft) |
| `GET` | `/api/rfqs/{rfq}/extractions` | `rfq.read` |
| `POST` | `/api/rfqs/{rfq}/extractions` | `rfq.read` |
| `POST` | `/api/proposals/{proposal}/approve` | `rfq.approve` |
| `POST` | `/api/proposals/{proposal}/reject` | `rfq.approve` |
| `GET` | `/api/suppliers/{supplier}/bank-account` | `bank.read` |
| `POST` | `/api/suppliers/{supplier}/bank-change-requests` | `bank.change_request` |
| `GET` | `/api/bank-change-requests/{id}` | `bank.read` |
| `POST` | `/api/bank-change-requests/{id}/approve` | `bank.approve` |
| `POST` | `/api/bank-change-requests/{id}/reject` | `bank.approve` |
| `GET` | `/api/supplier-profiles` | `supplier.profile.read` (buyers: eligible only; filters `q`, `product_category_id`, `brand_id`, `product_q`) |
| `GET` | `/api/supplier-profile` | `supplier.profile.read` (own) |
| `POST` | `/api/supplier-profile` | `supplier.profile.create` |
| `GET` | `/api/supplier-profiles/{id}` | `supplier.profile.read` |
| `PUT/PATCH` | `/api/supplier-profiles/{id}` | `supplier.profile.update` |
| `GET` | `/api/supplier/rfqs` | `supplier.rfq.read` (active distributions only) |
| `GET` | `/api/supplier/rfqs/{rfq}` | `supplier.rfq.read` |
| `POST` | `/api/supplier/rfq-distributions/{distribution}/quotation` | `quotation.create` |
| `GET` | `/api/supplier/quotations/{quotation}` | `quotation.read` |
| `PUT/PATCH` | `/api/supplier/quotations/{quotation}` | `quotation.update` (draft) |
| `DELETE` | `/api/supplier/quotations/{quotation}` | `quotation.delete` (draft) |
| `POST` | `/api/supplier/quotations/{quotation}/submit` | `quotation.submit` |
| `POST` | `/api/supplier/quotations/{quotation}/withdraw` | `quotation.withdraw` |
| `POST` | `/api/supplier/quotations/{quotation}/items` | `quotation.update` (draft) |
| `PUT/PATCH` | `/api/supplier/quotations/{quotation}/items/{item}` | `quotation.update` (draft) |
| `DELETE` | `/api/supplier/quotations/{quotation}/items/{item}` | `quotation.update` (draft) |
| `POST` | `/api/quotations/{quotation}/negotiation` | `negotiation.create` |
| `GET` | `/api/negotiations/{negotiation}` | `negotiation.read` |
| `GET` | `/api/negotiations/{negotiation}/offers` | `negotiation.read` |
| `POST` | `/api/negotiations/{negotiation}/offers` | `negotiation.offer.create` |
| `POST` | `/api/negotiations/{negotiation}/offers/{offer}/accept` | `negotiation.offer.accept` |
| `POST` | `/api/negotiations/{negotiation}/reject` | `negotiation.reject` |
| `POST` | `/api/negotiations/{negotiation}/withdraw` | `negotiation.withdraw` |
| `POST` | `/api/negotiations/{negotiation}/purchase-order` | `purchase_order.create` (accepted negotiation only; idempotent) |
| `GET` | `/api/purchase-orders` | `purchase_order.read` |
| `GET` | `/api/purchase-orders/{purchaseOrder}` | `purchase_order.read` |
| `POST` | `/api/purchase-orders/{purchaseOrder}/submit` | `purchase_order.submit` |
| `POST` | `/api/purchase-orders/{purchaseOrder}/cancel` | `purchase_order.cancel` |
| `POST` | `/api/purchase-orders/{purchaseOrder}/complete` | `purchase_order.complete` |
| `GET` | `/api/supplier/negotiations` | `negotiation.read` |
| `GET` | `/api/supplier/negotiations/{negotiation}` | `negotiation.read` |
| `GET` | `/api/supplier/negotiations/{negotiation}/offers` | `negotiation.read` |
| `GET` | `/api/supplier/purchase-orders` | `purchase_order.read` (non-draft) |
| `GET` | `/api/supplier/purchase-orders/{purchaseOrder}` | `purchase_order.read` |
| `POST` | `/api/supplier/purchase-orders/{purchaseOrder}/confirm` | `purchase_order.confirm` |
| `POST` | `/api/supplier/purchase-orders/{purchaseOrder}/reject` | `purchase_order.reject` (requires `reason`) |
| `POST` | `/api/purchase-orders/{purchaseOrder}/invoice` | `invoice.create` (confirmed PO only; supplier; idempotent) |
| `GET` | `/api/purchase-orders/{purchaseOrder}/invoice` | `invoice.read` |
| `GET` | `/api/invoices` | `invoice.read` (buyer company invoices) |
| `GET` | `/api/invoices/{invoice}` | `invoice.read` |
| `POST` | `/api/invoices/{invoice}/issue` | `invoice.issue` (draft → issued) |
| `POST` | `/api/invoices/{invoice}/cancel` | `invoice.cancel` (draft only) |
| `POST` | `/api/invoices/{invoice}/void` | `invoice.void` (issued only; requires `reason`) |
| `GET` | `/api/supplier/invoices` | `invoice.read` (supplier company invoices) |
| `GET` | `/api/supplier/invoices/{invoice}` | `invoice.read` |
| `GET` | `/api/product-categories` | `product.read` |
| `GET` | `/api/brands` | `product.read` |
| `POST` | `/api/brands` | `brand.create` |
| `GET` | `/api/products` | `product.read` (filters: `q`, category, brand, supplier, currency, moq; buyers = published only) |
| `POST` | `/api/products` | `product.create` (always starts `draft`) |
| `GET` | `/api/products/{product}` | `product.read` |
| `PUT/PATCH` | `/api/products/{product}` | `product.update` |
| `DELETE` | `/api/products/{product}` | `product.delete` |
| `POST` | `/api/products/{product}/transitions` | lifecycle transition (`product.update` / `product.review`) |
| `GET/PUT` | `/api/products/{product}/specifications` | `product.read` / `product.update` |
| `GET/PUT` | `/api/products/{product}/price-tiers` | `product.read` / `product.update` |

## Testing

```bash
php artisan test
```

Latest full run: **200 tests**, **1411 assertions**, **0 failures**, **0 errors**, **0 skipped**.

Coverage includes:

- Authentication and company association
- RFQ / distribution / quotation / negotiation workflows
- Purchase order creation from accepted negotiation
- PO lifecycle submit / confirm / reject / cancel / complete
- PO snapshot integrity vs product mutations
- PO number uniqueness and idempotent creation
- Invoice creation from confirmed PO (one invoice per PO)
- Invoice lifecycle issue / cancel / void
- Invoice snapshot integrity vs product/PO mutations
- Invoice number uniqueness and idempotent creation
- Cross-company isolation and spoofing defenses
- AI extraction / proposals / bank change / audit trails

**Not implemented:** payment processing, ZATCA / e-invoicing, VAT/tax calculation engines, credit notes, refunds, shipping, delivery, fulfillment, inventory, messaging, ratings/scoring, AI matching, automatic payment status, B2C checkout.

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
- Two buyer roles historically: `admin` (all permissions, no company) and `company_user` (no approve permissions). `supplier_user` is the supplier company role.
- Admin can read/update/approve across companies but cannot create an RFQ (no company context).
- New RFQs start as `draft`. Status is not an approval state machine.
- Only **submitted** RFQs can be distributed. Draft / cancelled / closed cannot receive new distributions.
- Matching requires structured catalog criteria (product and/or category from RFQ items). No artificial supplier ranking.
- Withdrawn distributions may be redistributed by reactivating the same unique row.
- Quotations require an active distribution belonging to the authenticated supplier. Submission requires every RFQ item to be quoted. Expired submitted quotes are marked `expired` on read when `valid_until` is past.
- Negotiations require an active submitted quotation. One open negotiation per quotation. Counter-offers alternate sides. Acceptance does not create a purchase order automatically — buyers create POs explicitly.
- Purchase orders require an accepted negotiation and snapshotted accepted-offer terms. One PO per negotiation.
- Invoices require a confirmed purchase order. One invoice per PO. Supplier creates/issues/cancels/voids; buyer reads. `paid` is reserved and not settable via public APIs. Invoice tax amount is preserved from the PO (no tax engine).
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
