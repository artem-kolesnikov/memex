<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * Provenance: which connection put a note here (2026-08-25).
 *
 * The one clean ADOPT from both adversaries in the curation brainstorm, and it
 * exists for a specific failure rather than for tidiness. A leaked curator
 * token is the likeliest compromise this product has, and a curator token's
 * safe writes bypass the review gate BY DESIGN — that is what the role is. So
 * the question the operator needs answered afterwards is not "what is held in
 * my inbox" but "what did this connection already put in my knowledge base",
 * and until now nothing could answer it.
 *
 * **`notes.created_by_token_id`, and deliberately not the Curator log.** The
 * log looks like it would do — it has filtered by writer token since the
 * activity view was built — but `NoteWriter::create()` writes a `create` row
 * only when the token is a CURATOR. An agent-role token's notes land pending
 * and write no row at all. A lever that covers one role and omits the other is
 * worse than none, because it answers confidently and short. The column is
 * written for every note by every writer, and survives limbo.
 *
 * What this is NOT, and must never become: a purge button. The answer is a
 * list to read. Quarantining or deleting what it finds stays held for review,
 * which is the constraint both adversaries put on it.
 */
final class NoteProvenanceTest extends ApiTestCase
{
    public function testMcpGetSaysWhichConnectionAddedTheNoteAndUnderTheWebsUsualName(): void
    {
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'title' => 'Filed by an assistant',
                'body_md' => 'Body.',
                'summary' => 'A description.',
            ]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $created = json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
        $noteId = $created['note']['id'];

        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'get', 'arguments' => ['id' => $noteId]],
        ]);
        $note = json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('added_by', $note, 'MCP get could say who edited a note last and never who added it');
        self::assertSame('assistant', $note['added_by']['kind']);
        self::assertStringContainsString('agent-', $note['added_by']['name']);
        self::assertArrayNotHasKey('token', $note['added_by'], 'provenance names the connection, never token material');
    }

    /** A note a person typed reads as a person, not as a nameless blank. */
    public function testANoteAPersonWroteIsAttributedToThePerson(): void
    {
        $note = $this->kb->a->note('Typed by hand', 'Body.', ['reference'], summary: 'A description.');

        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get', 'arguments' => ['id' => $note->getId()]],
        ]);
        $payload = json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);

        self::assertSame('person', $payload['added_by']['kind']);
    }

    /**
     * The lever itself: every note one connection added, and only those.
     */
    public function testTheNotesListCanBeNarrowedToOneConnectionsWrites(): void
    {
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'title' => 'Written by the curator token',
                'body_md' => 'Body.',
                'summary' => 'A description.',
            ]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $curatorNoteId = json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR)['note']['id'];

        $byHand = $this->kb->a->note('Typed by hand', 'Body.', ['reference'], summary: 'A description.');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/notes?added_by='.$this->kb->a->curatorTokenId);
        $ids = array_column($this->jsonResponse()['items'], 'id');

        self::assertContains($curatorNoteId, $ids);
        self::assertNotContains(
            $byHand->getId(),
            $ids,
            'a filter that returned the hand-typed note too would be no filter at all — this is the assertion that fails if added_by is ignored'
        );
    }

    /**
     * A value that is not an id is REFUSED — it must never mean "no filter".
     *
     * The first version parsed with `getInt('added_by') ?: null`, and
     * `getInt()` answers 0 for everything `FILTER_VALIDATE_INT` rejects: a
     * non-numeric string, a leading zero, scientific notation, a number past
     * PHP_INT_MAX. `0 ?: null` then collapsed all of them into "no filter", and
     * the endpoint answered **200 with the team's entire note list**.
     *
     * That is a worse failure than the empty list this feature's comments warn
     * about, and worse in the specific way that matters: an operator chasing a
     * leaked token gets a full list back with no error, which reads as "this
     * connection wrote all of this". Found by an adversarial review,
     * 2026-08-25, which probed the endpoint rather than reading the parse.
     *
     * @dataProvider notAnId
     */
    public function testAValueThatIsNotAnIdIsRefusedRatherThanIgnored(string $value): void
    {
        $this->kb->a->note('Typed by hand', 'Body.', ['reference'], summary: 'A description.');
        $this->loginAs($this->kb->a);

        // Encoded, because a raw CR/LF never reaches the controller — Symfony's
        // Request refuses the URI outright — and the point of that case is what
        // the PARSE does with a value that arrives decoded.
        $this->sessionRequest('GET', '/api/notes?added_by='.rawurlencode($value));

        self::assertSame(
            400,
            $this->httpStatus(),
            sprintf('added_by=%s answered %d — a filter that cannot be applied must not silently become no filter', $value, $this->httpStatus())
        );
    }

    /** @return array<string, array{string}> */
    public static function notAnId(): array
    {
        return [
            'zero' => ['0'],
            'not a number' => ['abc'],
            'leading zero' => ['01'],
            'scientific notation' => ['1e5'],
            'negative' => ['-1'],
            'past PHP_INT_MAX' => ['99999999999999999999'],
            'decimal' => ['1.5'],
            // Its own line in the history: the first strict version exempted
            // the empty string from validation entirely, so `?added_by=` was
            // treated as "not provided" and answered 200 with the whole list —
            // the exact failure the strict parse was written to close, still
            // open for one value. Found by an adversarial review, 2026-08-25.
            'empty' => [''],
        ];
    }

    /**
     * A REAL id with a trailing newline is refused too.
     *
     * Its own test rather than a data-provider row, and that is the finding
     * rather than a style choice: as a row it used the literal id `1`, which by
     * then belongs to no team because sequences are not rolled back between
     * tests — so it returned 400 from the TEAM check and passed identically
     * whether the pattern ended `$` or `\z`. It pinned nothing. Mutating the
     * anchor is what exposed that.
     *
     * PCRE's `$` matches immediately before a final newline unless the `D`
     * modifier is set, so `/^[1-9][0-9]*$/` accepts "1\n" — a pattern that
     * reads "digits only" and is not. Harmless in the event, since the value
     * casts to a real id and is re-checked against the vault; the defect is a
     * pattern that does not mean what it says, which is the kind someone
     * copies.
     */
    public function testARealIdWithATrailingNewlineIsRefused(): void
    {
        $this->loginAs($this->kb->a);
        $real = $this->kb->a->curatorTokenId;

        // The same id without the newline is accepted, so the refusal below is
        // the ANCHOR doing its job and not the lookup.
        $this->sessionRequest('GET', '/api/notes?added_by='.$real);
        self::assertSame(200, $this->httpStatus(), 'the bare id must be a valid filter: '.$this->body());

        $this->sessionRequest('GET', '/api/notes?added_by='.rawurlencode($real."\n"));
        self::assertSame(400, $this->httpStatus(), 'a trailing newline is not a digit');
    }

    /**
     * An id that names no connection of this vault is REFUSED, not silently
     * ignored.
     *
     * Silence is the dangerous answer for this feature specifically: an
     * operator chasing a leaked token reads an empty list as "it wrote
     * nothing", and a mistyped id would produce exactly that. An unknown id
     * and another team's id give the same 400, so the refusal reveals nothing.
     */
    public function testAnIdFromAnotherTeamIsRefusedRatherThanReturningNothing(): void
    {
        // Connection ids restart per vault, so B's probe is one past every id A holds.
        $this->kb->b->connection('b-filler', 'mxt_b_filler');
        $theirs = $this->kb->b->connection('b-only', 'mxt_b_only');
        $this->in($this->kb->a);
        self::assertNull($this->em->find(\App\Entity\ApiToken::class, $theirs), 'Precondition: A holds no connection by that id');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/notes?added_by='.$theirs);
        self::assertSame(400, $this->httpStatus(), 'another team\'s connection must not silently return an empty list');

        $this->sessionRequest('GET', '/api/notes?added_by=99999999');
        self::assertSame(400, $this->httpStatus(), 'and neither must an id that names nothing at all');
    }
}
