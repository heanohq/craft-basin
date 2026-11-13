<?php

use heano\basin\helpers\StudioEmbed;

it('parses the forms an admin might paste', function(string $input) {
    expect(StudioEmbed::parse($input))->toBe(['formUuid' => 'demo00quotes', 'hostedId' => 'demostudio01']);
})->with([
    'script tag' => '<script src="https://hf.usebasin.com/hf/demo00quotes-demostudio01.js" async></script>',
    'script URL' => 'https://hf.usebasin.com/hf/demo00quotes-demostudio01.js',
    'hosted URL' => 'https://usebasin.com/form/demo00quotes/hosted/demostudio01?iframe=true',
    'with whitespace and uppercase' => "  <SCRIPT SRC='https://HF.usebasin.com/hf/DEMO00QUOTES-DEMOSTUDIO01.js'></SCRIPT>\n",
]);

it('rejects things that aren’t Studio embeds', function(string $input) {
    expect(StudioEmbed::parse($input))->toBeNull();
})->with([
    '',
    'https://usebasin.com/f/demo00quotes',
    '<script src="https://evil.example.com/hf/demo00quotes-demostudio01.js"></script>x',
    'https://hf.usebasin.com/hf/demo00quotes.js',
    '<iframe src="https://example.com"></iframe>',
]);

it('reads the hosted ID from a Studio submission referrer', function() {
    $referrer = 'https://usebasin.com/form/demo00quotes/hosted/demostudio01?iframe=true&parentPage=https%3A%2F%2Fexample.com';
    expect(StudioEmbed::hostedIdFromReferrer($referrer, 'demo00quotes'))->toBe('demostudio01')
        ->and(StudioEmbed::hostedIdFromReferrer($referrer, 'demo0contact'))->toBeNull()
        ->and(StudioEmbed::hostedIdFromReferrer(null, 'demo00quotes'))->toBeNull();
});

it('builds the script URL', function() {
    expect(StudioEmbed::scriptUrl('demo00quotes', 'demostudio01'))
        ->toBe('https://hf.usebasin.com/hf/demo00quotes-demostudio01.js');
});
