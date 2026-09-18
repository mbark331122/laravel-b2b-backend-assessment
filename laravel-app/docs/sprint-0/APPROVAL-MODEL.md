# Approval Model

## 1. Purpose

Official business records that are approval-gated change **only** after an explicit approve action by an authorized actor. Proposals and change requests are first-class records. Rejection preserves official values.

## 2. Core states

Shared vocabulary for approval-gated artifacts:

| State | Meaning |
| --- | --- |
| `pending` | Proposed; official record unchanged |
| `approved` | Applied; official record updated from **stored** proposed values |
| `rejected` | Closed; official record unchanged |

## 3. Official-write rule

```
Client request body on approve/reject
    ≠ source of truth for values to apply

Stored proposal / change request
    = source of truth for values applied on approve
```

This is already implemented for:

- `RfqProposal::approve()` — applies stored `proposed_value`
- `BankChangeRequest::approve()` — applies stored `proposed_iban`

All future approval workflows must follow the same rule.

## 4. Existing workflows

### 4.1 AI field proposal → RFQ official fields

```
AiExtraction (pending)
  + RfqProposal (pending) when field differs
      → approve (rfq.approve)
          → RFQ field := stored proposed_value
          → proposal/extraction marked approved
          → audit
      → reject (rfq.approve)
          → RFQ unchanged
          → proposal marked rejected
          → audit
```

### 4.2 Bank IBAN change

```
BankChangeRequest (pending)
  → approve (bank.approve)
      → write BankAccountHistory snapshot
      → official IBAN := stored proposed_iban
      → request approved
      → audit
  → reject (bank.approve)
      → IBAN unchanged
      → audit
```

Verification (Sprint 9) sits before/with approval as defined in that sprint; it must not become a silent official write.

## 5. Future approval surfaces (by sprint)

| Sprint | Approval-gated concern |
| --- | --- |
| 4 | AI extraction proposals (exists) |
| 7 | Negotiation commercial changes where workflow requires approval |
| 8 | Conversion into PO only from approved commercial outcome |
| 9 | Bank change verification/approval (exists; align) |
| 13 | Dispute resolution transitions |
| 15 | Admin approval operations surfaces |

Not every edit needs approval. Direct edits remain allowed only where policy says so (e.g. buyer updating own draft RFQ fields today).

## 6. Implementation conventions

1. Prefer `approve(?string $reason)` / `reject(?string $reason)` on the proposal/request model.
2. Wrap official mutation + status flip + audit in `DB::transaction`.
3. Use `lockForUpdate()` on the official row when applying approval.
4. Controllers authorize, then call model workflow methods — they do not manually copy request values onto official rows.
5. Optional `reason` is allowed and audited when provided.

## 7. Permission binding

| Workflow | Permission (minimum) |
| --- | --- |
| AI proposal approve/reject | `rfq.approve` |
| Bank change approve/reject | `bank.approve` |
| Future domain approvals | dedicated `{domain}.approve` (or equivalent) added in owning sprint |

Company operational users must not receive approval permissions by default.

## 8. Testing invariants

For each approval workflow:

1. Unauthorized actor cannot approve/reject.
2. Pending leaves official values untouched.
3. Reject leaves official values untouched.
4. Approve changes official values to **stored** proposed values only.
5. Request-body spoofed values on approve are ignored.
6. Audit rows exist for approve/reject (and for official change when applicable).
