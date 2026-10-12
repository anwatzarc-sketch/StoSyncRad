<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Billing\Models\TaxRate;
use App\Domains\Billing\Schemas\TaxRateSchema;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Facades\DB;

/**
 * Tax rates: master data, and the rate that applies to an invoice line — the line's own rate, else the
 * item's tax rate, else the default rate, else `billing.invoice.tax_rate_percent`.
 */
final class TaxRates
{
    public function __construct(private readonly PlatformConfig $config, private readonly AuditRecorder $audit) {}

    /** @param  array<string, mixed>  $data  validated against TaxRateSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): TaxRate
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            $rate = $id === null ? new TaxRate : TaxRate::query()->lockForUpdate()->findOrFail($id);
            $before = $rate->exists ? $rate->only(['code', 'name', 'rate', 'is_default', 'is_active']) : null;
            $rate->fill(TaxRateSchema::columns($data));
            if ($rate->is_default) {
                TaxRate::query()->where('is_default', true)->when($rate->exists, fn ($q) => $q->whereKeyNot($rate->id))->update(['is_default' => false]);
            }
            $rate->forceFill(['created_by' => $rate->created_by ?? $actor->id, 'updated_by' => $actor->id])->save();
            $this->audit->record($actor, $before === null ? 'billing.tax_rate.created' : 'billing.tax_rate.updated', 'tax_rates', $rate->id, $before,
                $rate->only(['code', 'name', 'rate', 'is_default', 'is_active']));

            return $rate;
        });
    }

    /** Rate (percent) for an item, falling back to the default rate and then the configured percentage. */
    public function forItem(?string $itemId): string
    {
        if ($itemId !== null) {
            $own = DB::table('items as i')->join('tax_rates as t', 't.id', '=', 'i.tax_rate_id')->where('i.id', $itemId)->where('t.is_active', true)->value('t.rate');
            if ($own !== null) {
                return D::exact((string) $own, 4);
            }
        }

        return $this->default();
    }

    public function default(): string
    {
        $default = TaxRate::query()->where('is_default', true)->where('is_active', true)->value('rate');

        return D::exact((string) ($default ?? $this->config->get('billing.invoice.tax_rate_percent') ?? '0'), 4);
    }
}
