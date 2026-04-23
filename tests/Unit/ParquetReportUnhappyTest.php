<?php

declare(strict_types=1);

namespace SamuelTerra22\ReportGenerator\Tests\Unit;

use Flow\Parquet\Reader;
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

        $rows = $this->readBack($report->make());

        $this->assertTrue($rows[0]['active']);
        $this->assertFalse($rows[1]['active']);
    }

    public function test_null_values_round_trip_when_schema_declares_type(): void
    {
        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator([
            $this->makeResultObject(['name' => 'Alice', 'amount' => 100]),
            $this->makeResultObject(['name' => 'Nully', 'amount' => null]),
        ]));

        $report = new ParquetReport;
        $report->of('T', [], $query, ['Name' => 'name', 'Amount' => 'amount']);
        $report->schema(['amount' => 'int64']);

        $rows = $this->readBack($report->make());

        $this->assertSame(100, $rows[0]['amount']);
        $this->assertNull($rows[1]['amount']);
    }

    public function test_int32_schema_override_round_trips(): void
    {
        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator([
            $this->makeResultObject(['name' => 'A', 'qty' => '7']),
        ]));

        $report = new ParquetReport;
        $report->of('T', [], $query, ['Name' => 'name', 'Qty' => 'qty']);
        $report->showNumColumn(false);
        $report->schema(['qty' => 'int32']);

        $rows = $this->readBack($report->make());

        $this->assertSame(7, $rows[0]['qty']);
        $this->assertIsInt($rows[0]['qty']);
    }

    public function test_float_schema_override_round_trips(): void
    {
        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator([
            $this->makeResultObject(['name' => 'A', 'ratio' => '0.5']),
        ]));

        $report = new ParquetReport;
        $report->of('T', [], $query, ['Name' => 'name', 'Ratio' => 'ratio']);
        $report->showNumColumn(false);
        $report->schema(['ratio' => 'float']);

        $rows = $this->readBack($report->make());

        $this->assertEqualsWithDelta(0.5, $rows[0]['ratio'], 0.0001);
        $this->assertIsFloat($rows[0]['ratio']);
    }

    public function test_datetime_from_string_is_coerced(): void
    {
        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator([
            $this->makeResultObject(['name' => 'A', 'at' => '2025-03-15 10:30:00']),
        ]));

        $report = new ParquetReport;
        $report->of('T', [], $query, ['Name' => 'name', 'At' => 'at']);
        $report->showNumColumn(false);
        $report->schema(['at' => 'datetime']);

        $rows = $this->readBack($report->make());

        $this->assertInstanceOf(\DateTimeInterface::class, $rows[0]['at']);
        $this->assertSame('2025-03-15 10:30:00', $rows[0]['at']->format('Y-m-d H:i:s'));
    }

    public function test_datetime_from_datetime_instance_passes_through(): void
    {
        $when = new \DateTimeImmutable('2025-06-01 09:15:00');

        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator([
            $this->makeResultObject(['name' => 'A', 'at' => $when]),
        ]));

        $report = new ParquetReport;
        $report->of('T', [], $query, ['Name' => 'name', 'At' => 'at']);
        $report->showNumColumn(false);

        $rows = $this->readBack($report->make());

        $this->assertInstanceOf(\DateTimeInterface::class, $rows[0]['at']);
        $this->assertSame('2025-06-01 09:15:00', $rows[0]['at']->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readBack(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'parquet-unhappy-');
        file_put_contents($tmp, $bytes);

        try {
            $reader = new Reader;
            $file = $reader->read($tmp);
            $rows = [];
            foreach ($file->values() as $row) {
                $rows[] = $row;
            }

            return $rows;
        } finally {
            @unlink($tmp);
        }
    }

    public function test_flow_library_available_in_dev(): void
    {
        // Guard in ParquetReport::make() throws when the Writer class is
        // missing; this assertion keeps the happy path covered in dev so a
        // future dependency rename is caught here first.
        $this->assertTrue(class_exists(Writer::class));
    }
}
