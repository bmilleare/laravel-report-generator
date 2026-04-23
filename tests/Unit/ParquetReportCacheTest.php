<?php

declare(strict_types=1);

namespace SamuelTerra22\ReportGenerator\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Mockery;
use SamuelTerra22\ReportGenerator\ReportMedia\ParquetReport;
use SamuelTerra22\ReportGenerator\Tests\TestCase;

class ParquetReportCacheTest extends TestCase
{
    private function makeQueryWithResults(array $results): \Mockery\MockInterface
    {
        $resultObjects = array_map(fn ($row) => $this->makeResultObject($row), $results);

        $query = Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator($resultObjects));

        return $query;
    }

    public function test_cache_stores_parquet_bytes_on_miss(): void
    {
        $query = $this->makeQueryWithResults([
            ['name' => 'Alice', 'amount' => 100],
        ]);

        $report = new ParquetReport;
        $report->of('Test', [], $query, ['Name' => 'name', 'Amount' => 'amount'])
            ->cacheFor(60)
            ->cacheAs('parquet-test-key');

        $report->make();

        $cached = Cache::get('parquet-test-key');
        $this->assertIsString($cached);
        $this->assertSame('PAR1', substr($cached, 0, 4));
    }

    public function test_cache_hit_returns_cached_bytes_without_regenerating(): void
    {
        // Seed a sentinel that couldn't possibly be the output of generating
        // from the current query — proves the cache hit short-circuits.
        $sentinel = "PAR1-SENTINEL-BYTES\0".str_repeat('x', 32).'PAR1';
        Cache::put('parquet-cache-hit', $sentinel, 3600);

        // Query that would throw if actually iterated — guarantees make()
        // never reaches the collect/write path on a hit.
        $query = Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andThrow(new \RuntimeException('cursor should not be called on cache hit'));

        $report = new ParquetReport;
        $report->of('Test', [], $query, ['Name' => 'name', 'Amount' => 'amount'])
            ->cacheFor(60)
            ->cacheAs('parquet-cache-hit');

        $this->assertSame($sentinel, $report->make());
    }

    public function test_cache_hit_still_fires_after_render_and_complete_callbacks(): void
    {
        Cache::put('parquet-cb-hit', 'PAR1cachedPAR1', 3600);

        $query = $this->makeQueryWithResults([]);

        $calls = [];
        $report = new ParquetReport;
        $report->of('Test', [], $query, ['Name' => 'name', 'Amount' => 'amount'])
            ->cacheFor(60)
            ->cacheAs('parquet-cb-hit')
            ->onBeforeRender(function () use (&$calls) {
                $calls[] = 'before';
            })
            ->onAfterRender(function () use (&$calls) {
                $calls[] = 'after';
            })
            ->onComplete(function () use (&$calls) {
                $calls[] = 'complete';
            });

        $report->make();

        $this->assertSame(['before', 'after', 'complete'], $calls);
    }

    public function test_no_cache_skips_parquet_storage(): void
    {
        $query = $this->makeQueryWithResults([
            ['name' => 'Bob', 'amount' => 200],
        ]);

        $report = new ParquetReport;
        $report->of('Test', [], $query, ['Name' => 'name', 'Amount' => 'amount'])
            ->cacheFor(60)
            ->cacheAs('parquet-no-cache-key')
            ->noCache();

        $report->make();

        $this->assertNull(Cache::get('parquet-no-cache-key'));
    }
}
