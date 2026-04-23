<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AzureAuthControllerTest extends WebTestCase
{
    public function testConnectReturns404WhenSsoDisabled(): void
    {
        $client = static::createClient();
        // Test env has SSO_ENABLED unset/false by default.
        $client->request('GET', '/login/azure');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCheckReturns404WhenSsoDisabled(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login/azure/check');
        self::assertResponseStatusCodeSame(404);
    }

    public function testLoginPageHidesSsoButtonWhenDisabled(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/login/azure"]');
    }
}
