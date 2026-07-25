<?php

declare(strict_types=1);

namespace Tests\Integration\ProductImports;

use App\Modules\ProductImports\Csv\CsvParser;
use App\Modules\ProductImports\Csv\ExpectedHeaders;
use Tests\Integration\Support\BaseApiTestCase;

final class ProductImportsEndpointTest extends BaseApiTestCase
{
    private function writeCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'odoo');
        $this->assertNotFalse($path);
        $csvPath = $path . '.csv';
        rename($path, $csvPath);
        file_put_contents($csvPath, $contents);

        return $csvPath;
    }

    private function validHeader(): string
    {
        return implode(CsvParser::DELIMITER, ExpectedHeaders::all());
    }

    public function testUploadAnalyzesCsvAndExposesPreview(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $suffix = bin2hex(random_bytes(3));

        $existing = $this->request('POST', '/api/v1/products', [
            'name' => 'Existing-' . $suffix,
            'internal_reference' => 'REF-EXIST-' . $suffix,
            'barcode' => '840' . substr(preg_replace('/\D/', '', $suffix) . '0000000000', 0, 10),
        ], $super);
        $this->assertSame(201, $existing['status'], json_encode($existing['json']));
        $existingId = (string) $existing['json']['data']['id'];

        $csv = $this->validHeader() . "\n"
            . '__export__.p1;TRUE;Nuevo Producto ' . $suffix . ';7613034291' . substr($suffix, 0, 3)
            . ';REF-NEW-' . $suffix . ';Almacenable;Unidades;;Alimentacion;PURINA;OTC;;HUMEDO;;;;Verde;'
            . '__export__.s1;DISTRIVET S. A.;10,49;14,47;0' . "\n"
            . '__export__.p2;TRUE;Conflicto ' . $suffix . ';;REF-EXIST-' . $suffix
            . ';Almacenable;Unidades;;Alimentacion;;;;;;;;'
            . '__export__.s2;DISTRIVET S. A.;1;2;3' . "\n"
            . '__export__.p3;TRUE;;;REF-BAD-' . $suffix . ';Almacenable;Unidades;;;;;;;;;;;;;;;' . "\n";

        $path = $this->writeCsv($csv);
        $upload = $this->requestMultipart(
            'POST',
            '/api/v1/product-imports',
            [],
            ['file' => ['path' => $path, 'filename' => 'odoo-' . $suffix . '.csv']],
            $super
        );
        unlink($path);

        $this->assertSame(201, $upload['status'], $upload['raw']);
        $data = $upload['json']['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame('ready_for_review', $data['status']);
        $this->assertSame(3, $data['total_rows']);
        $this->assertSame(1, $data['ready_count']);
        $this->assertSame(1, $data['conflict_count']);
        $this->assertSame(1, $data['invalid_count']);
        $this->assertSame('super@clinic-erp.com', $data['created_by_email']);
        $this->assertContains('Alimentacion', $data['catalog_preview']['categories'] ?? []);
        $this->assertContains('PURINA', $data['catalog_preview']['brands'] ?? []);
        $this->assertContains('DISTRIVET S. A.', $data['catalog_preview']['suppliers'] ?? []);

        $importId = (string) $data['id'];

        $detail = $this->request('GET', '/api/v1/product-imports/' . $importId, null, $super);
        $this->assertSame(200, $detail['status']);
        $this->assertArrayHasKey('catalog_preview', $detail['json']['data']);

        $rows = $this->request('GET', '/api/v1/product-imports/' . $importId . '/rows', null, $super);
        $this->assertSame(200, $rows['status']);
        $this->assertCount(3, $rows['json']['data']);
        $this->assertSame(3, $rows['json']['meta']['total']);

        $conflicts = $this->request(
            'GET',
            '/api/v1/product-imports/' . $importId . '/rows?status=conflict',
            null,
            $super
        );
        $this->assertSame(200, $conflicts['status']);
        $this->assertCount(1, $conflicts['json']['data']);
        $this->assertSame($existingId, $conflicts['json']['data'][0]['existing_product_id']);
        $this->assertNotEmpty($conflicts['json']['data'][0]['diff']);

        $ready = $this->request(
            'GET',
            '/api/v1/product-imports/' . $importId . '/rows?status=ready',
            null,
            $super
        );
        $this->assertSame(200, $ready['status']);
        $this->assertCount(1, $ready['json']['data']);
        $this->assertNull($ready['json']['data'][0]['result_product_id']);
        $this->assertSame('Verde', $ready['json']['data'][0]['normalized']['tags'][0] ?? null);

        $list = $this->request('GET', '/api/v1/product-imports', null, $super);
        $this->assertSame(200, $list['status']);
        $ids = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $list['json']['data'] ?? []);
        $this->assertContains($importId, $ids);
    }

    public function testInvalidStructureReturnsInvalidSession(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $badHeader = implode(',', ExpectedHeaders::all());
        $path = $this->writeCsv($badHeader . "\n1,Foo\n");
        $upload = $this->requestMultipart(
            'POST',
            '/api/v1/product-imports',
            [],
            ['file' => ['path' => $path, 'filename' => 'bad.csv']],
            $super
        );
        unlink($path);

        $this->assertSame(201, $upload['status'], $upload['raw']);
        $this->assertSame('invalid', $upload['json']['data']['status']);
        $this->assertNotEmpty($upload['json']['data']['structural_errors']);
        $this->assertSame('wrong_delimiter', $upload['json']['data']['structural_errors'][0]['code']);
        $this->assertSame(0, $upload['json']['data']['total_rows']);
    }

    public function testStaffCannotAccessImports(): void
    {
        $staff = $this->authHeaderFor('staff@clinic-erp.com');
        $list = $this->request('GET', '/api/v1/product-imports', null, $staff);
        $this->assertSame(403, $list['status']);

        $path = $this->writeCsv($this->validHeader() . "\n");
        $upload = $this->requestMultipart(
            'POST',
            '/api/v1/product-imports',
            [],
            ['file' => ['path' => $path, 'filename' => 'x.csv']],
            $staff
        );
        unlink($path);
        $this->assertSame(403, $upload['status']);
    }
}
