<?php

namespace heano\basin\helpers;

/**
 * Parses Basin Form Studio embed codes (spec §10.3).
 *
 * Basin's API doesn't expose a Studio form's hosted ID, so admins paste the
 * embed code once. Accepted input: the `<script>` tag, its URL, or the hosted
 * form URL.
 */
final class StudioEmbed
{
    public const SCRIPT_URL = 'https://hf.usebasin.com/hf/%s-%s.js';
    public const HOSTED_URL = 'https://usebasin.com/form/%s/hosted/%s';

    private const ID = '[a-z0-9]{6,40}';

    /**
     * @return array{formUuid: string, hostedId: string}|null
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        $patterns = [
            '~hf\.usebasin\.com/hf/(' . self::ID . ')-(' . self::ID . ')\.js~i',
            '~usebasin\.com/form/(' . self::ID . ')/hosted/(' . self::ID . ')~i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $m)) {
                return ['formUuid' => strtolower($m[1]), 'hostedId' => strtolower($m[2])];
            }
        }
        return null;
    }

    /**
     * Finds a hosted ID in a submission referrer, which Basin sets to the
     * hosted form URL for Studio submissions.
     */
    public static function hostedIdFromReferrer(?string $referrer, string $formUuid): ?string
    {
        if ($referrer === null || $referrer === '') {
            return null;
        }
        $parsed = self::parse($referrer);
        return $parsed && $parsed['formUuid'] === strtolower($formUuid) ? $parsed['hostedId'] : null;
    }

    public static function isValidHostedId(string $id): bool
    {
        return (bool)preg_match('~^' . self::ID . '$~i', $id);
    }

    public static function scriptUrl(string $formUuid, string $hostedId): string
    {
        return sprintf(self::SCRIPT_URL, rawurlencode($formUuid), rawurlencode($hostedId));
    }

    /**
     * Cleans hidden field values for {@see hiddenFieldsJs()}: string keys
     * (not `action`, which Basin's message uses), scalar values as strings.
     * Anything else is dropped.
     *
     * @return array<string, string>
     */
    public static function hiddenFields(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }
        $clean = [];
        foreach ($fields as $name => $value) {
            // A list (numeric keys) has no field names.
            if (is_int($name)) {
                continue;
            }
            $name = trim($name);
            if ($name === '' || strtolower($name) === 'action' || mb_strlen($name) > 100) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = '';
            } elseif (!is_scalar($value) && !$value instanceof \Stringable) {
                continue;
            }
            $clean[$name] = (string)$value;
        }
        return $clean;
    }

    /**
     * JavaScript that sends hidden fields to a Studio embed's iframe once the
     * form inside it is ready (Basin's `basinSurveyReady` message), and again
     * on page load as a fallback. Only this form's iframe receives them.
     *
     * @param array<string, string> $fields
     */
    public static function hiddenFieldsJs(string $formUuid, string $hostedId, array $fields): string
    {
        $prefix = sprintf(self::HOSTED_URL, rawurlencode($formUuid), rawurlencode($hostedId));
        $json = fn($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload = $json(['action' => 'addHiddenFields'] + $fields);

        return '(function(){var p=' . $json($prefix) . ',m=' . $payload . ',o="https://usebasin.com";'
            . 'function s(){document.querySelectorAll("iframe.basinIframe").forEach(function(f){'
            . 'if(f.src.indexOf(p)===0&&f.contentWindow){f.contentWindow.postMessage(m,o);}});}'
            . 'window.addEventListener("message",function(e){if(e.origin===o&&e.data&&e.data.action==="basinSurveyReady"){s();}});'
            . 'if(document.readyState==="complete"){s();}else{window.addEventListener("load",s);}})();';
    }
}
