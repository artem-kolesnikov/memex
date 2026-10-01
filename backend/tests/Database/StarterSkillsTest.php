<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Directory\Account;
use App\Entity\Note;
use App\Service\ShippedText;
use App\Service\SocialAccounts;
use App\Service\SocialIdentity;
use App\Service\SocialProviders;
use App\Service\StarterSkills;
use App\Service\WelcomeNotes;
use App\Tests\Support\DoorHeldOpen;
use App\Tests\Support\Vaults;

/**
 * A new account opens on its notes list with three welcome notes on top and
 * two starter skills below them, switched on. The guide is not among them: it
 * is served as the `memex-guide` skill and never copied into a knowledge base.
 * Nor is a profile: the welcome notes say how to start one.
 */
final class StarterSkillsTest extends ApiTestCase
{
    private function openAccount(string $email): Account
    {
        $this->leave();

        return DoorHeldOpen::during(fn (): Account => self::getContainer()->get(SocialAccounts::class)->createAccount(
            new SocialIdentity(SocialProviders::GOOGLE, 'google-'.$email, $email, 'Starter'),
        ));
    }

    /** Sign in as a freshly opened account, through the fake provider's round trip like a browser. */
    private function signInAs(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/auth/google/start');
        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->client->request('GET', '/api/auth/google/callback?'.http_build_query([
            'code' => 'google-'.$email.'~'.$email.'~Starter',
            'state' => $params['state'],
        ]));
        $this->sessionRequest('GET', '/api/me');
        self::assertSame(200, $this->httpStatus(), 'the new account is signed in');
    }

    public function testANewAccountOpensOnTheWelcomeNotesAndTwoSkills(): void
    {
        $account = $this->openAccount('starters@example.test');
        Vaults::enter(self::getContainer(), $account->vault());

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT n.title, n.source, n.status, n.last_actor FROM notes n ORDER BY n.updated_at DESC, n.id DESC'
        );
        self::assertSame(
            ['Welcome to memex', 'Connect your first assistant', 'Make memex yours', 'Plain writing', 'Handoff'],
            array_column($rows, 'title'),
            'the list, newest change first, opens on the first welcome note, and holds no guide and no profile'
        );
        self::assertSame(
            [Note::SOURCE_MEMEX, Note::SOURCE_MEMEX, Note::SOURCE_MEMEX, Note::SOURCE_MANUAL, Note::SOURCE_MANUAL],
            array_column($rows, 'source'),
            'memex wrote the welcome notes; the skills are the owner\'s, as Add would have made them'
        );
        self::assertSame([Note::STATUS_VERIFIED], array_values(array_unique(array_column($rows, 'status'))));
        self::assertSame([Note::ACTOR_MEMEX], array_values(array_unique(array_column(array_slice($rows, 0, 3), 'last_actor'))),
            'the byline on a welcome note says memex, not the person');
    }

    public function testTheWelcomeNotesLinkIntoThisAccountAndToEachOther(): void
    {
        $account = $this->openAccount('links@example.test');
        Vaults::enter(self::getContainer(), $account->vault());
        $conn = $this->em->getConnection();

        $bodies = $conn->fetchFirstColumn('SELECT body_md FROM notes WHERE source = :memex', ['memex' => Note::SOURCE_MEMEX]);
        self::assertCount(3, $bodies);
        $all = implode("\n", $bodies);
        self::assertStringNotContainsString(WelcomeNotes::BASE, $all);
        self::assertStringNotContainsString(ShippedText::ORIGIN, $all);
        self::assertStringContainsString('`http://localhost/mcp`', $all, 'the note gives this server\'s own address');
        self::assertStringContainsString('(/'.$account->getHandle().'/inbox)', $all);
        self::assertStringContainsString('(/'.$account->getHandle().'/settings/connections)', $all);

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM note_links WHERE to_note_id IS NULL'),
            'every wiki-link in the welcome notes and the skills finds its note, whichever was written first');
        self::assertGreaterThan(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM note_links'));
    }

    public function testTheStarterSkillsAreServedAndTheCatalogueKnowsThem(): void
    {
        $this->openAccount('catalogue@example.test');
        $this->signInAs('catalogue@example.test');

        $this->sessionRequest('GET', '/api/skills');
        $byslug = array_column($this->jsonResponse()['skills'], null, 'slug');
        foreach (StarterSkills::SEEDED as $slug) {
            self::assertSame('served', $byslug[$slug]['status'], $slug.' arrives switched on');
            self::assertNotNull($byslug[$slug]['note_id'], $slug.' is a note the owner holds');
        }
        self::assertArrayNotHasKey('what-my-assistants-should-know-about-me', $byslug, 'memex ships no profile skill');

        $this->sessionRequest('POST', '/api/skills/handoff/add');
        self::assertSame(409, $this->httpStatus(), 'a taken entry is already here');
    }

    public function testSeedingTwiceAddsNothing(): void
    {
        $account = $this->openAccount('twice@example.test');
        Vaults::enter(self::getContainer(), $account->vault());
        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notes');

        self::assertSame([], self::getContainer()->get(StarterSkills::class)->seed());

        $after = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notes');
        self::assertSame($before, $after);
    }

    public function testAnAssistantOfTheNewAccountCanLoadAStarterSkill(): void
    {
        $this->openAccount('mcp@example.test');
        $this->signInAs('mcp@example.test');
        $this->sessionRequest('POST', '/api/tokens', ['name' => 'starter-agent']);
        self::assertSame(201, $this->httpStatus());
        $bearer = $this->jsonResponse()['token'];

        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_skill', 'arguments' => ['slug' => 'handoff']],
        ]);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Pick up', (string) json_encode($this->jsonResponse()));
    }
}
