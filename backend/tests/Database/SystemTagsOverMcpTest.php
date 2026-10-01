<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * The vocabulary an assistant is offered leads with the two tags memex reads.
 *
 * Through `list_tags` itself rather than through a query built beside it: a
 * test that rebuilds the controller's ORDER BY passes with the controller's
 * ordering removed, which is how this one was written first.
 */
final class SystemTagsOverMcpTest extends ApiTestCase
{
    public function testListTagsLeadsWithTheTagsMemexReads(): void
    {
        $this->kb->a->note('One', 'Body.', ['automation', 'skill']);
        $this->kb->a->note('Two', 'Body.', ['aardvark', 'live-state']);

        $names = array_column($this->listTags(), 'name');

        self::assertSame(['skill', 'live-state'], array_slice($names, 0, 2));
        self::assertSame(['aardvark', 'automation'], array_slice($names, 2));
    }

    public function testTheSystemTagsSayWhatTheyDoToEveryAssistant(): void
    {
        $this->kb->a->note('One', 'Body.', ['skill', 'automation']);

        $rows = [];
        foreach ($this->listTags() as $row) {
            $rows[$row['name']] = $row;
        }

        self::assertTrue($rows['skill']['system'] ?? false);
        self::assertNotSame('', $rows['skill']['system_effect'] ?? '');
        self::assertArrayNotHasKey('system', $rows['automation']);
    }

    /** @return array<int, array<string, mixed>> */
    private function listTags(): array
    {
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'list_tags', 'arguments' => []],
        ]);
        self::assertSame(200, $this->httpStatus(), 'list_tags did not answer: '.$this->body());

        $body = $this->jsonResponse();
        self::assertArrayHasKey('result', $body, 'list_tags returned a protocol error: '.$this->body());

        return $this->structured($body['result'])['tags'];
    }

    /** @return array<string, mixed> */
    private function structured(array $result): array
    {
        if (isset($result['structuredContent'])) {
            return $result['structuredContent'];
        }

        return json_decode((string) $result['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);
    }
}
