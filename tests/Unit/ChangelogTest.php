<?php

/*
 * CHANGELOG.md in the format Craft's Plugin Store reads. Craft silently
 * ignores a "##" heading it can't parse, with everything under it, so a typo
 * would lose a release's notes (and its critical flag) without any error.
 */

/** @return list<string> The changelog's level-two headings */
function changelogHeadings(): array
{
    preg_match_all('/^## .*$/m', file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'), $m);
    return $m[0];
}

it('gives every release a heading Craft can read', function() {
    $headings = changelogHeadings();
    expect($headings)->not->toBeEmpty();

    foreach ($headings as $i => $heading) {
        if ($i === 0 && $heading === '## Unreleased') {
            continue;
        }
        // "## 1.2.3 - 2026-11-21", optionally " [CRITICAL]": plain hyphens, no "v".
        expect(preg_match('/^## \d+\.\d+\.\d+ - \d{4}-\d{2}-\d{2}( \[CRITICAL\])?$/', $heading))
            ->toBe(1, "Not a heading Craft reads: \"$heading\"");
    }
});

it('lists releases newest first, each once, with dates that don’t go backwards', function() {
    $releases = [];
    foreach (changelogHeadings() as $heading) {
        if (preg_match('/^## (\S+) - (\S+)/', $heading, $m)) {
            $releases[] = ['version' => $m[1], 'date' => $m[2]];
        }
    }

    $versions = array_column($releases, 'version');
    $sorted = $versions;
    usort($sorted, fn($a, $b) => version_compare($b, $a));
    expect($versions)->toBe($sorted)
        ->and(array_unique($versions))->toBe($versions);

    $dates = array_column($releases, 'date');
    $sortedDates = $dates;
    rsort($sortedDates);
    expect($dates)->toBe($sortedDates);
});

it('gives each release some notes', function() {
    $sections = array_slice(preg_split('/^## .*$/m', file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md')), 1);
    foreach (array_combine(changelogHeadings(), $sections) as $heading => $section) {
        // Unreleased may be empty just after a release.
        if ($heading !== '## Unreleased') {
            expect(preg_match('/^- \S/m', $section))->toBe(1, "\"$heading\" has no entries");
        }
    }
});
