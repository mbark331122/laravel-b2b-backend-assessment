# Assessment 2 — Multi-Party Confidentiality

Server-side confidentiality for multi-party B2B purchase orders (Buyer → Intermediary N → Supplier). UI hiding is not a control.

## 1. Data Model

**Transaction anchor:** `PurchaseOrder` (existing commercial boundary). No parallel Deal/Transaction entity.

Side tables:

| Table | Purpose |
| --- | --- |
| `purchase_order_parties` | Normalized participation (`company_id`, `role`, `status`, `sequence`, `joined_at`) |
| `party_identity_grants` | Explicit viewer→visible identity authorization |
| `intermediary_commissions` | One commission per intermediary party (unique `purchase_order_party_id`) |
| `purchase_order_confidential_notes` | Internal-only notes |

No `intermediary_1_id` / `intermediary_2_id` columns.

## 2. Transaction Party Model

`PurchaseOrderParty` roles: `buyer`, `supplier`, `intermediary`, `internal_operator`.

- Unique `(purchase_order_id, company_id)`.
- Multiple intermediaries distinguished by rows + `sequence`, not fixed columns.
- Core buyer/supplier rows created on PO create (`ensureCoreParties`); classic direct trade grants mutual identity only when both cores are newly created.

Participation ≠ identity visibility.

## 3. Visibility Model

Central authority: `TransactionVisibilityService`.

Categories:

| Category | Examples |
| --- | --- |
| `PUBLIC_TO_TRANSACTION` | PO number/status/currency; party role labels without company |
| `PARTY_ONLY` | Own identity; own commission |
| `AUTHORIZED_PARTIES` | Other party identity when an explicit grant exists |
| `INTERNAL_ONLY` / `CONFIDENTIAL` | Confidential notes; cross-party commissions for privileged internals |

Evaluation context: User + Company + PO + Party + permission + grant + requested resource.

## 4. Permission Model

| Permission | Controls |
| --- | --- |
| `purchase_order.read` | Transaction access (scoped by participation / buyer / supplier / admin) |
| `purchase_order.party.read` | List/show party rows (identity still gated) |
| `purchase_order.party.manage` | Add intermediaries, set commissions, grant/revoke identity (buyer company or admin) |
| `purchase_order.party.identity.read` | Required to resolve any company identity (including own) |
| `purchase_order.commission.read` | Required to read commissions; non-admin sees **own** only |
| `purchase_order.confidential.read` | Confidential notes |

Role grants:

- `company_user`: party read/manage/identity (not commission/confidential)
- `supplier_user`: party read/identity (not manage/commission/confidential)
- `intermediary_user`: read + party read + identity + own commission
- `admin`: full catalog

`transaction.read` alone never unlocks identities, commissions, or confidential notes.

## 5. Commission Confidentiality

`IntermediaryCommission` belongs to a specific intermediary `PurchaseOrderParty`.

- Intermediary I sees only I’s commission.
- Intermediary J cannot GET I’s commission by ID (`denyAsNotFound`).
- Buyer/supplier without `commission.read` get 403 on list and 404 on direct ID.
- Admin with `commission.read` may view all (audited).

## 6. API Enforcement

Routes (under Sanctum):

- `GET/POST /purchase-orders/{purchaseOrder}/parties`
- `GET .../parties/{party}`, `GET .../parties/search`
- `POST .../identity-grants`, `POST .../identity-grants/revoke`
- `GET/POST .../commissions`, `GET /commissions/{commission}`
- `GET/POST .../confidential-notes`
- `GET .../export-view`, `GET .../visibility`

All Purchase Order JSON responses that return a PO representation — including lifecycle mutations (`create`, `submit`, `cancel`, `complete`, `confirm`, `reject`) — use `TransactionVisibilityService::serializePurchaseOrder` for the authenticated actor. Ungated `PurchaseOrder::toApiArray()` is not used for API response bodies.

Policies: `PurchaseOrderPolicy`, `PurchaseOrderPartyPolicy`, `IntermediaryCommissionPolicy`.

Cross-PO party/commission mismatch → 404. Client `include=confidential|commissions` ignored. Spoofed `role` / `visibility` / `company_id` / `is_internal` on write are ignored or rejected by server rules.

## 7. Query-Level Enforcement

- PO lists: `scopeVisibleTo` includes party participation.
- Commissions: `visibleCommissions()` filters by admin vs own `purchase_order_party_id` before serialization.
- Search: hidden identities excluded from name/id match (no existence leak).
- Unauthorized commission access: `denyAsNotFound` (no enumeration).

## 8. Serialization Enforcement

`serializePurchaseOrder` / `serializeParty` / `serializeCommission` are authorization-aware.

Unauthorized identity → `"company": null` (no company id/name/email).
Unauthorized commission → omitted / 404 (not redacted after full load in responses).

`$hidden` alone is insufficient; contextual serializers are required.

## 9. Export / Search Protection

No full export product. `exportRepresentation()` applies the same visibility rules for a future export path (`GET .../export-view`).

Search (`GET .../parties/search?q=`) never matches hidden company names/ids.

## 10. Audit Logging

Uses existing `AuditLogger` / `audit_logs`:

| Action | When |
| --- | --- |
| `purchase_order.party.added` | Intermediary added |
| `purchase_order.party.identity_granted` / `_revoked` | Visibility change |
| `purchase_order.party.identity_viewed` | Sensitive party show |
| `purchase_order.commission.set` / `_viewed` | Write / sensitive read |
| `purchase_order.confidential.created` / `_viewed` | Note create / read |

Payloads carry ids/roles/context — not raw commission amounts or note bodies on view events where avoidable.

## 11. Multi-Intermediary Scalability

N intermediaries = N `purchase_order_parties` rows with `role=intermediary` + optional sequence. Each may have its own commission and independent identity grants. Code iterates parties; no schema change for Intermediary 3…N.

## 12. Security Threat Model

| Threat | Mitigation |
| --- | --- |
| IDOR / BOLA | Policies + `denyAsNotFound`; participation required |
| Tenant isolation failure | `visibleTo` + party membership + company checks |
| Mass assignment | Empty `Fillable` on party/commission; server-controlled fields |
| Privilege escalation | Separate identity/commission/confidential permissions |
| Hidden-field / serialization leak | Contextual serializers; null company object |
| Relationship / eager-load leak | Authorized serializers; no confidential `with()` on public responses |
| Query / filter / search leak | Search skips non-visible identities |
| Export leak | Shared `exportRepresentation` |
| Enumeration | 404 for inaccessible commissions/parties/POs |
| Route model binding bypass | Belonging asserts + policy after resolve |
| Commission leakage | Own-party query filter + policy |
| Insider overreach | Admin still needs specific permissions; least-privilege internal tested |
| Unauthorized party creation | `party.manage` + buyer-company (or admin) |
| Visibility escalation | Grants only via manage endpoint; ensureCoreParties never re-grants later |
| Race on party/visibility | DB locks in add/grant/commission setters |
