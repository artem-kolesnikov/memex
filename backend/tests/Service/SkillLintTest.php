<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SkillLint;
use PHPUnit\Framework\TestCase;

final class SkillLintTest extends TestCase
{
    private function row(array $over = []): array
    {
        return $over + [
            'kind' => 'note', 'status' => 'served', 'slug' => 'house-style', 'title' => 'House style',
            'description' => 'How notes are written in this knowledge base, and when to reread it.',
            'body' => 'Steps.', 'updated_at' => '2026-01-01 00:00:00',
        ];
    }

    private function codes(array $row, array $usage = []): array
    {
        return array_column((new SkillLint())->findings($row, $usage), 'code');
    }

    public function testACleanRowHasNoFindings(): void
    {
        self::assertSame([], $this->codes($this->row(), ['total_30d' => 3]));
    }

    public function testDescriptionAndBodyBounds(): void
    {
        self::assertContains('no_description', $this->codes($this->row(['description' => ''])));
        self::assertContains('description_short', $this->codes($this->row(['description' => 'Helps with notes.'])));
        self::assertContains('description_long', $this->codes($this->row(['description' => str_repeat('x', 1025)])));
        self::assertContains('body_long', $this->codes($this->row(['body' => str_repeat('word ', 4100)])));
    }

    public function testASuffixedSlugIsNamed(): void
    {
        self::assertContains('slug_suffixed', $this->codes($this->row(['slug' => 'house-style-2'])));
        self::assertNotContains('slug_suffixed', $this->codes($this->row(['slug' => 'house-style-2', 'title' => 'House style 2'])));
    }

    public function testUnusedOnlyAfterThirtyDaysServed(): void
    {
        self::assertContains('unused', $this->codes($this->row(), ['total_30d' => 0]));
        self::assertNotContains('unused', $this->codes($this->row(['updated_at' => date('Y-m-d H:i:s')]), ['total_30d' => 0]));
        self::assertNotContains('unused', $this->codes($this->row(['status' => 'paused']), ['total_30d' => 0]));
    }

    public function testShippedRowsAreNotLinted(): void
    {
        self::assertSame([], $this->codes($this->row(['kind' => 'shipped', 'description' => ''])));
    }
}
