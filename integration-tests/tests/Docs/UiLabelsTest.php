<?php

use heano\basin\tests\craft\Docs;

/*
 * Bold text in the docs that names something in the Control Panel matches a
 * real label, the plugin's or Craft's, so a renamed setting, permission or
 * button can't leave the docs behind. Bold text that isn't either (Basin's
 * own UI, other products, or just emphasis) is listed in
 * not-plugin-labels.txt.
 */

/** @return list<string> Every string the plugin translates, i.e. its UI text, including Craft's own strings it uses */
function pluginStrings(): array
{
    $strings = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $source = file_get_contents($file->getPathname());
        preg_match_all("/Craft::t\\('(?:basin|app)',\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $php);
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\\|t\\('(?:basin|app)'/", $source, $twig);
        preg_match_all('/"([^"]*)"\|t\(\'(?:basin|app)\'/', $source, $twigDouble);
        foreach ([...$php[1], ...$twig[1], ...$twigDouble[1]] as $string) {
            $strings[] = stripslashes($string);
        }
    }
    return array_values(array_unique($strings));
}

/** @return list<string> Craft's own UI text, from its translations */
function craftStrings(): array
{
    return array_keys(require dirname(__DIR__, 2) . '/vendor/craftcms/cms/src/translations/en/app.php');
}

/**
 * Whether some text is one of the strings, ignoring case (prose may lower-case
 * a label), where a string's {placeholder} matches anything.
 */
function matchesString(string $text, array $strings): bool
{
    static $patterns = [];
    foreach ($strings as $string) {
        if (strcasecmp($string, $text) === 0) {
            return true;
        }
        if (str_contains($string, '{')) {
            $patterns[$string] ??= '/^' . preg_replace('/\\\{\w+\\\}/', '.+', preg_quote($string, '/')) . '$/ui';
            if (preg_match($patterns[$string], $text)) {
                return true;
            }
        }
    }
    return false;
}

/** @return list<string> */
function notPluginLabels(): array
{
    $lines = file(__DIR__ . '/not-plugin-labels.txt', FILE_IGNORE_NEW_LINES);
    return array_values(array_filter($lines, fn($line) => $line !== '' && !str_starts_with($line, '#')));
}

it('names the plugin’s UI as it is', function() {
    $strings = [...pluginStrings(), ...craftStrings()];
    $allowed = notPluginLabels();
    $known = fn(string $text) => in_array($text, $allowed, true) || matchesString($text, $strings);

    $unknown = [];
    foreach (Docs::pages() as $path => $markdown) {
        foreach (Docs::bold($markdown) as $bold) {
            // A list item's lead-in, e.g. "**Webhooks.**", is the label without its punctuation.
            $text = rtrim(Docs::plain($bold), ':.');
            // A path like "Basin → Settings", or a list like "Inbox, Spam and
            // Trash": each step or item is a label.
            $parts = fn(string $separator) => preg_split($separator, $text);
            $all = fn(array $labels) => count(array_filter($labels, $known)) === count($labels);
            $ok = $known($text) || $all($parts('/\s*→\s*/u')) || $all($parts('/\s*,\s*|\s+(?:and|or)\s+/'));
            if (!$ok) {
                $unknown[] = "$path: $text";
            }
        }
    }

    expect(array_values(array_unique($unknown)))->toBe([], 'Not a label in the plugin. Fix the docs, or add it to tests/Docs/not-plugin-labels.txt if it isn’t the plugin’s UI.');
});

it('lists nothing in not-plugin-labels.txt that the docs no longer use', function() {
    $used = [];
    foreach (Docs::pages() as $markdown) {
        foreach (Docs::bold($markdown) as $bold) {
            $text = rtrim(Docs::plain($bold), ':.');
            $used = [...$used, $text, ...preg_split('/\s*→\s*/u', $text), ...preg_split('/\s*,\s*|\s+(?:and|or)\s+/', $text)];
        }
    }
    expect(array_values(array_diff(notPluginLabels(), $used)))->toBe([]);
});
