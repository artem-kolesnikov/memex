<?php

declare(strict_types=1);

namespace App\Service;

use App\Directory\Account;
use Doctrine\DBAL\Connection;

/**
 * The edition's say over accounts arriving, signing in and leaving. The core
 * creates, signs in and deletes accounts; the door decides who may and hears
 * about each. {@see OpenDoor} turns nobody away.
 */
interface AccountDoor
{
    /**
     * Whether somebody new, holding $ticket (the code from an invite link, or
     * null), may have an account. Asked before any vault is made.
     */
    public function newcomerRefusal(?string $ticket): ?SocialAuthException;

    /**
     * The last word on somebody new, inside the directory transaction that
     * creates $account, after it is persisted and before the flush.
     *
     * @throws SocialAuthException to turn them away
     */
    public function admit(Account $account, ?string $ticket): void;

    /** The account exists, its vault is seeded and its owner is signed in. */
    public function opened(Account $account, string $provider, ?string $ticket, ?string $clientIp): void;

    /** Whether an existing account may sign in, or let an assistant in, now. */
    public function accountRefusal(Account $account): ?SocialAuthException;

    /**
     * Whether the edition lets this account in by a way of its own, beside the
     * providers linked to it, so its last provider may be removed.
     */
    public function ownWayIn(Account $account): bool;

    /** Inside the directory transaction that deletes an account, before its row goes. */
    public function closing(Connection $directory, int $accountId): void;
}
