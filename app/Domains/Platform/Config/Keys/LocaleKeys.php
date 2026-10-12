<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;
use App\Support\Enums\CalendarSystem;
use App\Support\Enums\UnitSystem;
use App\Support\Enums\WeekDay;

final class LocaleKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::str('locale.default', 'en', 'Default locale', 'Default interface language (IETF tag, e.g. en, am, om).', 'GB', ['pattern' => '/^[a-z]{2,3}(-[A-Z]{2})?$/']),
            self::list('locale.enabled', ['en'], 'Enabled locales', 'Languages users may switch to.', 'G', null, 'string', 1, 20),
            self::str('locale.timezone', 'Africa/Addis_Ababa', 'Timezone', 'Operational timezone for display and business dates (IANA name).', 'GB', ['pattern' => '/^[A-Za-z_]+(\/[A-Za-z0-9_+\-]+)*$/']),
            self::enum('locale.secondary_calendar', CalendarSystem::class, 'none', 'Secondary calendar', 'Optional secondary display calendar shown alongside Gregorian dates.'),
            self::str('locale.date_format', 'Y-m-d', 'Date format', 'PHP-style date display format.', 'G', ['max_length' => 20]),
            self::str('locale.time_format', 'H:i', 'Time format', 'PHP-style time display format.', 'G', ['max_length' => 20]),
            self::str('locale.datetime_format', 'Y-m-d H:i', 'Date-time format', 'PHP-style date-time display format.', 'G', ['max_length' => 30]),
            self::str('locale.number.decimal_separator', '.', 'Decimal separator', 'Character used as the decimal separator in displays and exports.', 'G', ['choices' => ['.', ',']]),
            self::str('locale.number.grouping_separator', ',', 'Grouping separator', 'Character used to group thousands.', 'G', ['choices' => [',', '.', ' ', "'", '']]),
            self::int('locale.quantity_display_precision', 2, 'Quantity display precision', 'Decimal places shown for quantities (storage precision is always 6).', 'GCI', 0, 6),
            self::str('locale.currency.base', 'ETB', 'Base currency', 'ISO 4217 code of the company base currency.', 'G', ['pattern' => '/^[A-Z]{3}$/']),
            self::list('locale.currency.enabled', ['ETB'], 'Enabled currencies', 'Currencies allowed on purchasing documents.', 'G', null, 'string', 1, 50),
            self::int('locale.currency.display_precision', 2, 'Currency display precision', 'Decimal places shown for money.', 'G', 0, 4),
            self::enum('locale.first_day_of_week', WeekDay::class, 'monday', 'First day of week', 'First day shown in calendars and weekly reports.'),
            self::enum('locale.unit_system', UnitSystem::class, 'metric', 'Unit system', 'Default unit system for weight, volume and length.'),
        ];
    }
}
