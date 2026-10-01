<?php

declare(strict_types=1);

namespace App\Service;

use App\Directory\Account;
use App\Directory\Identity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What happens when somebody arrives holding a Google or GitHub account. Three outcomes, and the rules that separate them are the
 * whole of the sign-in policy.
 *
 * **1. The identity is known** — sign that account in. Nothing else is consulted,
 * least of all the email, which may have changed at the provider since.
 *
 * **2. The identity is unknown and the {@see AccountDoor} admits them** — the
 * {@see AccountOpener} creates the account with the identity.
 *
 * **3. The identity is unknown and the door turns them away** — no account.
 *
 * ## The rule that looks like a missing feature
 *
 * An unknown identity whose email matches an account that already exists is
 * **refused, not merged**. It is refused even though it is almost always the
 * same person, because "almost always" is the property an attacker works with:
 * anyone who can get a provider to report your address would inherit your
 * knowledge base. The person is told to sign in the way they already can, and
 * link the provider from Settings — where being signed in already is the proof
 * that no email comparison can give.
 *
 * This is also why {@see link()} exists at all, and why it is the documented
 * route rather than a convenience: a person's memex address, Google address and
 * Apple address can be three different addresses, and no matching rule was ever
 * going to connect them.
 */
class SocialAccounts
{
    public function __construct(
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly AccountOpener $opener,
        private readonly AccountDoor $door,
    ) {
    }

    public function identityFor(SocialIdentity $identity): ?Identity
    {
        return $this->directoryEntityManager->getRepository(Identity::class)->findOneBy([
            'provider' => $identity->provider,
            'subject' => $identity->subject,
        ]);
    }

    /** @return array<int, Identity> */
    public function identitiesOf(Account $account): array
    {
        return $this->directoryEntityManager->getRepository(Identity::class)->findBy(['account' => $account], ['createdAt' => 'ASC']);
    }

    /**
     * Outcome 1: an identity we have seen before. Stamps last-used and refreshes
     * the recorded address, so the Settings screen keeps telling the truth about
     * which Google account this is.
     */
    public function signIn(Identity $existing, SocialIdentity $identity): Account
    {
        $existing->touch($identity->email);
        $this->directoryEntityManager->flush();

        return $existing->getAccount();
    }

    /**
     * Outcomes 2 and 3: a new person, holding $ticket (the code from an invite
     * link) or not. The account and the identity are created together by the
     * {@see AccountOpener}, so an invite forwarded to a group chat admits the
     * first person and nobody else.
     *
     * @throws SocialAuthException when the provider gave us nothing to call the account, or the door is shut
     */
    public function createAccount(SocialIdentity $identity, ?string $ticket = null): Account
    {
        if ($identity->email === null) {
            throw new SocialAuthException(
                SocialProviders::label($identity->provider).' did not give us a verified email address, '
                .'so there is nothing to name the account with. Verify an address with them, or ask for a different way in.',
                'no_email'
            );
        }

        return $this->opener->open(
            $identity->email,
            $this->accountName($identity),
            $this->memexName($identity),
            $ticket,
            function (Account $account) use ($identity): void {
                if ($this->accountByEmail((string) $identity->email) !== null) {
                    throw new SocialAuthException(
                        'There is already a memex account for '.$identity->email.'. Sign in the way you normally do, '
                        .'then add '.SocialProviders::label($identity->provider).' from Settings so it works next time.',
                        'email_in_use'
                    );
                }
                $accountIdentity = new Identity($account, $identity->provider, $identity->subject, $identity->email);
                $accountIdentity->touch($identity->email);
                $this->directoryEntityManager->persist($accountIdentity);
            },
        );
    }

    /**
     * Attach a provider to the account already signed in. The one route by
     * which an existing account gains a new way in.
     *
     * Re-linking the same identity to the same user is a success and not an
     * error: somebody who clicks the button twice has got what they wanted.
     *
     * @throws SocialAuthException when that provider account belongs to someone else
     */
    public function link(Account $account, SocialIdentity $identity): Identity
    {
        $existing = $this->identityFor($identity);
        if ($existing !== null) {
            if ($existing->getAccount()->getId() === $account->getId()) {
                $existing->touch($identity->email);
                $this->directoryEntityManager->flush();

                return $existing;
            }

            throw new SocialAuthException(
                'That '.SocialProviders::label($identity->provider).' account is already the way in to a different memex account. '
                .'Sign out of it with '.SocialProviders::label($identity->provider).' first, or use another one.',
                'identity_taken'
            );
        }

        $accountIdentity = new Identity($account, $identity->provider, $identity->subject, $identity->email);
        $this->directoryEntityManager->persist($accountIdentity);
        $this->directoryEntityManager->flush();

        return $accountIdentity;
    }

    /**
     * Remove a way in. Refuses to remove the last one, because the result is an
     * account nobody can open, unless the edition lets the account in by a way
     * of its own: memex sends no mail to let anybody back in. The count and the
     * removal share one write transaction, so two removals at once cannot both
     * count two.
     *
     * @throws SocialAuthException
     */
    public function unlink(Account $account, Identity $identity): void
    {
        if ($identity->getAccount()->getId() !== $account->getId()) {
            throw new SocialAuthException('That sign-in method belongs to a different account.', 'not_yours');
        }

        $removed = $this->directoryEntityManager->wrapInTransaction(function () use ($account, $identity): bool {
            if (count($this->identitiesOf($account)) <= 1 && !$this->door->ownWayIn($account)) {
                return false;
            }
            $this->directoryEntityManager->remove($identity);

            return true;
        });
        if (!$removed) {
            throw new SocialAuthException(
                'That is the only way into this account. Add another one first, and then remove this.',
                'last_method'
            );
        }
    }

    private function accountByEmail(string $email): ?Account
    {
        return $this->directoryEntityManager->getRepository(Account::class)->findOneBy(['email' => $email]);
    }

    /** A new memex is named after its owner, so it reads like theirs. */
    private function memexName(SocialIdentity $identity): string
    {
        $name = trim((string) ($identity->name ?? ''));
        if ($name === '' && $identity->email !== null) {
            $name = explode('@', $identity->email)[0];
        }

        return mb_substr($name === '' ? 'My knowledge base' : $name, 0, 120);
    }

    private function accountName(SocialIdentity $identity): string
    {
        $name = trim((string) ($identity->name ?? ''));

        return mb_substr($name === '' ? (string) $identity->email : $name, 0, 120);
    }
}
