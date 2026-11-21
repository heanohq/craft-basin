<?php

use heano\basin\tests\craft\FakeBasin;

/*
 * Every Control Panel page renders for an admin, against FakeBasin's data.
 */

it('renders {0}', function(string $path, array $expected) {
    $response = $this->actingAsAdmin()->get("/admin/basin/$path")->assertOk();

    foreach ($expected as $text) {
        $response->assertSee($text);
    }
})->with([
    'the forms list' => ['forms', ['Alpha form 1', 'Alpha form 2', 'https://usebasin.com/f/f00000000001']],
    'a form' => ['forms/f00000000001', ['Alpha form 1']],
    'a form’s settings' => ['forms/f00000000001/settings', ['Alpha form 1']],
    'the new form page' => ['forms/new', []],
    'a form’s submissions' => ['forms/f00000000001/submissions', ['person8@example.com', 'person11@example.com']],
    'one submission' => ['forms/f00000000001/submissions/1006', ['person2@example.com']],
    'the error log' => ['errors', []],
    'the settings' => ['settings', []],
]);

it('lists only forms in the linked project', function() {
    $this->basin->addForm('Beta only form', FakeBasin::inProject(1003));

    $this->actingAsAdmin()->get('/admin/basin/forms')
        ->assertOk()
        ->assertSee('Alpha form 1')
        ->assertDontSee('Beta only form');
});

it('keeps spam out of the inbox', function() {
    // Submission 1001 is marked as spam in the fixtures.
    $this->actingAsAdmin()->get('/admin/basin/forms/f00000000001/submissions')
        ->assertOk()
        ->assertDontSee('/submissions/1001"', false);
});

it('explains when Basin is down', function() {
    $this->basin->respond('GET', 'forms', 503);

    $this->actingAsAdmin()->get('/admin/basin/forms')
        ->assertOk()
        ->assertDontSee('Alpha form 1');
});

it('opens the first form’s submissions from the Submissions nav item', function() {
    $this->actingAsAdmin()->get('/admin/basin/submissions')
        ->assertRedirectTo('http://localhost:8080/admin/basin/forms/f00000000001/submissions');
});

it('404s for a form that isn’t in the linked project', function() {
    $this->actingAsAdmin()->withExceptionHandling()->get('/admin/basin/forms/f99999999999')
        ->assertNotFound();
});

it('keeps guests out', function() {
    $this->get('/admin/basin/forms')->assertRedirect();
});
