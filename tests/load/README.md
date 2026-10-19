# Load profiles (k6)

Profiles for the 100-tenant scale seed (`php artisan tenants:seed-scale`). They sign in as one owner per run, so
pick a tenant: `-e EMAIL=owner@tenant-007.test`.

```bash
k6 run -e BASE=http://localhost tests/load/browse.js     # task-management read mix
k6 run -e BASE=http://localhost tests/load/hr.js         # HR admin read mix
k6 run -e VUS=50 -e DURATION=5m tests/load/browse.js     # heavier
```

Thresholds fail the run: error rate under 1 %, board and dashboard p95 under 800 ms, search p95 under 600 ms.
Record the numbers from a baseline run in `docs/runbook.md` §6 before changing indexes or the query layer.
