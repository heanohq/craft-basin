<?php

use heano\basin\Plugin;

it('finds a form by its Basin ID', function() {
    expect(twig('{{ craft.basin.form("f00000000001").name }}'))->toContain('Alpha form 1');
});

it('gives the form’s endpoint', function() {
    expect(twig('{{ craft.basin.form("f00000000001").action }}'))->toContain('https://usebasin.com/f/f00000000001');
});

it('lists the linked project’s forms by name', function() {
    expect(twig('{% for form in craft.basin.forms() %}{{ form.name }};{% endfor %}'))->toContain('Alpha form 1;Alpha form 2;');
});

it('returns null for a form that isn’t in the project, and logs it', function() {
    expect(twig('{{ craft.basin.form("f99999999999") is null ? "missing" }}'))->toContain('missing');

    expect(Plugin::getInstance()->getFormIssues()->count())->toBe(1);
});

it('renders nothing for an empty value', function() {
    $html = Craft::$app->getView()->renderString('[{{ craft.basin.render(null) }}]');

    expect($html)->toBe('[]');
});
