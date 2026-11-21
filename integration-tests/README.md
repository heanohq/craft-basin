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

Keep these out of the plugin's own `composer.json`: craft-pest boots Craft whenever Pest runs, which would make the unit tests need a database.
