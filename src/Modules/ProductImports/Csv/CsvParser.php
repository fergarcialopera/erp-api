<?php

declare(strict_types=1);

namespace App\Modules\ProductImports\Csv;

use RuntimeException;

final class CsvParser
{
    /** Delimitador oficial del export Odoo (ES). Coma = error de exportación. */
    public const DELIMITER = ';';

    public function __construct(
        private readonly RowNormalizer $normalizer = new RowNormalizer(),
    ) {
    }

    /**
     * @return array{
     *     structural_errors: list<array{code:string,message:string,column:?string}>,
     *     products: list<array{row_number:int,raw:array<string,mixed>,normalized:array<string,mixed>}>
     * }
     */
    public function parseFile(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open CSV file');
        }

        try {
            return $this->parseHandle($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return array{
     *     structural_errors: list<array{code:string,message:string,column:?string}>,
     *     products: list<array{row_number:int,raw:array<string,mixed>,normalized:array<string,mixed>}>
     * }
     */
    public function parseHandle($handle): array
    {
        $structuralErrors = [];
        $firstLine = fgets($handle);
        if ($firstLine === false || trim($firstLine) === '') {
            return [
                'structural_errors' => [[
                    'code' => 'empty_file',
                    'message' => 'CSV file is empty',
                    'column' => null,
                ]],
                'products' => [],
            ];
        }

        $expected = ExpectedHeaders::all();
        $expectedSeparators = count($expected) - 1;
        $semiCount = substr_count($firstLine, self::DELIMITER);
        $commaCount = substr_count($firstLine, ',');
        if ($semiCount < $expectedSeparators && $commaCount >= $expectedSeparators) {
            return [
                'structural_errors' => [[
                    'code' => 'wrong_delimiter',
                    'message' => 'CSV must be semicolon-delimited (;). A comma-delimited file usually indicates an export error; re-export from Odoo with ";" as separator.',
                    'column' => null,
                ]],
                'products' => [],
            ];
        }

        rewind($handle);

        $headerRow = fgetcsv($handle, 0, self::DELIMITER, '"', '\\');
        if ($headerRow === false || $headerRow === [null] || $headerRow === []) {
            return [
                'structural_errors' => [[
                    'code' => 'empty_file',
                    'message' => 'CSV file is empty',
                    'column' => null,
                ]],
                'products' => [],
            ];
        }

        $headers = array_map(static fn ($h): string => trim((string) $h), $headerRow);
        // BOM UTF-8 en la primera cabecera
        if ($headers !== [] && str_starts_with($headers[0], "\xEF\xBB\xBF")) {
            $headers[0] = substr($headers[0], 3);
        }

        $missing = array_values(array_diff($expected, $headers));
        $unknown = array_values(array_diff($headers, $expected));
        if ($missing !== []) {
            $structuralErrors[] = [
                'code' => 'missing_headers',
                'message' => 'Missing required headers: ' . implode(', ', $missing),
                'column' => null,
            ];
        }
        if ($unknown !== []) {
            $structuralErrors[] = [
                'code' => 'unknown_headers',
                'message' => 'Unknown headers: ' . implode(', ', $unknown),
                'column' => null,
            ];
        }
        if ($structuralErrors !== []) {
            return ['structural_errors' => $structuralErrors, 'products' => []];
        }

        $indexByHeader = array_flip($headers);
        $products = [];
        $current = null;
        $lineNumber = 1;

        while (($row = fgetcsv($handle, 0, self::DELIMITER, '"', '\\')) !== false) {
            ++$lineNumber;
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $assoc = [];
            foreach ($expected as $header) {
                $idx = $indexByHeader[$header];
                $assoc[$header] = isset($row[$idx]) ? (string) $row[$idx] : '';
            }

            $odooId = trim((string) ($assoc[ExpectedHeaders::ID] ?? ''));
            if ($odooId === '') {
                if ($current === null) {
                    $structuralErrors[] = [
                        'code' => 'orphan_continuation_row',
                        'message' => 'Supplier continuation row without a preceding product at line ' . $lineNumber,
                        'column' => ExpectedHeaders::ID,
                    ];
                    continue;
                }
                $current['supplier_rows'][] = $assoc;
                $current['raw_lines'][] = $assoc;
                continue;
            }

            if ($current !== null) {
                $products[] = $this->finalizeProduct($current);
            }

            $current = [
                'row_number' => $lineNumber,
                'main' => $assoc,
                'supplier_rows' => [],
                'raw_lines' => [$assoc],
            ];
        }

        if ($current !== null) {
            $products[] = $this->finalizeProduct($current);
        }

        if ($products === [] && $structuralErrors === []) {
            $structuralErrors[] = [
                'code' => 'no_product_rows',
                'message' => 'CSV has headers but no product rows',
                'column' => null,
            ];
        }

        return [
            'structural_errors' => $structuralErrors,
            'products' => $products,
        ];
    }

    /**
     * @param list<string|null> $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{
     *     row_number:int,
     *     main:array<string,string>,
     *     supplier_rows:list<array<string,string>>,
     *     raw_lines:list<array<string,string>>
     * } $current
     * @return array{row_number:int,raw:array<string,mixed>,normalized:array<string,mixed>}
     */
    private function finalizeProduct(array $current): array
    {
        $normalized = $this->normalizer->normalizeProduct($current['main'], $current['supplier_rows']);

        return [
            'row_number' => $current['row_number'],
            'raw' => [
                'lines' => $current['raw_lines'],
                'csv_line' => $current['row_number'],
            ],
            'normalized' => $normalized,
        ];
    }
}
