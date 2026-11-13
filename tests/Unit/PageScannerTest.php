<?php

use heano\basin\helpers\PageScanner;

$honeypot = fn(string $name = '_gotcha') => fn(string $uuid) => [$name, "<input name=\"$name\" data-hp=\"$uuid\">"];

it('skips pages without Basin forms cheaply', function() {
    expect(PageScanner::mightHaveForms('<form action="/contact"></form>'))->toBeFalse()
        ->and(PageScanner::mightHaveForms('<form action="https://usebasin.com/f/abc123">'))->toBeTrue()
        ->and(PageScanner::mightHaveForms('<div data-basin-captcha="abc123"></div>'))->toBeTrue();
});

it('finds Basin form tags in their many spellings', function() {
    $html = <<<HTML
<form action="https://usebasin.com/f/aaa111" method="post">
<FORM method=post ACTION='https://www.usebasin.com/f/bbb222?x=1'>
<form class="x" action=https://usebasin.com/f/ccc333>
<form action="https://example.com/f/ddd444">
<form data-action="https://usebasin.com/f/eee555">
<form action="https://usebasin.com/f/fff666" data-basin-protect="false">
HTML;
    $forms = PageScanner::forms($html);

    expect(array_column($forms, 'uuid'))->toBe(['aaa111', 'bbb222', 'ccc333', 'fff666'])
        ->and(array_column($forms, 'optedOut'))->toBe([false, false, false, true]);
});

it('finds captcha placeholders', function() {
    $html = '<div class="x" data-basin-captcha="abc123"></div><div data-basin-captcha=\'abc123\'></div><div data-basin-captcha="def456"></div>';
    expect(PageScanner::placeholders($html))->toBe(['abc123', 'def456']);
});

it('adds a honeypot after each form tag', function() use ($honeypot) {
    $html = '<form action="https://usebasin.com/f/aaa111"><input name="email"></form>'
        . '<form action="https://usebasin.com/f/bbb222"><input name="email"></form>';
    $result = PageScanner::insertHoneypots($html, PageScanner::forms($html), $honeypot());

    expect($result)->toBe(
        '<form action="https://usebasin.com/f/aaa111"><input name="_gotcha" data-hp="aaa111"><input name="email"></form>'
        . '<form action="https://usebasin.com/f/bbb222"><input name="_gotcha" data-hp="bbb222"><input name="email"></form>'
    );
});

it('leaves forms that opt out, already have the field, or are unknown', function() use ($honeypot) {
    $html = '<form action="https://usebasin.com/f/aaa111" data-basin-protect="false"><input name="email"></form>'
        . '<form action="https://usebasin.com/f/bbb222"><input type="text" name="_gotcha"></form>'
        . '<form action="https://usebasin.com/f/ccc333"><input name="email"></form>';
    $known = $honeypot();
    $result = PageScanner::insertHoneypots($html, PageScanner::forms($html), fn($uuid) => $uuid === 'ccc333' ? null : $known($uuid));

    expect($result)->toBe($html);
});

it('only looks inside the form for an existing honeypot', function() use ($honeypot) {
    $html = '<form action="https://usebasin.com/f/aaa111"></form><input name="_gotcha">';
    $result = PageScanner::insertHoneypots($html, PageScanner::forms($html), $honeypot());

    expect($result)->toContain('data-hp="aaa111"');
});

it('uses a custom honeypot name', function() use ($honeypot) {
    $html = '<form action="https://usebasin.com/f/aaa111"><input name="website"></form>';
    expect(PageScanner::insertHoneypots($html, PageScanner::forms($html), $honeypot('website')))->toBe($html);
});

it('finds the page nonce', function() {
    expect(PageScanner::nonce('<script nonce="abc+/=123">x</script>'))->toBe('abc+/=123')
        ->and(PageScanner::nonce('<script>x</script>'))->toBeNull();
});

it('inserts before the closing body tag', function() {
    expect(PageScanner::insertBeforeBodyEnd('<body><p>x</p></BODY></html>', '<s>'))->toBe('<body><p>x</p><s></BODY></html>')
        ->and(PageScanner::insertBeforeBodyEnd('<p>partial</p>', '<s>'))->toBe('<p>partial</p><s>');
});
