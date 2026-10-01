<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Directory\Account;
use App\Directory\Identity;
use App\Entity\ApiToken;
use App\Entity\Note;
use App\Entity\VaultSettings;
use App\Service\BearerTokens;
use App\Service\NoteWriter;
use App\Service\SocialProviders;
use App\Storage\BoundVault;
use App\Storage\VaultFiles;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;

/**
 * One tenant of {@see TwoTeamFixture}: an account in the directory, its vault,
 * an agent-role and a curator-role connection, and a way to write notes into
 * it.
 *
 * It holds ids and re-fetches on use. Every method that touches the vault
 * enters it first, so a tenant's helpers always act on its own vault.
 */
final class Tenant
{
    public readonly int $accountId;
    public readonly BoundVault $vault;
    public readonly int $agentTokenId;
    public readonly int $curatorTokenId;
    public readonly string $email;
    /** Plaintext bearer values — only the hash is stored, exactly as in production. */
    public readonly string $agentBearer;
    public readonly string $curatorBearer;
    /**
     * The authorization code that signs this owner in, the way a browser does
     * it: providers are the only door. Format is the stub's:
     * `subject~email~name`.
     */
    public readonly string $signInCode;

    public function __construct(
        private readonly ContainerInterface $container,
        public readonly string $label,
    ) {
        $suffix = mb_strtolower($label);
        $this->email = "owner-$suffix@example.test";

        $directory = $this->directory();
        $account = new Account($container->get(VaultFiles::class)->create(), $this->email, "Owner $label");
        $directory->persist($account);
        $subject = "fixture-google-$suffix";
        $directory->persist(new Identity($account, SocialProviders::GOOGLE, $subject, $this->email));
        $directory->flush();
        $this->signInCode = "$subject~{$this->email}~Owner $label";
        $this->accountId = (int) $account->getId();
        $this->vault = $account->vault();

        $this->enter();
        $this->agentBearer = "mxt_agent_$suffix";
        $this->agentTokenId = $this->connection("agent-$suffix", $this->agentBearer, ApiToken::ROLE_AGENT);
        $this->curatorBearer = "mxt_curator_$suffix";
        $this->curatorTokenId = $this->connection("curator-$suffix", $this->curatorBearer, ApiToken::ROLE_CURATOR);
    }

    /** Make this tenant's vault the one test code reads and writes. */
    public function enter(): void
    {
        Vaults::enter($this->container, $this->vault);
    }

    public function account(): Account
    {
        $account = $this->directory()->find(Account::class, $this->accountId);
        self::assertFound($account);

        return $account;
    }

    public function vaultPath(): string
    {
        return $this->container->get(\App\Storage\DataDir::class)->vaultPath($this->vault->key);
    }

    /** This vault's public address segment. */
    public function handle(): string
    {
        return $this->vault->handle;
    }

    public function settings(): VaultSettings
    {
        $this->enter();

        return $this->em()->getRepository(VaultSettings::class)->current();
    }

    public function agentToken(): ApiToken
    {
        return $this->token($this->agentTokenId);
    }

    public function curatorToken(): ApiToken
    {
        return $this->token($this->curatorTokenId);
    }

    /**
     * A verified note in this tenant, written through the real write path.
     *
     * @param string[] $tags
     */
    public function note(string $title, string $body = 'Body.', array $tags = [], ?string $summary = null): Note
    {
        $this->enter();

        return $this->container->get(NoteWriter::class)->create(
            null,
            $title,
            $body,
            Note::SOURCE_MANUAL,
            null,
            $tags,
            enrich: \App\Service\EmbeddingSpend::Metered,
            summary: $summary,
            applyTags: false,
        )['note'];
    }

    /** A further connection with a bearer the test chooses. Returns its id. */
    public function connection(string $name, string $bearer, string $role = ApiToken::ROLE_AGENT): int
    {
        $this->enter();
        $token = new ApiToken($name);
        $token->setRole($role);
        $this->em()->persist($token);
        $this->em()->flush();
        $this->container->get('doctrine.dbal.directory_connection')->insert('bearer_tokens', [
            'token_hash' => BearerTokens::hash($bearer),
            'account_id' => $this->accountId,
            'connection_id' => $token->getId(),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $token->getId();
    }

    private function token(int $id): ApiToken
    {
        $this->enter();
        $token = $this->em()->find(ApiToken::class, $id);
        self::assertFound($token);

        return $token;
    }

    private function em(): EntityManagerInterface
    {
        return $this->container->get(EntityManagerInterface::class);
    }

    private function directory(): EntityManagerInterface
    {
        return $this->container->get('doctrine.orm.directory_entity_manager');
    }

    private static function assertFound(?object $entity): void
    {
        if ($entity === null) {
            throw new \RuntimeException('Fixture row is gone.');
        }
    }
}
