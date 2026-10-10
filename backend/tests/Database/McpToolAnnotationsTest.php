<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * Every advertised tool says what it may do.
 *
 * MCP's `ToolAnnotations` defaults are the pessimistic ones: a tool with no
 * annotations means `readOnlyHint: false` and `destructiveHint: true`. memex
 * shipped none for months, which told every client that `search` might destroy
 * something. Claude and ChatGPT shrugged; Gemini did not, and asked the
 * operator to approve each read individually — a dozen confirmations to answer
 * one question (2026-08-20).
 *
 * The property worth holding is not "the annotations are correct today" but
 * "no tool can be added without someone deciding". A tool absent from the
 * safety map silently inherits *destructive*, which is the failure this suite
 * exists to make loud.
 */
final class McpToolAnnotationsTest extends ApiTestCase
{
    public function testEveryToolCarriesAnnotations(): void
    {
        foreach ($this->tools($this->kb->a->curatorBearer) as $tool) {
            self::assertArrayHasKey(
                'annotations',
                $tool,
                sprintf('tool "%s" has no annotations, so clients must assume it is destructive', $tool['name'])
            );
            foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as $hint) {
                self::assertIsBool($tool['annotations'][$hint] ?? null, $tool['name'].' is missing '.$hint);
            }
        }
    }

    public function testReadingToolsSayTheyChangeNothing(): void
    {
        // These are the ones a user should never be asked to approve. If any of
        // them ever reports otherwise, the confirmation storm is back.
        $expected = ['search', 'get', 'inbox', 'list_tags', 'list_skills', 'get_skill', 'health', 'needs_enrichment'];

        $byName = $this->byName($this->kb->a->agentBearer);
        foreach ($expected as $name) {
            self::assertArrayHasKey($name, $byName, $name.' is no longer advertised to an agent');
            self::assertTrue($byName[$name]['annotations']['readOnlyHint'], $name.' must be read-only');
            self::assertFalse($byName[$name]['annotations']['destructiveHint'], $name.' must not be destructive');
        }
    }

    public function testWritingToolsDoNotClaimToBeReadOnly(): void
    {
        $byName = $this->byName($this->kb->a->curatorBearer);

        foreach (['propose', 'propose_delete', 'propose_merge', 'log'] as $name) {
            self::assertFalse($byName[$name]['annotations']['readOnlyHint'], $name.' writes, and must say so');
        }
    }

    public function testNoToolIsAdvertisedAsDestructive(): void
    {
        // Not a preference. Deletes and merges are proposals held for review for
        // every role including curator, and even an approved delete retires into
        // limbo for 30 days rather than being destroyed. NoteLimbo::purge() is
        // the only genuine destruction in the system and is not an MCP verb.
        //
        // The first cut marked the delete-shaped verbs destructive on the theory
        // that a confirmation is worth having. destructiveHint is not a
        // suggestion — it is the flag that makes a client interrupt its user —
        // so overstating it re-created the confirmation storm these annotations
        // exist to remove (operator, 2026-08-20).
        $destructive = [];
        foreach ($this->tools($this->kb->a->curatorBearer) as $tool) {
            if ($tool['annotations']['destructiveHint'] ?? true) {
                $destructive[] = $tool['name'];
            }
        }

        self::assertSame([], $destructive, 'no tool reachable over MCP can destroy anything');
    }

    public function testTheDeleteShapedVerbsStillDeclareThatTheyWrite(): void
    {
        // Not destructive is not the same as read-only: they file something a
        // human has to answer, and a client that thought otherwise might run
        // them unattended.
        $byName = $this->byName($this->kb->a->curatorBearer);

        foreach (['propose_delete', 'propose_merge'] as $name) {
            self::assertFalse($byName[$name]['annotations']['readOnlyHint'], $name.' writes, and must say so');
            self::assertFalse($byName[$name]['annotations']['destructiveHint'], $name.' only proposes');
        }
    }

    public function testEveryToolHasATitle(): void
    {
        // A client shows the title where it lists the tools. Both places are
        // filled, because 2025-06-18 clients read the tool's own `title` and
        // older ones read `annotations.title`.
        foreach ([$this->kb->a->curatorBearer, $this->kb->a->agentBearer] as $bearer) {
            foreach ($this->tools($bearer) as $tool) {
                self::assertIsString($tool['title'] ?? null, $tool['name'].' has no title');
                self::assertNotSame('', trim($tool['title']), $tool['name'].' has an empty title');
                self::assertSame($tool['title'], $tool['annotations']['title'] ?? null, $tool['name'].' carries two different titles');
            }
        }
    }

    public function testNoToolReachesTheOpenWeb(): void
    {
        // openWorldHint is what tells a client this call can read a page nobody
        // vetted. memex fetches nothing itself, so no tool may claim it.
        $open = [];
        foreach ($this->tools($this->kb->a->curatorBearer) as $tool) {
            if ($tool['annotations']['openWorldHint'] ?? false) {
                $open[] = $tool['name'];
            }
        }

        self::assertSame([], $open);
    }

    public function testTheServerIntroducesItselfAsMemex(): void
    {
        // This is the name a client puts in its list of connected apps and the
        // word a person types to invoke it. Gemini rendered "Memex Tools" from
        // `name`, ignoring the `title` we sent, so both fields are pinned:
        // whichever a client reads, it gets the same answer (operator,
        // 2026-08-20). memex.tools is the website; the thing an assistant talks
        // to is Memex.
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [],
        ]);

        $info = $this->jsonResponse()['result']['serverInfo'];
        self::assertSame('memex', $info['name']);
        self::assertSame('Memex', $info['title']);
        self::assertSame(static::getContainer()->get(\App\Service\ReleaseVersion::class)->current(), $info['version']);
        // The icon is the other half of how it appears in that list.
        self::assertStringEndsWith('/icon-512.png', $info['icons'][0]['src']);
    }

    /** @return array<int, array{name: string, annotations?: array<string, bool>}> */
    private function tools(string $bearer): array
    {
        $this->request('POST', '/mcp', $bearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $tools = $this->jsonResponse()['result']['tools'] ?? null;
        self::assertIsArray($tools, 'tools/list returned no tools');
        self::assertNotSame([], $tools);

        return $tools;
    }

    /** @return array<string, array{name: string, annotations: array<string, bool>}> */
    private function byName(string $bearer): array
    {
        return array_column($this->tools($bearer), null, 'name');
    }
}
