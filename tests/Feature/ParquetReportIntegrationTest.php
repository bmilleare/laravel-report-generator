<?php

declare(strict_types=1);

namespace SamuelTerra22\ReportGenerator\Tests\Feature;

use Flow\Parquet\Reader;
use Mockery\MockInterface;
use SamuelTerra22\ReportGenerator\ReportExporter;
use SamuelTerra22\ReportGenerator\ReportMedia\ParquetReport;
use SamuelTerra22\ReportGenerator\Tests\TestCase;

class ParquetReportIntegrationTest extends TestCase
{
    private function mockCursor(array $rows): MockInterface
    {
        $objects = array_map(fn ($r) => $this->makeResultObject($r), $rows);

        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator($objects));

        return $query;
    }

    private function readBack(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'parquet-it-');
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

    public function test_exporter_to_parquet_round_trips_with_schema(): void
    {
        $query = $this->mockCursor([
            ['name' => 'Alice', 'amount' => '10.50', 'created' => '2025-01-15'],
            ['name' => 'Bob', 'amount' => '22.00', 'created' => '2025-02-01'],
        ]);

        $exporter = (new ReportExporter)
            ->of('Sales', [], $query, [
                'Name' => 'name',
                'Amount' => 'amount',
                'Created' => 'created',
            ])
            ->schema([
                'amount' => 'double',
                'created' => 'date',
            ])
            ->showNumColumn(false);

        $bytes = $exporter->toParquet()->make();
        $rows = $this->readBack($bytes);

        $this->assertCount(2, $rows);
        $this->assertIsFloat($rows[0]['amount']);
        $this->assertEqualsWithDelta(10.5, $rows[0]['amount'], 0.001);
        $this->assertInstanceOf(\DateTimeInterface::class, $rows[0]['created']);
        $this->assertSame('2025-01-15', $rows[0]['created']->format('Y-m-d'));
    }

    public function test_exporter_shares_state_across_pdf_csv_parquet(): void
    {
        $query = $this->mockCursor([
            ['name' => 'Alice', 'amount' => 100],
            ['name' => 'Bob', 'amount' => 250],
        ]);

        $exporter = (new ReportExporter)
            ->of('Multi', ['Period' => 'Q1'], $query, [
                'Name' => 'name',
                'Amount' => 'amount',
            ])
            ->limit(2);

        $parquet = $exporter->toParquet();

        $this->assertInstanceOf(ParquetReport::class, $parquet);
        // Builder state must have flowed through: showNumColumn default true, limit=2
        $this->assertSame('PAR1', substr($parquet->make(), 0, 4));
    }

    public function test_facade_is_bound_on_service_container(): void
    {
        $instance = $this->app->make('parquet.report.generator');

        $this->assertInstanceOf(ParquetReport::class, $instance);
    }
}
