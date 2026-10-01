<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Directory\Account;
use App\Directory\Identity;
use App\Service\SocialProviders;
use App\Tests\Support\DoorHeldOpen;
use App\Tests\Support\SignsInThroughProviders;
use App\Tests\Support\SqlObservation;
use App\Tests\Support\Vaults;
use App\Storage\DataDir;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The sign-in policy, driven through HTTP the way a browser drives it.
 *
 * This is an authentication path, which means every property here is one whose
 * failure hands somebody another person's knowledge base. None of them can be
 * established by reading the code — an email comparison that looks careful and
 * a `hash_equals` that is never reached both read fine.
 *
 * The provider is a stub ({@see \App\Tests\Support\MockMlResponder}) that
 * carries the identity inside the authorization code, so a test says who is
 * signing in by choosing the code it sends back. The stub decides nothing; the
 * policy under test lives entirely in App\Service\SocialAccounts.
 */
class SocialSignInTest extends ApiTestCase
{
    use SignsInThroughProviders;

    protected function setUp(): void
    {
        parent::setUp();
        // Who may have an account is the edition's, and none of this is about that.
        DoorHeldOpen::hold();
    }

    protected function tearDown(): void
    {
        DoorHeldOpen::release();
        parent::tearDown();
    }

    // ------------------------------------------------- coming back afterwards

    public function testAKnownIdentityNeedsNoInviteTheSecondTime(): void
    {
        $this->signInWith(SocialProviders::GOOGLE, 'google-sub-1~ann@example.test~Ann');
        $created = $this->userByEmail('ann@example.test');
        self::assertNotNull($created);
        $this->assertOwnKnowledgeBase($created);
        self::assertNotNull($created);

        $this->client->request('POST', '/api/logout');
        $landing = $this->signInWith(SocialProviders::GOOGLE, 'google-sub-1~ann@example.test~Ann');

        self::assertSame($this->homeOfSubject('google-sub-1'), $landing);
        $this->sessionRequest('GET', '/api/me');
        self::assertSame($created->getHandle(), $this->jsonResponse()['team']['handle']);
        self::assertSame($created->getEmail(), $this->jsonResponse()['email']);

        // One account, not two, and the identity records that it was used.
        self::assertCount(1, $this->identities(['subject' => 'google-sub-1']));
        self::assertNotNull(
            $this->identity(['subject' => 'google-sub-1'])?->getLastUsedAt()
        );
    }

    /**
     * The sharpest case in the file. Signing in with a provider that reports an
     * address we already have an account for must NOT reach that account: it is
     * usually the same person and occasionally somebody who has arranged for a
     * provider to report your address.
     */
    public function testAMatchingEmailDoesNotOpenSomebodyElsesAccount(): void
    {
        $ownersEmail = $this->kb->a->email;

        $landing = $this->signInWith(SocialProviders::GOOGLE, 'impostor-1~'.$ownersEmail.'~Not Really');

        self::assertSame('/login?error=email_in_use', $landing);
        // Asserted on the impostor's own subject rather than on a count: the
        // fixture owner has an identity of their own since 2026-08-22, because
        // signing the suite in is how the password path's removal is survived.
        self::assertCount(0, $this->identities(['subject' => 'impostor-1']));


        // And nothing was signed in.
        $this->sessionRequest('GET', '/api/me');
        self::assertSame(401, $this->httpStatus());
    }

    /**
     * Google reporting an address it has not verified is a string somebody
     * typed. It becomes the account's contact address, so it must not be
     * trusted, and with nothing left to name the account the sign-in stops.
     */
    public function testAnUnverifiedGoogleAddressCannotOpenAnAccount(): void
    {
        $landing = $this->signInWith(SocialProviders::GOOGLE, 'unverified-1~!typed@example.test~Typed');

        self::assertSame('/login?error=no_email', $landing);
        self::assertNull($this->userByEmail('typed@example.test'));
    }

    public function testGitHubPrivateAddressIsFoundOnTheEmailsEndpoint(): void
    {
        $landing = $this->signInWith(SocialProviders::GITHUB, '4242~private~octo');

        self::assertSame($this->homeOfSubject('4242'), $landing);
        // /user reported no address at all; /user/emails had the verified one.
        $opened = $this->userByEmail('private-person@example.test');
        self::assertNotNull($opened);
        $this->assertOwnKnowledgeBase($opened);
    }

