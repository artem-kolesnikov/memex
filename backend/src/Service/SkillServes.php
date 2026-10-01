<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\SkillServe;
use Doctrine\ORM\EntityManagerInterface;

class SkillServes
{
    public const KEEP_DAYS = 90;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurationCharter $charter,
    ) {
    }

    /** The version a serve records: a hash of the exact text handed out. */
    public static function version(string $body): string
    {
        return substr(sha1($body), 0, 12);
    }

    public function record(array $skill, ?ApiToken $token, string $path): void
    {
        $preset = $skill['slug'] === CurationCharter::SLUG ? $this->charter->presetFor($token) : null;
        $this->em->persist(new SkillServe($skill['slug'], $token, $preset, $path, is_string($skill['body'] ?? null) ? self::version($skill['body']) : null));
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'DELETE FROM skill_serves WHERE served_at < :cutoff',
            ['cutoff' => self::daysAgo(self::KEEP_DAYS)],
        );
    }

    /**
     * The live connections that have loaded this text of a skill. Served is
     * not followed; this says only that the text was handed out.
     *
     * @return list<string> connection names
     */
    public function loadedBy(string $slug, string $body): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT k.name FROM skill_serves s
             JOIN api_tokens k ON k.id = s.token_id AND k.revoked_at IS NULL
             WHERE s.slug = :slug AND s.version = :version
             ORDER BY lower(k.name)',
            ['slug' => $slug, 'version' => self::version($body)],
        ));
    }

    /** @return array<string, array{total_30d:int, last_at:?string, last_token_id:?int, by_token:list<array{token_id:int, count:int, last_at:string}>}> */
    public function usage(): array
    {
        $out = [];
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT slug, token_id, COUNT(*) AS n, MAX(served_at) AS last_at, MAX(id) AS last_id
             FROM skill_serves
             WHERE served_at >= :since
             GROUP BY slug, token_id ORDER BY slug, last_id DESC',
            ['since' => self::daysAgo(30)],
        );
        foreach ($rows as $row) {
            $slug = $row['slug'];
            $out[$slug] ??= ['total_30d' => 0, 'last_at' => null, 'last_token_id' => null, 'by_token' => []];
            $out[$slug]['total_30d'] += (int) $row['n'];
            if ($row['token_id'] !== null) {
                $out[$slug]['by_token'][] = ['token_id' => (int) $row['token_id'], 'count' => (int) $row['n'], 'last_at' => $row['last_at']];
            }
        }

        // Last loaded is asked of every retained row (see KEEP_DAYS), not just the
        // 30-day window above: a skill can still be paused-and-forgotten rather
        // than never loaded, and the count above must stay a true 30-day count.
        $lastRows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT slug, token_id, served_at
             FROM skill_serves
             WHERE id IN (SELECT MAX(id) FROM skill_serves GROUP BY slug)
             ORDER BY slug',
        );
        foreach ($lastRows as $row) {
            $slug = $row['slug'];
            $out[$slug] ??= ['total_30d' => 0, 'last_at' => null, 'last_token_id' => null, 'by_token' => []];
            $out[$slug]['last_at'] = $row['served_at'];
            $out[$slug]['last_token_id'] = $row['token_id'] === null ? null : (int) $row['token_id'];
        }

        return $out;
    }

    private static function daysAgo(int $days): string
    {
        return (new \DateTimeImmutable('-'.$days.' days'))->format('Y-m-d H:i:s');
    }
}
