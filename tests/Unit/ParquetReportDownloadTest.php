<?php

declare(strict_types=1);

namespace SamuelTerra22\ReportGenerator\Tests\Unit;

use SamuelTerra22\ReportGenerator\ReportMedia\ParquetReport;
use SamuelTerra22\ReportGenerator\Tests\TestCase;

/**
 * download() writes bytes to stdout via `echo` plus `header()` calls.
 * In the CLI the header calls produce "headers already sent" warnings
 * which we suppress — the behaviour we actually care about here is that
 * the emitted payload is a valid Parquet file. Extension/filename logic
 * is already covered by the store() tests.
 */
class ParquetReportDownloadTest extends TestCase
{
    private function buildReport(): ParquetReport
    {
        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator([
            $this->makeResultObject(['name' => 'Alice', 'amount' => 100]),
        ]));

        $report = new ParquetReport;
        $report->of('T', [], $query, ['Name' => 'name', 'Amount' => 'amount']);
        $report->showNumColumn(false);

        return $report;
    }

    public function test_download_emits_valid_parquet_bytes_to_stdout(): void
    {
        ob_start();
        @$this->buildReport()->download('sales');
        $output = ob_get_clean();

        $this->assertNotEmpty($output);
        $this->assertSame('PAR1', substr($output, 0, 4));
        $this->assertSame('PAR1', substr($output, -4));
    }

    public function test_download_output_matches_make_bytes(): void
    {
        $fromMake = $this->buildReport()->make();

        ob_start();
        @$this->buildReport()->download('x');
        $fromDownload = ob_get_clean();

        // Both reports are built identically; their bytes must match.
        $this->assertSame(strlen($fromMake), strlen($fromDownload));
        $this->assertSame($fromMake, $fromDownload);
    }
}
