## Working on Primer

- Use the terms in `GLOSSARY.md` in code, UI and docs.
- The checks every change must pass are the steps in `.github/workflows/ci.yml`. Run them before you finish: `composer check-cs`, `composer phpstan` and `composer test`. The tests need no database server: they run Craft on in-memory SQLite. CI also runs them on MySQL and Postgres.
- Primer's control panel is Craft's own element index and element editor. Before adding a screen, template or script of Primer's own, look for the Craft API that already does it.
