<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Query;

use Illuminate\Support\Arr;
use League\Csv\Writer;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvResponder
{
    /** Cell prefixes that a spreadsheet application would interpret as the start of a formula. */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  iterable<array<string,mixed>>  $rows
     * @param  list<string>|null  $header  A stable, declared column list. When omitted, falls
     *                                     back to the union of keys seen across the (bounded)
     *                                     page - never the first row's keys alone, which would
     *                                     make the CSV ragged whenever a later row has a key an
     *                                     earlier row didn't (e.g. a class-3 `logo.*` object).
     */
    public static function stream(iterable $rows, string $filename, ?array $header = null): StreamedResponse
    {
        return new StreamedResponse(function () use ($rows, $header): void {
            // league/csv only prepends the BOM through output()/toString(); insertOne() writes
            // straight to the stream and skips it, so it is emitted by hand here.
            echo Writer::BOM_UTF8;
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open php://output for writing.');
            }
            $writer = Writer::createFromStream($stream);
            $writer->setDelimiter(';');

            /** @var list<array<string,mixed>> $flatRows */
            $flatRows = [];
            foreach ($rows as $row) {
                // `specifications` is a freeform class-3 object (arbitrary keys per record), unlike
                // the fixed-shape nested objects (`logo`, `ro_fleet`, `{code,label}` term objects)
                // that Arr::dot below flattens into stable `<field>.<subkey>` columns. Encoding it to
                // a single JSON cell keeps the declared column list stable across every row.
                if (isset($row['specifications']) && is_array($row['specifications'])) {
                    $row['specifications'] = json_encode($row['specifications'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $flatRows[] = Arr::dot($row);
            }

            $columns = $header ?? self::unionOfKeys($flatRows);
            if ($columns === []) {
                $writer->insertOne([]);

                return;
            }

            $writer->insertOne($columns);
            foreach ($flatRows as $flat) {
                $writer->insertOne(array_map(
                    static fn ($v) => self::escape(is_bool($v) ? ($v ? '1' : '0') : (string) ($v ?? '')),
                    array_map(static fn (string $column) => $flat[$column] ?? null, $columns),
                ));
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $flatRows
     * @return list<string>
     */
    private static function unionOfKeys(array $flatRows): array
    {
        $columns = [];
        foreach ($flatRows as $flat) {
            foreach (array_keys($flat) as $key) {
                $columns[$key] = true;
            }
        }

        return array_keys($columns);
    }

    /** Prevents CSV/formula injection: a cell starting with a spreadsheet-formula trigger gets a leading apostrophe. */
    private static function escape(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        foreach (self::DANGEROUS_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'".$value;
            }
        }

        return $value;
    }
}
