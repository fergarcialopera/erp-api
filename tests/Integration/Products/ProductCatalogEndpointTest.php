<?php

declare(strict_types=1);

namespace Tests\Integration\Products;

use App\Modules\Products\Services\ProductAccessService;
use Tests\Integration\Support\BaseApiTestCase;

final class ProductCatalogEndpointTest extends BaseApiTestCase
{
    public function testCatalogFlowAndProductRelations(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $suffix = bin2hex(random_bytes(3));

        $category = $this->request('POST', '/api/v1/categories', [
            'name' => 'Fungible-' . $suffix,
            'description' => 'Catálogo fungible',
        ], $super);
        $this->assertSame(201, $category['status'], json_encode($category['json']));
        $categoryId = (string) $category['json']['data']['id'];

        $subWrong = $this->request('POST', '/api/v1/subcategories', [
            'category_id' => '00000000-0000-0000-0000-000000000099',
            'name' => 'Jeringas-' . $suffix,
        ], $super);
        $this->assertSame(422, $subWrong['status']);

        $sub = $this->request('POST', '/api/v1/subcategories', [
            'category_id' => $categoryId,
            'name' => 'Jeringas-' . $suffix,
        ], $super);
        $this->assertSame(201, $sub['status'], json_encode($sub['json']));
        $subcategoryId = (string) $sub['json']['data']['id'];

        $otherCategory = $this->request('POST', '/api/v1/categories', [
            'name' => 'Otro-' . $suffix,
        ], $super);
        $this->assertSame(201, $otherCategory['status']);
        $otherCategoryId = (string) $otherCategory['json']['data']['id'];

        $brand = $this->request('POST', '/api/v1/brands', [
            'name' => 'Braun-' . $suffix,
        ], $super);
        $this->assertSame(201, $brand['status']);
        $brandId = (string) $brand['json']['data']['id'];

        $supplier = $this->request('POST', '/api/v1/suppliers', [
            'name' => 'DISTRIVET-' . $suffix,
            'legal_name' => 'DISTRIVET S.A.',
            'email' => 'compras-' . $suffix . '@example.com',
        ], $super);
        $this->assertSame(201, $supplier['status']);
        $supplierId = (string) $supplier['json']['data']['id'];

        $attachBrand = $this->request('POST', '/api/v1/brands/' . $brandId . '/suppliers', [
            'supplier_id' => $supplierId,
        ], $super);
        $this->assertSame(201, $attachBrand['status'], json_encode($attachBrand['json']));

        $brandSuppliers = $this->request('GET', '/api/v1/brands/' . $brandId . '/suppliers', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(200, $brandSuppliers['status']);
        $this->assertNotEmpty($brandSuppliers['json']['data']);

        $disp = $this->request('POST', '/api/v1/dispensing-types', [
            'name' => 'OTC-TEST-' . $suffix,
        ], $super);
        $this->assertSame(201, $disp['status']);
        $dispensingTypeId = (string) $disp['json']['data']['id'];

        $roleVet = $this->request('POST', '/api/v1/roles', [
            'name' => 'Veterinario-' . $suffix,
        ], $super);
        $this->assertSame(201, $roleVet['status']);
        $vetRoleId = (string) $roleVet['json']['data']['id'];

        $roleShop = $this->request('POST', '/api/v1/roles', [
            'name' => 'Personal-tienda-' . $suffix,
        ], $super);
        $this->assertSame(201, $roleShop['status']);
        $shopRoleId = (string) $roleShop['json']['data']['id'];

        $attachRole = $this->request('POST', '/api/v1/dispensing-types/' . $dispensingTypeId . '/roles', [
            'role_id' => $vetRoleId,
        ], $super);
        $this->assertSame(201, $attachRole['status'], json_encode($attachRole['json']));

        $roles = $this->request('GET', '/api/v1/dispensing-types/' . $dispensingTypeId . '/roles', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(200, $roles['status']);
        $this->assertCount(1, $roles['json']['data']);

        $product = $this->request('POST', '/api/v1/products', [
            'name' => 'Producto-' . $suffix,
            'barcode' => '843' . substr(preg_replace('/\D/', '', $suffix) . '0000000000', 0, 10),
            'internal_reference' => 'REF-' . $suffix,
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
            'brand_id' => $brandId,
            'dispensing_type_id' => $dispensingTypeId,
            'unit_of_measure' => 'Unidades',
        ], $super);
        $this->assertSame(201, $product['status'], json_encode($product['json']));
        $productId = (string) $product['json']['data']['id'];
        $this->assertSame($categoryId, $product['json']['data']['category_id']);
        $this->assertSame('Fungible-' . $suffix, $product['json']['data']['category']['name']);
        $this->assertSame('Braun-' . $suffix, $product['json']['data']['brand']['name']);
        $this->assertArrayHasKey('suppliers', $product['json']['data']);

        $badSub = $this->request('PATCH', '/api/v1/products/' . $productId, [
            'category_id' => $otherCategoryId,
            'subcategory_id' => $subcategoryId,
        ], $super);
        $this->assertSame(422, $badSub['status']);
        $this->assertStringContainsString('Subcategory does not belong', (string) ($badSub['json']['detail'] ?? ''));

        $addSupplier = $this->request('POST', '/api/v1/products/' . $productId . '/suppliers', [
            'supplier_id' => $supplierId,
            'supplier_reference' => 'ABC-' . $suffix,
            'purchase_price' => 10.5,
            'pvp' => 15.95,
            'net_cost' => 9.8,
            'is_preferred' => true,
        ], $super);
        $this->assertSame(201, $addSupplier['status'], json_encode($addSupplier['json']));
        $productSupplierId = (string) $addSupplier['json']['data']['id'];
        $this->assertTrue((bool) $addSupplier['json']['data']['is_preferred']);

        $supplier2 = $this->request('POST', '/api/v1/suppliers', [
            'name' => 'ALT-' . $suffix,
        ], $super);
        $this->assertSame(201, $supplier2['status']);
        $supplier2Id = (string) $supplier2['json']['data']['id'];

        $addSupplier2 = $this->request('POST', '/api/v1/products/' . $productId . '/suppliers', [
            'supplier_id' => $supplier2Id,
            'purchase_price' => 11,
            'is_preferred' => false,
        ], $super);
        $this->assertSame(201, $addSupplier2['status']);
        $productSupplier2Id = (string) $addSupplier2['json']['data']['id'];

        $preferred = $this->request(
            'PATCH',
            '/api/v1/products/' . $productId . '/suppliers/' . $productSupplier2Id . '/preferred',
            null,
            $super
        );
        $this->assertSame(200, $preferred['status']);
        $this->assertTrue((bool) $preferred['json']['data']['is_preferred']);

        $listSuppliers = $this->request('GET', '/api/v1/products/' . $productId . '/suppliers', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(200, $listSuppliers['status']);
        $this->assertCount(2, $listSuppliers['json']['data']);

        $access = new ProductAccessService(self::testPdo());

        $allowed = $access->canUserAccessProduct(
            ['operational_role_id' => $vetRoleId],
            ['dispensing_type_id' => $dispensingTypeId]
        );
        $this->assertTrue($allowed['allowed']);
        $this->assertNull($allowed['reason']);

        $denied = $access->canUserAccessProduct(
            ['operational_role_id' => $shopRoleId],
            ['dispensing_type_id' => $dispensingTypeId]
        );
        $this->assertFalse($denied['allowed']);
        $this->assertSame(ProductAccessService::DENIED_MESSAGE, $denied['reason']);

        $noRole = $access->canUserAccessProduct(
            ['operational_role_id' => null],
            ['dispensing_type_id' => $dispensingTypeId]
        );
        $this->assertFalse($noRole['allowed']);

        $patchUser = $this->request('PATCH', '/api/v1/users/44444444-4444-4444-4444-444444444444', [
            'operational_role_id' => $vetRoleId,
        ], $super);
        $this->assertSame(200, $patchUser['status'], json_encode($patchUser['json']));
        $this->assertSame($vetRoleId, $patchUser['json']['data']['operational_role_id']);
        $this->assertSame($vetRoleId, $patchUser['json']['data']['operational_role']['id']);

        $this->request('DELETE', '/api/v1/products/' . $productId . '/suppliers/' . $productSupplierId, null, $super);
        $this->request('DELETE', '/api/v1/brands/' . $brandId . '/suppliers/' . $supplierId, null, $super);
    }

    public function testStaffCanReadCatalogButNotWrite(): void
    {
        $staff = $this->authHeaderFor('staff@clinic-erp.com');
        $list = $this->request('GET', '/api/v1/categories', null, $staff);
        $this->assertSame(200, $list['status']);

        $create = $this->request('POST', '/api/v1/categories', ['name' => 'No-' . bin2hex(random_bytes(2))], $staff);
        $this->assertSame(403, $create['status']);
    }
}
