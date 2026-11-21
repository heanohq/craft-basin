<?php

namespace heano\basin\helpers;

/**
 * The Basin form settings the CP can edit (spec §6.7), and the framework-free
 * logic behind the editor: cleaning up values, checking them, working out
 * what changed, and matching Basin's error messages to fields.
 *
 * Values use Basin's field names throughout.
 */
final class FormEditor
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const EMAILS = 'emails';
    public const EMAIL = 'email';
    public const URL = 'url';
    public const COLOR = 'color';
    public const BOOL = 'bool';
    public const DAYS = 'days';
    public const TIMEZONE = 'timezone';

    /** Error codes returned by {@see validate()}. */
    public const ERROR_REQUIRED = 'required';
    public const ERROR_EMAIL = 'email';
    public const ERROR_URL = 'url';
    public const ERROR_COLOR = 'color';
    public const ERROR_DAYS = 'days';
    public const ERROR_TIMEZONE = 'timezone';
    public const ERROR_TOO_LONG = 'tooLong';

    public const MAX_DAYS = 3650;
    private const MAX_LENGTH = 1000;
    /** Basin rejects longer form names (checked 2026-11-21). */
    public const MAX_NAME_LENGTH = 100;
    private const MAX_BODY_LENGTH = 10000;

    /**
     * Every editable field and its type. Nothing else is ever sent to Basin,
     * so `project_id` and the settings Basin manages can't be changed.
     */
    public const FIELDS = [
        'name' => self::TEXT,
        'timezone' => self::TIMEZONE,
        'notification_emails' => self::EMAILS,
        'notification_cc_emails' => self::EMAILS,
        'notification_bcc_emails' => self::EMAILS,
        'notification_subject' => self::TEXT,
        'notification_from_name' => self::TEXT,
        'exclude_submitter_from_reply' => self::BOOL,
        'redirect_url' => self::URL,
        'redirect_heading' => self::TEXT,
        'redirect_message' => self::TEXTAREA,
        'redirect_button_text' => self::TEXT,
        'redirect_button_background_color' => self::COLOR,
        'redirect_button_text_color' => self::COLOR,
        'use_ajax' => self::BOOL,
        'autoreply' => self::BOOL,
        'autoreply_subject' => self::TEXT,
        'autoreply_from_name' => self::TEXT,
        'autoreply_greeting' => self::TEXT,
        'autoreply_body' => self::TEXTAREA,
        'autoreply_name' => self::TEXT,
        'autoreply_title' => self::TEXT,
        'autoreply_email' => self::EMAIL,
        'retention_days' => self::DAYS,
    ];

    /** Fields Basin keeps as null rather than an empty string when unset. */
    private const NULL_WHEN_EMPTY = ['redirect_url', 'redirect_button_background_color', 'redirect_button_text_color', 'autoreply_body'];

    /** Pending lists Basin keeps for each recipients field. */
    public const PENDING_FIELDS = [
        'notification_emails' => 'notification_emails_pending',
        'notification_cc_emails' => 'notification_cc_emails_pending',
        'notification_bcc_emails' => 'notification_bcc_emails_pending',
    ];

    /**
     * Basin's settings with each recipients field holding its verified and
     * pending addresses together, as the CP lists them. Basin treats a saved
     * list the same way: addresses it already has stay as they are, new ones
     * wait for verification, and pending ones left out are dropped.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function withPending(array $settings): array
    {
        foreach (self::PENDING_FIELDS as $key => $pendingKey) {
            if (array_key_exists($key, $settings) || array_key_exists($pendingKey, $settings)) {
                $all = self::str($settings[$key] ?? '') . ', ' . self::str($settings[$pendingKey] ?? '');
                $settings[$key] = implode(', ', self::splitEmails($all));
            }
        }
        return $settings;
    }

    /**
     * Cleans up editable values, from Basin or from the CP: trims text, tidies
     * email lists, and casts booleans and numbers. Unknown keys are dropped.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function normalise(array $values): array
    {
        $clean = [];
        foreach (self::FIELDS as $key => $type) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = $values[$key];
            $clean[$key] = match ($type) {
                self::BOOL => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                self::DAYS => is_numeric($value) ? (int)$value : (is_string($value) && trim($value) === '' ? null : $value),
                self::EMAILS => implode(', ', self::splitEmails(self::str($value))),
                // Craft's colour field posts the value without its "#".
                self::COLOR => preg_match('/^#?([0-9A-Fa-f]{6})$/', self::str($value), $m) ? '#' . strtoupper($m[1]) : strtoupper(self::str($value)),
                self::TEXTAREA => str_replace("\r\n", "\n", self::str($value)),
                default => self::str($value),
            };
        }
        return $clean;
    }

    /**
     * Checks the given (normalised) values. Only pass the fields being
     * changed, so a value Basin already holds is never rejected.
     *
     * @param array<string, mixed> $values
     * @return array<string, string> Field => one of the ERROR_* codes
     */
    public static function validate(array $values): array
    {
        $errors = [];
        foreach ($values as $key => $value) {
            $type = self::FIELDS[$key] ?? null;
            if ($type === null || $type === self::BOOL) {
                continue;
            }
            if ($type === self::DAYS) {
                if (!is_int($value) || $value < 1 || $value > self::MAX_DAYS) {
                    $errors[$key] = self::ERROR_DAYS;
                }
                continue;
            }

            $value = (string)$value;
            $max = match (true) {
                $key === 'name' => self::MAX_NAME_LENGTH,
                $type === self::TEXTAREA => self::MAX_BODY_LENGTH,
                default => self::MAX_LENGTH,
            };
            $error = match (true) {
                $key === 'name' && $value === '' => self::ERROR_REQUIRED,
                mb_strlen($value) > $max => self::ERROR_TOO_LONG,
                $value === '' => null,
                $type === self::EMAILS => self::firstInvalidEmail(self::splitEmails($value)) === null ? null : self::ERROR_EMAIL,
                $type === self::EMAIL => self::isEmail($value) ? null : self::ERROR_EMAIL,
                $type === self::URL => self::isUrl($value) ? null : self::ERROR_URL,
                $type === self::COLOR => preg_match('/^#[0-9A-F]{6}$/', $value) ? null : self::ERROR_COLOR,
                $type === self::TIMEZONE => self::isTimezone($value) ? null : self::ERROR_TIMEZONE,
                default => null,
            };
            if ($error !== null) {
                $errors[$key] = $error;
            }
        }
        return $errors;
    }

    /**
     * The fields whose values differ from what Basin has, ready to send.
     * Empty optional URLs and colours are sent as null, as Basin stores them.
     *
     * @param array<string, mixed> $original Settings from Basin
     * @param array<string, mixed> $submitted Values from the CP
     * @return array<string, mixed>
     */
    public static function changes(array $original, array $submitted): array
    {
        $before = self::normalise($original);
        $after = self::normalise($submitted);
        $changes = [];
        foreach ($after as $key => $value) {
            if (array_key_exists($key, $before) && $before[$key] === $value) {
                continue;
            }
            if ($key === 'notification_emails' || $key === 'notification_cc_emails' || $key === 'notification_bcc_emails') {
                // The same addresses in another order or case aren't a change,
                // and re-sending them could put them back into verification.
                if (self::sameEmails((string)($before[$key] ?? ''), (string)$value)) {
                    continue;
                }
            }
            $changes[$key] = $value === '' && in_array($key, self::NULL_WHEN_EMPTY, true) ? null : $value;
        }
        return $changes;
    }

    /**
     * Which field a Basin validation message is about, if it can be told.
     *
     * Basin's messages start with the field's name ("Name can't be blank",
     * "Retention days must be less than or equal to 3650"), except for email
     * addresses ("Invalid email address: …"), which are matched to the field
     * that contains the address.
     *
     * @param array<string, mixed> $values The values that were sent
     */
    public static function fieldForMessage(string $message, array $values = []): ?string
    {
        if (preg_match('/^Invalid email address:\s*(.+)$/i', trim($message), $m)) {
            $address = strtolower(trim($m[1]));
            foreach ($values as $key => $value) {
                $type = self::FIELDS[$key] ?? null;
                if (($type === self::EMAILS || $type === self::EMAIL) && in_array($address, array_map('strtolower', self::splitEmails((string)$value)), true)) {
                    return $key;
                }
            }
            return null;
        }

        // Longest names first, so "Redirect url" doesn't claim "Redirect button text…".
        $keys = array_keys(self::FIELDS);
        usort($keys, fn(string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($keys as $key) {
            $label = str_replace('_', ' ', $key);
            if (stripos($message, $label) === 0) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Splits a list of email addresses on commas, semicolons and whitespace.
     *
     * @return list<string>
     */
    public static function splitEmails(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $unique = [];
        foreach ($parts as $part) {
            $unique[strtolower($part)] ??= $part;
        }
        return array_values($unique);
    }

    /**
     * @param list<string> $emails
     */
    public static function firstInvalidEmail(array $emails): ?string
    {
        foreach ($emails as $email) {
            if (!self::isEmail($email)) {
                return $email;
            }
        }
        return null;
    }

    public static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isTimezone(string $value): bool
    {
        return $value === 'UTC' || in_array($value, \DateTimeZone::listIdentifiers(), true);
    }

    private static function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private static function sameEmails(string $a, string $b): bool
    {
        $norm = function(string $list): array {
            $emails = array_map('strtolower', self::splitEmails($list));
            sort($emails);
            return $emails;
        };
        return $norm($a) === $norm($b);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
