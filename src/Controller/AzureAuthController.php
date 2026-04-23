<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SsoStatusProvider;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AzureAuthController extends AbstractController
{
    public function __construct(
        private readonly ClientRegistry $registry,
        private readonly SsoStatusProvider $sso,
    ) {
    }

    #[Route('/login/azure', name: 'app_azure_connect', methods: ['GET'])]
    public function connect(): Response
    {
        if (!$this->sso->isEnabled()) {
            throw $this->createNotFoundException();
        }
        return $this->registry->getClient('azure')->redirect(['openid', 'profile', 'email', 'User.Read']);
    }

    #[Route('/login/azure/check', name: 'app_azure_check', methods: ['GET'])]
    public function check(): Response
    {
        if (!$this->sso->isEnabled()) {
            throw $this->createNotFoundException();
        }
        // Response comes from the authenticator's onAuthenticationSuccess redirect.
        return new Response('', 204);
    }
}
