<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\BearerTokens;
use App\Tests\Support\KbFixture;
use Doctrine\DBAL\Exception;

/**
 * A connection is two rows in two files: the connection in the vault and its
 * route in the directory. One without the other is a connection nobody can
 * use, listed in Settings as if somebody could.
 */
final class BearerTokensTest extends DatabaseTestCase
{
    public function testAConnectionWhoseRouteCannotBeWrittenIsNotKept(): void
    {
        $kb = new KbFixture(self::getContainer());
        $before = (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM api_tokens');
        $directory = self::getContainer()->get('doctrine.dbal.directory_connection');
        $directory->executeStatement("CREATE TEMP TRIGGER refuse_route BEFORE INSERT ON bearer_tokens BEGIN SELECT RAISE(ABORT, 'route refused'); END");
        try {
            self::getContainer()->get(BearerTokens::class)->issue($kb->account, 'Half written');
            self::fail('The route was refused, so nothing may be issued');
        } catch (Exception $refused) {
            self::assertStringContainsString('route refused', $refused->getMessage());
        } finally {
            $directory->executeStatement('DROP TRIGGER temp.refuse_route');
        }

        self::assertSame($before, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM api_tokens'));
    }
}
