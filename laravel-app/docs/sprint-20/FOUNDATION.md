# Sprint 20 — Notifications & Activity Delivery Foundation

In-app notification inbox only. No email/SMS/push/queues. AuditLog remains the security audit mechanism.

## Architecture

```
Domain event (controller / ApprovalWorkflowService after commit)
  → DomainNotificationPublisher (recipient rules + Assessment 2-safe copy)
    → NotificationService (persist + dedupe)
      → notifications table (company_id + user_id scoped)
```

## Lifecycle

`unread` (`read_at` null) → `read` (`read_at` set). Never deleted on read.

API:

| Method | Path |
| --- | --- |
| GET | `/api/notifications` (default `per_page=25`, newest first) |
| GET | `/api/notifications/{id}` |
| POST | `/api/notifications/{id}/read` |
| POST | `/api/notifications/read-all` |

No client create endpoint. Spoofed `company_id` / `recipient_id` ignored (server-owned).

## Permissions

`notification.read`, `notification.mark_read` — granted to company_user, supplier_user, intermediary_user.

## Tenant / recipient isolation

- Every row has exactly one `company_id` and one recipient `user_id`
- Queries always filter both current user and company
- Cross-user / cross-company → **404**

## Idempotency

Unique `(user_id, dedupe_key)` where `dedupe_key = {type}:{related}:{id}[:suffix]`.

## Assessment 2

Titles/bodies omit party names. Metadata strips `buyer_company*`, `supplier_company*`, commission, confidential fields.

## Supported events

RFQ submitted/distributed; PO submitted/confirmed/rejected/completed; Payment created/paid/failed/cancelled; Shipment created/shipped/delivered/cancelled; Delivery confirmation; RMA created/approved/rejected/received/closed; Return shipment created/shipped/delivered/cancelled; Credit note issued/cancelled/voided; Refund processed/failed/cancelled; Approval request created/approved/rejected/cancelled + step required.

## Intentionally out of scope

Email/SMS/push, preferences, digests, webhooks, Redis/Kafka, Sprint 21+.
