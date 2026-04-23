<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SsoStatusProvider
{
    public function __construct(
        #[Autowire(env: 'bool:SSO_ENABLED')]
        private readonly bool $enabled,
        #[Autowire(env: 'AZURE_TENANT_ID')]
        private readonly string $tenantId,
        #[Autowire(env: 'AZURE_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'AZURE_CLIENT_SECRET')]
        private readonly string $clientSecret,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function statusCode(): string
    {
        if (!$this->enabled) {
            return 'not_configured';
        }
        if ($this->tenantId === '' || $this->clientId === '' || $this->clientSecret === '') {
            return 'misconfigured';
        }
        return 'enabled';
    }

    public function tenantSuffix(): string
    {
        return substr($this->tenantId, -4);
    }
}
