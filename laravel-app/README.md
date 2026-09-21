# Laravel Mini B2B Backend

B2B procurement and supplier commerce API (multi-tenant). Current codebase includes identity/tenancy, supplier profiles, wholesale product catalog, RFQs, supplier matching & distribution, quotations, negotiation with immutable counter-offers, purchase orders with supplier confirmation, commercial invoices (snapshotted from confirmed POs), payment foundation (manual lifecycle, no gateway), shipping & fulfillment foundation (one shipment per confirmed PO, no carrier APIs), buyer delivery confirmation (immutable receipt acknowledgment), returns/RMA foundation (buyer request + supplier review), RMA return logistics (return shipments for approved RMAs; no carrier APIs), RMA financial resolution foundation (credit notes + internal refund records after closed RMA; no external money movement), mocked AI extraction with human approval, supplier bank-change approval, and auditability.

**Sprint 16 finalized the core B2B scope** with end-to-end integration tests, cross-domain security hardening, and lifecycle consistency fixes. **Assessment 2** adds multi-party confidentiality on purchase orders (normalized parties, identity grants, intermediary commissions) — see [`docs/assessment-2/MULTI-PARTY-CONFIDENTIALITY.md`](docs/assessment-2/MULTI-PARTY-CONFIDENTIALITY.md).

There is no frontend. The API is the product.

**Sprint 0 architecture docs:** [`docs/sprint-0/`](docs/sprint-0/)
**Assessment 2 confidentiality:** [`docs/assessment-2/`](docs/assessment-2/)

## Architecture

This is a single Laravel 13 API application.

**Auth.** Laravel Sanctum issues API tokens. Login does not accept `company_id`. Tenant context is taken from the authenticated user (or from the owned resource for admin actions).

**Tenancy.** `Company` is the tenant. Companies are classified with `is_buyer` / `is_supplier` (set only by trusted backend/seeders — not client input). Company users have a `company_id`. Admin has no company and can operate across tenants when a permission allows it. Queries and policies scope company users to their own company. RFQ creation additionally requires a buyer company.

**Authorization.** Roles (`admin`, `company_user`, `supplier_user`, `intermediary_user`) own permissions. Policies enforce permission + tenant (and buyer/supplier/intermediary participation) on every sensitive operation. Multi-party identity and commission visibility are separate from transaction read — see Assessment 2 docs.

**Catalog.** Supplier companies own a `SupplierProfile` and wholesale `Product` rows (brand, specs, MOQ/max/increment, base price + tiers, lifecycle). Buyers may browse/view **published** products only. New supplier products start as `draft` and require lifecycle review before publication. Product ownership always comes from the authenticated supplier company — never from client `company_id` / `supplier_id` / `tenant_id`.

**RFQ.** Official RFQ rows are the system of record for buyer demand. Buyer companies create draft RFQs (legacy commodity fields remain supported for AI enrichment), attach line items with optional published-product snapshots, then submit/cancel/close through controlled transitions. Submitted RFQs are immutable via normal update/item endpoints.

**Supplier matching & distribution.** Buyers discover eligible suppliers (`GET /api/supplier-profiles`) and preview deterministic matches for a submitted RFQ (`GET /api/rfqs/{rfq}/suppliers`). Matching uses structured catalog data only (published products overlapping RFQ product IDs and/or categories) — no AI, scores, or reputation ranking. Results are ordered by `display_name`, then `id`. Buyers distribute to selected eligible suppliers (`POST /api/rfqs/{rfq}/distributions`). Distribution lifecycle: `pending` / `sent` / `withdrawn` (server-controlled). Deduplication: unique `(rfq_id, supplier_company_id)`; withdrawing then redistributing reactivates the same row. Suppliers may **read** only RFQs explicitly distributed to their company (`GET /api/supplier/rfqs`).

**Quotations.** Suppliers create draft quotations against an **active** distribution for their own company (`POST /api/supplier/rfq-distributions/{distribution}/quotation`). Lifecycle: `draft` → `submitted` → `withdrawn` | `expired`. Totals are server-calculated (`line_total = qty × unit_price`, `subtotal = Σ line_total`, `total = subtotal + shipping + tax`). Client-provided totals are ignored. At most one active (`draft`/`submitted`) quotation per distribution via `active_lock`; withdrawn/expired rows are not reused — a new draft may be created. Submitted quotes become immutable; expiration is evaluated on read when `valid_until` is past (no scheduler required). Buyers list/compare non-draft quotations on their own RFQs. Comparison is neutral (no score, rank, or winner).

