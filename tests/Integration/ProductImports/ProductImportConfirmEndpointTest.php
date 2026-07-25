<?php

declare(strict_types=1);

namespace Tests\Integration\ProductImports;

use App\Modules\ProductImports\Csv\CsvParser;
use App\Modules\ProductImports\Csv\ExpectedHeaders;
use Tests\Integration\Support\BaseApiTestCase;

final class ProductImportConfirmEndpointTest extends BaseApiTestCase
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

    /**
     * @return array{import_id:string,conflict_row_id:string,ready_row_id:string,existing_id:string,suffix:string}
     */
    private function uploadReviewSession(array $super): array
    {
        $suffix = bin2hex(random_bytes(3));

        $existing = $this->request('POST', '/api/v1/products', [
            'name' => 'Existing-' . $suffix,
            'internal_reference' => 'REF-EXIST-' . $suffix,
            'packaging' => 'Old pack',
        ], $super);
        $this->assertSame(201, $existing['status'], json_encode($existing['json']));
        $existingId = (string) $existing['json']['data']['id'];

        $barcode = '84' . sprintf('%011d', hexdec($suffix) % 100000000000);
        $csv = $this->validHeader() . "\n"
            . '__export__.p1;TRUE;Nuevo Confirm ' . $suffix . ';' . $barcode
            . ';REF-NEW-' . $suffix . ';Almacenable;Unidades;Caja 8;AlimentacionImport' . $suffix
            . ';MarcaImport' . $suffix . ';OTC;;SubcatImport' . $suffix . ';;;;TagImport' . $suffix . ';'
            . '__export__.s1;VendorImport ' . $suffix . ';10,49;14,47;0' . "\n"
            . '__export__.p2;TRUE;Updated Name ' . $suffix . ';;REF-EXIST-' . $suffix
            . ';Almacenable;Unidades;New pack;;;;;;;;;__export__.s2;VendorImport ' . $suffix . ';1;2;3' . "\n";

        $path = $this->writeCsv($csv);
        $upload = $this->requestMultipart(
            'POST',
            '/api/v1/product-imports',
            [],
            ['file' => ['path' => $path, 'filename' => 'confirm-' . $suffix . '.csv']],
            $super
        );
        unlink($path);
        $this->assertSame(201, $upload['status'], $upload['raw']);
        $importId = (string) $upload['json']['data']['id'];
        $this->assertSame(1, $upload['json']['data']['ready_count'], json_encode($upload['json']['data']));
        $this->assertSame(1, $upload['json']['data']['conflict_count'], json_encode($upload['json']['data']));

        $rows = $this->request('GET', '/api/v1/product-imports/' . $importId . '/rows', null, $super);
        $this->assertSame(200, $rows['status']);
        $readyRowId = null;
        $conflictRowId = null;
        foreach ($rows['json']['data'] as $row) {
            if (($row['status'] ?? '') === 'ready') {
                $readyRowId = (string) $row['id'];
            }
            if (($row['status'] ?? '') === 'conflict') {
                $conflictRowId = (string) $row['id'];
            }
        }
        $this->assertNotNull($readyRowId);
        $this->assertNotNull($conflictRowId);

        return [
            'import_id' => $importId,
            'conflict_row_id' => (string) $conflictRowId,
            'ready_row_id' => (string) $readyRowId,
            'existing_id' => $existingId,
            'suffix' => $suffix,
        ];
    }

    public function testConfirmRequiresConflictDecision(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $session = $this->uploadReviewSession($super);

        $confirm = $this->request(
            'POST',
            '/api/v1/product-imports/' . $session['import_id'] . '/confirm',
            null,
            $super
        );
        $this->assertSame(422, $confirm['status']);
        $this->assertStringContainsString('without a decision', (string) ($confirm['json']['detail'] ?? ''));
    }

    public function testConfirmCreatesAndUpdatesWithDecisions(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $session = $this->uploadReviewSession($super);
        $suffix = $session['suffix'];

        $decision = $this->request(
            'PATCH',
            '/api/v1/product-imports/' . $session['import_id'] . '/rows/' . $session['conflict_row_id'],
            ['decision' => 'update_existing'],
            $super
        );
        $this->assertSame(200, $decision['status'], json_encode($decision['json']));
        $this->assertSame('update_existing', $decision['json']['data']['decision']);

        $confirm = $this->request(
            'POST',
            '/api/v1/product-imports/' . $session['import_id'] . '/confirm',
            null,
            $super
        );
        $this->assertSame(200, $confirm['status'], $confirm['raw']);
        $this->assertSame('completed', $confirm['json']['data']['status']);
        $this->assertSame(1, $confirm['json']['data']['created_count']);
        $this->assertSame(1, $confirm['json']['data']['updated_count']);
        $this->assertSame(0, $confirm['json']['data']['failed_count']);

        $createdRows = $this->request(
            'GET',
            '/api/v1/product-imports/' . $session['import_id'] . '/rows?status=created',
            null,
            $super
        );
        $this->assertSame(200, $createdRows['status']);
        $this->assertCount(1, $createdRows['json']['data']);
        $createdProductId = (string) $createdRows['json']['data'][0]['result_product_id'];
        $this->assertNotSame('', $createdProductId);

        $product = $this->request('GET', '/api/v1/products/' . $createdProductId, null, $super);
        $this->assertSame(200, $product['status']);
        $this->assertSame('Nuevo Confirm ' . $suffix, $product['json']['data']['name']);
        $this->assertSame('Caja 8', $product['json']['data']['packaging']);
        $this->assertSame('AlimentacionImport' . $suffix, $product['json']['data']['category']['name'] ?? null);
        $this->assertSame('MarcaImport' . $suffix, $product['json']['data']['brand']['name'] ?? null);
        $this->assertNotEmpty($product['json']['data']['tags']);
        $this->assertNotEmpty($product['json']['data']['suppliers']);
        $this->assertSame(10.49, $product['json']['data']['suppliers'][0]['purchase_price']);

        $updated = $this->request('GET', '/api/v1/products/' . $session['existing_id'], null, $super);
        $this->assertSame(200, $updated['status']);
        $this->assertSame('Updated Name ' . $suffix, $updated['json']['data']['name']);
        $this->assertSame('New pack', $updated['json']['data']['packaging']);

        $updatedRows = $this->request(
            'GET',
            '/api/v1/product-imports/' . $session['import_id'] . '/rows?status=updated',
            null,
            $super
        );
        $this->assertCount(1, $updatedRows['json']['data']);
        $this->assertSame($session['existing_id'], $updatedRows['json']['data'][0]['result_product_id']);
    }

    public function testBulkSkipAndCancel(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $session = $this->uploadReviewSession($super);

        $bulk = $this->request(
            'PATCH',
            '/api/v1/product-imports/' . $session['import_id'] . '/rows',
            ['decision' => 'skip', 'status' => 'conflict'],
            $super
        );
        $this->assertSame(200, $bulk['status'], json_encode($bulk['json']));
        $this->assertSame(1, $bulk['json']['data']['updated']);

        $confirm = $this->request(
            'POST',
            '/api/v1/product-imports/' . $session['import_id'] . '/confirm',
            null,
            $super
        );
        $this->assertSame(200, $confirm['status'], $confirm['raw']);
        $this->assertSame(1, $confirm['json']['data']['created_count']);
        $this->assertSame(1, $confirm['json']['data']['skipped_count']);
        $this->assertSame(0, $confirm['json']['data']['updated_count']);

        $session2 = $this->uploadReviewSession($super);
        $cancel = $this->request(
            'POST',
            '/api/v1/product-imports/' . $session2['import_id'] . '/cancel',
            null,
            $super
        );
        $this->assertSame(200, $cancel['status']);
        $this->assertSame('cancelled', $cancel['json']['data']['status']);

        $confirmCancelled = $this->request(
            'POST',
            '/api/v1/product-imports/' . $session2['import_id'] . '/confirm',
            null,
            $super
        );
        $this->assertSame(422, $confirmCancelled['status']);
    }

    public function testCreateNewOnConflictAllowsDuplicateInternalReference(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $session = $this->uploadReviewSession($super);

        $decision = $this->request(
            'PATCH',
            '/api/v1/product-imports/' . $session['import_id'] . '/rows/' . $session['conflict_row_id'],
            ['decision' => 'create_new'],
            $super
        );
        $this->assertSame(200, $decision['status']);

        $confirm = $this->request(
            'POST',
            '/api/v1/product-imports/' . $session['import_id'] . '/confirm',
            null,
            $super
        );
        $this->assertSame(200, $confirm['status'], $confirm['raw']);
        $this->assertSame(2, $confirm['json']['data']['created_count']);
        $this->assertSame(0, $confirm['json']['data']['updated_count']);

        $list = $this->request(
            'GET',
            '/api/v1/products?search=REF-EXIST-' . $session['suffix'],
            null,
            $super
        );
        $this->assertSame(200, $list['status']);
        $matches = array_values(array_filter(
            $list['json']['data'] ?? [],
            static fn (array $row): bool => ($row['internal_reference'] ?? '') === 'REF-EXIST-' . $session['suffix']
        ));
        $this->assertCount(2, $matches);
    }
}