    // --------------------------------------------------------------- Microsoft

    /*
     * Microsoft is the third provider, and the only one whose reported address
     * may be a fiction. Entra puts no ownership check on the `email` claim, so
     * a tenant administrator can put anybody's address on anybody's account —
     * the shape of the nOAuth problem. Their answer is the optional `xms_edov`
     * claim, and these are the tests that it is actually consulted.
     *
     * The stub carries `subject~email~name~upn`; `!` in front of the address
     * means the token arrives WITHOUT the claim, which is what a real one does
     * when the app registration has not been configured for it.
     */

    public function testAProvenMicrosoftAddressOpensAnAccount(): void
    {
        $landing = $this->signInWith(
            SocialProviders::MICROSOFT,
            'dir-user-1~ann@contoso.test~Ann Example~ann@contoso.test'
        );

        self::assertSame($this->homeOfEmail('ann@contoso.test'), $landing);
        $opened = $this->userByEmail('ann@contoso.test');
        self::assertNotNull($opened);
        $this->assertOwnKnowledgeBase($opened);
    }

    /**
     * The one that matters. An administrator can type `someone@gmail.test` into
     * their directory for a user who has never touched that mailbox; without
     * the claim to prove it, memex must not take that as the account's contact
     * address. The sign-in name is the fallback because Entra only permits a
     * UPN suffix on a domain the tenant has verified with Microsoft.
     */
    public function testAnUnprovenMicrosoftAddressLosesToTheSignInName(): void
    {
        $landing = $this->signInWith(
            SocialProviders::MICROSOFT,
            'dir-user-2~!victim@gmail.test~Bob~bob@contoso.test'
        );

        self::assertSame($this->homeOfEmail('bob@contoso.test'), $landing);
        self::assertNull($this->userByEmail('victim@gmail.test'), 'An unproven address must never become an account');
        $opened = $this->userByEmail('bob@contoso.test');
        self::assertNotNull($opened);
        $this->assertOwnKnowledgeBase($opened);
    }

    public function testAnUnprovenMicrosoftAddressWithNoSignInNameOpensNothing(): void
    {
        $landing = $this->signInWith(SocialProviders::MICROSOFT, 'dir-user-3~!victim@gmail.test~Bob');

        self::assertSame('/login?error=no_email', $landing);
        self::assertNull($this->userByEmail('victim@gmail.test'));
    }

    /**
     * A B2B guest's sign-in name is `person_gmail.com#EXT#@tenant.onmicrosoft
     * .com` — an identifier wearing an address's clothes, and a mailbox that
     * does not exist.
     *
     * **This test was written expecting the email validator to catch it, and
     * the validator does not**: `#` is legal in a local part, so the guest got
     * an account whose contact address silently swallowed every message memex
     * would ever send. `#EXT#` is now refused by name.
     */
    public function testAGuestsSignInNameIsNotAnAddress(): void
    {
        $landing = $this->signInWith(
            SocialProviders::MICROSOFT,
            'dir-guest-1~!guest@gmail.test~Guest~guest_gmail.com#EXT#@contoso.onmicrosoft.test'
        );

        self::assertSame('/login?error=no_email', $landing);
        self::assertNull($this->userByEmail('guest@gmail.test'));
    }

    /**
     * Keyed on `oid` and `tid`, which is Microsoft's own guidance, and NOT on
     * `sub` — which is stable for one application and would therefore pass any
     * test that only signed in twice. The stub reports a different `sub` from
     * its `oid` precisely so this can be asserted rather than assumed.
     */
    public function testAMicrosoftAccountIsKeyedOnTheDirectoryIdAndItsTenant(): void
    {
        $this->signInWith(SocialProviders::MICROSOFT, 'dir-user-4~cara@contoso.test~Cara~cara@contoso.test');

        $identity = $this->identity([
            'provider' => SocialProviders::MICROSOFT,
            'subject' => 'test-tenant.dir-user-4',
        ]);

        self::assertNotNull($identity, 'The identity should be keyed on tenant + directory id');
        self::assertNull($this->identity(['subject' => 'pairwise-dir-user-4']));
    }

