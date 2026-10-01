<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Who caused this embedding to be bought, and therefore whether it counts
 * against the knowledge base's bounded allowance.
 *
 * ## Why this exists as a required argument rather than a flag with a default
 *
 * CLAUDE.md §Spend states the rule for server-side TEXT generation and the
 * reason it is structural: "`MlClient`'s text methods take `AiCredentials`
 * with no default value, so a new call site cannot forget. Keep it that way —
 * a defaulted parameter here is a call site that can spend somebody else's
 * money."
 *
 * That rule was written for text and never applied one layer up, where the
 * EMBEDDINGS are bought — and embeddings are the path that spends the
 * operator's own key for every team, always, by documented exception. There,
 * `NoteWriter::create()` and `update()` carried `bool $enrich = true`: a
 * default that buys an embedding, on exactly the shape of parameter the rule
 * warns about. Four of eight `create()` call sites were behind a
 * `SpendLimiter`; the other four were not, and nothing made that visible.
 * Found by the 2026-08-24 audit.
 *
 * So the same medicine: no default anywhere on the path, and a name at each
 * call site saying whose decision this was.
 */
enum EmbeddingSpend
{
    /**
     * Caused by a request — an agent's `propose`, a bearer POST, an upload, a
     * note saved in the editor. Counts against the team's allowance and is
     * refused with a 429 when that is spent.
     *
     * The default reading for anything reachable by a token. The review gate
     * holds what a token WRITES and has never held what it SPENDS, which is
     * the whole gap this closes.
     */
    case Metered;

    /**
     * Caused by the knowledge base's OWNER, deliberately, at a moment they
     * chose. Not counted.
     *
     * Three callers, and every one of them is the owner acting rather than a
     * token being obeyed:
     *
     * - **Approving held work in the review inbox** (`ReviewVerdicts`). This is
     *   the one place in the product where a person has looked at this exact
     *   content and said yes to it. Metering it would make the review inbox
     *   fail part-way through the batch approval it was built for — punishing
     *   the operator for using the trust choke-point, while defending nothing:
     *   both approve routes refuse a bearer token outright, so this path
     *   cannot be reached without a human verdict.
     * - **Applying an approved merge** (`NoteWriter::mergeNotes`). A merge is
     *   held for review for EVERY role, so reaching it means a person read
     *   both notes and approved combining them.
     * - **`app:embed`, the backfill sweep.** Operator-run and systemd-run,
     *   bounded by its own batch size rather than by an hourly window, and it
     *   exists precisely to catch up work that was deferred. A limiter here
     *   would make the sweep stall at the limit and silently under-cover.
     *
     * Anything else claiming this case should be read as a bug.
     *
     * ## Before adding a fourth
     *
     * The rule a curator-role write must not break is stated in
     * `NoteWriter::propose()`: **the curator role exempts a write from the
     * gate, never from the budget.** An apply path that runs behind a token
     * rather than a verdict is `Metered`, however trusted the token is.
     *
     * And a test that COUNTS occurrences of `OwnerInitiated` in the source
     * cannot see an exemption reached through a shared helper — that is how
     * one shipped green in 2026-08-25. What catches it is asking who can
     * reach the line, which is a behavioural test, and there is one.
     */
    case OwnerInitiated;
}
