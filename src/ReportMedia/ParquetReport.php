<?php

declare(strict_types=1);

namespace SamuelTerra22\ReportGenerator\ReportMedia;

use Closure;
use DateTimeInterface;
use Exception;
use Flow\Parquet\ParquetFile\Schema;
use Flow\Parquet\ParquetFile\Schema\FlatColumn;
use Flow\Parquet\Writer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SamuelTerra22\ReportGenerator\ReportGenerator;

class ParquetReport extends ReportGenerator
{
    protected $showMeta = false;

    private const SUPPORTED_TYPES = [
        'int32', 'int64', 'float', 'double', 'string', 'boolean',
        'date', 'datetime', 'decimal',
    ];

    public function make(): string
    {
        if (! class_exists(Writer::class)) {
            throw new Exception('Please install flow-php/parquet to generate Parquet Report!');
        }

        $this->fireCallbacks($this->onBeforeRenderCallbacks);

        if ($this->cacheEnabled) {
            $cached = $this->getCache()->get($this->getCacheKey());
            if ($cached !== null) {
                $this->fireCallbacks($this->onAfterRenderCallbacks);
                $this->fireCallbacks($this->onCompleteCallbacks);

                return $cached;
            }
        }

        $rows = $this->collectRows();
        $schema = $this->buildSchema($rows);
        $rows = $this->coerceRows($rows, $schema);

        $tmp = tempnam(sys_get_temp_dir(), 'parquet-report-');
        if ($tmp === false) {
            throw new Exception('Unable to create temp file for Parquet output.');
        }

        // Writer requires the file not exist yet.
        @unlink($tmp);

        try {
            $writer = new Writer;
            $writer->write($tmp, $schema, $rows);
            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new Exception('Failed reading Parquet output from temp file.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $this->fireCallbacks($this->onAfterRenderCallbacks);

        if ($this->cacheEnabled) {
            $this->getCache()->put($this->getCacheKey(), $bytes, $this->cacheDuration * 60);
        }

        $this->fireCallbacks($this->onCompleteCallbacks);

        return $bytes;
    }

    public function download(string $filename): void
    {
        $bytes = $this->make();

        $name = Str::endsWith($filename, '.parquet') ? $filename : $filename.'.parquet';

        header('Content-Type: application/vnd.apache.parquet');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.strlen($bytes));
        echo $bytes;
    }

    public function store(string $disk, string $path): string
    {
        $bytes = $this->make();

        if (! Str::endsWith($path, '.parquet')) {
            $path .= '.parquet';
        }

        Storage::disk($disk)->put($path, $bytes);

        return $path;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectRows(): array
    {
        $rows = [];
        $rowIndex = 0;
        $ctr = 1;

        foreach ($this->query->take($this->limit ?: null)->cursor() as $result) {
            $this->fireCallbacks($this->onRowCallbacks, $result, $rowIndex);

            $row = [];

            if ($this->showNumColumn) {
                $row['no'] = $ctr;
            }

            foreach ($this->columns as $colName => $colData) {
                $key = Str::snake($colName);
                $row[$key] = $this->resolveValue($result, $colData);
            }

            $rows[] = $row;
            $rowIndex++;
            $ctr++;
        }

        return $rows;
    }

    private function resolveValue(mixed $result, mixed $colData): mixed
    {
        if ($colData instanceof Closure) {
            return $colData($result);
        }

        return $result->{$colData} ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildSchema(array $rows): Schema
    {
        $columns = [];
        $sample = $rows[0] ?? [];

        if ($this->showNumColumn) {
            $columns[] = FlatColumn::int32('no');
        }

        foreach (array_keys($this->columns) as $colName) {
            $key = Str::snake((string) $colName);
            $type = $this->parquetSchema[$key] ?? $this->inferType($sample[$key] ?? null);

            $columns[] = $this->makeColumn($key, $type);
        }

        return Schema::with(...$columns);
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int64',
            is_float($value) => 'double',
            is_bool($value) => 'boolean',
            $value instanceof DateTimeInterface => 'datetime',
            default => 'string',
        };
    }

    private function makeColumn(string $name, string $type): FlatColumn
    {
        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new Exception("Unsupported Parquet schema type [{$type}] for column [{$name}]. Supported: ".implode(', ', self::SUPPORTED_TYPES));
        }

        return match ($type) {
            'int32' => FlatColumn::int32($name),
            'int64' => FlatColumn::int64($name),
            'float' => FlatColumn::float($name),
            'double' => FlatColumn::double($name),
            'boolean' => FlatColumn::boolean($name),
            'date' => FlatColumn::date($name),
            'datetime' => FlatColumn::dateTime($name),
            'decimal' => FlatColumn::decimal($name),
            default => FlatColumn::string($name),
        };
    }

    /**
     * Coerce PHP values to match their declared / inferred Parquet type so the
     * writer doesn't fail on mixed native types (e.g. stringy numerics).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function coerceRows(array $rows, Schema $schema): array
    {
        $types = [];
        foreach ($schema->columnsFlat() as $col) {
            $types[$col->name()] = $this->parquetSchema[$col->name()]
                ?? $this->columnTypeFromSchema($col);
        }

        return array_map(function (array $row) use ($types) {
            foreach ($row as $key => $value) {
                $row[$key] = $this->coerceValue($value, $types[$key] ?? 'string');
            }

            return $row;
        }, $rows);
    }

    private function columnTypeFromSchema(FlatColumn $col): string
    {
        // Reverse-map via logical type name for inference path.
        $logical = $col->logicalType()?->name();

        return match (true) {
            $logical === 'DATE' => 'date',
            $logical === 'TIMESTAMP' => 'datetime',
            $logical === 'DECIMAL' => 'decimal',
            default => strtolower($col->type()->name),
        };
    }

    private function coerceValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int32', 'int64' => (int) $value,
            'float', 'double' => (float) $value,
            'boolean' => (bool) $value,
            'date', 'datetime' => $value instanceof DateTimeInterface
                ? $value
                : new \DateTimeImmutable((string) $value),
            default => (string) $value,
        };
    }
}
