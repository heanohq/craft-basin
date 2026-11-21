<?php

use craft\elements\Entry as EntryElement;
use heano\basin\fields\BasinFormField;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use markhuot\craftpest\factories\Entry;

/*
 * The Basin Form field: what it stores, what templates get back, and how it
 * copes with forms that disappear from Basin or can't be reached.
 */

function freshEntry(EntryElement $entry): EntryElement
{
    newRequest();
    return EntryElement::find()->id($entry->id)->status(null)->one();
}

it('stores the form’s Basin ID and gives templates the form', function() {
    [$section] = sectionWithFormField();
    $entry = Entry::factory()->section($section)->contactForm('f00000000001')->create();

    $saved = freshEntry($entry);

    expect($saved->contactForm)->toBeInstanceOf(BasinForm::class)
        ->and($saved->contactForm->name)->toBe('Alpha form 1')
        ->and(twig('{{ entry.contactForm.action }}', ['entry' => $saved]))->toBe('https://usebasin.com/f/f00000000001');

    $stored = (new craft\db\Query())->select('content')->from('{{%elements_sites}}')->where(['elementId' => $entry->id])->scalar();
    expect($stored)->toContain('f00000000001');
});

it('gives templates nothing for a form that’s gone from Basin, and logs where it’s used', function() {
    [$section] = sectionWithFormField();
    $entry = Entry::factory()->section($section)->contactForm('f00000000001')->create();
    unset($this->basin->forms[1001]);
    newRequest();

    $saved = freshEntry($entry);

    expect($saved->contactForm)->toBeNull()
        ->and(twig('{{ craft.basin.render(entry.contactForm) }}', ['entry' => $saved]))->toBe('')
        ->and(Plugin::getInstance()->getFormIssues()->count())->toBe(1);
});

it('keeps the stored form when the entry is saved while the form is unavailable', function(Closure $breakIt) {
    [$section] = sectionWithFormField();
    $entry = Entry::factory()->section($section)->contactForm('f00000000001')->create();
    $breakIt($this->basin);
    newRequest();

    $saved = freshEntry($entry);
    $saved->title = 'Changed';
    expect(Craft::$app->getElements()->saveElement($saved))->toBeTrue();

    // Basin's back: the entry still has its form.
    $this->basin->forms[1001] ??= json_decode(file_get_contents(dirname(__DIR__, 3) . '/tests/fixtures/forms.json'), true)['forms'][0];
    $this->basin->respond('GET', 'forms', 200, ['forms' => array_values($this->basin->forms), 'meta' => ['count' => count($this->basin->forms)]]);
    newRequest();
    expect(freshEntry($entry)->contactForm?->uuid)->toBe('f00000000001');
})->with([
    'Basin is down' => [fn($basin) => $basin->respond('GET', 'forms', 503)],
    'the form is gone' => [fn($basin) => $basin->forms = array_diff_key($basin->forms, [1001 => 1])],
]);

it('only accepts the forms the field allows', function(array $fieldSettings) {
    [$section] = sectionWithFormField($fieldSettings);

    $entry = Entry::factory()->section($section)->contactForm('f00000000001')->make();

    expect(Craft::$app->getElements()->saveElement($entry))->toBeFalse()
        ->and($entry->getFirstError('contactForm'))->toContain('can’t be chosen');
})->with([
    'a list of forms' => [['allowedForms' => ['f00000000002']]],
    'Form Studio forms only' => [['selectable' => BasinFormField::SELECTABLE_STUDIO]],
]);

it('lists the project’s forms in the entry editor', function() {
    [$section] = sectionWithFormField();
    $entry = Entry::factory()->section($section)->contactForm('f00000000001')->create();

    $this->actingAsAdmin()->get($entry->getCpEditUrl())
        ->assertOk()
        ->assertSee('Alpha form 1')
        ->assertSee('Alpha form 2');
});

it('warns in the entry editor when the chosen form is gone', function() {
    [$section] = sectionWithFormField();
    $entry = Entry::factory()->section($section)->contactForm('f00000000001')->create();
    unset($this->basin->forms[1001]);
    newRequest();

    $this->actingAsAdmin()->get($entry->getCpEditUrl())
        ->assertOk()
        ->assertSee('Form unavailable (f00000000001)')
        ->assertSee('isn’t in the linked Basin project');
});
