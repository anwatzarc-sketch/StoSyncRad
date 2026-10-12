<?php

declare(strict_types=1);

namespace App\Support\Validation;

/**
 * Machine-readable validation error codes (Chapter 05 §5.4, Chapter 11 §11.4).
 *
 * Laravel's validator only carries string messages, so message() appends a hidden,
 * parseable payload marker that ApiErrorResponder extracts into the error envelope.
 */
enum ValidationErrorCode: string
{
    case REQUIRED = 'REQUIRED';
    case NULL_NOT_ALLOWED = 'NULL_NOT_ALLOWED';
    case TYPE_MISMATCH = 'TYPE_MISMATCH';
    case ENUM_INVALID = 'ENUM_INVALID';
    case RANGE_INVALID = 'RANGE_INVALID';
    case LENGTH_EXCEEDED = 'LENGTH_EXCEEDED';
    case PRECISION_EXCEEDED = 'PRECISION_EXCEEDED';
    case FORMAT_INVALID = 'FORMAT_INVALID';
    case DATE_ORDER_INVALID = 'DATE_ORDER_INVALID';
    case INVALID_IDENTIFIER = 'INVALID_IDENTIFIER';
    case REFERENCE_NOT_FOUND = 'REFERENCE_NOT_FOUND';
    case REFERENCE_INACTIVE = 'REFERENCE_INACTIVE';
    case UNKNOWN_PROPERTY = 'UNKNOWN_PROPERTY';
    case SERVER_CONTROLLED_PROPERTY = 'SERVER_CONTROLLED_PROPERTY';
    case PROPERTY_FORBIDDEN = 'PROPERTY_FORBIDDEN';
    case PROPERTY_IMMUTABLE = 'PROPERTY_IMMUTABLE';
    case DUPLICATE_VALUE = 'DUPLICATE_VALUE';
    case ITEMS_INVALID = 'ITEMS_INVALID';
    case CUSTOM = 'CUSTOM';

    public const MARKER = "\u{1F}ssr:";

    /**
     * Build a human-readable message with an embedded machine payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function message(string $attribute, array $payload = [], ?string $text = null): string
    {
        $text ??= $this->defaultText($attribute, $payload);

        return $text.self::MARKER.json_encode(['code' => $this->value] + $payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Split a validator message into [text, payload].
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function parse(string $message): array
    {
        $pos = strpos($message, self::MARKER);
        if ($pos === false) {
            return [$message, ['code' => self::CUSTOM->value]];
        }

        $payload = json_decode(substr($message, $pos + strlen(self::MARKER)), true);

        return [substr($message, 0, $pos), is_array($payload) ? $payload : ['code' => self::CUSTOM->value]];
    }

    /** @param  array<string, mixed>  $p */
    private function defaultText(string $attribute, array $p): string
    {
        $name = '`'.$attribute.'`';

        return match ($this) {
            self::REQUIRED => "$name is required.",
            self::NULL_NOT_ALLOWED => "$name cannot be null.",
            self::TYPE_MISMATCH => "$name must be of type {$p['expected']}.".(isset($p['received']) ? " Received: `{$p['received']}`." : ''),
            self::ENUM_INVALID => "$name must be one of: ".implode(', ', $p['allowed_values'] ?? []).'.'.(isset($p['received']) ? " Received: `{$p['received']}`." : ''),
            self::RANGE_INVALID => "$name is out of range".(isset($p['min']) ? " (min {$p['min']}" : '').(isset($p['max']) ? (isset($p['min']) ? ", max {$p['max']})" : " (max {$p['max']})") : (isset($p['min']) ? ')' : '')).'.',
            self::LENGTH_EXCEEDED => "$name must not exceed {$p['max']} characters.",
            self::PRECISION_EXCEEDED => "$name allows at most {$p['scale']} decimal places (values are never rounded silently).",
            self::FORMAT_INVALID => "$name has an invalid format.".(isset($p['expected']) ? " Expected: {$p['expected']}." : ''),
            self::DATE_ORDER_INVALID => "$name must be {$p['relation']} `{$p['other']}`.",
            self::INVALID_IDENTIFIER => "$name must be a valid UUID.",
            self::REFERENCE_NOT_FOUND => "$name references a record that does not exist.",
            self::REFERENCE_INACTIVE => "$name references an inactive or archived record.",
            self::UNKNOWN_PROPERTY => "$name is not a recognised property.",
            self::SERVER_CONTROLLED_PROPERTY => "$name is controlled by the server and cannot be supplied.",
            self::PROPERTY_FORBIDDEN => "You are not permitted to set $name.",
            self::PROPERTY_IMMUTABLE => "$name cannot be changed in the current state.",
            self::DUPLICATE_VALUE => "$name must be unique.",
            self::ITEMS_INVALID => "$name contains invalid items.",
            self::CUSTOM => "$name is invalid.",
        };
    }
}
