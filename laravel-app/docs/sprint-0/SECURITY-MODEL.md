# Security Model

## 1. Non-negotiable principles

1. Multi-tenant isolation is enforced **backend-side only**.
2. Frontend checks are never trusted.
3. A user from Company A must never read or mutate Company B data by manipulating:
   - `company_id`
   - resource IDs
   - URLs
   - request bodies
   - query parameters
4. Authorization is enforced with Laravel Policies / Gates on every sensitive operation.
5. AI has **no** direct authority over official business records.
6. Sensitive changes use controlled approval workflows.
7. Important changes are auditable (see [AUDIT-MODEL.md](./AUDIT-MODEL.md)).

## 2. Tenant model

| Concept | Rule |
| --- | --- |
| Tenant root | `Company` |
| User membership | `users.company_id` (nullable for platform admin) |
| Resource ownership | Stored FK on the resource (or parent aggregate) |
| Request `company_id` | Ignored for ownership assignment and tenancy decisions |

### Classification (Sprint 1)

Companies are classified as buyer and/or supplier. Authorization must still key off:

- authenticated user
- permission
- resource tenant membership / participation

not off client-supplied company type fields alone.

## 3. Actor types

| Actor | `company_id` | Cross-tenant |
| --- | --- | --- |
| Platform admin | `null` (current pattern) | Allowed only when permission grants the action |
| Buyer company user | buyer company | Denied (404) outside own tenant |
| Supplier company user | supplier company | Denied (404) outside own tenant / non-eligible resources |

Admin cannot invent tenant context from the client. Where create requires a company (e.g. RFQ create today), admin without a company is denied by policy.

## 4. Authorization algorithm

For every sensitive action:

```
1. Authenticate (auth:sanctum) unless public login
2. Resolve target resource (route binding)
3. Check permission for action
4. Check tenant / participation rule
5. Allow OR
   - deny 403 if permission missing on an otherwise visible tenant resource
   - deny 404 if resource is outside tenant visibility (denyAsNotFound)
```

### Existing pattern to preserve

`RfqPolicy` / `SupplierPolicy` use:

- permission check first
- `tenantResponse()` comparing resource company to user company
- admin skips company equality after permission succeeds
- `denyAsNotFound()` for cross-tenant

New policies must follow the same response semantics.

## 5. List / query isolation

- Index endpoints must scope via `visibleTo($user)` (or domain equivalent).
- Query parameter `company_id` must not expand visibility for non-admin users.
- Admin list scope may be global only when permission allows.

## 6. Create isolation

On create:

- Set tenant from `$request->user()->company` or from an already-authorized parent resource.
- Never set tenant from request body `company_id`.
- Extra IDs in the body (`rfq_id`, `supplier_id`, etc.) must not re-parent resources unless the sprint defines an authorized admin operation.

## 7. Update isolation

- Authorize against the stored resource tenant.
- Ignore attempts to change `company_id`.
- Sensitive fields under approval workflows are not directly writable.

## 8. AI safety boundary

```
Input text
  → extractor (parse only)
  → store AiExtraction + RfqProposal if conflict
  → official RFQ unchanged
  → human approval with rfq.approve
  → official field updated from stored proposed_value
```

Forbidden:

- Extractor loading/saving RFQ official fields
- Controllers applying AI output directly onto RFQ
- Trusting approval request body as the value to apply

## 9. Banking safety boundary

```
Change request (proposed IBAN stored)
  → official IBAN unchanged
  → verification/approval (bank.approve)
  → history snapshot + official IBAN = stored proposed_iban
```

Forbidden:

- Mass-assign IBAN
- Direct PUT/PATCH IBAN endpoints
- Applying request-body IBAN on approve

## 10. Transaction participation (future sprints)

For quotations, negotiation, PO, messaging, disputes:

- Access requires tenant participation in the transaction (buyer side, supplier side, or admin permission).
- Resource ID guessing across tenants returns 404.
- Supplier RFQ visibility additionally requires match/distribution eligibility (Sprint 5).

## 11. Security testing requirements (every relevant sprint)

Mandatory scenarios (accumulate toward Sprint 16):

- Cross-tenant resource access
- Manipulated `company_id`
- Manipulated resource IDs
- Unauthorized approval
- Unauthorized bank changes
- AI direct modification attempts
- Unauthorized quotation / PO / shipment / dispute access (as those domains exist)

Existing suite under `tests/Feature/Security/` is the template.
