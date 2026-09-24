# Sprint 18 — Baseline Report

**Branch:** `cursor/sprint-18-company-onboarding-2e4b`  
**Base tip:** `origin/cursor/sprint-17-production-foundation-2e4b` @ `a5e9721`  
**Date:** 2026-09-24

## Pre-change verification

| Check | Result |
| --- | --- |
| Working tree | Clean |
| `php artisan test` | **256 tests**, **3467 assertions**, **0 failures**, **0 errors**, **0 skipped** |

## Existing foundation (verified)

| Area | State |
| --- | --- |
| Company | Tenant shell: `name`, `is_buyer`, `is_supplier` only |
| User | `company_id` + `role_id` (non-fillable); no `is_active` |
| SupplierProfile | Separate supplier trading profile — must not be duplicated |
| Company profile/addresses/contacts/invitations | **None** |
| Permissions `company.*` | **None** |
| Mail/Notification app classes | **None** (skip outbound email subsystem) |

## Scope

Sprint 18 = Company & Business Onboarding only. Preserve Sprint 16–17 and Assessment 2.
