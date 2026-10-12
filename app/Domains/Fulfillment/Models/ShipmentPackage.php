<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Physical package in a shipment. */
final class ShipmentPackage extends BaseModel
{
    protected $table = 'shipment_packages';

    protected $fillable = ['shipment_id', 'package_no', 'packaging_type_lookup_value_id', 'weight', 'dimensions', 'tracking_number'];

    protected function casts(): array
    {
        return [
            'dimensions' => 'array',
            'package_no' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentPackageLine::class, 'shipment_package_id');
    }
}