**Negotiation.** Participants open a negotiation against an active submitted quotation (`POST /api/quotations/{quotation}/negotiation`). An immutable initial offer (sequence 1, supplier side) is snapshotted from the quotation. Counter-offers are append-only (`POST /api/negotiations/{negotiation}/offers`) with server-enforced alternating turns. Offers cannot be edited or deleted. Lifecycle: `open` → `accepted` | `rejected` | `withdrawn` | `expired`. Acceptance is by the opposite party on the latest proposed unexpired offer (transaction + row locks). After a quotation is **accepted**, a new negotiation cannot be opened for that quotation. Original quotation is never mutated. No ranking or automatic winner selection.

**Purchase Orders.** Buyers create a PO only from an **accepted** negotiation (`POST /api/negotiations/{negotiation}/purchase-order`). Commercial terms are snapshotted from the accepted offer (immutable). One PO per negotiation (unique `negotiation_id`); idempotent re-create returns the existing PO. Server-generated unique `number` (`PO-{YEAR}-{id}`). Lifecycle: `draft` → `pending_supplier_confirmation` → `confirmed` → `completed` (or `rejected` / `cancelled`). Supplier confirms/rejects after submit; buyer cancels while draft/pending; buyer completes confirmed POs.

**Multi-party confidentiality (Assessment 2).** The PO is the transaction anchor. Normalized `purchase_order_parties` support buyer, supplier, and N intermediaries. Identity visibility requires explicit `party_identity_grants` (classic direct trade grants mutual buyer↔supplier identity on create). Intermediary commissions are bound to a party row and visible only to that intermediary (or privileged admin). Enforcement is in `TransactionVisibilityService`, policies, and authorization-aware serialization — not the UI.

**Commercial Invoices.** Suppliers create an invoice only from a **confirmed** purchase order (`POST /api/purchase-orders/{purchaseOrder}/invoice`). Commercial values are snapshotted from the confirmed PO (immutable after create). One invoice per PO (unique `purchase_order_id`); idempotent re-create returns the existing invoice. Server-generated unique `number` (`INV-{YEAR}-{id}`). Lifecycle: `draft` → `issued` | `cancelled`; `issued` → `voided` | `paid` (`paid` only via payment mark-paid). Void is blocked while a pending or paid payment exists. No ZATCA, tax engines, shipping, or fulfillment. Credit notes / refunds are a separate financial-resolution domain (Sprint 15).

**Payments (foundation).** Buyers create a payment only for an **issued** invoice (`POST /api/invoices/{invoice}/payment`). Amount and currency are taken from the invoice total (client amounts ignored). Server-generated unique `number` (`PAY-{YEAR}-{id}`). Methods: `bank_transfer` / `cash` / `manual` (provider-independent labels only). Lifecycle: `pending` → `paid` | `failed` | `cancelled`. At most one pending payment per invoice (`active_lock`); after failed/cancelled a new payment may be created; a paid invoice cannot receive another payment. Supplier marks paid/failed (manual bookkeeping); buyer may cancel pending. Marking paid also transitions the invoice to `paid`. **No payment gateway, webhooks, wallets, bank APIs, or partial payments.** Internal refund records (Sprint 15) do not mutate payment amounts.

**Shipments (fulfillment foundation).** Suppliers create a shipment only from a **confirmed** purchase order (`POST /api/purchase-orders/{purchaseOrder}/shipment`). One shipment per PO (unique `purchase_order_id`); idempotent re-create returns the existing shipment. Server-generated unique `number` (`SHP-{YEAR}-{id}`). Line items and commercial values are snapshotted from the confirmed PO (full qty; no partial/split shipments). Immutable origin/destination address snapshots. Carrier / tracking / shipping_method are manual informational fields only. Lifecycle: `pending` → `processing` → `shipped` → `delivered`, or `pending`/`processing` → `cancelled`. Operational metadata updatable only while `pending`/`processing`. Independent of payment state. **No carrier APIs, tracking webhooks, warehouses, inventory, packages, or rate calculation.**

