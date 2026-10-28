## What and why

## Checklist (see docs/coding-standards.md)
- [ ] Behaviour change is covered by a test; refactors change no behaviour, route names, JSON shapes or messages
- [ ] Layering respected (controller validates, authorizes, calls one service, presents)
- [ ] Types declared; no new static-analysis baseline entries or lint warnings
- [ ] Security checklist applied (bound SQL, auth/throttle on routes, signed downloads, no secrets/PII leaks)
- [ ] Works on PostgreSQL and sqlite; tenant isolation preserved
- [ ] New messages wrapped in `__()` / locale keys added (en + es)
- [ ] Focused ParaTest + Pint (+ `npm run lint && npm test && npm run build` if JS) green; full run noted if cross-cutting
