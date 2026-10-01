<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The three tags memex itself reads.
 *
 * Almost every tag in a knowledge base is a word a person chose, and memex has
 * no opinion about it: `recipes` means whatever its owner means by it. These
 * three are different in kind — the SERVER branches on them, and what an
 * assistant is handed changes depending on whether a note carries one:
 *
 *  - `skill` — {@see SkillLibrary} serves verified notes tagged `skill` to
 *    every connected assistant as loadable instructions.
 *  - `live-state` — {@see McpServer::liveStateNotice()}
 *    attaches a standing write-back instruction to every retrieval of a note
 *    tagged with it.
 *  - `user-profile` — {@see UserProfiles} finds the owner's profile by it,
 *    names it to every connection at `initialize`, and
 *    {@see McpServer::profileNotice()} attaches the
 *    reading instruction to every retrieval. Membership changes no
 *    permission: a profile is edited through the same gate as any note.
 *
 * That makes the WORD load-bearing, not just the notes wearing it, and the tag
 * screen offers exactly one irreversible bulk edit: removing a tag rewrites
 * every note carrying it, outside the review gate and with no limbo to restore
 * from. One click on the wrong chip unpublishes the whole skill library,
 * and nothing afterwards says why assistants stopped loading their charter.
 * So the two names are refused there — see {@see TagAdmin::assertRemovable()}.
 *
 * **What is locked is the vocabulary, not membership.** A single note may
 * still gain or lose either tag, from the editor or from an assistant's
 * proposal: a note that has stopped describing live state should stop carrying
 * `live-state`, and that is ordinary curation. Merging another tag INTO one of
 * these is allowed too — consolidating `skills` into `skill` is the tag hygiene
 * the curator charter asks for. Only taking the word itself out of the
 * vocabulary is refused.
 *
 * Zero-note system tags are still garbage-collected like any other
 * ({@see NoteWriter}): a word no note carries is not protected data, and
 * seeding it into every empty account would be a default nobody chose.
 *
 * Adding a third name here is a product decision, not a refactor: it means
 * memex has grown another tag it acts on, and the reason string has to say
 * what breaks without it — that text is what the owner reads when the lock
 * stops them.
 */
final class SystemTags
{
    public const SKILL = 'skill';
    public const LIVE_STATE = 'live-state';
    public const USER_PROFILE = 'user-profile';

    /**
     * Name => why memex holds on to it, addressed to the owner reading the
     * lock on the tag screen. Written in the second person, in terms of what
     * stops working, because "this tag is reserved" answers nothing.
     */
    private const REASONS = [
        self::SKILL =>
            'Verified notes tagged “skill” are served to every connected assistant as '
            .'instructions it can load. Taking this word out of your vocabulary would '
            .'unpublish all of them at once.',
        self::LIVE_STATE =>
            'Notes tagged “live-state” describe systems that change, and every assistant '
            .'that reads one is asked to correct it if its work made the note wrong. '
            .'Taking this word out of your vocabulary would switch that off silently.',
        self::USER_PROFILE =>
            'A note tagged “user-profile” is your profile: memex names it to every connected '
            .'assistant when it connects, and asks whoever reads it to propose a correction '
            .'when a conversation shows a line has changed. Taking this word out of your '
            .'vocabulary would make your profile invisible to your assistants without '
            .'deleting it.',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::REASONS);
    }

    public static function isSystem(string $name): bool
    {
        return isset(self::REASONS[self::normalize($name)]);
    }

    /** Why this name is held, or null if it is an ordinary tag. */
    public static function reason(string $name): ?string
    {
        return self::REASONS[self::normalize($name)] ?? null;
    }

    /**
     * The registry as the SPA and MCP receive it.
     *
     * @return list<array{name: string, reason: string}>
     */
    public static function all(): array
    {
        return array_map(
            static fn (string $name) => ['name' => $name, 'reason' => self::REASONS[$name]],
            array_keys(self::REASONS),
        );
    }

    /**
     * An ORDER BY fragment putting the tags memex reads at the top of a list.
     *
     * Every listing that trims — the notes list collapses what will not fit
     * into "+N" — decided by alphabet alone, so whether a note announced
     * itself as a skill depended on the other words it happened to carry.
     * The rank is built from the registry, so a third name sorts without
     * anybody remembering this method.
     *
     * $column is a SQL identifier the CALLER writes, never user input.
     */
    public static function sqlRank(string $column): string
    {
        $whens = '';
        foreach (array_keys(self::REASONS) as $i => $name) {
            $whens .= " WHEN '".$name."' THEN ".$i;
        }

        return 'CASE LOWER('.$column.')'.$whens.' ELSE '.count(self::REASONS).' END';
    }

    /**
     * The same order, for lists already in PHP.
     *
     * @template T
     * @param list<T> $items
     * @param callable(T): string $name
     * @return list<T>
     */
    public static function sortBy(array $items, callable $name): array
    {
        $rank = array_flip(array_keys(self::REASONS));
        $of = static fn ($item): array => [
            $rank[self::normalize($name($item))] ?? count($rank),
            self::normalize($name($item)),
        ];
        usort($items, static fn ($a, $b): int => $of($a) <=> $of($b));

        return $items;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    public static function sort(array $names): array
    {
        return self::sortBy($names, static fn (string $n): string => $n);
    }

    /** Tag names are stored lowercased (NoteWriter::resolveTags); match that. */
    private static function normalize(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
