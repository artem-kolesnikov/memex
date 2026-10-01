<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Exception\DriverException;

final class StorageLimitExceeded extends \RuntimeException
{
    /** @var array{observed: int, limit: int}|null */
    private static ?array $lastRefusal = null;

    public function __construct(
        public readonly string $dimension,
        public readonly int $observed,
        public readonly int $limit,
        public readonly string $action,
        ?string $message = null,
    ) {
        parent::__construct($message ?? 'Storage limit exceeded for '.$dimension.': '.$observed.' exceeds '.$limit.'. '.$action);
    }

    /** @return array{error: string, dimension: string, observed: int, limit: int, action: string} */
    public function payload(): array
    {
        return ['error' => $this->getMessage(), 'dimension' => $this->dimension, 'observed' => $this->observed, 'limit' => $this->limit, 'action' => $this->action];
    }

    /** What the vault's body trigger was refusing, kept for {@see fromThrowable()}. */
    public static function recordRefusal(int $observed, int $limit): void
    {
        self::$lastRefusal = ['observed' => $observed, 'limit' => $limit];
    }

    /**
     * The vault's body trigger aborts with `MEMEX_BODY_LIMIT`; anything else,
     * or that message with no refusal behind it, is not a storage refusal.
     */
    public static function fromThrowable(\Throwable $error): ?self
    {
        do {
            if ($error instanceof self) {
                return $error;
            }
            if ($error instanceof DriverException && self::$lastRefusal !== null
                && str_contains($error->getMessage(), 'MEMEX_BODY_LIMIT')) {
                return new self('note_bytes', self::$lastRefusal['observed'], self::$lastRefusal['limit'], 'Shorten the final note body before saving or applying this change.');
            }
        } while ($error = $error->getPrevious());

        return null;
    }
}
