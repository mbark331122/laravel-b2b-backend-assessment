# Permission Model

## 1. Goals

- Backend permissions gate all sensitive operations.
- Minimum catalog required by product scope (already present) remains stable.
- Catalog is **extensible** per domain without renaming existing keys.
- Roles grant permissions; policies enforce permission + tenant.

## 2. Existing minimum catalog (must keep)

| Permission | Constant | Typical grant |
| --- | --- | --- |
| `rfq.read` | `Permission::RFQ_READ` | admin, company_user |
| `rfq.create` | `Permission::RFQ_CREATE` | admin*, company_user |
| `rfq.update` | `Permission::RFQ_UPDATE` | admin, company_user |
| `rfq.approve` | `Permission::RFQ_APPROVE` | admin only (today) |
| `bank.read` | `Permission::BANK_READ` | admin, company_user |
| `bank.change_request` | `Permission::BANK_CHANGE_REQUEST` | admin, company_user |
| `bank.approve` | `Permission::BANK_APPROVE` | admin only (today) |

\*Admin RFQ create is currently denied by policy when `company_id` is null — permission alone is insufficient without tenant context.

Defined in `app/Models/Permission.php` and seeded by `PermissionSeeder` / `RoleSeeder`.

## 3. Current roles

| Role | Permissions |
| --- | --- |
| `admin` | All `Permission::names()` |
| `company_user` | `Permission::companyUserNames()` (all except `rfq.approve`, `bank.approve`) |

## 4. Extension rules (Sprints 1–15)

1. **Naming:** `{domain}.{action}` lowercase dotted strings.
2. **Constants:** add on `Permission` (or domain-grouped constants) and include in `names()`.
3. **Role grants:** update seeders/tests in the same sprint.
4. **No silent reuse:** do not overload `rfq.approve` for unrelated domains; add e.g. `quotation.approve`, `po.approve` when needed.
5. **Buyer vs supplier:** Sprint 1 may introduce distinct company roles (e.g. buyer_user, supplier_user) **or** keep role names and gate by company classification + permissions. Either approach is allowed if tenant isolation and permission checks remain backend-enforced. Do not remove the minimum RFQ/bank permissions.

## 5. Planned permission namespaces (by domain)

These are the **target namespaces** for later sprints. Exact action names are finalized in the owning sprint; this list locks the domains only.

| Domain | Example permission prefixes |
| --- | --- |
| Identity / admin | `company.*`, `user.*`, `role.*`, `permission.*` |
| Catalog | `product.*`, `supplier.profile.*`, `supplier.verify` |
| RFQ | existing `rfq.*` (+ lifecycle actions if required) |
| AI proposals | covered by `rfq.read` / `rfq.approve` unless split later |
| Matching | `rfq.distribute`, `rfq.match.read` (examples) |
| Quotations | `quotation.read`, `quotation.create`, `quotation.update`, … |
| Negotiation | `negotiation.read`, `negotiation.offer`, … |
| Purchase orders | `po.read`, `po.create`, `po.update`, … |
| Banking | existing `bank.*` |
| Payments | `payment.*` |
| Shipment / delivery | `shipment.*`, `delivery.*` |
| Messaging | `message.*` |
| Disputes | `dispute.*` |
| Reports | `report.*` |
| Audit admin | `audit.read` |

## 6. Enforcement points

| Layer | Responsibility |
| --- | --- |
| `User::hasPermission()` | Role→permission resolution |
| Policies | Map ability names to permission + tenant |
| Controllers | `$this->authorize(...)` |
| Form requests | `authorize()` when validating updates |

Ability names should stay aligned with policy methods (`view`, `create`, `update`, `approve`, …).

## 7. Sprint 1 expectation

Sprint 1 must:

- Preserve existing seven permissions and their security tests.
- Add buyer/supplier company classification.
- Extend roles/permissions only as required for identity/tenancy/audit foundation.
- Keep approval permissions out of standard operational company users unless a later sprint explicitly redesigns grants (default: approvals remain privileged).
