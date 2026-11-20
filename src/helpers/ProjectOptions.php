<?php

namespace heano\basin\helpers;

/**
 * Options for the Basin project dropdown on the settings page: the account's
 * projects, then the environment variables that hold one of their IDs.
 *
 * Craft's own `includeEnvVars` can't be used, as it skips numeric values. The
 * saved value is always one of the options, so saving the page never changes
 * the project without the admin choosing to.
 */
final class ProjectOptions
{
    /**
     * @param array<int, string> $projects Project ID => name, as Basin lists them
     * @param string $current The saved value: a project ID, an env var reference, or ''
     * @param array<string, string> $env Environment variable name => value
     * @param array{choose: string, unknown: string, notSet: string, envVars: string} $labels
     *        `unknown` labels a saved ID that isn't in `$projects`.
     * @return list<array<string, mixed>> Options for Craft's select and selectize inputs
     */
    public static function build(array $projects, string $current, array $env, array $labels): array
    {
        $options = [];
        if ($current === '') {
            $options[] = ['label' => $labels['choose'], 'value' => ''];
        }

        $values = [];
        foreach ($projects as $id => $name) {
            $options[] = ['label' => $name, 'value' => (string)$id, 'data' => ['hint' => (string)$id]];
            $values[(string)$id] = true;
        }

        if ($current !== '' && !str_starts_with($current, '$') && !isset($values[$current])) {
            $options[] = ['label' => $labels['unknown'], 'value' => $current, 'data' => ['hint' => $current]];
        }

        $envOptions = [];
        foreach ($env as $name => $value) {
            if (ctype_digit($value) && isset($projects[(int)$value])) {
                $envOptions['$' . $name] = ['label' => '$' . $name, 'value' => '$' . $name, 'data' => ['hint' => $projects[(int)$value]]];
            }
        }
        if (str_starts_with($current, '$') && !isset($envOptions[$current])) {
            $value = $env[substr($current, 1)] ?? '';
            $envOptions[$current] = ['label' => $current, 'value' => $current, 'data' => ['hint' => $value === '' ? $labels['notSet'] : $value]];
        }
        if ($envOptions) {
            ksort($envOptions);
            $options[] = ['optgroup' => $labels['envVars']];
            array_push($options, ...array_values($envOptions));
        }

        return $options;
    }
}
