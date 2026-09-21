# Assessment 2 — Test Results

Commands run in `laravel-app` after implementation:

```bash
php artisan migrate:fresh --seed --force   # OK
php artisan test --filter=MultiPartyConfidentialitySecurityTest
php artisan test
```

| Suite | Tests | Assertions | Failures | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: |
| MultiPartyConfidentialitySecurityTest | 7 | 203 | 0 | 0 | 0 |
| Full regression (`php artisan test`) | 251 | 3398 | 0 | 0 | 0 |

Threats exercised in the focused suite: buyer/supplier identity hiding, intermediary commission isolation, IDOR by commission/party/PO id, search non-discovery, export-view redaction, mass-assignment spoofing, include probes, least-privilege internal access + audit, N-intermediary add without schema change, classic mutual-identity regression.
