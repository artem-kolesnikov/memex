<?php

declare(strict_types=1);

namespace App\Service;

use App\Storage\VaultContext;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Which notes a curation pass has already been handed, so a second pass running
 * beside it gets different ones (operator 2026-08-26: several
 * delegated curators may run concurrently and memex must have a mechanism).
 *
 * ## The problem this solves, and the one it does not
 *
 * The queue's cooldown is derived from `curator_log`, and a pass writes its
 * `examined` rows at the END of the run. So between two curators starting an
 * hour apart there is nothing at all to tell the second that the first is
 * already three notes into the same top-of-queue: both are handed the same
 * ranked list and do the same work twice. That is the WASTE half of C-9, and
 * this class is all of it.
 *
 * The other half is silent overwrite, and it is not here on purpose. A lease is
 * advisory — it decides who is OFFERED a note, never who may write to one —
 * because a lease that could block a write would be a lock, and a lock that
 * lives in a cache is a lock that a cleared cache turns into data loss. What
 * stops two curators overwriting each other is {@see NoteWriter::propose()}
 * refusing to auto-apply a full body from a curator connection: an anchored
 * patch fails loudly when the text it names has moved, and that check is in the
 * write path where it belongs.
 *
 * ## Why a cache and not a table
 *
 * Everything here is worthless in half an hour by construction, so the storage
 * that forgets on its own is the honest one. A deploy clearing the pool costs
 * exactly one duplicated pass, which is the same cost as a pass that crashed —
 * and both are cheaper than a migration and a sweeper for rows nobody would
 * ever read twice.
 *
 * ## Two limits, both found in review and both deliberate
 *
 * **The read-modify-write is serialised per vault** by the same flock store the
 * spend limiters use. Without it the race was worse than "two curators claim
 * one note": every mutation reads the whole map and writes it back, so a
 * release landing after a concurrent claim silently discarded that claim, and
 * the reverse order resurrected a batch that had just been released (codex,
 * 2026-08-26). Nothing there could corrupt a note or outlive the absolute
 * `until` on each entry — but a mechanism that fails precisely when two
 * curators run is a mechanism that fails when it is needed.
 *
 * **A lease belongs to a CONNECTION, not to a run.** Two processes sharing one
 * curator token are indistinguishable here: they are handed each other's work
 * and either may release the other's claim. That matches how the operator
 * delegates — one connection per assistant — and the alternative is a run id in
 * the tool schema, which is API surface bought for a case nobody has. Worth
 * revisiting the day somebody genuinely runs one token twice at once.
 */
class CurationLeases
{
    /**
     * How long a handed-out note stays claimed.
     *
     * The charter's budget is 15–25 notes a run, and a pass working at a
     * conversational pace takes tens of minutes. Half an hour is long enough
     * that a live pass keeps its work and short enough that a pass which died
     * on its third note does not hold the other twenty-two hostage until
     * somebody notices.
     */
    public const TTL_SECONDS = 1800;

    /**
     * A pass that asked for more than this is not a pass, and its claim would
     * empty the queue for everybody else. The queue's own `limit` caps at 100;
     * this is the same number, stated where the claiming happens so that a
     * future change to one is visible against the other.
     */
    private const MAX_CLAIM = 100;

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly LockFactory $lockFactory,
        private readonly VaultContext $vault,
    ) {
    }

    /**
     * Notes another connection is already working, so this run should not be
     * offered them.
     *
     * Its own claims are deliberately NOT in the answer: a pass that calls the
     * queue twice — because it was interrupted, or because it works in
     * batches — must be handed its own work again rather than watch the queue
     * go empty in front of it.
     *
     * @return int[] note ids, in no particular order
     */
    public function heldElsewhere(?int $tokenId): array
    {
        $held = [];
        foreach ($this->live($this->vault->current()->key) as $noteId => $lease) {
            if ($lease['token'] !== $tokenId) {
                $held[] = $noteId;
            }
        }

        return $held;
    }

    /**
     * Claim these notes for this connection, replacing whatever it held before.
     *
     * Replacing rather than adding is what makes a lease follow the RUN: the
     * queue call that starts a pass's next batch releases the batch before it,
     * so a curator working steadily never accumulates a claim over the whole
     * knowledge base.
     *
     * A null token is memex's own enrichment pass or a browser session. Neither
     * is a curation run, and neither claims anything.
     *
     * @param int[] $noteIds
     */
    public function claim(?int $tokenId, array $noteIds): void
    {
        if ($tokenId === null) {
            return;
        }

        $vault = $this->vault->current()->key;
        $lock = $this->lock($vault);
        $leases = $this->live($vault);
        foreach ($leases as $noteId => $lease) {
            if ($lease['token'] === $tokenId) {
                unset($leases[$noteId]);
            }
        }

        $until = time() + self::TTL_SECONDS;
        foreach (array_slice(array_values(array_unique($noteIds)), 0, self::MAX_CLAIM) as $noteId) {
            $leases[(int) $noteId] = ['token' => $tokenId, 'until' => $until];
        }

        $this->save($vault, $leases);
        $lock->release();
    }

    /**
     * Everything a connection holds, released. Called when a run RECORDS itself
     * — the `examined` rows are the durable version of the same statement, and
     * they carry a cooldown of their own, so keeping the lease as well would
     * hold notes the log already protects.
     */
    public function release(?int $tokenId): void
    {
        if ($tokenId === null) {
            return;
        }

        $vault = $this->vault->current()->key;
        $lock = $this->lock($vault);
        $leases = $this->live($vault);
        $before = count($leases);
        foreach ($leases as $noteId => $lease) {
            if ($lease['token'] === $tokenId) {
                unset($leases[$noteId]);
            }
        }
        if (count($leases) !== $before) {
            $this->save($vault, $leases);
        }
        $lock->release();
    }

    /**
     * The read-modify-write around one vault's map, serialised.
     *
     * Short-lived and auto-releasing: a worker killed mid-claim leaves a lock
     * that expires in seconds, and the worst a lost lock can do here is the
     * race it was taken to prevent. `acquire(true)` blocks, because the
     * alternative — giving up and skipping the claim — is exactly the
     * duplicated pass this class exists to stop.
     */
    private function lock(string $vault): \Symfony\Component\Lock\LockInterface
    {
        $lock = $this->lockFactory->createLock('curation-leases-'.$vault, 5.0);
        $lock->acquire(true);

        return $lock;
    }

    /**
     * One vault's leases with the expired ones dropped.
     *
     * Pruning on read rather than on a schedule: the only thing that ever asks
     * is a curation call, and an entry nobody asks about costs nothing but the
     * bytes it sits in until the pool item expires under it.
     *
     * @return array<int, array{token: int, until: int}>
     */
    private function live(string $vault): array
    {
        /** @var array<int, array{token: int, until: int}> $stored */
        $stored = $this->pool->getItem($this->key($vault))->get() ?? [];
        $now = time();

        return array_filter($stored, static fn (array $lease): bool => $lease['until'] > $now);
    }

    /** @param array<int, array{token: int, until: int}> $leases */
    private function save(string $vault, array $leases): void
    {
        $item = $this->pool->getItem($this->key($vault));
        $item->set($leases);
        // The item outlives the longest lease inside it, so the pool never
        // expires a live claim; `live()` is what decides what is still true.
        $item->expiresAfter(self::TTL_SECONDS * 2);
        $this->pool->save($item);
    }

    private function key(string $vault): string
    {
        return 'curation_leases.'.$vault;
    }
}
