<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Entity\VaultSettings;

/**
 * Who wrote a note, in ONE shape, for every screen that asks.
 *
 * ## The defect this exists to close
 *
 * "Who wrote this" was answered in three different formats by three code paths,
 * and only one of them gave the right answer:
 *
 * - the notes list joined `api_tokens` in raw SQL and produced
 *   `{name, icon, icon_url}` with the name the operator chose;
 * - the note page sent `created_by`, a bare string, from
 *   `$note->getCreatedByToken()?->getName()` — the **registered** name, so a
 *   connection renamed to "Claude · research" still showed as `dev-curator`,
 *   with no mark beside it. The operator saw it as an unnamed agent, which is
 *   exactly what it was;
 * - and nothing anywhere could name a PERSON, so the operator's own writes
 *   showed as blank.
 *
 * Two serialisers for one row is a pattern this codebase has been bitten by
 * before (2026-08-23: the notes list is raw SQL in {@see HybridSearch}, not
 * `noteToArray()`). The answer is not to remember to fix both — it is to have
 * one function that produces the shape, called from both, so a fix cannot land
 * in one and miss the other.
 *
 * ## The shape
 *
 * `{kind: 'assistant'|'person'|'memex', name, initial, icon, icon_url}` — and
 * the client renders it identically wherever it appears. `initial` is set only
 * for a person; agents and memex keep their glyph or logo.
 *
 * The assistant's name is resolved through the token every time and never
 * copied, which is what makes the operator's ruling hold: renaming a connection
 * renames it everywhere at once (2026-08-23).
 */
final class ActorView
{
    public const KIND_ASSISTANT = 'assistant';
    public const KIND_PERSON = 'person';
    /**
     * memex itself — the scheduled enrichment pass (2026-08-23).
     *
     * A third kind rather than an assistant with a special name, because it is
     * neither of the other two and pretending otherwise would put a connection
     * in the notes list that nobody could find under Connections. It is the
     * same author `notes.summary_by` has called `memex` since descriptions
     * were first attributed.
     */
    public const KIND_MEMEX = 'memex';

    /** Same glyph as the account menu, so a person reads as a person on sight. */
    public const PERSON_ICON = 'fa-solid fa-circle-user';

    /** The product's own mark, for work the product did itself. */
    public const MEMEX_ICON = 'fa-solid fa-wand-magic-sparkles';

    /**
     * The ORM path: an entity in hand.
     *
     * The assistant wins when there is one — it is more specific than the
     * account it authenticated against — which is the same precedence
     * {@see \App\Entity\Note::attribute()} enforces in the database.
     *
     * @return array{kind: string, name: string, initial: ?string, icon: ?string, icon_url: ?string, icon_url_dark: ?string}|null
     */
    public static function of(?ApiToken $token, ?OwnerMark $owner, ?string $actor = null): ?array
    {
        if ($token !== null) {
            return [
                'kind' => self::KIND_ASSISTANT,
                'name' => $token->displayName(),
                'initial' => null,
                ...AgentIcons::markFor(
                    $token->getIconKey(),
                    $token->hasUploadedIcon() ? '/api/tokens/'.$token->getId().'/icon' : null
                ),
            ];
        }
        if ($owner !== null) {
            return self::person($owner->name, $owner->email, $owner->iconKey);
        }
        if ($actor === Note::ACTOR_MEMEX) {
            return self::memex();
        }

        return null;
    }

    /** Who wrote a note last. */
    public static function lastWriter(Note $note, OwnerMark $owner): ?array
    {
        return self::of($note->getLastActorToken(), $note->isLastWrittenByOwner() ? $owner : null, $note->getLastActor());
    }

    /** Who replaced a revision's state. */
    public static function replacer(NoteRevision $revision, OwnerMark $owner): ?array
    {
        $token = $revision->getReplacedByToken();

        return self::of($token, $token === null && Note::isOwnerActor($revision->getReplacedBy()) ? $owner : null, $revision->getReplacedBy());
    }

    public static function added(Note $note, OwnerMark $owner): ?array
    {
        return $note->getSource() === Note::SOURCE_MEMEX
            ? self::memex()
            : self::of($note->getCreatedByToken(), $note->isAddedByOwner() ? $owner : null);
    }

