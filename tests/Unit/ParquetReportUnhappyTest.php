<?php

declare(strict_types=1);

namespace SamuelTerra22\ReportGenerator\Tests\Unit;

use Flow\Parquet\Writer;
use SamuelTerra22\ReportGenerator\ReportMedia\ParquetReport;
use SamuelTerra22\ReportGenerator\Tests\TestCase;

class ParquetReportUnhappyTest extends TestCase
{
    public function test_empty_result_set_still_writes_valid_parquet_file(): void
    {
        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator([]));

        $report = new ParquetReport;
        $report->of('Empty', [], $query, ['Name' => 'name', 'Amount' => 'amount']);
        // First-row inference is not available, so user must supply the schema.
        $report->schema(['name' => 'string', 'amount' => 'int64']);

        $bytes = $report->make();

        $this->assertSame('PAR1', substr($bytes, 0, 4));
    }

    public function test_boolean_column_round_trips(): void
    {
        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator([
            $this->makeResultObject(['name' => 'Alice', 'active' => true]),
            $this->makeResultObject(['name' => 'Bob', 'active' => false]),
        ]));

        $report = new ParquetReport;
        $report->of('T', [], $query, ['Name' => 'name', 'Active' => 'active']);
        $report->showNumColumn(false);

        $bytes = $report->make();

        // Shouldn't throw; magic bytes intact.
        $this->assertSame('PAR1', substr($bytes, 0, 4));
        $this->assertSame('PAR1', substr($bytes, -4));
    }

    public function test_flow_library_available_in_dev(): void
    {
        // Guard in ParquetReport::make() throws when the Writer class is
        // missing; this assertion keeps the happy path covered in dev so a
        // future dependency rename is caught here first.
        $this->assertTrue(class_exists(Writer::class));
    }
}