    /** A personal Microsoft account signs in too — that is what `/common` buys. */
    public function testAPersonalMicrosoftAccountIsAdmittedAsWell(): void
    {
        $landing = $this->signInWith(SocialProviders::MICROSOFT, 'personal~dave@outlook.test~Dave~dave@outlook.test');

        self::assertSame($this->homeOfEmail('dave@outlook.test'), $landing);
        $opened = $this->userByEmail('dave@outlook.test');
        self::assertNotNull($opened);
        $this->assertOwnKnowledgeBase($opened);
    }

    /**
     * The audience check. TLS already proves the token came from Microsoft, so
     * this catches the other thing: a box whose `.env.local` has two
     * registrations crossed, which would otherwise sign people in against
     * credentials nobody here controls.
     */
    public function testAnIdentityTokenMintedForAnotherApplicationIsRefused(): void
    {
        $landing = $this->signInWith(SocialProviders::MICROSOFT, 'wrong-audience~eve@contoso.test~Eve~eve@contoso.test');

        self::assertSame('/login?error=provider', $landing);
        self::assertNull($this->userByEmail('eve@contoso.test'));
    }

    /**
     * An expired client secret is Microsoft's characteristic failure, and it is
     * the whole reason the Admin pane counts down to one. It has to land as an
     * explanation on the sign-in screen rather than as a blank redirect.
     */
    public function testAnExpiredMicrosoftSecretBecomesAnExplanation(): void
    {
        $landing = $this->signInWith(SocialProviders::MICROSOFT, 'refuse');

        self::assertSame('/login?error=provider', $landing);
    }

    // ----------------------------------------------------------------- forgery

    public function testAForgedStateIsRefused(): void
    {
        $this->client->request('GET', '/api/auth/google/start');

        $this->client->request('GET', '/api/auth/google/callback?'.http_build_query([
            'code' => 'attacker-1~attacker@example.test~Attacker',
            'state' => 'a-state-the-server-never-issued',
        ]));

        self::assertSame('/login?error=state', $this->client->getResponse()->headers->get('Location'));
        self::assertNull($this->userByEmail('attacker@example.test'));
    }

    public function testACallbackWithNoStartedSignInIsRefused(): void
    {
        $this->client->request('GET', '/api/auth/google/callback?code=x~x@example.test~X&state=anything');

        self::assertSame('/login?error=state', $this->client->getResponse()->headers->get('Location'));
    }

    public function testCancellingAtTheProviderSaysSoAndCreatesNothing(): void
    {
        $this->client->request('GET', '/api/auth/google/start');
        parse_str(
            (string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY),
            $params
        );

        $this->client->request('GET', '/api/auth/google/callback?error=access_denied&state='.$params['state']);

        self::assertSame('/login?error=denied', $this->client->getResponse()->headers->get('Location'));
    }

    // -------------------------------------------------------------- linking

    public function testLinkingAttachesAProviderToTheOpenSessionAndThenSignsYouIn(): void
    {
        // The operator's own case: his memex address, his Google address and
        // his Apple address are three different addresses, so linking from a
        // session is the only route that could ever have worked.
        $this->loginAs($this->kb->a);

        $landing = $this->signInWith(SocialProviders::GOOGLE, 'operator-g-1~different@gmail.test~Operator', link: true);
        self::assertSame('/'.$this->kb->a->handle().'/settings/account?linked=google', $landing);

        $linked = $this->identity(['subject' => 'operator-g-1']);
        self::assertNotNull($linked);
        self::assertSame($this->kb->a->accountId, $linked->getAccount()->getId());
        self::assertSame('different@gmail.test', $linked->getEmail());

        // The account's own address is untouched: a login identity and a
        // contact address are different things.
        self::assertSame($this->kb->a->email, $this->kb->a->account()->getEmail());

        $this->client->request('POST', '/api/logout');
        self::assertSame($this->homeOfSubject('operator-g-1'), $this->signInWith(SocialProviders::GOOGLE, 'operator-g-1~different@gmail.test~Operator'));
        $this->sessionRequest('GET', '/api/me');
        self::assertSame($this->kb->a->email, $this->jsonResponse()['email']);
    }

