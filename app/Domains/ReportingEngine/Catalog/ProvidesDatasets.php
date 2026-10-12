<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Catalog;

/** A group of dataset definitions (one class per module under ReportingEngine/Datasets). */
interface ProvidesDatasets
{
    /** @return array<int, DatasetDefinition> */
    public static function datasets(): array;
}
