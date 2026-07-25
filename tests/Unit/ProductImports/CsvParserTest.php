<?php

declare(strict_types=1);

namespace Tests\Unit\ProductImports;

use App\Modules\ProductImports\Csv\CsvParser;
use App\Modules\ProductImports\Csv\ExpectedHeaders;
use App\Modules\ProductImports\Csv\RowNormalizer;
use PHPUnit\Framework\TestCase;

final class CsvParserTest extends TestCase
{
    private function sampleCsv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        $this->assertNotFalse($path);
        file_put_contents($path, $body);

        return $path;
    }

    public function testParsesValidRowsAndSpanishDecimals(): void
    {
        $header = implode(CsvParser::DELIMITER, ExpectedHeaders::all());
        $csv = $header . "\n"
            . '__export__.product_template_1;TRUE;FELIX SALMON;7613034291042;12427037;Almacenable;Unidades;;Alimentacion;PURINA;OTC;;HUMEDO;;;;Verde;__export__.supplier_1;DISTRIVET S. A.;10,49;14,47;0' . "\n"
            . '__export__.product_template_2;TRUE;FELIX PARTY;0;12371179;Almacenable;Unidades;;Alimentacion;;;;;;;;Verde;__export__.supplier_2;DISTRIVET S. A.;14,8;0;' . "\n";

        $path = $this->sampleCsv($csv);
        $parsed = (new CsvParser())->parseFile($path);
        unlink($path);

        $this->assertSame([], $parsed['structural_errors']);
        $this->assertCount(2, $parsed['products']);
        $this->assertSame(2, $parsed['products'][0]['row_number']);
        $this->assertSame('FELIX SALMON', $parsed['products'][0]['normalized']['name']);
        $this->assertSame('7613034291042', $parsed['products'][0]['normalized']['barcode']);
        $this->assertNull($parsed['products'][1]['normalized']['barcode']);
        $this->assertSame(10.49, $parsed['products'][0]['normalized']['suppliers'][0]['purchase_price']);
        $this->assertSame(14.8, $parsed['products'][1]['normalized']['suppliers'][0]['purchase_price']);
        $this->assertSame(0.0, $parsed['products'][0]['normalized']['suppliers'][0]['net_cost']);
        $this->assertNull($parsed['products'][1]['normalized']['suppliers'][0]['net_cost']);
    }

    public function testGroupsContinuationSupplierRows(): void
    {
        $header = implode(CsvParser::DELIMITER, ExpectedHeaders::all());
        $main = [
            '__export__.p1', 'TRUE', 'Product A', '123', 'REF-A', 'Almacenable', 'Unidades',
            '', '', '', '', '', '', '', '', '', 'Verde',
            '__export__.s1', 'VENDOR A', '1', '2', '3',
        ];
        $continuation = array_fill(0, count(ExpectedHeaders::all()), '');
        $continuation[array_search(ExpectedHeaders::SUPPLIER_VENDOR, ExpectedHeaders::all(), true)] = 'VENDOR B';
        $continuation[array_search(ExpectedHeaders::SUPPLIER_PRICE, ExpectedHeaders::all(), true)] = '4';
        $continuation[array_search(ExpectedHeaders::SUPPLIER_PVP, ExpectedHeaders::all(), true)] = '5';
        $continuation[array_search(ExpectedHeaders::SUPPLIER_NET_COST, ExpectedHeaders::all(), true)] = '6';

        $csv = $header . "\n"
            . implode(CsvParser::DELIMITER, $main) . "\n"
            . implode(CsvParser::DELIMITER, $continuation) . "\n";
        $path = $this->sampleCsv($csv);
        $parsed = (new CsvParser())->parseFile($path);
        unlink($path);

        $this->assertSame([], $parsed['structural_errors']);
        $this->assertCount(1, $parsed['products']);
        $this->assertCount(2, $parsed['products'][0]['normalized']['suppliers']);
        $this->assertSame('VENDOR A', $parsed['products'][0]['normalized']['suppliers'][0]['vendor']);
        $this->assertSame('VENDOR B', $parsed['products'][0]['normalized']['suppliers'][1]['vendor']);
    }

    public function testRejectsCommaDelimitedAsWrongDelimiter(): void
    {
        $header = implode(',', ExpectedHeaders::all());
        $path = $this->sampleCsv($header . "\n1,Foo\n");
        $parsed = (new CsvParser())->parseFile($path);
        unlink($path);

        $this->assertNotEmpty($parsed['structural_errors']);
        $this->assertSame('wrong_delimiter', $parsed['structural_errors'][0]['code']);
        $this->assertSame([], $parsed['products']);
    }

    public function testRejectsMissingHeaders(): void
    {
        $path = $this->sampleCsv("ID;Nombre\n1;Foo\n");
        $parsed = (new CsvParser())->parseFile($path);
        unlink($path);

        $this->assertNotEmpty($parsed['structural_errors']);
        $this->assertSame('missing_headers', $parsed['structural_errors'][0]['code']);
        $this->assertSame([], $parsed['products']);
    }

    public function testNormalizerBarcodeZeroBecomesNull(): void
    {
        $normalizer = new RowNormalizer();
        $assoc = [];
        foreach (ExpectedHeaders::all() as $header) {
            $assoc[$header] = '';
        }
        $assoc[ExpectedHeaders::NAME] = 'X';
        $assoc[ExpectedHeaders::BARCODE] = '0';
        $assoc[ExpectedHeaders::ACTIVE] = 'FALSE';

        $normalized = $normalizer->normalizeProduct($assoc);
        $this->assertNull($normalized['barcode']);
        $this->assertFalse($normalized['is_active']);
    }
}
