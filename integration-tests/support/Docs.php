<?php

namespace heano\basin\tests\craft;

use RuntimeException;

/**
 * Reads the user docs' Markdown, so tests can check what the docs say
 * against what the plugin does.
 *
 * The docs live in heano's site repo (src/content/docs/craft-plugins/basin/docs).
 * Tests read them from DOCS_DIR, or integration-tests/.docs by default; CI
 * checks them out there. See README.md.
 */
final class Docs
{
    public static function dir(): string
    {
        $dir = getenv('DOCS_DIR') ?: dirname(__DIR__) . '/.docs';
        if (!is_file("$dir/index.md")) {
            throw new RuntimeException("The docs aren't in $dir. Copy heano's src/content/docs/craft-plugins/basin/docs there, or set DOCS_DIR.");
        }
        return rtrim($dir, '/');
    }

    /** @return array<string, string> Markdown without front matter, keyed by path relative to the docs, e.g. "reference/twig.md" */
    public static function pages(): array
    {
        $dir = self::dir();
        $pages = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'md' || $file->getExtension() === 'mdx') {
                $path = substr($file->getPathname(), strlen($dir) + 1);
                $pages[$path] = self::withoutFrontMatter(file_get_contents($file->getPathname()));
            }
        }
        ksort($pages);
        return $pages;
    }

    public static function page(string $path): string
    {
        $file = self::dir() . '/' . $path;
        if (!is_file($file)) {
            throw new RuntimeException("No docs page $path");
        }
        return self::withoutFrontMatter(file_get_contents($file));
    }

    /**
     * The part of a page under a heading, up to the next heading of the same
     * or a higher level. `$heading` is the heading's text, e.g. "Settings".
     */
    public static function section(string $markdown, string $heading): string
    {
        $lines = explode("\n", $markdown);
        $level = null;
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.*?)\s*$/', $line, $m)) {
                if ($level !== null && strlen($m[1]) <= $level) {
                    break;
                }
                if ($level === null && $m[2] === $heading) {
                    $level = strlen($m[1]);
                    continue;
                }
            }
            if ($level !== null) {
                $out[] = $line;
            }
        }
        if ($level === null) {
            throw new RuntimeException("No heading \"$heading\"");
        }
        return implode("\n", $out);
    }

    /** @return list<string> Every heading's text */
    public static function headings(string $markdown): array
    {
        preg_match_all('/^#{1,6}\s+(.*?)\s*$/m', self::withoutCode($markdown), $m);
        return $m[1];
    }

    /**
     * The first Markdown table in some Markdown, as rows keyed by the header
     * cells. Unnamed columns are keyed by their position.
     *
     * @return list<array<string|int, string>>
     */
    public static function table(string $markdown): array
    {
        $rows = [];
        $header = null;
        foreach (explode("\n", $markdown) as $line) {
            if (!str_starts_with(trim($line), '|')) {
                if ($header !== null) {
                    break;
                }
                continue;
            }
            $cells = array_map('trim', explode('|', trim(trim($line), '|')));
            if ($header === null) {
                $header = array_map(fn($cell, $i) => $cell === '' ? $i : $cell, $cells, array_keys($cells));
            } elseif (!preg_match('/^[\s|:-]+$/', $line)) {
                $rows[] = array_combine($header, array_pad($cells, count($header), ''));
            }
        }
        if ($header === null) {
            throw new RuntimeException('No table');
        }
        return $rows;
    }

    /**
     * Fenced code blocks in a language across every page.
     *
     * @return array<string, string> Code, keyed by "page.md #n"
     */
    public static function codeBlocks(string $language): array
    {
        $blocks = [];
        foreach (self::pages() as $path => $markdown) {
            preg_match_all('/^```' . preg_quote($language, '/') . '\b[^\n]*\n(.*?)^```/ms', $markdown, $m);
            foreach ($m[1] as $i => $code) {
                $blocks[$path . ' #' . ($i + 1)] = $code;
            }
        }
        return $blocks;
    }

    /** @return list<string> The text of each `code span` */
    public static function codeSpans(string $text): array
    {
        preg_match_all('/`([^`]+)`/', $text, $m);
        return $m[1];
    }

    /** @return list<string> The text of each **bold** span outside code */
    public static function bold(string $markdown): array
    {
        preg_match_all('/\*\*(.+?)\*\*/', self::withoutCode($markdown), $m);
        return $m[1];
    }

    /** Text without Markdown formatting (bold, italics, code, links). */
    public static function plain(string $text): string
    {
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        return trim(str_replace(['**', '`', '*'], '', $text));
    }

    /**
     * The forms the docs' examples use, by their demo IDs: `demo0contact`
     * (Contact, reCAPTCHA and a honeypot) and `demo00quotes` (Quote request, a
     * Form Studio form published as `demostudio01`).
     *
     * @return array{contact: array<string, mixed>, quotes: array<string, mixed>}
     */
    public static function addDemoForms(FakeBasin $basin): array
    {
        $contact = $basin->addForm('Contact', ['uuid' => 'demo0contact'] + FakeBasin::recaptcha() + FakeBasin::honeypot());
        $quotes = $basin->addForm('Quote request', ['uuid' => 'demo00quotes']);
        $basin->publishStudioForm('demo00quotes', 'demostudio01');
        \heano\basin\Plugin::getInstance()->getFormSettings()->save('demo00quotes', ['hostedFormId' => 'demostudio01']);
        return ['contact' => $contact, 'quotes' => $quotes];
    }

    private static function withoutFrontMatter(string $markdown): string
    {
        return preg_replace('/\A---\n.*?\n---\n/s', '', $markdown);
    }

    private static function withoutCode(string $markdown): string
    {
        return preg_replace('/^```.*?^```/ms', '', $markdown);
    }
}
