<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\SsoStatusProvider;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class AzureAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ClientRegistry $registry,
        private readonly AzureUserResolver $resolver,
        private readonly UrlGeneratorInterface $router,
        private readonly SsoStatusProvider $sso,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $this->sso->isEnabled() && $request->attributes->get('_route') === 'app_azure_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->registry->getClient('azure');
        $token = $client->getAccessToken();
        $owner = $client->fetchUserFromToken($token);
        $raw = $owner->toArray();

        $email = $raw['mail'] ?? $raw['userPrincipalName'] ?? $raw['email'] ?? null;
        $name = $raw['displayName'] ?? $raw['name'] ?? $email;
        if (!is_string($email) || $email === '') {
            throw new AuthenticationException('Azure did not return a usable email claim.');
        }

        $user = $this->resolver->resolve($email, is_string($name) ? $name : $email);
        $this->logger->info('SSO login', ['email' => $email, 'userId' => $user->getId()->toRfc4122()]);

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier()));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->warning('SSO failure', ['message' => $exception->getMessage()]);
        return new RedirectResponse($this->router->generate('app_login') . '?sso_error=1');
    }
}
