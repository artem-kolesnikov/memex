<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\McpServer;
use PHPUnit\Framework\TestCase;

/**
 * The `inbox` verb's body contract (stage 7i, 2026-08-16).
 *
 * Unit tests in the strict sense — no kernel, no database. What they cover is
 * the one decision in the verb that is pure and the one that has burned this
 * project before: **how much of a proposed body a caller is handed, and whether
 * it can tell.** A cap that silently drops content is how 23 changesets in the
 * operator's own history lost the note ids they targeted (gotcha 2026-08-15),
 * and the difference between that disaster and this feature is entirely in
 * these five assertions: the length is measured on the WHOLE body, and a slice
 * announces itself.
 *
 * The rest of the verb — team scoping, the three queries, the counts — is a
 * database property and is verified live against prod, as every backend change
 * in this project has been.
 */
final class McpInboxTest extends TestCase
{
    private const BODY = 'The quick brown fox jumps over the lazy dog.';

    public function testWithoutMaxCharsTheBodyIsWithheldAndItsLengthIsStated(): void
    {
        $sized = McpServer::sizeProposedBody(
            ['id' => 7, 'proposed_body_md' => self::BODY], null);

        self::assertArrayNotHasKey('proposed_body_md', $sized,
            'a body nobody asked for must not ride along — an inbox holding an edit '
            .'to a 95,000-character note is unreadable otherwise');
        self::assertSame(44, $sized['proposed_body_chars']);
        self::assertArrayNotHasKey('proposed_body_truncated', $sized,
            'nothing was cut, because nothing was sent');
        self::assertSame(7, $sized['id'], 'the rest of the item is untouched');
    }

    public function testASliceSaysItIsOneAndStillReportsTheWholeLength(): void
    {
        $sized = McpServer::sizeProposedBody(
            ['proposed_body_md' => self::BODY], 9);

        self::assertSame('The quick', $sized['proposed_body_md']);
        self::assertTrue($sized['proposed_body_truncated']);
        // The number that matters: measured on the body, NOT on the slice. A
        // caller reading 44 against nine characters in hand knows to ask for
        // the rest; one reading 9 believes it has the whole thing.
        self::assertSame(44, $sized['proposed_body_chars']);
    }

    public function testACapLargerThanTheBodyReturnsAllOfItAndSaysNothingWasCut(): void
    {
        $sized = McpServer::sizeProposedBody(
            ['proposed_body_md' => self::BODY], 10_000);

        self::assertSame(self::BODY, $sized['proposed_body_md']);
        self::assertFalse($sized['proposed_body_truncated']);
    }

    public function testAnEditThatProposesNoBodyReportsZeroRatherThanNothing(): void
    {
        // A tags-only or title-only edit — and a delete or merge proposal,
        // which never carry one. Zero is a fact; a missing key reads as
        // "unknown" and invites a second call to find out.
        foreach ([null, 4000] as $maxChars) {
            $sized = McpServer::sizeProposedBody(
                ['id' => 1, 'type' => 'delete', 'proposed_body_md' => null], $maxChars);

            self::assertSame(0, $sized['proposed_body_chars']);
            self::assertArrayNotHasKey('proposed_body_md', $sized);
        }
    }

    public function testCharactersAreCountedAndCutAsCharacters(): void
    {
        // `strlen`/`substr` would report 12 for this body and cut an accented
        // character in half at the boundary, producing invalid UTF-8 in a JSON
        // response. Notes in this vault are prose in more than one language.
        $sized = McpServer::sizeProposedBody(
            ['proposed_body_md' => 'héllo wörld'], 5);

        self::assertSame(11, $sized['proposed_body_chars']);
        self::assertSame('héllo', $sized['proposed_body_md']);
        self::assertTrue($sized['proposed_body_truncated']);
    }
}
