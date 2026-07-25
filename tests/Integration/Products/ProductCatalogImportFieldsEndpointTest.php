<?php

declare(strict_types=1);

namespace Tests\Integration\Products;

use Tests\Integration\Support\BaseApiTestCase;

final class ProductCatalogImportFieldsEndpointTest extends BaseApiTestCase
{
    public function testExtendedCatalogFlowAndProductFields(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $suffix = bin2hex(random_bytes(3));

        $brand = $this->request('POST', '/api/v1/brands', [
            'name' => 'Purina-' . $suffix,
        ], $super);
        $this->assertSame(201, $brand['status'], json_encode($brand['json']));
        $brandId = (string) $brand['json']['data']['id'];

        $otherBrand = $this->request('POST', '/api/v1/brands', [
            'name' => 'OtraMarca-' . $suffix,
        ], $super);
        $this->assertSame(201, $otherBrand['status']);
        $otherBrandId = (string) $otherBrand['json']['data']['id'];

        $subBrandWrong = $this->request('POST', '/api/v1/sub-brands', [
            'brand_id' => '00000000-0000-0000-0000-000000000099',
            'name' => 'Felix-' . $suffix,
        ], $super);
        $this->assertSame(422, $subBrandWrong['status']);

        $subBrand = $this->request('POST', '/api/v1/sub-brands', [
            'brand_id' => $brandId,
            'name' => 'Felix-' . $suffix,
        ], $super);
        $this->assertSame(201, $subBrand['status'], json_encode($subBrand['json']));
        $subBrandId = (string) $subBrand['json']['data']['id'];
        $this->assertSame($brandId, $subBrand['json']['data']['brand_id']);

        $subBrandsByBrand = $this->request('GET', '/api/v1/sub-brands?brand_id=' . $brandId, null, $super);
        $this->assertSame(200, $subBrandsByBrand['status']);
        $this->assertNotEmpty($subBrandsByBrand['json']['data']);

        $species = $this->request('POST', '/api/v1/species', [
            'name' => 'Gato-' . $suffix,
        ], $super);
        $this->assertSame(201, $species['status'], json_encode($species['json']));
        $speciesId = (string) $species['json']['data']['id'];

        $specialty = $this->request('POST', '/api/v1/specialties', [
            'name' => 'Dermatologia-' . $suffix,
        ], $super);
        $this->assertSame(201, $specialty['status'], json_encode($specialty['json']));
        $specialtyId = (string) $specialty['json']['data']['id'];

        $tagGreen = $this->request('POST', '/api/v1/product-tags', [
            'name' => 'Verde-' . $suffix,
        ], $super);
        $this->assertSame(201, $tagGreen['status'], json_encode($tagGreen['json']));
        $tagGreenId = (string) $tagGreen['json']['data']['id'];

        $tagPromo = $this->request('POST', '/api/v1/product-tags', [
            'name' => 'Promo-' . $suffix,
        ], $super);
        $this->assertSame(201, $tagPromo['status']);
        $tagPromoId = (string) $tagPromo['json']['data']['id'];

        $product = $this->request('POST', '/api/v1/products', [
            'name' => 'Felix Crispies-' . $suffix,
            'internal_reference' => 'REF-' . $suffix,
            'national_code' => 'CN-' . $suffix,
            'packaging' => 'Caja 8x45gr',
            'brand_id' => $brandId,
            'sub_brand_id' => $subBrandId,
            'species_id' => $speciesId,
            'specialty_id' => $specialtyId,
            'tag_ids' => [$tagGreenId],
        ], $super);
        $this->assertSame(201, $product['status'], json_encode($product['json']));
        $productId = (string) $product['json']['data']['id'];
        $this->assertSame('CN-' . $suffix, $product['json']['data']['national_code']);
        $this->assertSame('Caja 8x45gr', $product['json']['data']['packaging']);
        $this->assertSame($subBrandId, $product['json']['data']['sub_brand_id']);
        $this->assertSame('Felix-' . $suffix, $product['json']['data']['sub_brand']['name']);
        $this->assertSame('Gato-' . $suffix, $product['json']['data']['species']['name']);
        $this->assertSame('Dermatologia-' . $suffix, $product['json']['data']['specialty']['name']);
        $this->assertCount(1, $product['json']['data']['tags']);
        $this->assertSame($tagGreenId, $product['json']['data']['tags'][0]['id']);

        $badSubBrand = $this->request('PATCH', '/api/v1/products/' . $productId, [
            'brand_id' => $otherBrandId,
            'sub_brand_id' => $subBrandId,
        ], $super);
        $this->assertSame(422, $badSubBrand['status']);
        $this->assertStringContainsString('Sub-brand does not belong', (string) ($badSubBrand['json']['detail'] ?? ''));

        $patched = $this->request('PATCH', '/api/v1/products/' . $productId, [
            'tag_ids' => [$tagGreenId, $tagPromoId],
            'species_id' => null,
        ], $super);
        $this->assertSame(200, $patched['status'], json_encode($patched['json']));
        $this->assertNull($patched['json']['data']['species_id']);
        $this->assertCount(2, $patched['json']['data']['tags']);

        $cleared = $this->request('PATCH', '/api/v1/products/' . $productId, [
            'tag_ids' => [],
        ], $super);
        $this->assertSame(200, $cleared['status'], json_encode($cleared['json']));
        $this->assertSame([], $cleared['json']['data']['tags']);

        $detail = $this->request('GET', '/api/v1/products/' . $productId, null, $super);
        $this->assertSame(200, $detail['status']);
        $this->assertSame('CN-' . $suffix, $detail['json']['data']['national_code']);
        $this->assertArrayHasKey('tags', $detail['json']['data']);
    }

    public function testDuplicateNationalCodeIsRejected(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $suffix = bin2hex(random_bytes(3));

        $first = $this->request('POST', '/api/v1/products', [
            'name' => 'CN-Uno-' . $suffix,
            'national_code' => 'CN-DUP-' . $suffix,
        ], $super);
        $this->assertSame(201, $first['status'], json_encode($first['json']));

        $second = $this->request('POST', '/api/v1/products', [
            'name' => 'CN-Dos-' . $suffix,
            'national_code' => 'CN-DUP-' . $suffix,
        ], $super);
        $this->assertSame(422, $second['status']);
    }

    public function testStaffCanReadNewCatalogsButNotWrite(): void
    {
        $staff = $this->authHeaderFor('staff@clinic-erp.com');
        $suffix = bin2hex(random_bytes(2));

        foreach (['sub-brands', 'species', 'specialties', 'product-tags'] as $resource) {
            $list = $this->request('GET', '/api/v1/' . $resource, null, $staff);
            $this->assertSame(200, $list['status'], 'GET /' . $resource . ' failed for staff');

            $create = $this->request('POST', '/api/v1/' . $resource, ['name' => 'No-' . $suffix], $staff);
            $this->assertSame(403, $create['status'], 'POST /' . $resource . ' should be forbidden for staff');
        }
    }
}