**Delivery Confirmation.** Buyers confirm receipt only for a **delivered** shipment (`POST /api/shipments/{shipment}/delivery-confirmation`). One confirmation per shipment (unique `shipment_id`); idempotent re-create returns the existing confirmation. Server-generated unique `number` (`DEL-{YEAR}-{id}`). Status is always `confirmed` and immutable (no update/delete/cancel). Does **not** auto-complete the PO, mark the invoice paid, create a payment, or create another shipment. Independent of payment status. Supplier may read related confirmations only. **No returns, RMA, disputes, refunds, ratings, or escrow.**

**Returns / RMA.** Buyers create an RMA only for a **delivered** shipment that already has a delivery confirmation (`POST /api/shipments/{shipment}/rma`). One **active** RMA per shipment (`active_lock`); a new RMA may be created only after the previous reaches `rejected` / `cancelled` / `closed`. Server-generated unique `number` (`RMA-{YEAR}-{id}`). Items snapshot shipment lines; return qty must be > 0 and ≤ shipped qty. Lifecycle: `requested` → `approved`|`rejected`|`cancelled`; `approved` → `received` → `closed`. Buyer cancels while requested; supplier approves/rejects/receives/closes. Does **not** refund payments, create credit notes, modify invoices/payments, change inventory, or create return shipments automatically.

**RMA Return Logistics.** Buyers create a return shipment only for an **approved** RMA (`POST /api/rmas/{rma}/return-shipment`). One **active** return shipment per RMA (`active_lock`). After a return is **delivered**, another return shipment cannot be created for that RMA (cancelled returns may be retried). Server-generated unique `number` (`RMA-RET-{YEAR}-{id}`). Items must belong to the RMA; qty ≤ RMA item qty; price snapshots from original shipment lines. Origin = buyer return address; destination = supplier. Lifecycle: `pending` → `shipped` → `delivered`, or `pending`/`shipped` → `cancelled`. Buyer updates logistics while pending and may cancel; supplier marks shipped/delivered. Does **not** auto-mark RMA received/closed, refund, or change inventory. **No carrier APIs or tracking webhooks.**

**RMA Financial Resolution.** Suppliers create a credit note only for a **closed** RMA that has a **delivered** return shipment (`POST /api/rmas/{rma}/credit-note`). Quantities come from RMA items; unit prices and currency from the original invoice snapshots; tax is proportional to returned merchandise subtotal. One credit note per RMA (unique `rma_id`); idempotent re-create returns the existing note. Server-generated unique `number` (`CN-{YEAR}-{id}`). Lifecycle: `draft` → `issued` | `cancelled`; `issued` → `voided`. Void is blocked once any refund record exists. After issue, suppliers may create an **internal** refund record (`POST /api/credit-notes/{creditNote}/refund`) for a paid payment on the same invoice chain — amount = credit note total (≤ paid amount). One refund per credit note (unique `credit_note_id`); number `REF-{YEAR}-{id}`. Refund lifecycle: `pending` → `processed` | `failed` | `cancelled` (requires credit note to remain `issued`). **“Processed” means recorded internally — not an external bank/gateway transfer.** Does **not** mutate invoice totals, payment amounts, PO, RMA, shipment, return shipment, or inventory. **No payment gateways, bank APIs, wallets, partial/split refunds, currency conversion, or ZATCA.**

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
Invoice 1──* Payment
PurchaseOrder 1──1 Shipment
Shipment 1──* ShipmentItem
Shipment 1──1 DeliveryConfirmation
Shipment 1──* Rma
Rma 1──* RmaItem
Rma 1──* ReturnShipment
ReturnShipment 1──* ReturnShipmentItem
Rma 1──1 CreditNote
CreditNote 1──* CreditNoteItem
CreditNote 1──1 Refund
Refund → Payment (paid)
AiExtraction 1──* RfqProposal

Supplier 1──1 SupplierBankAccount
SupplierBankAccount 1──* BankChangeRequest
SupplierBankAccount 1──* BankAccountHistory

