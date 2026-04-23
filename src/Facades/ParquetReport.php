<?php

declare(strict_types=1);

namespace SamuelTerra22\ReportGenerator\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SamuelTerra22\ReportGenerator\ReportMedia\ParquetReport
 */
class ParquetReport extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'parquet.report.generator';
    }
}