    public function testLinkingTwiceIsNotAnError(): void
    {
        $this->loginAs($this->kb->a);
        $this->signInWith(SocialProviders::GOOGLE, 'operator-g-1~op@gmail.test~Operator', link: true);
        $landing = $this->signInWith(SocialProviders::GOOGLE, 'operator-g-1~op@gmail.test~Operator', link: true);

        self::assertSame('/'.$this->kb->a->handle().'/settings/account?linked=google', $landing);
        self::assertCount(1, $this->identities(['subject' => 'operator-g-1']));
    }

    public function testOneProviderAccountCannotBeTheWayIntoTwoKnowledgeBases(): void
    {
        $this->loginAs($this->kb->a);
        $this->signInWith(SocialProviders::GOOGLE, 'shared-g-1~shared@gmail.test~Shared', link: true);
        $this->client->request('POST', '/api/logout');

        $this->loginAs($this->kb->b);
        $landing = $this->signInWith(SocialProviders::GOOGLE, 'shared-g-1~shared@gmail.test~Shared', link: true);

        self::assertSame('/'.$this->kb->b->handle().'/settings/account?error=identity_taken', $landing);
        $shared = $this->identity(['subject' => 'shared-g-1']);
        self::assertSame($this->kb->a->accountId, $shared?->getAccount()->getId(), 'The identity stayed with A');
    }

    public function testLinkingWithoutASessionGoesNowhere(): void
    {
        $landing = $this->signInWith(SocialProviders::GOOGLE, 'nobody-1~nobody@example.test~Nobody', link: true);

        self::assertSame('/login?error=link_session', $landing);
        self::assertNull($this->userByEmail('nobody@example.test'));
    }

    /**
     * The property the whole design exists for: one person, several providers,
     * each with its own name and its own address, and memex treats every one of
     * them as the same account.
     *
     * Unasserted until 2026-08-21 despite being the point. It is true by
     * construction — `signIn()` writes only to the identity row and returns
     * whichever user owns it — and "true by construction" is exactly the kind
     * of claim that stops being true the day somebody adds a convenience that
     * copies the provider's profile onto the account.
     */
    public function testSeveralProvidersWithDifferentNamesAndAddressesAreOneAccount(): void
    {
        $originalName = $this->kb->a->account()->getName();
        $originalEmail = $this->kb->a->account()->getEmail();

        // Two providers, deliberately disagreeing about who this person is and
        // both disagreeing with the memex account.
        $this->loginAs($this->kb->a);
        $this->signInWith(SocialProviders::GOOGLE, 'same-human-g~work@gmail.test~Jane D', link: true);
        $this->signInWith(SocialProviders::GITHUB, '9001~personal@users.test~octocat', link: true);
        $this->client->request('POST', '/api/logout');

        // Either one opens the same knowledge base.
        foreach ([
            [SocialProviders::GOOGLE, 'same-human-g~work@gmail.test~Jane D'],
            [SocialProviders::GITHUB, '9001~personal@users.test~octocat'],
        ] as [$provider, $code]) {
            self::assertSame($this->homeOfSubject($code), $this->signInWith($provider, $code), $provider.' should sign in');
            $this->sessionRequest('GET', '/api/me');
            self::assertSame($this->kb->a->handle(), $this->jsonResponse()['team']['handle'], $provider.' reached a different account');
            $this->client->request('POST', '/api/logout');
        }

        // And neither renamed the person or changed their address. A provider's
        // idea of who you are does not overwrite your own.
        $this->directory();
        self::assertSame($originalName, $this->kb->a->account()->getName(), 'A provider must not rename the account');
        self::assertSame($originalEmail, $this->kb->a->account()->getEmail(), 'A provider must not change the contact address');

        // The distinct provider addresses ARE kept, one per identity, so the
        // Settings screen can tell two Google accounts apart.
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/identities');
        $emails = array_map(static fn (array $r) => $r['email'], $this->jsonResponse()['identities']);
        sort($emails);
        // The fixture's own sign-in identity is in this list too, and belongs
        // there: it is a third provider account reaching the same knowledge
        // base, which is the property under test.
        self::assertSame([$this->kb->a->email, 'personal@users.test', 'work@gmail.test'], $emails);
    }

