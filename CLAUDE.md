## Working on Primer

- Use the terms in `GLOSSARY.md` (Preset, Relation field, Shared field) in code, UI and docs.
- The checks every change must pass are the steps in `.github/workflows/ci.yml`. Run them before you finish; the integration and multisite suites need a test database, set in `tests/.env` (copy `tests/.env.example`).
