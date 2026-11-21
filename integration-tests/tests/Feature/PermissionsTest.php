<?php

use markhuot\craftpest\factories\User;

/*
 * Each page and action, for a user with exactly the permissions it needs and
 * for one without. Refused actions must not reach Basin.
 */

/** A non-admin who can use the CP and the plugin, plus the given permissions. */
function editor(string ...$permissions): craft\elements\User
{
    $user = User::factory()->admin(false)->create();
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, ['accessCp', 'accessPlugin-basin', ...$permissions]);
    return $user;
}

it('lets {0} open {1}', function(string $who, string $path, array $permissions) {
    $this->actingAs(editor(...$permissions))->get("/admin/basin/$path")->assertOk();
})->with([
    ['anyone with the plugin', 'forms', []],
    ['anyone with the plugin', 'forms/f00000000001', []],
    ['anyone with the plugin', 'errors', []],
    ['form editors', 'forms/new', ['basin-editForms']],
    ['form editors', 'forms/f00000000001/settings', ['basin-editForms']],
    ['submission viewers', 'forms/f00000000001/submissions', ['basin-viewSubmissions']],
    ['submission viewers', 'forms/f00000000001/submissions/1006', ['basin-viewSubmissions']],
]);

it('keeps {0} out of {1}', function(string $who, string $path, array $permissions) {
    $this->actingAs(editor(...$permissions))->withExceptionHandling()->get("/admin/basin/$path")->assertForbidden();
})->with([
    ['people without form editing', 'forms/new', []],
    ['people without form editing', 'forms/f00000000001/settings', ['basin-manageForms']],
    ['people without submissions', 'forms/f00000000001/submissions', ['basin-editForms']],
    ['people without submissions', 'forms/f00000000001/submissions/1006', []],
    ['non-admins', 'settings', ['basin-editForms', 'basin-viewSubmissions']],
]);

it('keeps people without the plugin out', function() {
    $user = User::factory()->admin(false)->create();
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, ['accessCp']);

    $this->actingAs($user)->withExceptionHandling()->get('/admin/basin/forms')->assertForbidden();
});

it('refuses {0} without {1}, and sends nothing to Basin', function(string $action, string $missing, array $permissions, array $body) {
    settings(['allowDeletion' => true]);
    $this->basin->publishStudioForm('f00000000001', 'studio1');
    $this->actingAs(editor(...$permissions));

    $this->withExceptionHandling();
    cpAction($action, $body)->assertForbidden();

    $writes = array_filter($this->basin->requests, fn($r) => $r['method'] !== 'GET');
    expect($writes)->toBe([]);
})->with([
    ['basin/forms/create', 'basin-editForms', ['basin-manageForms'], ['fields' => ['name' => 'New']]],
    ['basin/forms/save-settings', 'basin-editForms', ['basin-manageForms'], ['formUuid' => 'f00000000001', 'fields' => ['notification_subject' => 'x']]],
    ['basin/forms/delete', 'basin-deleteForms', ['basin-editForms'], ['formUuid' => 'f00000000001', 'confirmName' => 'Alpha form 1']],
    ['basin/forms/save-studio-embed', 'basin-manageForms', ['basin-editForms'], ['formUuid' => 'f00000000001', 'embed' => 'https://hf.usebasin.com/hf/f00000000001-studio1.js']],
    ['basin/forms/save-recaptcha-version', 'basin-manageForms', ['basin-editForms'], ['formUuid' => 'f00000000001', 'recaptchaVersion' => 'v2']],
    ['basin/submissions/apply', 'basin-manageSubmissions', ['basin-viewSubmissions'], ['formUuid' => 'f00000000001', 'basinAction' => 'markRead', 'ids' => [1006]]],
    ['basin/submissions/apply', 'basin-deleteSubmissions', ['basin-viewSubmissions', 'basin-manageSubmissions'], ['formUuid' => 'f00000000001', 'basinAction' => 'delete', 'ids' => [1006]]],
    ['basin/submissions/resend-webhooks', 'basin-manageSubmissions', ['basin-viewSubmissions'], ['formUuid' => 'f00000000001', 'id' => 1006]],
    ['basin/submissions/export', 'basin-exportSubmissions', ['basin-viewSubmissions'], ['formUuid' => 'f00000000001']],
    ['basin/settings/save', 'admin', ['basin-editForms', 'basin-viewSubmissions'], ['settings' => ['apiKey' => '$OTHER']]],
]);

it('allows {0} with {1}', function(string $action, string $permission, array $permissions, array $body) {
    settings(['allowDeletion' => true]);
    $this->actingAs(editor(...$permissions));

    cpAction($action, $body)->assertOk();
})->with([
    ['basin/forms/save-settings', 'basin-editForms', ['basin-editForms'], ['formUuid' => 'f00000000001', 'fields' => ['notification_subject' => 'x']]],
    ['basin/forms/save-recaptcha-version', 'basin-manageForms', ['basin-manageForms'], ['formUuid' => 'f00000000001', 'recaptchaVersion' => 'v2']],
    ['basin/submissions/apply', 'basin-manageSubmissions', ['basin-viewSubmissions', 'basin-manageSubmissions'], ['formUuid' => 'f00000000001', 'basinAction' => 'markRead', 'ids' => [1006]]],
    ['basin/submissions/apply', 'basin-deleteSubmissions', ['basin-viewSubmissions', 'basin-deleteSubmissions'], ['formUuid' => 'f00000000001', 'basinAction' => 'delete', 'ids' => [1006]]],
]);

it('hides the Submissions and Settings nav items from people who can’t use them', function() {
    $this->actingAs(editor())->get('/admin/basin/forms')
        ->assertOk()
        ->assertDontSee('/admin/basin/submissions"', false)
        ->assertDontSee('/admin/basin/settings"', false);
});