    // ------------------------------------------------------ removing a way in

    public function testTheLastWayIntoAnAccountCannotBeRemoved(): void
    {
        $this->signInWith(SocialProviders::GOOGLE, 'solo-1~solo@example.test~Solo');

        $this->sessionRequest('GET', '/api/me/identities');
        $identities = $this->jsonResponse();
        self::assertCount(1, $identities['identities']);

        $this->sessionRequest('DELETE', '/api/me/identities/'.$identities['identities'][0]['id']);

        self::assertSame(400, $this->httpStatus());
        self::assertCount(1, $this->identities(['subject' => 'solo-1']));
    }

    /**
     * Two removals from one browser run side by side; each must count the
     * ways in inside the transaction that removes one, or both count two and
     * the account is left with none.
     */
    public function testRemovingAWayInCountsTheOthersInsideItsOwnTransaction(): void
    {
        $directory = $this->directory();
        $second = new Identity($directory->find(Account::class, $this->kb->a->accountId), SocialProviders::GITHUB, 'second-way-in', 'second@users.test');
        $directory->persist($second);
        $directory->flush();
        $this->loginAs($this->kb->a);

        $transactions = [];
        SqlObservation::during(
            static::getContainer()->get('doctrine.dbal.directory_connection'),
            static function (string $sql, array $params, ?int $transaction) use (&$transactions): void {
                if (preg_match('/^\s*(SELECT\b.*\bFROM identities\b.*\bWHERE\b.*\baccount_id\b|DELETE FROM identities\b)/is', $sql) === 1) {
                    $transactions[] = $transaction;
                }
            },
            fn () => $this->sessionRequest('DELETE', '/api/me/identities/'.$second->getRef()),
        );

        self::assertSame(200, $this->httpStatus(), $this->body());
        [$count, $removal] = \array_slice($transactions, -2);
        self::assertNotNull($count, 'the ways in were counted outside a transaction');
        self::assertSame($count, $removal, 'the count and the removal ran in different transactions');
    }

    public function testAnotherTenantsSignInMethodIsNotEvenVisible(): void
    {
        $this->loginAs($this->kb->a);
        $this->signInWith(SocialProviders::GOOGLE, 'a-only-1~a@gmail.test~A', link: true);
        $aIdentity = $this->identity(['subject' => 'a-only-1']);
        self::assertNotNull($aIdentity);
        $this->client->request('POST', '/api/logout');

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/me/identities');
        $subjects = array_column($this->jsonResponse()['identities'], 'email');
        self::assertNotContains('a@gmail.test', $subjects, "B must not see A's sign-in methods");

        $this->sessionRequest('DELETE', '/api/me/identities/'.$aIdentity->getRef());
        // Not 403: an id that is not yours is indistinguishable from one that
        // does not exist, or the error message is an existence oracle.
        self::assertSame(404, $this->httpStatus());
        self::assertNotNull($this->identity(['subject' => 'a-only-1']));
    }

    /**
     * Coming back to the OAuth consent page after signing in — the return half
     * of the fix that stopped that page asking a social account for a password
     * it has never had.
     */
    public function testSigningInCanReturnToTheConsentPage(): void
    {
        $this->loginAs($this->kb->a);
        $this->signInWith(SocialProviders::GOOGLE, 'return-1~a@gmail.test~A', link: true);
        $this->client->request('POST', '/api/logout');

        $next = '/oauth/authorize?client_id=abc&response_type=code';
        $landed = $this->signInWith(SocialProviders::GOOGLE, 'return-1~a@gmail.test~A', next: $next);

        self::assertSame($next, $landed, 'it must come back to the page that sent them');
    }

