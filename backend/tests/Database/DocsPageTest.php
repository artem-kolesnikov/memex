<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\CurationCharter;

/**
 * The Docs page: short how-tos rendered for the server and the reader, whose
 * prompts are the ones the app hands out.
 */
class DocsPageTest extends ApiTestCase
{
    public function testTheDocsAreRenderedForThisServerAndThisReader(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/docs');
        self::assertSame(200, $this->httpStatus());
        $body = $this->jsonResponse()['body'];

        self::assertStringContainsString('# Connect an assistant', $body);
        self::assertStringContainsString('/mcp', $body);
        self::assertStringContainsString('](/'.$this->kb->a->handle().'/inbox)', $body, 'links open the reader\'s own pages');
        foreach (['{{base}}', '{{origin}}', '{{web}}', '{{local}}', '{{edition:'] as $placeholder) {
            self::assertStringNotContainsString($placeholder, $body);
        }
    }

    public function testTheMaintenancePassPromptIsTheOneSettingsHandsOut(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/docs');
        $flat = static fn (string $text): string => trim((string) preg_replace('/\s+/', ' ', str_replace('>', ' ', $text)));

        self::assertStringContainsString($flat(CurationCharter::SHORT_PROMPT), $flat($this->jsonResponse()['body']));
    }

    public function testAConnectionCannotOpenTheDocsPage(): void
    {
        $this->request('GET', '/api/docs', $this->kb->a->agentBearer);
        self::assertSame(403, $this->httpStatus());
    }
}
