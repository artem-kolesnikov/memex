<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * How large the bound vault may grow: its notes, held drafts included, and
 * its live connections, within the edition's {@see AccountLimits::room()}.
 *
 * Key-blind on purpose. The spend ceilings lift for an account on its own
 * key because they bound the server's bill; these bound its disk, which an
 * own key does not pay for. An account over a lowered limit
 * keeps everything it has: reading, editing, deleting and export all work,
 * and only a new note or a new connection is refused.
 */
final class GrowthLimits
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountLimits $limits,
    ) {
    }

    /** @return array{notes: ?int, connections: ?int} null where no ceiling applies */
    public function current(): array
    {
        return $this->limits->room();
    }

    /**
     * A pending note is a held draft of a new one, and it is a row in `notes`
     * already, so counting the table counts both; approving a draft never
     * needs room it does not already have.
     */
    public function notes(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notes');
    }

    public function connections(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM api_tokens WHERE revoked_at IS NULL');
    }

    /** @throws StorageLimitExceeded */
    public function assertNoteRoom(int $adding = 1): void
    {
        $limit = $this->current()['notes'];
        if ($limit === null) {
            return;
        }
        $held = $this->notes();
        if ($held + $adding <= $limit) {
            return;
        }

        $this->limits->roomReached('notes', $limit);
        $action = 'Delete notes, or reject drafts waiting for review, to make room.';
        throw new StorageLimitExceeded('notes', $held + $adding, $limit, $action, $adding === 1
            ? sprintf('This memex has reached its limit of %d notes, drafts waiting for review included. %s', $limit, $action)
            : sprintf('This would add %d notes, and this memex has room for %d more within its limit of %d, drafts waiting for review included. %s', $adding, max(0, $limit - $held), $limit, $action));
    }

    /** @throws StorageLimitExceeded */
    public function assertConnectionRoom(): void
    {
        $refusal = $this->connectionRefusal(alert: true);
        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /**
     * The refusal a new connection would meet, for a page that says so before
     * anyone presses a button. `alert` when it is an attempt being refused
     * rather than a question asked.
     */
    public function connectionRefusal(bool $alert = false): ?StorageLimitExceeded
    {
        $limit = $this->current()['connections'];
        if ($limit === null) {
            return null;
        }
        $live = $this->connections();
        if ($live < $limit) {
            return null;
        }

        if ($alert) {
            $this->limits->roomReached('connections', $limit);
        }
        $action = 'Remove one you no longer use in Settings › Assistants, then connect again.';

        return new StorageLimitExceeded('connections', $live + 1, $limit, $action, sprintf('This memex has reached its limit of %d connections. %s', $limit, $action));
    }
}
