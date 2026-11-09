<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Support;

/**
 * Normalises raw ANAF values before they reach a typed DTO property.
 *
 * ANAF does not keep its scalar types stable: ids and counts arrive as numbers or
 * strings, Errors as a string, an object or a list. Taken verbatim under
 * strict_types, a wrong type is a TypeError that loses an upload index or crashes
 * a sync. Each method maps what it understands and returns null for the rest.
 *
 * @internal Not part of the public API; may change without notice.
 */
final class AnafValue
{
    /**
     * An identifier (index_incarcare, id_descarcare, cui, ...).
     *
     * An int becomes its digits, a string is trimmed (other strings kept as they
     * are), and an empty string is null. Floats, bools, arrays and null are null:
     * a float id has already lost digits, so it is never trusted. Big ids survive
     * only when the JSON was decoded with JSON_BIGINT_AS_STRING.
     */
    public static function id(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * An explicit yes from ANAF: true, 1, "1" or "true" (any case, trimmed).
     *
     * Everything else is no, so a string "false" or "0" never reads as yes the way a
     * plain (bool) cast would.
     */
    public static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true'], true);
    }

    /**
     * Free text: any scalar becomes a string, anything else is null.
     */
    public static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * A whole number: an int, a whole float or a string of digits (optionally signed).
     */
    public static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1) {
            return filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
        }

        return null;
    }

    /**
     * ANAF's Errors in any of its shapes, as a list of messages.
     *
     * A string is one message; an object with errorMessage is one message; a list
     * may hold strings, numbers and {errorMessage} objects. Any other object,
     * bools, nested lists, empty and whitespace-only messages are dropped. Null
     * when nothing is left.
     *
     * @return non-empty-list<string>|null
     */
    public static function errors(mixed $value): ?array
    {
        // An object is one entry only when it carries errorMessage; any other object is no list.
        if (is_array($value) && ! array_is_list($value)) {
            $value = array_key_exists('errorMessage', $value) ? [$value] : [];
        }

        $messages = [];

        foreach (is_array($value) ? $value : [$value] as $entry) {
            $message = is_array($entry) ? ($entry['errorMessage'] ?? null) : $entry;

            if (is_scalar($message) && ! is_bool($message) && trim((string) $message) !== '') {
                $messages[] = (string) $message;
            }
        }

        return $messages === [] ? null : $messages;
    }
}
