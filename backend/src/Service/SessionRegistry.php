<?php

declare(strict_types=1);

namespace App\Service;

use App\Directory\Account;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Every browser signed in to an account: what it is, where from, when last —
 * and the one place that decides whether a session is still a session.
 *
 * ## The rule the whole feature rests on
 *
 * **A session is only a session while it has a row here.** Ending one deletes
 * the row, and every authenticated request checks, so the browser that was
 * ended is refused on its very next request. There is no cache to expire and
 * no token to wait out.
 *
 * That is the inverse of `deleted_notes`, for a reason worth naming: a note
 * has to be MOVED out of `notes` because twenty-six raw-SQL sites read that
 * table and would each need to remember a condition. Authentication is not
 * twenty-six places. It is one, so a refusal there is the whole enforcement.
 *
 * ## Why the key is ours and not PHP's
 *
 * Symfony migrates the PHP session id when somebody signs in, to defeat
 * session fixation. Session ATTRIBUTES survive that migration, so the identity
 * of a session is an attribute we generate ({@see open()}) rather than the id,
 * which changes underneath us at the one moment we most care about. The PHP id
 * is recorded alongside, kept current, and used only to destroy the data when
 * a session is ended — so "ended" means gone rather than merely refused.
 *
 * ## What a touch costs
 *
 * One indexed SELECT per authenticated browser request, and an UPDATE at most
 * every {@see TOUCH_THROTTLE} seconds — or immediately when something worth
 * recording changed, like the address or a migrated session id. Nothing here
 * goes through the ORM: a raw statement on the authentication path cannot
 * flush somebody's half-built entity as a side effect of measuring them.
 */