AuditLog → actor (User), company (Company), auditable (morph)
```

- **Companies** — tenants with buyer/supplier classification (`is_buyer`, `is_supplier`). Seeded: Company A/B (buyer), Supplier Company / Supplier Company B (supplier).
- **Users** — `company_id` nullable (admin is null). `role_id` required. `company_id` / `role_id` are not fillable. Classification is read from the user's company.
- **Roles / Permissions** — `admin` has all permissions. `company_user` (buyer) has RFQ/quotation/negotiation permissions plus `purchase_order.read|create|submit|cancel|complete`, `purchase_order.party.read|manage|identity.read`, `invoice.read`, `payment.read|create|cancel`, `shipment.read`, `delivery_confirmation.read|create`, `rma.read|create|cancel`, `return_shipment.read|create|update|cancel`, and `credit_note.read` / `refund.read` (not commission/confidential). `supplier_user` has catalog/quotation/negotiation permissions plus `purchase_order.read|confirm|reject`, `purchase_order.party.read|identity.read`, `invoice.read|create|issue|cancel|void`, `payment.read|mark_paid|mark_failed`, `shipment.read|create|update|process|ship|deliver|cancel`, `delivery_confirmation.read`, `rma.read|approve|reject|receive|close`, `return_shipment.read|ship|deliver`, `credit_note.read|create|issue|cancel|void`, and `refund.read|create|process|fail|cancel`. `intermediary_user` has `purchase_order.read`, `purchase_order.party.read|identity.read`, and `purchase_order.commission.read` (own commission only). Platform-only: `brand.create`, `product.review`. Assessment 2 also defines `purchase_order.confidential.read` (admin / explicitly granted internals).
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
- **Invoices** — one per confirmed PO; unique `number`; snapshotted buyer/supplier/currency/totals from the PO; lifecycle `draft` / `issued` / `voided` / `paid` (via payment mark-paid) / `cancelled`.
- **Invoice Items** — immutable line snapshots from PO items (not live Product/PO joins after create).
- **Payments** — buyer-created against issued invoices; amount = invoice.total; unique `number`; methods `bank_transfer` / `cash` / `manual`; lifecycle `pending` / `paid` / `failed` / `cancelled`; one pending per invoice via `active_lock`.
- **Shipments** — one per confirmed PO; unique `number`; PO item snapshots; origin/destination address snapshots; lifecycle `pending` / `processing` / `shipped` / `delivered` / `cancelled`.
- **Shipment Items** — immutable fulfillment line snapshots from confirmed PO items (full quantity; no partials).
- **Delivery Confirmations** — one per delivered shipment; unique `number`; buyer-created immutable `confirmed` record; does not mutate PO/invoice/payment.
- **RMAs** — buyer-created against delivered+confirmed shipments; unique `number`; one active RMA per shipment; lifecycle `requested` / `approved` / `rejected` / `received` / `closed` / `cancelled`.
- **RMA Items** — immutable snapshots from shipment items with validated return quantities.
- **Return Shipments** — buyer-created logistics for approved RMAs; unique `number`; one active per RMA; lifecycle `pending` / `shipped` / `delivered` / `cancelled`.
- **Return Shipment Items** — immutable snapshots from RMA/shipment items with validated quantities and price snapshots.
- **Credit Notes** — supplier-created from closed RMA + delivered return shipment; unique `number`; one per RMA; qty from RMA / prices from invoice snapshots; lifecycle `draft` / `issued` / `cancelled` / `voided`.
- **Credit Note Items** — immutable historical lines (`unit_price_snapshot`, `line_total_snapshot`, `product_snapshot`).
- **Refunds** — internal financial records from issued credit notes against a paid payment; unique `number`; one per credit note; amount = credit note total; lifecycle `pending` / `processed` / `failed` / `cancelled`. Not external money movement.
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
- Payment relationships, amount, and currency are derived from the issued invoice. Client ownership IDs / `number` / amount / currency / status are ignored. Buyers create/cancel; suppliers mark paid/failed; neither side accesses another company’s payments.
- Shipment relationships and line items are derived from the confirmed PO. Client ownership IDs / `number` / status / item qty/prices are ignored. Suppliers create and transition; buyers read only. Address snapshots are historical shipping data, not ownership.
- Delivery confirmation relationships and confirming user are derived from the delivered shipment and authenticated buyer. Client ownership IDs / `number` / status / `confirmed_at` are ignored. Immutable after create.
- RMA relationships and items are derived from the delivered shipment + delivery confirmation. Client ownership IDs / `number` / status / product IDs are ignored. Buyer creates/cancels; supplier reviews. Does not mutate shipment/PO/invoice/payment.
- Return shipment relationships and items are derived from the approved RMA. Client ownership IDs / `number` / status are ignored. Buyer creates/updates/cancels; supplier ships/delivers. Does not auto-change RMA/invoice/payment.
- Credit note relationships, currency, and totals are derived from the closed RMA → invoice chain. Client ownership IDs / `number` / money fields / status are ignored. Buyer reads only; supplier creates/issues/cancels/voids only their own. Does not mutate invoice/payment/PO/RMA.
- Refund relationships, amount, and currency are derived from the issued credit note and paid payment. Client ownership IDs / `number` / amount / currency / status / `payment_id` are ignored. Buyer reads only; supplier creates/processes/fails/cancels. Does not execute external transfers or rewrite payment amounts.

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
| `payment.created` / `marked_paid` / `marked_failed` / `cancelled` | Payment lifecycle |
| `shipment.created` / `updated` / `processing` / `shipped` / `delivered` / `cancelled` | Shipment lifecycle |
| `delivery_confirmation.created` | Buyer confirms delivered shipment receipt |
| `rma.created` / `cancelled` / `approved` / `rejected` / `received` / `closed` | RMA lifecycle |
| `return_shipment.created` / `updated` / `shipped` / `delivered` / `cancelled` | Return shipment lifecycle |
| `credit_note.created` / `issued` / `cancelled` / `voided` | Credit note lifecycle |
| `refund.created` / `processed` / `failed` / `cancelled` | Internal refund record lifecycle |
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
| `POST` | `/api/invoices/{invoice}/payment` | `payment.create` (issued invoice only; buyer; amount from invoice) |
| `GET` | `/api/payments` | `payment.read` (buyer company payments) |
| `GET` | `/api/payments/{payment}` | `payment.read` |
| `POST` | `/api/payments/{payment}/mark-paid` | `payment.mark_paid` (pending → paid; supplier/admin) |
| `POST` | `/api/payments/{payment}/mark-failed` | `payment.mark_failed` |
| `POST` | `/api/payments/{payment}/cancel` | `payment.cancel` (pending only; buyer/admin) |
| `POST` | `/api/purchase-orders/{purchaseOrder}/shipment` | `shipment.create` (confirmed PO only; supplier; idempotent) |
| `GET` | `/api/purchase-orders/{purchaseOrder}/shipment` | `shipment.read` |
| `GET` | `/api/shipments` | `shipment.read` (buyer company shipments) |
| `GET` | `/api/shipments/{shipment}` | `shipment.read` |
| `PATCH` | `/api/shipments/{shipment}` | `shipment.update` (carrier/tracking/method/notes; pending/processing only) |
| `POST` | `/api/shipments/{shipment}/processing` | `shipment.process` |
| `POST` | `/api/shipments/{shipment}/ship` | `shipment.ship` |
| `POST` | `/api/shipments/{shipment}/deliver` | `shipment.deliver` |
| `POST` | `/api/shipments/{shipment}/cancel` | `shipment.cancel` |
| `POST` | `/api/shipments/{shipment}/delivery-confirmation` | `delivery_confirmation.create` (delivered only; buyer; idempotent) |
| `GET` | `/api/shipments/{shipment}/delivery-confirmation` | `delivery_confirmation.read` |
| `GET` | `/api/delivery-confirmations` | `delivery_confirmation.read` (buyer company) |
| `GET` | `/api/delivery-confirmations/{deliveryConfirmation}` | `delivery_confirmation.read` |
| `POST` | `/api/shipments/{shipment}/rma` | `rma.create` (delivered + delivery confirmation; buyer) |
| `GET` | `/api/rmas` | `rma.read` (buyer company) |
| `GET` | `/api/rmas/{rma}` | `rma.read` |
| `POST` | `/api/rmas/{rma}/cancel` | `rma.cancel` (requested only) |
| `POST` | `/api/rmas/{rma}/return-shipment` | `return_shipment.create` (approved RMA; buyer; idempotent active) |
| `GET` | `/api/rmas/{rma}/return-shipment` | `return_shipment.read` |
| `POST` | `/api/rmas/{rma}/credit-note` | `credit_note.create` (closed RMA + delivered return; supplier; idempotent) |
| `GET` | `/api/rmas/{rma}/credit-note` | `credit_note.read` |
| `GET` | `/api/return-shipments` | `return_shipment.read` (buyer company) |
| `GET` | `/api/return-shipments/{returnShipment}` | `return_shipment.read` |
| `PATCH` | `/api/return-shipments/{returnShipment}` | `return_shipment.update` (pending only) |
| `POST` | `/api/return-shipments/{returnShipment}/ship` | `return_shipment.ship` |
| `POST` | `/api/return-shipments/{returnShipment}/deliver` | `return_shipment.deliver` |
| `POST` | `/api/return-shipments/{returnShipment}/cancel` | `return_shipment.cancel` |
| `GET` | `/api/credit-notes` | `credit_note.read` (buyer company) |
| `GET` | `/api/credit-notes/{creditNote}` | `credit_note.read` |
| `POST` | `/api/credit-notes/{creditNote}/issue` | `credit_note.issue` |
| `POST` | `/api/credit-notes/{creditNote}/cancel` | `credit_note.cancel` |
| `POST` | `/api/credit-notes/{creditNote}/void` | `credit_note.void` (requires `reason`) |
| `POST` | `/api/credit-notes/{creditNote}/refund` | `refund.create` (issued credit note + paid payment; idempotent) |
| `GET` | `/api/refunds` | `refund.read` (buyer company) |
| `GET` | `/api/refunds/{refund}` | `refund.read` |
| `POST` | `/api/refunds/{refund}/process` | `refund.process` |
| `POST` | `/api/refunds/{refund}/fail` | `refund.fail` |
| `POST` | `/api/refunds/{refund}/cancel` | `refund.cancel` |
| `GET` | `/api/supplier/invoices` | `invoice.read` (supplier company invoices) |
| `GET` | `/api/supplier/invoices/{invoice}` | `invoice.read` |
| `GET` | `/api/supplier/payments` | `payment.read` (supplier company payments) |
| `GET` | `/api/supplier/payments/{payment}` | `payment.read` |
| `GET` | `/api/supplier/shipments` | `shipment.read` (supplier company shipments) |
| `GET` | `/api/supplier/shipments/{shipment}` | `shipment.read` |
| `GET` | `/api/supplier/delivery-confirmations` | `delivery_confirmation.read` (supplier company) |
| `GET` | `/api/supplier/delivery-confirmations/{deliveryConfirmation}` | `delivery_confirmation.read` |
| `GET` | `/api/supplier/rmas` | `rma.read` (supplier company) |
| `GET` | `/api/supplier/rmas/{rma}` | `rma.read` |
| `POST` | `/api/supplier/rmas/{rma}/approve` | `rma.approve` |
| `POST` | `/api/supplier/rmas/{rma}/reject` | `rma.reject` (requires `rejection_reason`) |
| `POST` | `/api/supplier/rmas/{rma}/received` | `rma.receive` |
| `POST` | `/api/supplier/rmas/{rma}/close` | `rma.close` |
| `GET` | `/api/supplier/return-shipments` | `return_shipment.read` (supplier company) |
| `GET` | `/api/supplier/return-shipments/{returnShipment}` | `return_shipment.read` |
| `GET` | `/api/supplier/credit-notes` | `credit_note.read` (supplier company) |
| `GET` | `/api/supplier/credit-notes/{creditNote}` | `credit_note.read` |
| `GET` | `/api/supplier/refunds` | `refund.read` (supplier company) |
| `GET` | `/api/supplier/refunds/{refund}` | `refund.read` |
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

Latest full run: **252 tests**, **3428 assertions**, **0 failures**, **0 errors**, **0 skipped**.

Assessment 2 focused (`MultiPartyConfidentialitySecurityTest`): **8 tests**, **233 assertions**, **0 failures**.

Sprint 16 focused (`FullB2bLifecycle*` + `FinalB2bSecurity*`): **7 tests**, **390 assertions**, **0 failures**.

Coverage includes:

- Authentication and company association
- RFQ / distribution / quotation / negotiation workflows
- Purchase order creation from accepted negotiation
- PO lifecycle submit / confirm / reject / cancel / complete
- PO snapshot integrity vs product mutations
- PO number uniqueness and idempotent creation
- Multi-party confidentiality: identity grants, commission isolation, N intermediaries (Assessment 2)
- Multi-party IDOR / search / export-view / mass-assignment bypass attempts (Assessment 2)
- Invoice creation from confirmed PO (one invoice per PO)
- Invoice lifecycle issue / cancel / void
- Invoice void blocked when pending/paid payment exists
- Invoice snapshot integrity vs product/PO mutations
- Invoice number uniqueness and idempotent creation
- Payment creation from issued invoice (amount from invoice.total)
- Payment lifecycle mark-paid / mark-failed / cancel
- Duplicate pending payment prevention (`active_lock`)
- Shipment creation from confirmed PO (one shipment per PO)
- Shipment lifecycle processing / ship / deliver / cancel
- Shipment item and address snapshot integrity
- Delivery confirmation from delivered shipment (one per shipment)
- Delivery confirmation immutability and payment/PO independence
- RMA creation after delivery confirmation; supplier review lifecycle
- RMA quantity validation and active-RMA uniqueness
- Return shipment for approved RMA; ship/deliver/cancel lifecycle
- Return shipment blocked after delivered (retry only after cancel)
- Return shipment item quantity and ownership validation
- Credit note from closed RMA + delivered return; qty/price snapshots
- Credit note lifecycle draft → issued | cancelled; issued → voided
- Credit note void blocked when refund exists
- Accepted negotiation cannot be reopened for a second commercial outcome
- Internal refund from issued credit note + paid payment
- Refund lifecycle pending → processed | failed | cancelled
- Credit note / refund isolation, spoofing defenses, and permission checks
- Full end-to-end RFQ → refund integration (Sprint 16)
- Final cross-domain security integration suite (Sprint 16)
- Cross-company isolation and spoofing defenses
- AI extraction / proposals / bank change / audit trails

**Not implemented (final core scope exclusions):** B2C, generic marketplace functionality, subscriptions, messaging, ratings/reviews, AI product features beyond the existing mock RFQ extractor, inventory, warehouse management, carrier integrations, payment gateways, external refund APIs, bank APIs, wallets, ZATCA / e-invoicing, analytics, dashboards, VAT/tax calculation engines, packages, split/partial shipments, disputes, escrow, replacement/exchange orders, partial/split refund engines, currency conversion.

## AI / Cursor Usage

This project was implemented in Cursor across fixed sprints (0–16). The AI coding agent generated the Laravel application, migrations, models, policies, controllers, seeders, tests, and this README from the assessment prompts. Sprint 16 is the final core hardening and verification sprint.

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
- Negotiations require an active submitted quotation. One open negotiation per quotation. After acceptance, a new negotiation cannot be opened for that quotation. Counter-offers alternate sides. Acceptance does not create a purchase order automatically — buyers create POs explicitly.
- Purchase orders require an accepted negotiation and snapshotted accepted-offer terms. One PO per negotiation.
- Invoices require a confirmed purchase order. One invoice per PO. Supplier creates/issues/cancels/voids; buyer reads. Invoice `paid` is set only when a payment is marked paid (not via invoice create payloads). Void blocked while pending/paid payment exists. Invoice tax amount is preserved from the PO (no tax engine).
- Payments require an issued invoice. Buyer creates; amount = invoice.total. One pending payment per invoice. Supplier marks paid/failed; buyer cancels pending. No payment gateway or automatic money movement.
- Shipments require a confirmed purchase order (independent of payment state). One shipment per PO. Supplier creates/updates/transitions; buyer reads. No carrier integrations or inventory.
- Delivery confirmations require a delivered shipment. Buyer creates; supplier reads. One confirmation per shipment. Does not complete the PO or change invoice/payment state. No disputes or ratings.
- RMAs require a delivered shipment with delivery confirmation. Buyer creates/cancels; supplier approves/rejects/receives/closes. One active RMA per shipment. Does not refund, adjust invoices, or modify inventory.
- Return shipments require an approved RMA. Buyer creates/updates/cancels; supplier ships/delivers. One active return shipment per RMA; no second create after delivered. Does not auto-change RMA received/closed or financial records.
- Credit notes require a closed RMA with a delivered return shipment and an invoice on the same PO. Supplier creates/issues/cancels/voids; buyer reads. One credit note per RMA. Amounts from RMA qty × invoice unit prices; tax proportional. Void blocked once a refund exists. Does not mutate invoice/payment.
- Refunds require an issued credit note and a paid payment on the same invoice. Supplier creates/processes/fails/cancels; buyer reads. One refund per credit note. Amount = credit note total (≤ paid amount). Lifecycle actions require credit note to remain issued. Internal bookkeeping only — no gateway/bank transfer.
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
