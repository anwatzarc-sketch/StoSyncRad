<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

enum BrandAssetKind: string implements SystemEnum
{
    use HasSystemEnum;

    case LOGO_LIGHT = 'logo_light';
    case LOGO_DARK = 'logo_dark';
    case LOGO_MARK = 'logo_mark';
    case FAVICON_SOURCE = 'favicon_source';
    case PWA_ICON_SOURCE = 'pwa_icon_source';
    case LOGIN_BACKGROUND = 'login_background';
    case LETTERHEAD_HEADER = 'letterhead_header';
    case LETTERHEAD_FOOTER = 'letterhead_footer';
    case EMAIL_HEADER = 'email_header';
    case EMAIL_FOOTER = 'email_footer';
    case FONT_FILE = 'font_file';
    case DERIVED_ICON = 'derived_icon';
}