final class SessionRegistry
{
    /**
     * Seconds between last-seen writes for one session. The list shows dates,
     * so minute-level truth costs a write per request and buys nothing.
     */
    public const TOUCH_THROTTLE = 900;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $db,
        /** Idle lifetime in seconds; the same number framework.yaml gives the handler. */
        private readonly int $lifetime,
    ) {
    }

    /**
     * Record a new signed-in browser and return the key that identifies it.
     * The caller puts the key in the session; nothing else can find the row.
     */
    public function open(Account $account, Request $request): string
    {
        $key = bin2hex(random_bytes(32));
        $now = new \DateTimeImmutable();
        $ip = self::ip($request);

        $this->db->executeStatement(
            'INSERT INTO user_sessions
                (ref, account_id, session_key, php_session_id, created_at, last_seen_at, expires_at, created_ip, last_ip, user_agent)
             VALUES (:ref, :uid, :key, :sid, :now, :now, :expires, :ip, :ip, :ua)',
            [
                'ref' => bin2hex(random_bytes(8)),
                'uid' => $account->getId(),
                'key' => $key,
                'sid' => self::sessionId($request),
                'now' => self::stamp($now),
                'expires' => self::stamp($now->modify('+'.$this->lifetime.' seconds')),
                'ip' => $ip,
                'ua' => BrowserName::store($request->headers->get('User-Agent')),
            ]
        );

        return $key;
    }

    /**
     * Is this still a live session, and record that it was used.
     *
     * False means the browser holding it must be signed out: the row was ended
     * from the Settings screen, or it idled past its lifetime. An expired row
     * is deleted on the way past rather than left for the sweep, so the list
     * cannot show a session that has just been refused.
     */
    public function verify(string $key, int $accountId, Request $request): bool
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, last_seen_at, expires_at, php_session_id, last_ip
               FROM user_sessions WHERE session_key = :key AND account_id = :uid',
            ['key' => $key, 'uid' => $accountId]
        );
        if ($row === false) {
            return false;
        }

        $now = new \DateTimeImmutable();
        if (new \DateTimeImmutable((string) $row['expires_at']) <= $now) {
            $this->db->executeStatement('DELETE FROM user_sessions WHERE id = :id', ['id' => $row['id']]);

            return false;
        }

        $ip = self::ip($request);
        $sid = self::sessionId($request);
        $stale = new \DateTimeImmutable((string) $row['last_seen_at']) < $now->modify('-'.self::TOUCH_THROTTLE.' seconds');
        // A changed address or a migrated session id is worth writing at once:
        // the first is what somebody reads this screen to notice, and the
        // second is what lets us destroy the data when the session is ended.
        if ($stale || $row['last_ip'] !== $ip || $row['php_session_id'] !== $sid) {
            $this->db->executeStatement(
                'UPDATE user_sessions
                    SET last_seen_at = :now, expires_at = :expires, last_ip = :ip, php_session_id = :sid
                  WHERE id = :id',
                [
                    'now' => self::stamp($now),
                    'expires' => self::stamp($now->modify('+'.$this->lifetime.' seconds')),
                    'ip' => $ip,
                    'sid' => $sid,
                    'id' => $row['id'],
                ]
            );
        }

        return true;
    }

    /**
     * This person's live sessions, newest use first, with the one asking
     * marked.
     *
     * Expired rows are cleared first, so the screen never offers to end a
     * session that has already ended — the daily sweep is a tidy-up, not the
     * thing that makes this list true.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFor(int $accountId, ?string $currentKey): array
    {
        $this->sweep();

        $rows = $this->db->fetchAllAssociative(
            'SELECT ref, session_key, created_at, last_seen_at, expires_at, created_ip, last_ip, user_agent
               FROM user_sessions WHERE account_id = :uid ORDER BY last_seen_at DESC',
            ['uid' => $accountId]
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['ref'],
            // Which row is the browser reading this. Nothing else distinguishes
            // it, and ending your own session from a list is a different act
            // from signing out.
            'current' => $currentKey !== null && hash_equals((string) $row['session_key'], $currentKey),
            'browser' => BrowserName::describe($row['user_agent'] === null ? null : (string) $row['user_agent']),
            'ip' => $row['last_ip'] ?? $row['created_ip'],
            'created_at' => self::iso($row['created_at']),
            'last_seen_at' => self::iso($row['last_seen_at']),
            'expires_at' => self::iso($row['expires_at']),
        ], $rows);
    }

    /**
     * End one session. Scoped to the owner, so an id from another account is
     * indistinguishable from one that does not exist.
     */
    public function end(string $ref, int $accountId): bool
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, php_session_id FROM user_sessions WHERE ref = :ref AND account_id = :uid',
            ['ref' => $ref, 'uid' => $accountId]
        );
        if ($row === false) {
            return false;
        }

        $this->db->executeStatement('DELETE FROM user_sessions WHERE id = :id', ['id' => $row['id']]);
        $this->destroyData($row['php_session_id'] === null ? null : (string) $row['php_session_id']);

        return true;
    }

    /** Sign out every other browser, leaving the one asking signed in. */
    public function endOthers(int $accountId, string $exceptKey): int
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, php_session_id FROM user_sessions WHERE account_id = :uid AND session_key <> :key',
            ['uid' => $accountId, 'key' => $exceptKey]
        );
        if ($rows === []) {
            return 0;
        }

        $this->db->executeStatement(
            'DELETE FROM user_sessions WHERE account_id = :uid AND session_key <> :key',
            ['uid' => $accountId, 'key' => $exceptKey]
        );
        foreach ($rows as $row) {
            $this->destroyData($row['php_session_id'] === null ? null : (string) $row['php_session_id']);
        }

        return count($rows);
    }

    /**
     * Sign out every browser, sparing none.
     *
     * Its own method rather than {@see endOthers()} with a key that matches
     * nothing. That works today — a key is always 64 hex characters from
     * `random_bytes`, so the empty string can never name a row — but it is an
     * invariant held in one place and relied on in another, and the failure if
     * it ever stopped holding is one session quietly surviving an ending
     * that was meant to end all of them. A predicate with no sentinel in it cannot be wrong that way.
     */
    public function endAll(int $accountId): int
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, php_session_id FROM user_sessions WHERE account_id = :uid',
            ['uid' => $accountId]
        );
        if ($rows === []) {
            return 0;
        }

        $this->db->executeStatement('DELETE FROM user_sessions WHERE account_id = :uid', ['uid' => $accountId]);
        foreach ($rows as $row) {
            $this->destroyData($row['php_session_id'] === null ? null : (string) $row['php_session_id']);
        }

        return count($rows);
    }

    /** Signing out normally: the row goes with the session it described. */
    public function endByKey(string $key): void
    {
        $this->db->executeStatement('DELETE FROM user_sessions WHERE session_key = :key', ['key' => $key]);
    }

    /** Rows whose session has idled out. Cheap, indexed, and usually nothing. */
    public function sweep(): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM user_sessions WHERE expires_at <= :now',
            ['now' => self::stamp(new \DateTimeImmutable())]
        );
    }

    /**
     * Destroy the session data itself.
     *
     * Refusing the session is what makes ending it immediate; this is what
     * makes it honest. Wrapped because the row may already be gone — the
     * handler's own garbage collector reaches the same rows — and failing to
     * delete data that is unreachable anyway must never turn into a 500 on a
     * button somebody pressed for safety.
     */
    private function destroyData(?string $phpSessionId): void
    {
        if ($phpSessionId === null || $phpSessionId === '') {
            return;
        }
        $this->db->executeStatement('DELETE FROM sessions WHERE sess_id = :sid', ['sid' => $phpSessionId]);
    }

    /** IPv6 is 45 characters at its longest, which is what the column holds. */
    private static function ip(Request $request): ?string
    {
        $ip = $request->getClientIp();

        return $ip === null || $ip === '' ? null : mb_substr($ip, 0, 45);
    }

    private static function sessionId(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }
        $id = $request->getSession()->getId();

        return $id === '' ? null : $id;
    }

    private static function stamp(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }

    private static function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return (new \DateTimeImmutable($value))->format(DATE_ATOM);
    }
}
