# Sprint 20 — Test Results

| Suite | Tests | Assertions | Failures | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: |
| Baseline (Sprint 19 tip) | 288 | 3670 | 0 | 0 | 0 |
| NotificationFoundation + Security | 12 | ~67 | 0 | 0 | 0 |
| Full regression (post-change) | **300** | **3739** | **0** | **0** | **0** |

`php artisan migrate:fresh --seed --force` — OK (includes `2026_09_25_200000_create_notifications_table`).
