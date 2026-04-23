<?php

declare(strict_types=1);

namespace SamuelTerra22\ReportGenerator\Tests\Unit;

use Flow\Parquet\Reader;
use Illuminate\Support\Facades\Storage;
use SamuelTerra22\ReportGenerator\ReportMedia\ParquetReport;
use SamuelTerra22\ReportGenerator\Tests\TestCase;

class ParquetReportTest extends TestCase
{
    private function makeReport(array $results = [], ?array $columns = null): ParquetReport
    {
        $resultObjects = array_map(fn ($row) => $this->makeResultObject($row), $results);

        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator($resultObjects));

        $report = new ParquetReport;
        $report->of(
            'Test Parquet',
            ['Period' => 'January'],
            $query,
            $columns ?? ['Name' => 'name', 'Amount' => 'amount']
        );

        return $report;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readBack(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'parquet-read-');
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

    public function test_make_returns_non_empty_parquet_bytes(): void
    {
        $report = $this->makeReport([
            ['name' => 'Alice', 'amount' => 100],
        ]);

        $bytes = $report->make();

        $this->assertNotEmpty($bytes);
        // Parquet magic bytes start with PAR1
        $this->assertSame('PAR1', substr($bytes, 0, 4));
        $this->assertSame('PAR1', substr($bytes, -4));
    }

    public function test_round_trip_preserves_rows_and_adds_row_number(): void
    {
        $report = $this->makeReport([
            ['name' => 'Alice', 'amount' => 100],
            ['name' => 'Bob', 'amount' => 250],
        ]);

        $rows = $this->readBack($report->make());

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['no']);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame(2, $rows[1]['no']);
        $this->assertSame('Bob', $rows[1]['name']);
    }

    public function test_show_num_column_false_omits_row_number(): void
    {
        $report = $this->makeReport([
            ['name' => 'Alice', 'amount' => 100],
        ]);
        $report->showNumColumn(false);

        $rows = $this->readBack($report->make());

        $this->assertArrayNotHasKey('no', $rows[0]);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function test_infers_integer_type_for_numeric_amount(): void
    {
        $report = $this->makeReport([
            ['name' => 'Alice', 'amount' => 100],
        ]);

        $rows = $this->readBack($report->make());

        // int inferred as int64, round-trips as int
        $this->assertIsInt($rows[0]['amount']);
        $this->assertSame(100, $rows[0]['amount']);
    }

    public function test_infers_double_type_for_float_values(): void
    {
        $report = $this->makeReport([
            ['name' => 'Alice', 'amount' => 99.95],
        ]);

        $rows = $this->readBack($report->make());

        $this->assertIsFloat($rows[0]['amount']);
        $this->assertEqualsWithDelta(99.95, $rows[0]['amount'], 0.0001);
    }

    public function test_schema_override_forces_declared_type(): void
    {
        // name would infer as string, force it through
        $report = $this->makeReport([
            ['name' => 'Alice', 'amount' => '100'],
        ]);
        $report->schema(['amount' => 'int64']);

        $rows = $this->readBack($report->make());

        $this->assertIsInt($rows[0]['amount']);
        $this->assertSame(100, $rows[0]['amount']);
    }

    public function test_closure_column_value_is_written(): void
    {
        $resultObjects = [$this->makeResultObject(['name' => 'alice', 'amount' => 10])];

        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator($resultObjects));

        $report = new ParquetReport;
        $report->of('Test', [], $query, [
            'Name' => fn ($r) => strtoupper($r->name),
            'Amount' => 'amount',
        ]);

        $rows = $this->readBack($report->make());

        $this->assertSame('ALICE', $rows[0]['name']);
        $this->assertSame(10, $rows[0]['amount']);
    }

    public function test_limit_applies(): void
    {
        $query = \Mockery::mock('Illuminate\Database\Query\Builder');

        $query->shouldReceive('take')
            ->with(2)
            ->andReturnUsing(function () {
                $limited = \Mockery::mock('Illuminate\Database\Query\Builder');
                $limited->shouldReceive('cursor')->andReturn(new \ArrayIterator([
                    $this->makeResultObject(['name' => 'Alice', 'amount' => 1]),
                    $this->makeResultObject(['name' => 'Bob', 'amount' => 2]),
                ]));

                return $limited;
            });

        $report = new ParquetReport;
        $report->of('Test', [], $query, ['Name' => 'name', 'Amount' => 'amount']);
        $report->limit(2);

        $rows = $this->readBack($report->make());

        $this->assertCount(2, $rows);
    }

    public function test_display_name_is_snaked_for_parquet_column(): void
    {
        $report = $this->makeReport(
            [['name' => 'Alice', 'amount' => 10]],
            ['Full Name' => 'name', 'Total Amount' => 'amount']
        );

        $rows = $this->readBack($report->make());

        $this->assertArrayHasKey('full_name', $rows[0]);
        $this->assertArrayHasKey('total_amount', $rows[0]);
        $this->assertSame('Alice', $rows[0]['full_name']);
        $this->assertSame(10, $rows[0]['total_amount']);
    }

    public function test_edit_column_and_format_column_are_ignored_for_type_fidelity(): void
    {
        $report = $this->makeReport([
            ['name' => 'Alice', 'amount' => 100],
        ]);
        // Would stringify to "$100.00" in CSV/PDF — parquet must keep raw.
        $report->editColumn('Amount', [
            'displayAs' => fn ($r) => '$'.number_format((float) $r->amount, 2),
        ]);
        $report->formatColumn('Amount', 'currency', ['prefix' => 'R$']);

        $rows = $this->readBack($report->make());

        $this->assertIsInt($rows[0]['amount']);
        $this->assertSame(100, $rows[0]['amount']);
    }

    public function test_on_row_callback_fires_for_each_row(): void
    {
        $seen = [];

        $report = $this->makeReport([
            ['name' => 'Alice', 'amount' => 1],
            ['name' => 'Bob', 'amount' => 2],
        ]);
        $report->onRow(function ($row, int $index) use (&$seen) {
            $seen[] = [$index, $row->name];
        });

        $report->make();

        $this->assertSame([[0, 'Alice'], [1, 'Bob']], $seen);
    }

    public function test_lifecycle_callbacks_fire_in_order(): void
    {
        $calls = [];

        $report = $this->makeReport([['name' => 'A', 'amount' => 1]]);
        $report->onBeforeRender(function () use (&$calls) {
            $calls[] = 'before';
        });
        $report->onAfterRender(function () use (&$calls) {
            $calls[] = 'after';
        });
        $report->onComplete(function () use (&$calls) {
            $calls[] = 'complete';
        });

        $report->make();

        $this->assertSame(['before', 'after', 'complete'], $calls);
    }

    public function test_unsupported_schema_type_throws(): void
    {
        $report = $this->makeReport([['name' => 'A', 'amount' => 1]]);
        $report->schema(['amount' => 'bogus']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Unsupported Parquet schema type/');

        $report->make();
    }

    public function test_store_writes_to_disk_and_appends_extension(): void
    {
        Storage::fake('local');

        $report = $this->makeReport([['name' => 'Alice', 'amount' => 1]]);

        $path = $report->store('local', 'reports/users');

        $this->assertSame('reports/users.parquet', $path);
        $this->assertTrue(Storage::disk('local')->exists('reports/users.parquet'));
    }

    public function test_store_keeps_existing_parquet_extension(): void
    {
        Storage::fake('local');

        $report = $this->makeReport([['name' => 'Alice', 'amount' => 1]]);

        $path = $report->store('local', 'reports/users.parquet');

        $this->assertSame('reports/users.parquet', $path);
    }
}
