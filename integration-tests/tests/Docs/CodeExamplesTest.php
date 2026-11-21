<?php

use heano\basin\tests\craft\Docs;
use markhuot\craftpest\factories\Entry;

/*
 * Every Twig example in the docs renders, with the docs' demo forms and an
 * entry whose Basin Form field is set, and outputs what it's meant to. Strict
 * variables, so a property or method that doesn't exist fails.
 */

it('renders every Twig example', function() {
    Docs::addDemoForms($this->basin);
    [$section] = sectionWithFormField();
    $entry = Entry::factory()->section($section)->title('Get a quote')->contactForm('demo00quotes')->create();
    newRequest();

    $blocks = Docs::codeBlocks('twig');
    expect($blocks)->not->toBeEmpty();
    foreach ($blocks as $where => $code) {
        $html = twig($code, ['entry' => $entry]);

        // An example that shows the Form Studio version when there is one shows that instead.
        if (str_contains($code, 'form.action') && !str_contains($code, 'craft.basin.render(')) {
            expect(str_contains($html, 'https://usebasin.com/f/demo'))->toBeTrue("$where outputs the form's endpoint");
        }
        if (str_contains($code, 'craft.basin.render(')) {
            expect(str_contains($html, 'demostudio01'))->toBeTrue("$where outputs the Form Studio embed");
        }
        if (str_contains($code, 'form.honeypot()')) {
            expect(str_contains($html, 'data-basin-honeypot'))->toBeTrue("$where outputs the honeypot");
        }
        if (str_contains($code, 'form.captcha()')) {
            expect(str_contains($html, 'data-basin-captcha'))->toBeTrue("$where outputs the CAPTCHA placeholder");
        }
    }
});
