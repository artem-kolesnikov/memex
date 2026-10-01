<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Directory\Account;
use App\Directory\Identity;
use App\Storage\DataDir;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Signing in the way a browser does, through the fake providers, and what a
 * test reads afterwards. For a class extending {@see \App\Tests\Database\ApiTestCase}.
 */
trait SignsInThroughProviders
{
    /**
     * Where a completed sign-in lands, matching SocialAuthController::HOME.
     *
     * Written as a constant after these assertions went stale without failing:
     * they asserted `/`, which was the app's home until the landing page took
     * that path on 2026-08-30, and they stayed green because they compare the
     * redirect STRING and nothing in this suite routes it through nginx.
     * A backend that sent people to the marketing page after a successful
     * sign-in would have passed every one of them.
     */
    /**
     * Where a completed sign-in should land: the notes list of the knowledge
     * base the identity that just signed in belongs to.
     *
     * Resolved from the provider SUBJECT rather than written out, because
     * every page moved inside the vault's handle and a handle is minted per
     * account — so there is no one string to assert any more. Asserting the
     * shape instead would accept a redirect into somebody else's knowledge
     * base, which is the one failure this destination could have.
     */
    /**
     * The same destination, found by the address the account ended up with.
     *
     * Microsoft stores `<tenant>.<object>` as its subject rather than anything
     * the sign-in code spells, so the subject lookup above has nothing to match
     * on for that provider.
     */
    private function homeOfEmail(string $email): string
    {
        $account = $this->userByEmail($email);
        self::assertNotNull($account, 'No account for '.$email);

        return '/'.$account->getHandle().'/notes';
    }

    private function homeOfSubject(string $codeOrSubject): string
    {
        $subject = explode('~', $codeOrSubject)[0];
        $identity = $this->identity(['subject' => $subject]);
        self::assertNotNull($identity, 'No identity for subject '.$subject);

        return '/'.$identity->getAccount()->getHandle().'/notes';
    }

    private function directory(): EntityManagerInterface
    {
        $directory = static::getContainer()->get('doctrine.orm.directory_entity_manager');
        $directory->clear();

        return $directory;
    }

    /** @param array<string, mixed> $criteria */
    private function identity(array $criteria): ?Identity
    {
        return $this->directory()->getRepository(Identity::class)->findOneBy($criteria);
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return list<Identity>
     */
    private function identities(array $criteria): array
    {
        return $this->directory()->getRepository(Identity::class)->findBy($criteria);
    }

    /**
     * Walk the round trip the browser walks: start, read the state out of the
     * redirect to the provider, come back with it.
     *
     * @return string the SPA path the callback redirected to
     */
    private function signInWith(string $provider, string $code, ?string $invite = null, bool $link = false, ?string $next = null): string
    {
        $query = [];
        if ($invite !== null) {
            $query['invite'] = $invite;
        }
        if ($link) {
            $query['link'] = '1';
        }
        if ($next !== null) {
            $query['next'] = $next;
        }
        $this->client->request('GET', '/api/auth/'.$provider.'/start'.($query === [] ? '' : '?'.http_build_query($query)));

        $location = (string) $this->client->getResponse()->headers->get('Location');
        if (!str_contains($location, 'state=')) {
            // start() refused before the provider was ever reached.
            return $location;
        }
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        $this->client->request('GET', '/api/auth/'.$provider.'/callback?'.http_build_query([
            'code' => $code,
            'state' => $params['state'],
        ]));

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /**
     * A new account landed in a knowledge base of its OWN.
     *
     * The destination helpers read the vault off the account under test, so
     * they would assert a cross-tenant assignment against itself: give the new
     * account fixture vault B and `homeOfEmail()` cheerfully expects B's
     * address. This says the vault is neither fixture vault, exists, and
     * belongs to nobody else.
     */
    private function assertOwnKnowledgeBase(Account $account): void
    {
        $key = $account->getVaultKey();
        self::assertNotSame($this->kb->a->accountId, $account->getId(), 'A new account is fixture account A');
        self::assertNotSame($this->kb->b->accountId, $account->getId(), 'A new account is fixture account B');
        self::assertNotSame($this->kb->a->account()->getVaultKey(), $key, 'A new account joined fixture vault A');
        self::assertNotSame($this->kb->b->account()->getVaultKey(), $key, 'A new account joined fixture vault B');
        self::assertNotSame($this->kb->a->handle(), $account->getHandle());
        self::assertNotSame($this->kb->b->handle(), $account->getHandle());
        self::assertFileExists(static::getContainer()->get(DataDir::class)->vaultPath($key), 'A new account has no vault');
        self::assertSame(
            [1, $account->getId()],
            array_map('intval', array_values((array) static::getContainer()->get('doctrine.dbal.directory_connection')->fetchAssociative(
                'SELECT count(*) AS n, min(id) AS owner FROM accounts WHERE vault_key = :key',
                ['key' => $key]
            ))),
            'A new knowledge base holds exactly its own owner and nobody else'
        );
    }

    private function userByEmail(string $email): ?Account
    {
        return $this->directory()->getRepository(Account::class)->findOneBy(['email' => $email]);
    }
}

