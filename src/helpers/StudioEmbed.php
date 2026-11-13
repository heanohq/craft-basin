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
}