    /**
     * @dataProvider elsewhere
     */
    public function testSignInWillNotRedirectAnywhereElse(string $next): void
    {
        // An unchecked `next` on a sign-in path is an open redirect, and an
        // open redirect on a sign-in path is a phishing page hosted on the
        // domain people were told to trust. `//evil.test` and `/\evil.test` are
        // the two spellings a browser reads as another origin.
        $this->loginAs($this->kb->a);
        $this->signInWith(SocialProviders::GOOGLE, 'elsewhere-1~a@gmail.test~A', link: true);
        $this->client->request('POST', '/api/logout');

        $landed = $this->signInWith(SocialProviders::GOOGLE, 'elsewhere-1~a@gmail.test~A', next: $next);

        self::assertSame($this->homeOfSubject('elsewhere-1'), $landed, var_export($next, true).' was accepted as a destination');
    }

    /** @return iterable<string, array{string}> */
    public static function elsewhere(): iterable
    {
        yield 'another origin' => ['https://evil.test/harvest'];
        yield 'protocol-relative' => ['//evil.test/harvest'];
        yield 'backslash spelling' => ['/\\evil.test/harvest'];
        yield 'a page inside our own app' => ['/settings/general'];
        yield 'a lookalike path' => ['/oauth/authorize-evil?x=1'];
        yield 'the login screen itself' => ['/login'];
    }

    public function testABearerTokenCannotRearrangeHowAnAccountIsOpened(): void
    {
        $this->loginAs($this->kb->a);
        $this->signInWith(SocialProviders::GOOGLE, 'a-only-1~a@gmail.test~A', link: true);
        $id = (string) $this->identity(['subject' => 'a-only-1'])?->getRef();

        // Even the account's own curator token. A leaked token must not be able
        // to take the account over, which is the same rule the password form has.
        $this->request('DELETE', '/api/me/identities/'.$id, $this->kb->a->curatorBearer);

        self::assertSame(403, $this->httpStatus());
        self::assertNotNull($this->identity(['ref' => $id]));
    }

    // ---------------------------------------------------------------- offering

    public function testTheSignInScreenIsToldWhichProvidersExistWithoutASession(): void
    {
        $this->sessionRequest('GET', '/api/auth/providers');

        self::assertSame(200, $this->httpStatus());
        $ids = array_column($this->jsonResponse()['providers'], 'id');
        self::assertSame(
            [SocialProviders::GOOGLE, SocialProviders::APPLE, SocialProviders::MICROSOFT, SocialProviders::GITHUB],
            $ids,
            'Catalogue order, which is the order the buttons appear in — two to a row on the '
            .'sign-in screen, so this is Google | Apple over Microsoft | GitHub (operator, 2026-08-23).'
        );
    }

    /**
     * The file Microsoft fetches to confirm that memex.tools and the app
     * registration belong to the same people. It is a claim about a credential,
     * so it is built FROM the credential: a hardcoded id would keep asserting
     * an old registration after the real one was replaced, and nothing would
     * say so.
     */
    public function testTheMicrosoftIdentityAssociationNamesTheConfiguredApp(): void
    {
        $this->client->request('GET', '/.well-known/microsoft-identity-association.json');

        self::assertSame(200, $this->httpStatus());
        self::assertSame(
            ['associatedApplications' => [['applicationId' => 'test-microsoft-client']]],
            $this->jsonResponse()
        );

        // Unauthenticated on purpose: Microsoft fetches it with no credentials.
        // Nothing but the client id, which is already in every authorize URL.
        self::assertStringNotContainsString('test-microsoft-secret', $this->body());
    }

    public function testAProviderThisServerDoesNotKnowIsNotStarted(): void
    {
        // Any name that is not in the catalogue. This test used `apple` until
        // Apple was built, which is a fair warning about naming a real product
        // as the example of a thing that does not exist.
        $this->client->request('GET', '/api/auth/facebook/start');

        self::assertSame('/login?error=unconfigured', $this->client->getResponse()->headers->get('Location'));
    }

    public function testAProviderRefusalBecomesAnExplanationRatherThanASession(): void
    {
        // `refuse` is the stub's redirect_uri_mismatch, the commonest real one.
        $landing = $this->signInWith(SocialProviders::GOOGLE, 'refuse');

        self::assertSame('/login?error=provider', $landing);
    }
}
