<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

enum TemplateEngine: string implements SystemEnum
{
    use HasSystemEnum;

    case SAFE_HTML = 'safe_html';   // sandboxed {{ }} syntax (SafeTemplateRenderer) — never PHP/Blade
    case MJML = 'mjml';
    case LABEL_ZPL = 'label_zpl';
    case LABEL_HTML = 'label_html';
}
