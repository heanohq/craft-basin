<?php

use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use heano\basin\tests\craft\Docs;
use heano\basin\tests\craft\FakeBasin;
use heano\basin\variables\BasinVariable;
use Twig\Markup;

/*
 * The Twig API as the docs describe it (reference/twig.md): every
 * craft.basin method, every form property and method, and the HTML
 * attributes the plugin reads.
 */

/** @return list<string> Public methods of a class, other than inherited ones from Craft or Yii */
function ownPublicMethods(string $class): array
{
    $methods = [];
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (str_starts_with($method->getDeclaringClass()->getName(), 'heano\\') && !$method->isStatic() && !$method->isConstructor()) {
            $methods[] = $method->getName();
        }
    }
    return $methods;
}

it('documents every craft.basin method, and only real ones', function() {
    preg_match_all('/^## `craft\.basin\.(\w+)\(/m', Docs::page('reference/twig.md'), $m);

    expect($m[1])->toEqualCanonicalizing(ownPublicMethods(BasinVariable::class));
});

/**
 * Forms in each configuration, as templates get them, to check each
 * documented property against.
 *
 * @return array<string, BasinForm>
 */
function formsInEachConfiguration(FakeBasin $basin): array
{
    $forms = Docs::addDemoForms($basin);
    $basin->addForm('Plain', ['uuid' => 'demo000plain']);
    $basin->addForm('hCaptcha', ['uuid' => 'demohcaptcha'] + FakeBasin::hcaptcha());
    $basin->addForm('Turnstile', ['uuid' => 'demoturnstil', 'use_ajax' => true, 'redirect_url' => 'https://example.com/thanks'] + FakeBasin::turnstile());
    newRequest();

    $variable = new BasinVariable();
    return array_map(fn($uuid) => $variable->form($uuid), [
        'reCAPTCHA' => 'demo0contact',
        'Form Studio' => 'demo00quotes',
        'no spam protection' => 'demo000plain',
        'hCaptcha' => 'demohcaptcha',
        'Turnstile' => 'demoturnstil',
    ]);
}

it('gives each documented form property the value PHP has', function() {
    $rows = Docs::table(Docs::section(Docs::page('reference/twig.md'), 'Forms'));
    $properties = [];
    foreach ($rows as $row) {
        if (preg_match('/^form\.(\w+)$/', Docs::plain($row[0]), $m)) {
            $properties[] = $m[1];
        }
    }
    expect($properties)->not->toBeEmpty();

    foreach (formsInEachConfiguration($this->basin) as $configuration => $form) {
        foreach ($properties as $property) {
            // What Twig gives, not what PHP gives: Twig prefers a method with
            // the same name over a getter.
            $twig = twig("{% set v = form.$property %}{{ v is same as(null) ? 'null' : (v is iterable ? v|json_encode : v ~ '') }}", ['form' => $form]);
            $php = $form->$property;
            $expected = $php === null ? 'null' : (is_bool($php) ? ($php ? '1' : '') : (string)$php);
            expect($twig)->toBe($expected, "form.$property on a form with $configuration");
        }
    }
});

it('gives each documented form method', function() {
    $rows = Docs::table(Docs::section(Docs::page('reference/twig.md'), 'Forms'));
    $form = formsInEachConfiguration($this->basin)['reCAPTCHA'];
    foreach ($rows as $row) {
        if (preg_match('/^form\.(\w+)\(/', Docs::plain($row[0]), $m)) {
            expect(method_exists($form, $m[1]))->toBeTrue("form.{$m[1]}()")
                ->and(twig("{{ form.{$m[1]}() }}", ['form' => $form]))->toBeString();
        }
    }
});

it('lists the HTML attributes the plugin reads', function() {
    $rows = Docs::table(Docs::section(Docs::page('reference/twig.md'), 'HTML attributes'));
    $script = file_get_contents(dirname(__DIR__, 3) . '/src/web/protect.js');

    foreach ($rows as $row) {
        preg_match('/^([\w-]+)=/', Docs::plain($row['Attribute']), $m);
        expect(str_contains($script, "'{$m[1]}'"))->toBeTrue("protect.js reads {$m[1]}");
    }
});

it('marks the helpers safe and outputs nothing when a form doesn’t need them', function() {
    $forms = formsInEachConfiguration($this->basin);

    expect($forms['no spam protection']->captcha())->toBeInstanceOf(Markup::class)
        ->and((string)$forms['no spam protection']->captcha())->toBe('')
        ->and((string)$forms['reCAPTCHA']->captcha())->not->toBe('');
});
