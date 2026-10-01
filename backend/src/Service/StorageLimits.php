<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The two size limits the operator sets from control: how large one note body
 * may be, and how much a ZIP import may expand to. Rows, not constants, for
 * the same reason `spend_limits` is: raising one is not a deploy.
 *
 * The policy is a directory row. The vault's body triggers are the
 * enforcement, asking {@see bodyOverLimit()} through a function every vault
 * connection registers; {@see assertBody()} is the early check that refuses
 * before any tag, link or provider work is done for a body that cannot be saved.
 */
final class StorageLimits implements ResetInterface
{
    public const CEILINGS = ['max_note_bytes' => 8388608, 'max_import_bytes' => 1073741824];

    /** @var array{max_note_bytes: int, max_import_bytes: int}|null */
    private ?array $current = null;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $connection,
    ) {
    }

    /**
     * Whether a body of this many bytes is over the note limit; when it is,
     * the numbers are kept for the refusal the trigger is about to raise.
     */
    public function bodyOverLimit(int $bytes): bool
    {
        $limit = $this->current()['max_note_bytes'];
        if ($bytes <= $limit) {
            return false;
        }
        StorageLimitExceeded::recordRefusal($bytes, $limit);

        return true;
    }

    /**
     * @param int $previousBytes the body this write replaces, so a note that
     *                           was already over a lowered limit may still be
     *                           saved smaller or unchanged
     */
    public function assertBody(string $body, int $previousBytes = 0): void
    {
        $observed = strlen($body);
        $limit = $this->current()['max_note_bytes'];
        if ($observed > $limit && $observed > $previousBytes) {
            throw new StorageLimitExceeded('note_bytes', $observed, $limit, 'Shorten the final note body before saving or applying this change.');
        }
    }

    /** @return array{max_note_bytes: int, max_import_bytes: int} */
    public function current(): array
    {
        return $this->current ??= $this->read();
    }

    /** @return array{max_note_bytes: int, max_import_bytes: int} */
    private function read(): array
    {
        $row = $this->connection->fetchAssociative('SELECT max_note_bytes, max_import_bytes FROM storage_policy WHERE id = 1');
        if ($row === false) {
            throw new \RuntimeException('Storage policy is missing; run the required migration.');
        }
        $result = [];
        foreach (self::CEILINGS as $key => $ceiling) {
            $value = (string) $row[$key];
            if (!preg_match('/\A[1-9][0-9]{0,12}\z/', $value) || (int) $value > $ceiling) {
                throw new \RuntimeException('Invalid storage policy value: '.$key);
            }
            $result[$key] = (int) $value;
        }

        return $result;
    }

    /**
     * @param array<string, int> $changes
     * @return array{max_note_bytes: int, max_import_bytes: int}
     */
    public function update(array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (!isset(self::CEILINGS[$key]) || !is_int($value) || $value < 1 || $value > self::CEILINGS[$key]) {
                throw new \InvalidArgumentException('Invalid storage policy value: '.$key);
            }
        }
        if ($changes !== []) {
            $sets = array_map(static fn (string $key): string => $key.' = :'.$key, array_keys($changes));
            if ($this->connection->executeStatement('UPDATE storage_policy SET '.implode(', ', $sets).', updated_at = :now WHERE id = 1', [...$changes, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]) !== 1) {
                throw new \RuntimeException('Storage policy is missing; run the required migration.');
            }
        }

        $this->current = null;

        return $this->current();
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
