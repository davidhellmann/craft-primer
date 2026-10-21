#!/usr/bin/env bash
# Installs the browser suite's Craft from scratch: drops every table in its database (tests/browser/.env or the
# environment, see config/db.php), installs Craft from the multi-site Pro fixture, installs Primer and creates the
# admin the suite logs in with. The database itself must exist. Then serve it with
# `php tests/browser/craft serve 127.0.0.1 --port=8484 --docroot=tests/browser/web`; `npm test` does both.
set -euo pipefail
cd "$(dirname "$0")"

/bin/rm -rf config/project storage/runtime web/cpresources
cp -R ../fixtures/pro config/project

php craft db/drop-all-tables --interactive=0
# The primary site keeps the fixture's name, URL and language: the installer applies these to it.
php craft install/craft --interactive=0 \
    --username=admin --password=primer-browser --email=admin@primer.test \
    --site-name='Corporate EN' --site-url='https://corporate.test/en/' --language=en-US
php craft plugin/install primer
