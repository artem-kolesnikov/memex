<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Tests\Support\MockMlResponder;

/**
 * Keyword search finds a Russian word in any of its forms and a word inside a
 * sentence of a script written without spaces. Every note sits far past the
 * meaning cutoff, so whatever is found here was found by its words.
 */
final class SearchEveryLanguageTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ml->vectorFor = static fn (string $content): array => str_contains($content, 'zznote')
            ? MockMlResponder::spread(1.0)
            : MockMlResponder::spread(0.0);
    }

    /** @return list<string> */
    private function titles(string $query): array
    {
        $this->request('GET', '/api/notes?q='.urlencode($query), $this->kb->a->agentBearer);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return array_column($this->jsonResponse()['items'], 'title');
    }

    public function testARussianWordIsFoundInAnyOfItsForms(): void
    {
        $this->kb->a->note('Аренда', 'Срок действия договора истёк в России. zznote');
        $this->kb->a->note('Дача', 'Отправка доверенности на дачу. zznote');

        self::assertSame(['Аренда'], $this->titles('договор'));
        self::assertSame(['Аренда'], $this->titles('договоры'));
        self::assertSame(['Аренда'], $this->titles('истек'));
        self::assertSame(['Аренда'], $this->titles('Россия'));
        self::assertSame(['Дача'], $this->titles('доверенность'));
    }

    public function testAWordInsideAnUnspacedSentenceIsFound(): void
    {
        $this->kb->a->note('会议记录', '我们讨论了价格表和项目计划。zznote');
        $this->kb->a->note('東京', '東京で会議があります。zznote');
        $this->kb->a->note('회의', '회의는 내일입니다. zznote');
        $this->kb->a->note('ประชุม', 'ประชุมวันพรุ่งนี้ zznote');

        self::assertSame(['会议记录'], $this->titles('价格'));
        self::assertSame(['会议记录'], $this->titles('项目'));
        self::assertSame(['会议记录'], $this->titles('格表'));
        self::assertSame([], $this->titles('价钱'));
        self::assertSame(['東京'], $this->titles('会議'));
        self::assertSame(['회의'], $this->titles('회의'));
        self::assertSame(['ประชุม'], $this->titles('ประชุม'));
    }

    public function testAQuotedPhraseAcrossRunsIsFound(): void
    {
        $this->kb->a->note('회의', '오늘 회의 기록을 정리했습니다. zznote');
        $this->kb->a->note('Phones', 'iPhone 价格很高 zznote');

        self::assertSame(['회의'], $this->titles('"회의 기록"'));
        self::assertSame(['Phones'], $this->titles('"iPhone 价格"'));
    }

    public function testAThaiWordMatchesOnlyWithItsOwnMarks(): void
    {
        $this->kb->a->note('ปลา', 'วันนี้กินปลา zznote');

        self::assertSame([], $this->titles('ปู'));
        self::assertSame(['ปลา'], $this->titles('ปลา'));
        self::assertSame(['ปลา'], $this->titles('กิน'));
        self::assertSame([], $this->titles('ก้น'));
    }

    public function testAWordMixingScriptsIsFound(): void
    {
        $this->kb->a->note('Phones', 'iPhone价格很高 zznote');

        self::assertSame(['Phones'], $this->titles('iPhone价格'));
    }

    public function testEnglishStillMatchesByStem(): void
    {
        $this->kb->a->note('Review gate', 'Every agent note lands pending. zznote');

        self::assertSame(['Review gate'], $this->titles('notes'));
    }

    public function testARussianMatchCarriesAPassageMarkingTheFormFound(): void
    {
        $this->kb->a->note('Аренда', 'Срок действия договора истёк. zznote');

        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'search', 'arguments' => ['query' => 'договор']],
        ]);
        $items = json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)['items'];

        self::assertStringContainsString('договора', $items[0]['matched_text'] ?? '');
    }
}
