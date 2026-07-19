<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Application\Support\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testNormalizesCaseAccentsAndSpaces(): void
    {
        $this->assertSame('receta-veterinaria', Slug::from('RECETA VETERINARIA'));
        $this->assertSame('receta-veterinaria', Slug::from('Receta veterinaria'));
        $this->assertSame('otc-clinica', Slug::from('OTC-CLÍNICA'));
    }

    public function testEmptyBecomesEmpty(): void
    {
        $this->assertSame('', Slug::from('   '));
    }
}
