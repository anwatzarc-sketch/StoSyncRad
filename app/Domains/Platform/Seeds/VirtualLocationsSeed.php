<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use App\Domains\Inventory\Services\InventoryLocationResolver;
use Illuminate\Support\Facades\DB;

/** Company-level virtual inventory locations (Chapter 06 §6.5). */
final class VirtualLocationsSeed extends Seed
{
    public function __construct(private readonly InventoryLocationResolver $resolver) {}

    public function name(): string
    {
        return 'virtual_locations';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function run(array $options = []): array
    {
        $before = DB::table('inventory_locations')->where('code', 'like', 'VIRT:%')->count();
        $this->resolver->seedVirtualLocations();

        return ['inserted' => DB::table('inventory_locations')->where('code', 'like', 'VIRT:%')->count() - $before];
    }
}
