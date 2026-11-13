<?php

namespace heano\basin\helpers;

/**
 * Finds Basin forms in rendered HTML and edits the page around them (spec
 * §10.4). Plain string work with no Craft dependencies, so it's unit-tested.
 */
final class PageScanner
{
    /** Opening `<form>` tags whose action is a Basin endpoint. */
    private const FORM_TAG = '~<form\b[^>]*?\saction\s*=\s*["\']?https?://(?:www\.)?usebasin\.com/f/([A-Za-z0-9]+)[^>]*>~i';

    /** Placeholders output by `form.captcha()`. */
    private const PLACEHOLDER = '~\sdata-basin-captcha\s*=\s*["\']([A-Za-z0-9]+)["\']~i';

    /** A cheap first check, before any regex work. */
    public static function mightHaveForms(string $html): bool
    {
        return str_contains($html, 'usebasin.com/f/') || str_contains($html, 'data-basin-captcha');
    }

    /**
     * Opening tags of Basin forms, in page order.
     *
     * @return array<int, array{tag: string, offset: int, uuid: string, optedOut: bool}>
     */
    public static function forms(string $html): array
    {
        preg_match_all(self::FORM_TAG, $html, $matches, PREG_OFFSET_CAPTURE);
        $forms = [];
        foreach ($matches[0] as $i => [$tag, $offset]) {
            $forms[] = [
                'tag' => $tag,
                'offset' => $offset,
                'uuid' => $matches[1][$i][0],
                'optedOut' => (bool)preg_match('/\sdata-basin-protect\s*=\s*["\']?false/i', $tag),
            ];
        }
        return $forms;
    }

    /**
     * Form UUIDs of `form.captcha()` placeholders.
     *
     * @return string[]
     */
    public static function placeholders(string $html): array
    {
        preg_match_all(self::PLACEHOLDER, $html, $matches);
        return array_values(array_unique($matches[1]));
    }

    /**
     * Inserts a honeypot just after each form's opening tag, unless the form
     * opts out or already has a field with that name.
     *
     * @param array<int, array{tag: string, offset: int, uuid: string, optedOut: bool}> $forms From {@see forms()}, for this same HTML.
     * @param callable(string $uuid): ?array{0: string, 1: string} $honeypotFor Field name and markup for a form, or null to skip it.
     */
    public static function insertHoneypots(string $html, array $forms, callable $honeypotFor): string
    {
        // Work backwards so earlier offsets stay valid.
        foreach (array_reverse($forms) as $form) {
            if ($form['optedOut'] || ($honeypot = $honeypotFor($form['uuid'])) === null) {
                continue;
            }
            [$name, $markup] = $honeypot;
            $start = $form['offset'] + strlen($form['tag']);
            $close = stripos($html, '</form', $start);
            $body = substr($html, $start, $close === false ? null : $close - $start);
            if (preg_match('/\sname\s*=\s*["\']?' . preg_quote($name, '/') . '["\'\s>]/i', $body)) {
                continue;
            }
            $html = substr_replace($html, $markup, $start, 0);
        }
        return $html;
    }

    /** The page's CSP nonce, if a script tag already carries one. */
    public static function nonce(string $html): ?string
    {
        return preg_match('/<script\b[^>]*\snonce\s*=\s*["\']([A-Za-z0-9+\/=_\-]+)["\']/i', $html, $m) ? $m[1] : null;
    }

    /** Inserts markup before the last `</body>`, or at the end if there isn't one. */
    public static function insertBeforeBodyEnd(string $html, string $markup): string
    {
        $pos = strripos($html, '</body>');
        return $pos === false ? $html . $markup : substr_replace($html, $markup, $pos, 0);
    }
}
