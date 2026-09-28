# Sprint 17 — Baseline Report

**Branch:** `cursor/sprint-17-production-foundation-2e4b`  
**Base tip:** `origin/cursor/assessment-2-multi-party-confidentiality-2e4b` @ `3f3ce9f`  
**Date:** 2026-09-24

## Environment

| Item | Value |
| --- | --- |
| PHP | 8.4.25 |
| Laravel | 13.30.1 |
| Working tree at baseline | Clean (branch created from Assessment 2 tip) |

## Pre-change verification

| Check | Result |
| --- | --- |
| `php artisan migrate:fresh --seed --force` | OK |
| `php artisan test` | **252 tests**, **3428 assertions**, **0 failures**, **0 errors**, **0 skipped** |

## Scope

Sprint 17 = Scope 2 foundation only. No new business features. Preserve Sprint 16 hardening and Assessment 2 confidentiality.

## Audit priorities (actionable)

1. Remove write/`lockForUpdate` from `serializePurchaseOrder` read path
2. Quotation create race (exists check outside transaction)
3. PO / parties list N+1 (eager-load parties/company)
4. Missing indexes with concrete query justification
5. Optional pagination foundation (`per_page`) without breaking existing contracts
6. Audit on quotation/negotiation auto-expiration
7. Document intentionally unchanged areas (denyAsNotFound, server totals, Assessment 2, Sprint 16 locks)
