<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Directory\Account;
use App\Tests\Support\Tenant;

/**
 * Renaming yourself, and renaming your knowledge base.
 *
 * Without it, a new person joining with a Google profile reading
 * "jane d" would carry that forever.
 *
 * The properties worth asserting are the boundaries, not the happy path: an
 * empty name would blank the nav, a token-authenticated rename would let a
 * leaked token disguise itself, and another tenant must be untouched.
 */
class AccountNameTest extends ApiTestCase
{
    private function nameOf(Tenant $tenant): string
    {
        return $this->account($tenant)->getName();
    }

    public function testAPersonCanRenameThemselves(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me', ['name' => 'Jane Doe']);

        self::assertSame(200, $this->httpStatus());
        // The response is the fresh /api/me, so the SPA needs no second call.
        self::assertSame('Jane Doe', $this->jsonResponse()['name']);
        self::assertSame('Jane Doe', $this->nameOf($this->kb->a));
    }

    public function testTheNameIsTrimmedAndInnerWhitespaceCollapsed(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me', ['name' => "  Jane   D  \n"]);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('Jane D', $this->jsonResponse()['name']);
    }

    public function testANameOfOnlyWhitespaceIsRefused(): void
    {
        $this->loginAs($this->kb->a);
        $before = $this->nameOf($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me', ['name' => '   ']);

        self::assertSame(400, $this->httpStatus());
        self::assertSame($before, $this->nameOf($this->kb->a), 'A blank name would empty the nav');
    }

    public function testANameLongerThanTheColumnIsRefusedByUsFirst(): void
    {
        $this->loginAs($this->kb->a);
        $before = $this->nameOf($this->kb->a);

        // 121 characters against a varchar(120): without the check this is a
        // database error surfacing as a 500 rather than a message.
        $this->sessionRequest('PATCH', '/api/me', ['name' => str_repeat('a', 121)]);

        self::assertSame(400, $this->httpStatus());
        self::assertSame($before, $this->nameOf($this->kb->a));
    }

    public function testAtTheColumnLimitItIsAccepted(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me', ['name' => str_repeat('a', 120)]);

        self::assertSame(200, $this->httpStatus());
    }

    public function testABearerTokenCannotRenameTheAccount(): void
    {
        $before = $this->nameOf($this->kb->a);

        // The account's own curator token, which is the strongest one there is.
        // A leaked token changing the name at the top of every screen is the
        // cheapest cover for a token that should not be there.
        $this->request('PATCH', '/api/me', $this->kb->a->curatorBearer, ['name' => 'Not You']);

        self::assertSame(403, $this->httpStatus());
        self::assertSame($before, $this->nameOf($this->kb->a));
    }

    public function testARequestThatNamesNothingIsRefusedRatherThanBlanking(): void
    {
        $this->loginAs($this->kb->a);
        $before = $this->nameOf($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me', ['nickname' => 'oops']);

        self::assertSame(400, $this->httpStatus());
        self::assertSame($before, $this->nameOf($this->kb->a));
    }

    public function testRenamingOneTenantLeavesTheOtherAlone(): void
    {
        $bBefore = $this->nameOf($this->kb->b);
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me', ['name' => 'Renamed A']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame($bBefore, $this->nameOf($this->kb->b));
    }

    public function testAKnowledgeBaseCanBeRenamed(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/memex', ['name' => '  Acme   Research  ']);

        self::assertSame(200, $this->httpStatus());
        // Trimmed and collapsed like the person's name, for the same reason:
        // it is drawn in a header and quoted to every connected assistant.
        self::assertSame('Acme Research', $this->jsonResponse()['team']['name']);
        self::assertSame('Acme Research', $this->memexNameOf($this->kb->a));
    }

    public function testRenamingTheMemexDoesNotRenameThePerson(): void
    {
        $before = $this->nameOf($this->kb->a);
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/memex', ['name' => 'Something Else']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame($before, $this->nameOf($this->kb->a), 'Two names, two fields');
    }

    public function testAnEmptyMemexNameIsRefused(): void
    {
        $before = $this->memexNameOf($this->kb->a);
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/memex', ['name' => "  \t "]);

        self::assertSame(400, $this->httpStatus());
        self::assertSame($before, $this->memexNameOf($this->kb->a));
    }

    public function testAMemexNameLongerThanTheColumnIsRefusedByUsFirst(): void
    {
        $before = $this->memexNameOf($this->kb->a);
        $this->loginAs($this->kb->a);

        // 121 characters against a varchar(120): without the check this is a
        // driver exception and a 500 rather than a sentence somebody can act on.
        $this->sessionRequest('PATCH', '/api/me/memex', ['name' => str_repeat('n', 121)]);

        self::assertSame(400, $this->httpStatus());
        self::assertSame($before, $this->memexNameOf($this->kb->a));
    }

    public function testABearerTokenCannotRenameTheKnowledgeBase(): void
    {
        // The name is what every connected assistant is told it is writing to,
        // so an assistant that could change it could disguise where a note went.
        $before = $this->memexNameOf($this->kb->a);

        $this->request('PATCH', '/api/me/memex', $this->kb->a->curatorBearer, ['name' => 'Renamed by a token']);

        self::assertSame(403, $this->httpStatus());
        self::assertSame($before, $this->memexNameOf($this->kb->a));
    }

    public function testRenamingOneKnowledgeBaseLeavesTheOtherAlone(): void
    {
        $bBefore = $this->memexNameOf($this->kb->b);
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/memex', ['name' => 'Renamed A']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame($bBefore, $this->memexNameOf($this->kb->b));
    }

    private function memexNameOf(Tenant $tenant): string
    {
        return $this->account($tenant)->getMemexName();
    }

    private function account(Tenant $tenant): Account
    {
        $directory = self::getContainer()->get('doctrine.orm.directory_entity_manager');
        $directory->clear();

        return $directory->getRepository(Account::class)->find($tenant->accountId);
    }
}
