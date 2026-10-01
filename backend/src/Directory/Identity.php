<?php

declare(strict_types=1);

namespace App\Directory;

use Doctrine\ORM\Mapping as ORM;

/**
 * One way of proving you are a particular user: a Google account, a GitHub
 * account, and later an Apple or Microsoft one.
 *
 * **An identity is not an email address.** One person's memex account can be
 * a work address, their Google account a gmail address and their Apple one
 * an icloud address, and no rule about matching addresses would ever let
 * them sign in. That is the ordinary case: GitHub addresses change and
 * can be private, and Apple hands out per-app relay addresses by design. So an
 * identity is attached DELIBERATELY, from a signed-in session on the Settings
 * screen, and the provider's email is stored as a record of what it said rather
 * than as the thing we match on.
 *
 * Matching on email would also be the takeover: whoever controls an account at
 * a provider that reports your address would inherit your knowledge base.
 *
 * The unique constraint on `(provider, subject)` is the safety property: one
 * Google account can sign in as exactly one memex account, so linking an identity
 * that another account already holds is refused rather than silently moved.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identities')]
#[ORM\Index(name: 'idx_identities_account', columns: ['account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_identity_provider_subject', columns: ['provider', 'subject'])]
class Identity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** How the owner's screens name this identity: random, never the row id. */
    #[ORM\Column(length: 16, unique: true)]
    private string $ref;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    /** `google`, `github` or `microsoft` — App\Service\SocialProviders holds the list. */
    #[ORM\Column(length: 32)]
    private string $provider;

    /**
     * The provider's own immutable id for the account (Google's `sub`, GitHub's
     * numeric `id`, Microsoft's `tid` and `oid` joined by a dot). Never the
     * email, which changes.
     */
    #[ORM\Column(length: 191)]
    private string $subject;

    /**
     * What the provider said the address was when the identity was attached.
     * Shown on the Settings screen so a person can tell two Google accounts
     * apart. Nothing authenticates against it.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(Account $account, string $provider, string $subject, ?string $email = null)
    {
        $this->ref = bin2hex(random_bytes(8));
        $this->account = $account;
        $this->provider = $provider;
        $this->subject = $subject;
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /**
     * Stamped on every successful sign-in through this identity. It is what
     * makes "you have not used GitHub since March" answerable before someone
     * removes a sign-in method they think is dead.
     */
    public function touch(?string $email = null): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
        if ($email !== null && $email !== '') {
            $this->email = $email;
        }
    }
}