    /** @return array{kind: string, name: string, initial: ?string, icon: ?string, icon_url: ?string, icon_url_dark: ?string} */
    public static function memex(): array
    {
        return [
            'kind' => self::KIND_MEMEX,
            'name' => 'memex',
            'initial' => null,
            'icon' => self::MEMEX_ICON,
            'icon_url' => null,
            'icon_url_dark' => null,
        ];
    }

    /**
     * The raw-SQL path: joined columns, no entities loaded.
     *
     * `$prefix` names the join — `agent_`/`person_` for the last writer — so
     * one query can carry more than one actor without the columns colliding.
     * Kept beside {@see of()} rather than in the query's own file: the two must
     * agree about the shape, and code that must agree belongs together.
     *
     * @param array<string, mixed> $row
     * @return array{kind: string, name: string, initial: ?string, icon: ?string, icon_url: ?string, icon_url_dark: ?string}|null
     */
    public static function fromRow(array $row, string $agentPrefix, OwnerMark $owner, ?string $actor = null): ?array
    {
        $tokenId = $row[$agentPrefix.'token_id'] ?? null;
        if ($tokenId !== null) {
            $display = $row[$agentPrefix.'display_name'] ?? null;
            $name = is_string($display) && $display !== '' ? $display : (string) $row[$agentPrefix.'name'];
            $iconKey = $row[$agentPrefix.'icon_key'] ?? null;

            return [
                'kind' => self::KIND_ASSISTANT,
                'name' => $name,
                'initial' => null,
                ...AgentIcons::markFor(
                    is_string($iconKey) ? $iconKey : null,
                    self::truthy($row[$agentPrefix.'has_upload'] ?? false)
                        ? '/api/tokens/'.((int) $tokenId).'/icon'
                        : null
                ),
            ];
        }

        if (Note::isOwnerActor($actor)) {
            return self::person($owner->name, $owner->email, $owner->iconKey);
        }
        if ($actor === Note::ACTOR_MEMEX) {
            return self::memex();
        }

        return null;
    }

    /**
     * Raw SQL hands a boolean back as an integer, not a bool. Only a real
     * truth counts: a loose cast of a stray string would put an uploaded-icon
     * URL on an assistant that has no upload and render a broken image beside
     * its name.
     */
    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    /**
     * A person's name falls back to the email rather than to nothing: an
     * account can be created with a blank name, and "who edited this: (blank)"
     * is worse than an address somebody recognises.
     *
     * The initial is decided here too, so "that was me" stays answerable
     * without reading the name, whether or not anybody has been to Settings.
     *
     * @return array{kind: string, name: string, initial: ?string, icon: ?string, icon_url: ?string, icon_url_dark: ?string}
     */
    private static function person(string $name, string $email, ?string $iconKey = null): array
    {
        $name = trim($name);

        return [
            'kind' => self::KIND_PERSON,
            'name' => $name !== '' ? $name : $email,
            ...self::personMark($name, $email, $iconKey),
        ];
    }

    /**
     * A person's initial comes from the same name this view exposes, with the
     * email as the fallback when the account has no name.
     *
     * @return array{initial: ?string, icon: ?string, icon_url: ?string, icon_url_dark: ?string}
     */
    public static function personMark(string $name, string $email, ?string $iconKey): array
    {
        $source = trim($name) !== '' ? trim($name) : trim($email);
        $initial = $source !== '' ? mb_strtoupper(mb_substr($source, 0, 1)) : null;

        // Addressed as "mine" rather than by an id because every path that
        // creates an account creates its knowledge base with it — social
        // sign-up and invite redemption both do — so the person in a row is the
        // one asking.
        if ($iconKey === VaultSettings::ICON_UPLOAD) {
            return [
                'initial' => $initial,
                'icon' => self::PERSON_ICON,
                'icon_url' => '/api/me/icon',
                'icon_url_dark' => '/api/me/icon',
            ];
        }
        if ($iconKey !== null && AgentIcons::isGlyph($iconKey)) {
            return ['initial' => $initial, ...AgentIcons::markFor($iconKey, null)];
        }

        return [
            'initial' => $initial,
            'icon' => self::PERSON_ICON,
            'icon_url' => null,
            'icon_url_dark' => null,
        ];
    }
}
