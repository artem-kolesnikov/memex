<?php

declare(strict_types=1);

namespace App\Security;

use App\Directory\Account;
use App\Entity\ApiToken;
use App\Service\BearerTokens;
use App\Storage\VaultContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Bearer-token auth for agents/API clients. The plaintext token (mxt_<hex>) is
 * hashed and looked up in the directory, which names the account; the request
 * is bound to that account's vault, and the connection found there is stashed
 * on the request so controllers can apply the review gate.
 */
class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const REQUEST_ATTRIBUTE = '_api_token';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly BearerTokens $tokens,
        private readonly VaultContext $context,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $plaintext = trim(substr($request->headers->get('Authorization', ''), 7));
        if ($plaintext === '') {
            throw new CustomUserMessageAuthenticationException('Empty bearer token');
        }

        $route = $this->tokens->route($plaintext);
        $account = $route === null ? null : $this->directoryEntityManager->find(Account::class, $route['account_id']);
        if ($account === null) {
            throw new CustomUserMessageAuthenticationException('Invalid or revoked token');
        }
        $this->context->bind($account->vault());
        $token = $this->em->find(ApiToken::class, $route['connection_id']);
        if ($token === null || $token->isRevoked()) {
            throw new CustomUserMessageAuthenticationException('Invalid or revoked token');
        }
        $token->touch();
        $this->em->flush();
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $token);

        return new SelfValidatingPassport(
            new UserBadge($account->getUserIdentifier(), fn () => $account)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => $exception->getMessageKey()], Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $response = new JsonResponse(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        // MCP clients discover the OAuth authorization server from the 401
        // (RFC 9728) — this is what lets claude.ai connectors self-configure.
        if (str_starts_with($request->getPathInfo(), '/mcp')) {
            $response->headers->set(
                'WWW-Authenticate',
                'Bearer resource_metadata="'.$request->getSchemeAndHttpHost().'/.well-known/oauth-protected-resource"'
            );
        }

        return $response;
    }
}
