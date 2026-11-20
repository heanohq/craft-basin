<?php

use heano\basin\helpers\ProjectOptions;

const PROJECT_LABELS = ['choose' => 'Choose a project', 'unknown' => 'Project not found', 'notSet' => 'not set', 'envVars' => 'Environment Variables'];
const PROJECTS = [1001 => 'Alpha', 1002 => 'Beta'];

it('lists the projects, with a prompt when none is chosen', function() {
    expect(ProjectOptions::build(PROJECTS, '', [], PROJECT_LABELS))->toBe([
        ['label' => 'Choose a project', 'value' => ''],
        ['label' => 'Alpha', 'value' => '1001', 'data' => ['hint' => '1001']],
        ['label' => 'Beta', 'value' => '1002', 'data' => ['hint' => '1002']],
    ]);
});

it('adds nothing when the saved project is listed', function() {
    expect(ProjectOptions::build(PROJECTS, '1002', [], PROJECT_LABELS))->toHaveCount(2);
});

it('keeps a saved project that isn’t listed', function() {
    $unknown = ['label' => 'Project not found', 'value' => '1009', 'data' => ['hint' => '1009']];
    expect(ProjectOptions::build(PROJECTS, '1009', [], PROJECT_LABELS))->toContain($unknown)
        ->and(ProjectOptions::build([], '1009', [], PROJECT_LABELS))->toBe([$unknown]);
});

it('lists env vars that hold a project ID, named by project', function() {
    $env = ['BASIN_PROJECT_ID' => '1002', 'APP_ID' => '1003', 'NAME' => 'Alpha', 'ALPHA_ID' => '1001'];
    expect(array_slice(ProjectOptions::build(PROJECTS, '$BASIN_PROJECT_ID', $env, PROJECT_LABELS), 2))->toBe([
        ['optgroup' => 'Environment Variables'],
        ['label' => '$ALPHA_ID', 'value' => '$ALPHA_ID', 'data' => ['hint' => 'Alpha']],
        ['label' => '$BASIN_PROJECT_ID', 'value' => '$BASIN_PROJECT_ID', 'data' => ['hint' => 'Beta']],
    ]);
});

it('keeps a saved env var that doesn’t hold a listed project', function(array $env, string $hint) {
    expect(ProjectOptions::build(PROJECTS, '$BASIN_PROJECT_ID', $env, PROJECT_LABELS))
        ->toContain(['label' => '$BASIN_PROJECT_ID', 'value' => '$BASIN_PROJECT_ID', 'data' => ['hint' => $hint]]);
})->with([
    'not set' => [[], 'not set'],
    'empty' => [['BASIN_PROJECT_ID' => ''], 'not set'],
    'another project' => [['BASIN_PROJECT_ID' => '1009'], '1009'],
]);
