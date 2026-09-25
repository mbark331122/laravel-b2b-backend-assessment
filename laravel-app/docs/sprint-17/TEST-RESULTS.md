# Sprint 17 — Test Results

| Suite | Tests | Assertions | Failures | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: |
| Baseline (pre-change) | 252 | 3428 | 0 | 0 | 0 |
| Sprint17FoundationTest | 4 | 39 | 0 | 0 | 0 |
| MultiPartyConfidentialitySecurityTest | 8 | 233 | 0 | 0 | 0 |
| FullB2bLifecycle + FinalB2bSecurity | 7 | 390 | 0 | 0 | 0 |
| Full regression (post-change) | **256** | **3467** | **0** | **0** | **0** |

`php artisan migrate:fresh --seed --force` — OK (includes `2026_09_24_170000_add_sprint_17_query_indexes`).
