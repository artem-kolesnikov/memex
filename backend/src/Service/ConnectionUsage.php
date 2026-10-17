<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * How each connection used memex, day by day: how often its assistant opened
 * memex, and which tools it called, each answered, refused or failed.
 *
 * Counts and tool names only, never an argument, a query or a result. They
 * tell an assistant that connects and never calls apart from one whose calls
 * memex refuses or fails, which is what the operator reads them for. Kept for
 * fourteen days, the span Privacy gives operational logs.
 *
 * The handshake a client runs when a chat starts (`initialize`, `tools/list`)
 * is counted but is not use: only a call stamps `api_tokens.last_called_at`.
 */
class ConnectionUsage
{
    public const KEEP_DAYS = 14;

    public const OK = 'ok';
    public const REFUSED = 'refused';
    public const FAILED = 'failed';

    public const OPENED = 'initialize';
    public const LISTED = 'tools/list';
    public const UNKNOWN_TOOL = '(unknown tool)';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function isHandshake(string $kind): bool
    {
        return $kind === self::OPENED || $kind === self::LISTED;
    }

    /** The oldest day still kept, today included in the count. */
    public static function firstDay(): string
    {
        return (new \DateTimeImmutable('-'.(self::KEEP_DAYS - 1).' days', new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    public function record(int $tokenId, string $kind, string $outcome): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            $this->connection->transactional(function (Connection $db) use ($tokenId, $kind, $outcome, $now): void {
                $db->executeStatement(
                    'INSERT INTO connection_usage (token_id, day, kind, calls, refused, failed)
                     VALUES (:token, :day, :kind, 1, :refused, :failed)
                     ON CONFLICT (token_id, day, kind) DO UPDATE SET
                        calls = calls + 1,
                        refused = refused + excluded.refused,
                        failed = failed + excluded.failed',
                    [
                        'token' => $tokenId,
                        'day' => $now->format('Y-m-d'),
                        'kind' => $kind,
                        'refused' => (int) ($outcome === self::REFUSED),
                        'failed' => (int) ($outcome === self::FAILED),
                    ],
                );
                if (!self::isHandshake($kind)) {
                    $db->executeStatement(
                        'UPDATE api_tokens SET last_called_at = :now WHERE id = :token',
                        ['now' => $now->format('Y-m-d H:i:s'), 'token' => $tokenId],
                    );
                }
                $db->executeStatement('DELETE FROM connection_usage WHERE day < :first', ['first' => self::firstDay()]);
            });
        } catch (\Throwable $e) {
            // A count is never worth failing the call it counts.
            $this->logger->warning('Connection usage was not recorded', ['kind' => $kind, 'exception' => $e]);
        }
    }

    /**
     * The kept days, summed per connection: how often it opened memex and how
     * often opening failed, and its calls by tool.
     *
     * @return array<int, array{sessions: int, sessions_failed: int, calls: int, refused: int, failed: int, tools: list<array{tool: string, calls: int, refused: int, failed: int}>}>
     */
    public function byConnection(): array
    {
        $out = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT token_id, kind, SUM(calls) AS calls, SUM(refused) AS refused, SUM(failed) AS failed
             FROM connection_usage WHERE day >= :first
             GROUP BY token_id, kind ORDER BY token_id, SUM(calls) DESC, kind',
            ['first' => self::firstDay()],
        );
        foreach ($rows as $row) {
            $token = (int) $row['token_id'];
            $out[$token] ??= self::empty() + ['tools' => []];
            [$calls, $refused, $failed] = [(int) $row['calls'], (int) $row['refused'], (int) $row['failed']];
            if ($row['kind'] === self::OPENED) {
                $out[$token]['sessions'] += $calls;
            }
            if (self::isHandshake((string) $row['kind'])) {
                $out[$token]['sessions_failed'] += $failed;
                continue;
            }
            $out[$token]['calls'] += $calls;
            $out[$token]['refused'] += $refused;
            $out[$token]['failed'] += $failed;
            $out[$token]['tools'][] = ['tool' => (string) $row['kind'], 'calls' => $calls, 'refused' => $refused, 'failed' => $failed];
        }

        return $out;
    }

    /** @return array{sessions: int, sessions_failed: int, calls: int, refused: int, failed: int} the whole vault over the kept days */
    public function totals(): array
    {
        $totals = self::empty();
        foreach ($this->byConnection() as $connection) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $connection[$key];
            }
        }

        return $totals;
    }

    /** @return array{sessions: int, sessions_failed: int, calls: int, refused: int, failed: int} */
    private static function empty(): array
    {
        return ['sessions' => 0, 'sessions_failed' => 0, 'calls' => 0, 'refused' => 0, 'failed' => 0];
    }
}
