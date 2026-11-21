# Integration tests

A small Craft project that installs the plugin from this repo (a Composer path repository) and tests it inside a real Craft install, using [craft-pest](https://github.com/markhuot/craft-pest-core).

Basin is never contacted. `support/FakeBasin.php` stands in for Basin's API: it serves the data in `../tests/fixtures`, applies the changes the plugin sends, and records every request. Tests add forms in any configuration with its presets:

```php
$form = $this->basin->addForm('Contact', FakeBasin::turnstile() + FakeBasin::honeypot());
$this->basin->respond('GET', 'forms', 503); // Basin is down
```

## Running them

They need a MySQL database called `basin_tests`, which they empty and refill freely. `phpunit.xml` pins the database name, so they can't touch a site's own database even where `CRAFT_DB_*` is set (as in DDEV).

In the plugin's DDEV sandbox (once):

```bash
ddev exec 'mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS basin_tests; GRANT ALL ON basin_tests.* TO \"db\"@\"%\";"'
ddev exec -d /var/www/craft-basin/integration-tests composer install
```

Then:

```bash
ddev exec -d /var/www/craft-basin/integration-tests vendor/bin/pest
```

The first run installs Craft (Pro) and the plugin. Each test runs in a transaction that's rolled back, with fresh plugin services and a fresh FakeBasin. Elsewhere, set `CRAFT_DB_SERVER`, `CRAFT_DB_USER` and `CRAFT_DB_PASSWORD`.

## Test suites

- **Integration** (`tests/Feature`, the default): the plugin's PHP and pages. Front-end test pages are in `templates/basin-test`. `HarnessTest` checks the test tools themselves.
- **Uninstall** (`tests/Uninstall`): uninstalls and reinstalls the plugin. Dropping tables ends the test transaction, so it runs on its own and leaves the plugin installed:

  ```bash
  ddev exec -d /var/www/craft-basin/integration-tests vendor/bin/pest --testsuite=Uninstall
  ```

- **Browser** (`tests/Browser`): the JavaScript, in Chromium, through Pest's browser plugin. Test pages block the CAPTCHA providers' scripts with a Content Security Policy, and the tests stand in for the providers, so nothing outside the test reaches the network. The plugin needs Pest 4 (PHP 8.3 or later), which `composer.json` doesn't require because the other suites also run on PHP 8.2. To run them:

  ```bash
  ddev exec -d /var/www/craft-basin/integration-tests 'composer require --dev -W "pestphp/pest:^4.0" "pestphp/pest-plugin-browser:^4.0"'
  ddev exec -d /var/www/craft-basin/integration-tests 'npm install --no-save --no-package-lock playwright@1.59.1 && npx playwright install chromium'
  ddev exec 'sudo npx --prefix /var/www/craft-basin/integration-tests playwright install-deps chromium'
  ddev exec -d /var/www/craft-basin/integration-tests vendor/bin/pest --testsuite=Browser
  ```

  Don't commit the `composer.json` change. Chromium's system libraries go when DDEV restarts.

- **Docs** (`tests/Docs`): the user docs against the plugin, so neither can drift from the other. It checks the config keys, settings, permissions, Twig API, GraphQL types and example queries, renders every Twig example, and checks that bold text naming the Control Panel matches a real label in the plugin or Craft. Bold text that isn't (Basin's own UI, other products, emphasis) is listed in `tests/Docs/not-plugin-labels.txt`. The docs are in heano's site repo, so copy them in first (`.docs` is ignored by git), or point `DOCS_DIR` at them:

  ```bash
  rsync -a --delete ~/Documents/www/heano/src/content/docs/craft-plugins/basin/docs/ .docs/
  ddev exec -d /var/www/craft-basin/integration-tests vendor/bin/pest --testsuite=Docs
  ```

- **Providers** (`tests/Providers`): the real reCAPTCHA, hCaptcha and Turnstile, using their public test keys (reCAPTCHA v3 has none, so it uses Basin's), on a page whose Content Security Policy allows only what the docs' CSP table lists, with and without a nonce. Any CSP violation fails, so a provider changing what its script loads shows up as a docs fix to make. Needs the network, the docs, and the same setup as Browser.

CI checks the published docs (heano's `master`) on every push, on pull requests and daily, and heano checks docs changes against this repo's `main` before they're published.

## Fixes to craft-pest

`support/TestableResponse.php` replaces craft-pest's test response, which otherwise checks assertions against the wrong page after a second request, renders a second Control Panel page empty, and never fires the events Yii fires when sending a response (the plugin adds spam protection in one).

Keep these out of the plugin's own `composer.json`: craft-pest boots Craft whenever Pest runs, which would make the unit tests need a database.
