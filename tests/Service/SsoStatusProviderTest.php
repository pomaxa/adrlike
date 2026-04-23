<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SsoStatusProvider;
use PHPUnit\Framework\TestCase;

final class SsoStatusProviderTest extends TestCase
{
    public function testDisabledWhenFlagOff(): void
    {
        $p = new SsoStatusProvider(false, 'tenant-abcd-1234', 'client', 'secret');
        self::assertFalse($p->isEnabled());
        self::assertSame('not_configured', $p->statusCode());
    }

    public function testMisconfiguredWhenFlagOnButMissingVars(): void
    {
        $p = new SsoStatusProvider(true, 'tenant', '', 'secret');
        self::assertTrue($p->isEnabled());
        self::assertSame('misconfigured', $p->statusCode());
    }

    public function testEnabledShowsMaskedTenantSuffix(): void
    {
        $p = new SsoStatusProvider(true, 'abcdef-1234-5678-9abc', 'client', 'secret');
        self::assertSame('enabled', $p->statusCode());
        self::assertSame('9abc', $p->tenantSuffix());
    }
}
