<?php

declare(strict_types=1);

namespace SamuelTerra22\ReportGenerator\Tests\Feature;

use SamuelTerra22\ReportGenerator\ReportMedia\ParquetReport;
use SamuelTerra22\ReportGenerator\Tests\TestCase;

/**
 * Cross-validates Parquet output against an independent reader (pyarrow /
 * Apache Arrow C++). Our other round-trip tests read flow-php output with
 * flow-php, which cannot detect a mutually-consistent-but-spec-incorrect bug.
 * pyarrow is the de-facto Parquet reference — if it parses our file and the
 * values match, the file is genuinely conformant.
 *
 * The test auto-skips when the local validator is not provisioned, so CI
 * without Python support remains green.
 */
class ParquetCrossValidationTest extends TestCase
{
    private const VENV_PYTHON = __DIR__.'/../../.venv-parquet-verify/bin/python';

    private const VALIDATOR = __DIR__.'/../Stubs/parquet_inspect.py';

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable(self::VENV_PYTHON)) {
            $this->markTestSkipped('pyarrow venv not provisioned — run: python3 -m venv .venv-parquet-verify && .venv-parquet-verify/bin/pip install pyarrow');
        }

        if (! is_file(self::VALIDATOR)) {
            $this->markTestSkipped('parquet_inspect.py stub missing');
        }
    }

    /**
     * @return array{num_rows:int, schema:array<int, array{name:string,type:string}>, rows:array<int, array<string, mixed>>}
     */
    private function inspectWithPyarrow(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'parquet-xval-');
        file_put_contents($tmp, $bytes);

        try {
            $cmd = escapeshellarg(self::VENV_PYTHON)
                .' '.escapeshellarg(self::VALIDATOR)
                .' '.escapeshellarg($tmp)
                .' 2>&1';
            $raw = shell_exec($cmd);
            $this->assertIsString($raw, 'pyarrow validator returned no output');

            $decoded = json_decode($raw, true);
            $this->assertIsArray($decoded, "pyarrow output was not JSON: {$raw}");

            return $decoded;
        } finally {
            @unlink($tmp);
        }
    }

    private function makeQuery(array $rows): \Mockery\MockInterface
    {
        $objects = array_map(fn ($r) => $this->makeResultObject($r), $rows);
        $query = \Mockery::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('take')->andReturnSelf();
        $query->shouldReceive('when')->andReturnSelf();
        $query->shouldReceive('cursor')->andReturn(new \ArrayIterator($objects));

        return $query;
    }

    public function test_pyarrow_reads_basic_output_and_values_match(): void
    {
        $query = $this->makeQuery([
            ['name' => 'Alice', 'amount' => 100],
            ['name' => 'Bob', 'amount' => 250],
        ]);

        $report = new ParquetReport;
        $report->of('Xval', [], $query, ['Name' => 'name', 'Amount' => 'amount']);
        $report->showNumColumn(false);

        $data = $this->inspectWithPyarrow($report->make());

        $this->assertSame(2, $data['num_rows']);
        $this->assertSame(
            [['name' => 'name', 'type' => 'string'], ['name' => 'amount', 'type' => 'int64']],
            $data['schema']
        );
        $this->assertSame([
            ['name' => 'Alice', 'amount' => 100],
            ['name' => 'Bob', 'amount' => 250],
        ], $data['rows']);
    }

    public function test_pyarrow_reads_all_supported_primitive_types(): void
    {
        $query = $this->makeQuery([
            [
                'i32' => 1,
                'i64' => 9_000_000_000,
                'flt' => 1.25,
                'dbl' => 3.14159,
                'str' => 'hello',
                'bln' => true,
            ],
        ]);

        $report = new ParquetReport;
        $report->of('Types', [], $query, [
            'I32' => 'i32',
            'I64' => 'i64',
            'Flt' => 'flt',
            'Dbl' => 'dbl',
            'Str' => 'str',
            'Bln' => 'bln',
        ]);
        $report->showNumColumn(false);
        $report->schema([
            'i32' => 'int32',
            'i64' => 'int64',
            'flt' => 'float',
            'dbl' => 'double',
            'str' => 'string',
            'bln' => 'boolean',
        ]);

        $data = $this->inspectWithPyarrow($report->make());

        $typesByName = [];
        foreach ($data['schema'] as $field) {
            $typesByName[$field['name']] = $field['type'];
        }
        $this->assertSame('int32', $typesByName['i32']);
        $this->assertSame('int64', $typesByName['i64']);
        $this->assertSame('float', $typesByName['flt']);
        $this->assertSame('double', $typesByName['dbl']);
        $this->assertSame('string', $typesByName['str']);
        $this->assertSame('bool', $typesByName['bln']);

        $row = $data['rows'][0];
        $this->assertSame(1, $row['i32']);
        $this->assertSame(9_000_000_000, $row['i64']);
        $this->assertEqualsWithDelta(1.25, $row['flt'], 0.0001);
        $this->assertEqualsWithDelta(3.14159, $row['dbl'], 0.00001);
        $this->assertSame('hello', $row['str']);
        $this->assertTrue($row['bln']);
    }

    public function test_pyarrow_reads_date_and_datetime_logical_types(): void
    {
        $query = $this->makeQuery([
            ['d' => '2025-03-15', 'dt' => '2025-03-15 10:30:00'],
        ]);

        $report = new ParquetReport;
        $report->of('Temporal', [], $query, ['D' => 'd', 'Dt' => 'dt']);
        $report->showNumColumn(false);
        $report->schema(['d' => 'date', 'dt' => 'datetime']);

        $data = $this->inspectWithPyarrow($report->make());

        $typesByName = [];
        foreach ($data['schema'] as $field) {
            $typesByName[$field['name']] = $field['type'];
        }

        $this->assertSame('date32[day]', $typesByName['d']);
        $this->assertStringStartsWith('timestamp[', $typesByName['dt']);

        $row = $data['rows'][0];
        $this->assertSame('2025-03-15', $row['d']);
        // The PHP DateTimeImmutable we construct for datetime is in the local
        // timezone. pyarrow serialises with isoformat(); both anchor on the
        // same wall-clock instant, so we just check the date+time are present.
        $this->assertStringStartsWith('2025-03-15T10:30:00', $row['dt']);
    }

    public function test_pyarrow_confirms_null_values_round_trip(): void
    {
        $query = $this->makeQuery([
            ['name' => 'Alice', 'amount' => 100],
            ['name' => 'Nully', 'amount' => null],
        ]);

        $report = new ParquetReport;
        $report->of('Nulls', [], $query, ['Name' => 'name', 'Amount' => 'amount']);
        $report->showNumColumn(false);
        $report->schema(['name' => 'string', 'amount' => 'int64']);

        $data = $this->inspectWithPyarrow($report->make());

        $this->assertSame(100, $data['rows'][0]['amount']);
        $this->assertNull($data['rows'][1]['amount']);
    }

    public function test_pyarrow_confirms_boolean_values_round_trip(): void
    {
        $query = $this->makeQuery([
            ['name' => 'A', 'active' => true],
            ['name' => 'B', 'active' => false],
        ]);

        $report = new ParquetReport;
        $report->of('Bools', [], $query, ['Name' => 'name', 'Active' => 'active']);
        $report->showNumColumn(false);

        $data = $this->inspectWithPyarrow($report->make());

        $this->assertTrue($data['rows'][0]['active']);
        $this->assertFalse($data['rows'][1]['active']);
    }
}
