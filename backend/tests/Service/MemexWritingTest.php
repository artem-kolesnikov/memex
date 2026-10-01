<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MemexWriting;
use App\Service\Personalization;
use PHPUnit\Framework\TestCase;

/**
 * memex-writing's text: generated from fixed rulesets and the owner's presets,
 * the same settings always giving the same text, and each preset putting its
 * own line in without leaving a fixed part to contradict it.
 */
final class MemexWritingTest extends TestCase
{
    /** @param array<string, mixed> $stored */
    private static function text(array $stored = []): string
    {
        return (string) MemexWriting::text(Personalization::read($stored === [] ? null : $stored));
    }

    public function testTheDefaultsReadInOneLoad(): void
    {
        $text = self::text();
        self::assertSame($text, self::text(), 'the same settings give the same text');
        self::assertLessThan(900, count(preg_split('/\s+/', trim($text)) ?: []), 'the whole skill at its defaults stays under 900 words');
        foreach (['# Every note', '# Choices the owner set', '# Shapes by kind'] as $heading) {
            self::assertStringContainsString($heading, $text);
        }
        self::assertStringStartsWith(MemexWriting::HEADER, $text);
        self::assertStringContainsString(MemexWriting::PRECEDENCE, $text);
    }

    public function testEveryOptionPutsItsOwnTextInTheSkill(): void
    {
        foreach (MemexWriting::options(Personalization::defaults()) as $row) {
            foreach ($row['choices'] as $choice) {
                self::assertStringContainsString($choice['text'], self::text([$row['axis'] => $choice['value']]), $row['axis'].' '.$choice['value']);
            }
            foreach ($row['add_ons'] as $addOn) {
                $off = self::text();
                $on = self::text([$addOn['key'] => true]);
                self::assertStringNotContainsString($addOn['text'], $off);
                self::assertStringContainsString($addOn['text'], $on, $addOn['key']);
            }
        }
    }

    public function testNoOptionShowsTextItWouldNotSend(): void
    {
        $settings = Personalization::read(['opening' => 'context']);
        $reasons = array_column(MemexWriting::options($settings)[3]['choices'], 'text', 'value')['reasons'];
        self::assertStringContainsString($reasons, (string) MemexWriting::text($settings));
        self::assertStringNotContainsString('Put the conclusion first', (string) MemexWriting::text($settings),
            'with the situation set out first, the reasoning line does not say the conclusion comes first');
        self::assertStringContainsString('For anything else, open as set above.', (string) MemexWriting::text($settings));
    }

    public function testConclusionsOnlyReshapesTheDecisionAndMeetingShapes(): void
    {
        self::assertStringContainsString('the options considered', self::text());
        self::assertStringContainsString('decisions with their reasons', self::text());
        $bare = self::text(['reasoning' => 'bare']);
        self::assertStringNotContainsString('the options considered', $bare);
        self::assertStringNotContainsString('decisions with their reasons', $bare);
    }

    public function testMinimalMarkupTakesOutTheBoldLine(): void
    {
        self::assertStringContainsString('Bold at most a few words', self::text());
        self::assertStringNotContainsString('Bold at most a few words', self::text(['format_minimal' => true]));
    }

    public function testTheOffSwitch(): void
    {
        self::assertNull(MemexWriting::text(Personalization::read(['writing' => false])));
        self::assertNull(MemexWriting::connectParagraph(Personalization::read(['writing' => false])));
        self::assertNotSame(MemexWriting::version(Personalization::read(null)), MemexWriting::version(Personalization::read(['writing' => false])));
        self::assertNotSame(MemexWriting::version(Personalization::read(null)), MemexWriting::version(Personalization::read(['scope' => 'one_idea'])));
    }

    public function testTheSkillNamesNoLanguageAndDecidesNothingAboutSaving(): void
    {
        $all = self::text(['scope' => 'whole_topic', 'opening' => 'answer', 'format' => 'prose', 'format_minimal' => true, 'reasoning' => 'rationale', 'reasoning_confidence' => true, 'reasoning_sources' => true]);
        foreach (['English', 'language', 'tone', 'save a note', 'should be saved', 'whether to save'] as $word) {
            self::assertDoesNotMatchRegularExpression('/\\b'.$word.'\\b/i', $all, $word);
        }
    }
}
