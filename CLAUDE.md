## Working on Primer

- Use the terms in `GLOSSARY.md` in code, UI and docs.
- The checks every change must pass are the steps in `.github/workflows/ci.yml`. Run them before you finish; the integration and multisite suites need a test database, set in `tests/.env` (copy `tests/.env.example`).
- The browser smoke suite (`npm ci`, then `npm test`) installs its own Craft into the database set in `tests/browser/.env` (copy `tests/browser/.env.example`; every table in it is dropped), serves it with `php craft serve` and drives the control panel with Playwright.
