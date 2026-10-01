<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Changing part of a note without resending all of it.
 *
 * ## Why this exists
 *
 * Until 2026-08-22 an edit was a **full replacement body**, and nothing else.
 * That is fine for a note somebody just wrote and holds in front of them, and
 * it fails in three ways on the notes that matter most — the long canonical
 * records this knowledge base is built around:
 *
 * 1. **It caused the overwrite hazard.** Two
 *    proposals held on one note each carry a whole body built from the note as
 *    it stood when their author read it, so approving the second silently
 *    reverts the first, and nothing in the inbox says the two overlap. A patch
 *    resolved at APPROVAL time composes instead: two changes to different
 *    sentences both land, and one whose anchor has since moved fails loudly
 *    rather than quietly winning.
 * 2. **It made the `live_state_notice` impractical exactly where it is aimed.**
 *    That notice asks an agent to file a full replacement, and the notes
 *    carrying it run to tens of thousands of characters. The instruction was
 *    hardest to follow on precisely the notes it protects.
 * 3. **It forced a regeneration where a correction would do.** An agent
 *    reproducing 40,000 characters to fix three sentences can corrupt a
 *    sentence it was not even editing, and nobody would find out for months.
 *
 * ## The contract
 *
 * Operations are `{find, replace}` pairs applied **in order**, each against the
 * result of the last. `find` is matched **literally** — no regular expressions,
 * because an anchor a person cannot read is an anchor nobody can review — and
 * must appear **exactly once**. Not zero times, which means the note is not
 * what the author thought; not twice, which means the author does not know
 * which one they are changing. Both are refused with the count, because the
 * fix is the same either way: quote more of the surrounding text.
 *
 * That "exactly once" rule is the whole safety property. It is what lets a
 * patch be applied twice — at proposal time as a check, and again at approval
 * time for real — and give the same answer or a clear refusal.
 *
 * An empty `find` is refused: it matches at every position. `replace` may be
 * empty, which is how a sentence is deleted.
 */
final class NotePatch
{
    /** Anchors longer than this are a pasted body, not an anchor. */
    private const MAX_ANCHOR = 20000;

    /** Enough for a rewrite in pieces; past it, send a body. */
    private const MAX_OPERATIONS = 50;

    /**
     * How much of an anchor to quote back in an error. Long enough to find the
     * problem, short enough that the message stays a message.
     */
    private const EXCERPT = 60;

    /**
     * Read the wire form into operations, refusing anything malformed before it
     * reaches a note.
     *
     * @param mixed $raw the `patch` field as it arrived
     * @return array<int, array{find: string, replace: string}>
     * @throws NotePatchException
     */
    public static function parse(mixed $raw): array
    {
        // A JSON string is accepted as well as a list, because MCP clients
        // CACHE the tool schema at connector creation (found 2026-08-20 with
        // ChatGPT) — so a client connected before `patch` existed does not know
        // it is an array and sends the caller's value stringified. Refusing
        // that would read as "the feature is broken" to the one caller who did
        // everything right. Parsed strictly: a string that is not a JSON list
        // still fails, and fails saying so.
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : $raw;
        }
        if (!is_array($raw) || $raw === []) {
            throw new NotePatchException('patch must be a non-empty list of {find, replace} operations.');
        }
        if (count($raw) > self::MAX_OPERATIONS) {
            throw new NotePatchException('A patch may carry at most '.self::MAX_OPERATIONS.' operations; this one carries '.count($raw).'.');
        }

        $operations = [];
        foreach (array_values($raw) as $i => $op) {
            $at = 'Operation '.($i + 1).': ';
            if (!is_array($op)) {
                throw new NotePatchException($at.'each operation must be an object with `find` and `replace`.');
            }
            $find = $op['find'] ?? null;
            $replace = $op['replace'] ?? null;
            if (!is_string($find) || $find === '') {
                // An empty anchor matches at every position, so it is not a
                // question with an answer.
                throw new NotePatchException($at.'`find` must be a non-empty string — the exact text to replace.');
            }
            if (!is_string($replace)) {
                throw new NotePatchException($at.'`replace` must be a string (send "" to delete the text).');
            }
            if (mb_strlen($find) > self::MAX_ANCHOR) {
                throw new NotePatchException($at.'`find` is longer than '.self::MAX_ANCHOR.' characters. An anchor that long is a body — send `body_md` instead.');
            }
            if ($find === $replace) {
                // Not harmless: a proposal claiming a change it does not make
                // is reviewed and approved as though it did something.
                throw new NotePatchException($at.'`find` and `replace` are identical, so this operation would change nothing.');
            }
            $operations[] = ['find' => $find, 'replace' => $replace];
        }

        return $operations;
    }

    /**
     * Apply operations to a body, or explain exactly which one did not fit.
     *
     * Nothing is applied unless every operation fits: the caller gets a whole
     * new body or an exception, never a note half-patched by the operations
     * that happened to come first.
     *
     * @param array<int, array{find: string, replace: string}> $operations
     * @throws NotePatchException
     */
    public static function apply(string $body, array $operations): string
    {
        $result = $body;
        foreach ($operations as $i => $op) {
            $count = substr_count($result, $op['find']);
            if ($count !== 1) {
                throw new NotePatchException(self::explain($i, $op['find'], $count));
            }
            $result = str_replace($op['find'], $op['replace'], $result);
        }

        if (trim($result) === '') {
            throw new NotePatchException('That patch would empty the note. Deleting a note is `propose_delete`, which keeps it restorable.');
        }

        return $result;
    }

    /**
     * The refusal, worded so the fix is obvious. Both failures have the same
     * remedy — quote more of the note — and the count is what tells them apart.
     */
    private static function explain(int $index, string $find, int $count): string
    {
        $excerpt = mb_strlen($find) > self::EXCERPT ? mb_substr($find, 0, self::EXCERPT).'…' : $find;
        $at = 'Operation '.($index + 1).': ';

        if ($count === 0) {
            return $at.'that text is not in the note as it now stands — “'.$excerpt.'”. '
                .'Read the note again and anchor on what is there; it may have been edited since you read it.';
        }

        return $at.'that text appears '.$count.' times in the note — “'.$excerpt.'”. '
            .'Include enough of the surrounding text to name the one you mean.';
    }
}
