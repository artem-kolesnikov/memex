<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ApiToken;
use App\Service\BearerTokens;

/**
 * register → authorize → token, driven as a real client drives it, for the
 * {@see \App\Tests\Database\ApiTestCase} suites that need a token minted by
 * the OAuth front door rather than written by the fixture.
 */
trait DrivesOAuthFlow
{
    /** Verifier and its S256 challenge, fixed so the test reads as one story. */
    private const VERIFIER = 'a-code-verifier-long-enough-to-be-legal-per-rfc7636';
    private const CHALLENGE = 'KWdSoxvW5IPsEM6g-c7TZu3xQOndF0au47cLiYIyGFk';

    /** @return array{client_id: string, redirect_uri: string} */
    private function registerClient(string $redirectUri = 'https://oauth-redirect.googleusercontent.com/r/test'): array
    {
        $this->client->request(
            'POST',
            '/oauth/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['client_name' => 'Test Client', 'redirect_uris' => [$redirectUri]], JSON_THROW_ON_ERROR)
        );

        return ['client_id' => $this->jsonResponse()['client_id'], 'redirect_uri' => $redirectUri];
    }

    /** @param array{client_id: string, redirect_uri: string} $client */
    private function authorizeUri(array $client, string $scope = ''): string
    {
        $query = [
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $client['redirect_uri'],
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ];
        if ($scope !== '') {
            $query['scope'] = $scope;
        }

        return '/oauth/authorize?'.http_build_query($query);
    }

    /**
     * Read the consent page and take the token out of it, which is what a
     * browser does and what an attacker cannot.
     *
     * @param array{client_id: string, redirect_uri: string} $client
     */
    private function consentToken(array $client, string $scope = ''): string
    {
        $this->client->request('GET', $this->authorizeUri($client, $scope));
        $page = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->httpStatus(), 'the consent page did not render: '.mb_substr($page, 0, 200));
        self::assertMatchesRegularExpression('/name="_csrf" value="([^"]+)"/', $page, 'no CSRF token on the consent page');
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);

        return $m[1];
    }

    /**
     * Consent in whatever session is signed in, and return the code.
     *
     * @param array{client_id: string, redirect_uri: string} $client
     */
    private function consent(array $client, string $scope = ''): string
    {
        $this->client->followRedirects(false);
        $this->client->request('POST', $this->authorizeUri($client, $scope), [
            '_csrf' => $this->consentToken($client, $scope),
        ]);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('code=', $location, 'consent did not produce a code: '.$location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        return (string) $params['code'];
    }

    /**
     * POST the exchange as given. `$fields` overrides or, with null, removes
     * what an honest client would send.
     *
     * @param array{client_id: string, redirect_uri: string} $client
     * @param array<string, string|null>                      $fields
     */
    private function requestToken(string $code, array $client, array $fields = []): void
    {
        $this->client->request('POST', '/oauth/token', array_filter([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => self::VERIFIER,
            'client_id' => $client['client_id'],
            'redirect_uri' => $client['redirect_uri'],
            ...$fields,
        ], static fn (?string $v) => $v !== null));
    }

    /** @param array{client_id: string, redirect_uri: string} $client */
    private function exchange(string $code, array $client): ApiToken
    {
        $this->requestToken($code, $client);

        $body = $this->jsonResponse();
        self::assertArrayHasKey('access_token', $body, 'token exchange failed: '.json_encode($body));

        $route = static::getContainer()->get(BearerTokens::class)->route($body['access_token']);
        self::assertNotNull($route, 'no token row was written');
        $tenant = $route['account_id'] === $this->kb->a->accountId ? $this->kb->a : $this->kb->b;
        self::assertSame($tenant->accountId, $route['account_id']);
        $this->in($tenant);
        $token = $this->em->find(ApiToken::class, $route['connection_id']);
        self::assertNotNull($token, 'no token row was written');

        return $token;
    }
}
