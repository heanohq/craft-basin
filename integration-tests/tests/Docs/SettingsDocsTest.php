<?php

use heano\basin\models\Settings;
use heano\basin\tests\craft\Docs;

/*
 * The settings as the docs describe them: every config key and Control Panel
 * setting, its type and its default (reference/config.md,
 * getting-started/settings.md).
 */

/** @return array<string, mixed> Each setting's default, from the model */
function settingDefaults(): array
{
    return (new ReflectionClass(Settings::class))->getDefaultProperties();
}

/** @return array<string, string> Each setting's PHP type */
function settingTypes(): array
{
    $types = [];
    foreach ((new ReflectionClass(Settings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if (!$property->isStatic()) {
            $types[$property->getName()] = (string)$property->getType();
        }
    }
    return $types;
}

/** @return list<array<string|int, string>> The config docs' settings table */
function configTable(): array
{
    return Docs::table(Docs::section(Docs::page('reference/config.md'), 'Settings'));
}

it('documents every config key, and only real ones', function() {
    $documented = array_map(fn($row) => Docs::plain($row['Key']), configTable());

    expect($documented)->toEqualCanonicalizing(array_keys(settingTypes()));
});

it('gives each config key its real type and default', function() {
    $defaults = settingDefaults();
    $types = settingTypes();

    foreach (configTable() as $row) {
        $key = Docs::plain($row['Key']);
        $type = Docs::plain($row['Type']);
        $default = Docs::plain($row['Default']);

        if ($types[$key] === 'string' && preg_match_all("/'(\\w+)'/", $type, $values)) {
            // A choice, e.g. 'v3' or 'v2'.
            expect(in_array($defaults[$key], $values[1], true))->toBeTrue("Type of $key");
        } else {
            expect($type)->toBe($types[$key], "Type of $key");
        }

        if ($default === "Basin's key") {
            expect($defaults[$key])->toBeString()->not->toBe('', "Default of $key");
        } else {
            expect($default)->toBe(var_export($defaults[$key], true), "Default of $key");
        }
    }
});

it('runs the config file examples', function() {
    // The environment variables the examples refer to.
    foreach (['BASIN_API_KEY' => 'fake-basin-key', 'BASIN_PROJECT_ID' => '1002'] as $name => $value) {
        putenv("$name=$value");
        $_SERVER[$name] = $value;
    }
    foreach (Docs::codeBlocks('php') as $where => $code) {
        if (!str_contains($code, 'return [')) {
            continue;
        }
        $file = tempnam(sys_get_temp_dir(), 'basin-config');
        file_put_contents($file, $code);
        $config = require $file;
        unlink($file);

        // A per-environment config, or a flat one.
        $values = isset($config['*']) ? array_merge(...array_values($config)) : $config;
        expect(array_diff(array_keys($values), array_keys(settingTypes())))->toBe([], "Unknown keys in $where");

        $settings = new Settings();
        $settings->setAttributes($values, false);
        expect($settings->validate())->toBeTrue("$where: " . json_encode($settings->getErrors()));
    }

    foreach (['BASIN_API_KEY', 'BASIN_PROJECT_ID'] as $name) {
        putenv($name);
        unset($_SERVER[$name]);
    }
});

it('describes the Control Panel settings as they are', function() {
    $template = file_get_contents(dirname(__DIR__, 3) . '/src/templates/settings/index.twig');
    // Each field's label and setting name, without running into the next field.
    preg_match_all("/label: '([^']+)'\\|t\\('basin'\\),(?:(?!label:).)*?name: 'settings\\[(\\w+)\\]'/s", $template, $m);
    $fields = array_combine($m[1], $m[2]);
    $defaults = settingDefaults();

    $rows = Docs::table(Docs::page('getting-started/settings.md'));
    expect(array_map(fn($row) => Docs::plain($row['Setting']), $rows))
        ->toEqualCanonicalizing(array_keys($fields), 'The settings page has these settings');

    foreach ($rows as $row) {
        $label = Docs::plain($row['Setting']);
        $default = $defaults[$fields[$label]];
        $expected = match (true) {
            is_bool($default) => $default ? 'On' : 'Off',
            $fields[$label] === 'navLabel' => 'Basin',
            $default === '' => '—',
            default => (string)$default,
        };
        expect(Docs::plain($row['Default']))->toBe($expected, "Default of $label");
    }
});
