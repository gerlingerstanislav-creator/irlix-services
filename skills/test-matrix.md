# Feature test matrix — [feature]

| Layer | Scenario | Setup / mutation ordering | Expected observable state | Automated/manual | Result/evidence |
| --- | --- | --- | --- | --- | --- |
| Unit | Normalization / edge value | nullable / invalid / empty | stable output or safe error | | |
| Component | Happy path | user input → save | controlled updates and success | | |
| Component | Invalid form | missing/invalid values | focus + field error, no write | | |
| Integration | Old request resolves last | request A; B; resolve B then A | B wins, no ghost loading | | |
| Integration | Refetch while draft dirty | edit; background refresh | draft remains until explicit resolution | | |
| Integration | Permission/expired session | backend returns 403/401 | appropriate denied/reauth state | | |
| E2E | Navigation/filter persistence | query; open detail; back/reload | relevant filter persists | | |
| E2E | Keyboard/modal | open; navigate; Escape | focus correctly restored | | |
| E2E | Bulk partial failure | server returns mixed result | successful items not repeated, failures clear | | |
| Browser | Responsive + zoom | supported viewport set | readable, usable, no clipped actions | | |
