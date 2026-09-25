# Sprint 18 — Company & Business Onboarding

Company remains the tenant. No second tenancy model. SupplierProfile unchanged.

## Domain objects

| Object | Purpose |
| --- | --- |
| `CompanyProfile` | 1:1 business/legal profile (legal name, registration, tax id, contacts, website) |
| `CompanyAddress` | Reusable registered / billing / shipping addresses |
| `CompanyContact` | Non-user business contacts |
| `CompanyInvitation` | Tenant-scoped invitations with hashed tokens |
| `User.is_active` | Member activation flag (server-managed) |

## API (auth:sanctum + active.company)

| Method | Path |
| --- | --- |
| GET/PUT/PATCH | `/api/company/profile` |
| CRUD | `/api/company/addresses` |
| CRUD | `/api/company/contacts` |
| GET / activate / deactivate | `/api/company/members` |
| GET/POST / revoke | `/api/company/invitations` |
| POST (public) | `/api/invitations/accept` |

Spoofed `company_id` / ownership fields are ignored; ownership is server-derived.

## Permissions (`{domain}.{action}`)

`company.profile.read|update`, `company.address.read|manage`, `company.contact.read|manage`, `company.member.read|manage`, `company.invitation.create|read|manage`

Granted to `company_user`, `supplier_user`, and `intermediary_user` (own company only).

## Invitation security

- Plain token returned **once** at create (`invitation_token`); never listed again
- Stored as SHA-256 `token_hash` (not reversible)
- Accept derives company + role from invitation; client `company_id` ignored
- Pending uniqueness via `pending_lock` (`company_id:email`)
- Expired / revoked / accepted tokens cannot be reused
- Cross-company invitation access → 404

## Member management

- Cannot deactivate self
- Cannot deactivate the last active member with `company.member.manage`
- Deactivation sets `is_active=false`, revokes Sanctum tokens
- `EnsureActiveCompanyMember` middleware blocks deactivated members (403)
- Login rejects deactivated members (403)

## Audit events

`company.profile.updated`, `company.address.*`, `company.contact.*`, `company.member.activated|deactivated`, `company.invitation.created|accepted|revoked|expired`

## Migration

`2026_09_24_180000_add_sprint_18_company_onboarding`

## Intentionally out of scope

Departments, approvals, email delivery subsystem, SSO/MFA, ZATCA, CRM, Sprint 19+ features.
