<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Infrastructure\Config\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ENV_TEST_STRING');
        putenv('ENV_TEST_INT');
        putenv('ENV_TEST_BOOL');
        unset($_ENV['ENV_TEST_STRING'], $_ENV['ENV_TEST_INT'], $_ENV['ENV_TEST_BOOL']);
        parent::tearDown();
    }

    public function testStringReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('default', Env::string('ENV_TEST_STRING', 'default'));
    }

    public function testStringReadsFromEnv(): void
    {
        $_ENV['ENV_TEST_STRING'] = '  value  ';
        putenv('ENV_TEST_STRING=value');

        $this->assertSame('  value  ', Env::string('ENV_TEST_STRING'));
        $this->assertSame('value', Env::trimmed('ENV_TEST_STRING'));
    }

    public function testIntCastsNumericEnv(): void
    {
        $_ENV['ENV_TEST_INT'] = '6379';
        putenv('ENV_TEST_INT=6379');

        $this->assertSame(6379, Env::int('ENV_TEST_INT', 0));
    }

    public function testBoolParsesTruthyValues(): void
    {
        $_ENV['ENV_TEST_BOOL'] = 'true';
        putenv('ENV_TEST_BOOL=true');

        $this->assertTrue(Env::bool('ENV_TEST_BOOL', false));
    }
}
