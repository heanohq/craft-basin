<?php

use heano\basin\tests\craft\Docs;

/*
 * The permissions as the docs list them (control-panel/permissions.md), named
 * and nested as Craft shows them under Settings → Users.
 */

/**
 * The Basin permissions' labels as Craft lists them, with nested ones
 * prefixed "↳ ".
 *
 * @return list<string>
 */
function basinPermissionLabels(): array
{
    $group = collect(Craft::$app->getUserPermissions()->getAllPermissions())
        ->first(fn($group) => $group['heading'] === 'Basin');
    expect($group)->not->toBeNull('No Basin permissions');

    $labels = [];
    $walk = function(array $permissions, int $depth) use (&$walk, &$labels) {
        foreach ($permissions as $permission) {
            $labels[] = str_repeat('↳ ', $depth) . $permission['label'];
            $walk($permission['nested'] ?? [], $depth + 1);
        }
    };
    $walk($group['permissions'], 0);
    return $labels;
}

/** The label of Craft's own permission to see the plugin, under General → Access the control panel. */
function accessPluginLabel(): string
{
    $find = function(array $permissions) use (&$find): ?string {
        foreach ($permissions as $name => $permission) {
            if (strtolower($name) === 'accessplugin-basin') {
                return $permission['label'];
            }
            if ($found = $find($permission['nested'] ?? [])) {
                return $found;
            }
        }
        return null;
    };
    foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
        if ($label = $find($group['permissions'])) {
            return $label;
        }
    }
    throw new RuntimeException('No permission to access the plugin');
}

it('lists every permission by its name in Craft, nested as Craft shows it', function() {
    $documented = array_map(fn($row) => Docs::plain($row['Permission']), Docs::table(Docs::page('control-panel/permissions.md')));

    expect($documented)->toBe([accessPluginLabel(), ...basinPermissionLabels()]);
});
