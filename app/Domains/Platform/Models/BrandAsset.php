<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\BrandAssetKind;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $brand_kit_id
 * @property BrandAssetKind $kind
 * @property string|null $variant
 * @property string $path
 * @property string|null $public_path
 * @property string $mime
 * @property string $checksum
 * @property int|null $width
 * @property int|null $height
 * @property int $bytes
 */
final class BrandAsset extends BaseModel
{
    protected $table = 'brand_assets';

    protected $fillable = ['brand_kit_id', 'kind', 'variant', 'path', 'public_path', 'mime', 'checksum', 'width', 'height', 'bytes'];

    protected function casts(): array
    {
        return ['kind' => BrandAssetKind::class, 'width' => 'integer', 'height' => 'integer', 'bytes' => 'integer'];
    }

    public function brandKit(): BelongsTo
    {
        return $this->belongsTo(BrandKit::class);
    }
}
