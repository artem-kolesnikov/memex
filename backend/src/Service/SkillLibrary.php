<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\Note;
use Symfony\Component\Yaml\Yaml;

/**
 * Skills belong to the knowledge base. A skill IS a verified note tagged
 * `skill`, with one class of exception: the instruction sets memex serves
 * itself, held in {@see ShippedSkills}, because they are memex's own machinery
 * rather than the user's content and a frozen copy in a note could never
 * receive a shipped correction. Everything else here is the user's, findable
 * and editable in their own KB.
 *
 * What ships with the application is a **catalogue**: markdown files in
 * `config/skills/` that onboarding offers, and that the user chooses from. A
 * chosen skill is written into the KB as a real note and stops being ours. The
 * catalogue is also how a user gets a skill back after deleting it, and how
 * skills added to memex later reach accounts that already exist.
 *
 * Separate from both: {@see ShippedSkills}, the instruction sets memex serves
 * ITSELF because they are about operating memex. Their slugs are reserved here,
 * so no note can claim one.
 *
 * The rejected alternative was serving every shipped text as a runtime
 * fallback whenever a knowledge base had no note of that slug. It reads well — nobody is
 * ever without a skill — but it means an assistant loads instructions the user
 * never chose and cannot see anywhere on the website, and declining a skill at
 * onboarding would not actually decline it.
 */
class SkillLibrary
{
    /**
     * Slug of the curation charter — named by the server itself in the
     * `initialize` instructions of a curator connection. Served by
     * {@see CurationCharter} and reserved, with every other shipped slug, in
     * {@see self::all()} so no note can claim one.
     */
    public const CURATION_CHARTER = 'memex-curation';

    public function __construct(
        private readonly ShippedSkills $shipped,
        private readonly SkillServing $serving,
        private readonly string $skillDefaultsDir,
    ) {
    }

    /**
     * Slug of a skill, derived from its title. Public and static because it is
     * the join between a shipped file and the note that overrides it: if the
     * two ever slugged differently, an edited charter would silently appear
     * alongside the shipped one instead of replacing it.
     */
    public static function slugify(string $title): string
    {
        return SkillSlug::fromTitle($title);
    }

    /**
     * Every skill this knowledge base can load: shipped first, then its own verified
     * notes tagged `skill` that are enabled, granted to `$token` (or ungated),
     * and offered on `$surface` when one is given.
     *
     * @return list<array{id: ?int, slug: string, title: string, description: string, body: string, updated_at: string, status?: string, enabled?: bool, auto?: bool, command?: bool, grants?: list<int>}>
     */
    public function all(?ApiToken $token = null, ?string $surface = null): array
    {
        $skills = $this->shipped->all($token);
        // A record given a slug before memex reserved it keeps that slug; it is
        // never served under a name memex vouches for, whether or not the
        // shipped skill of that name is on.
        $reserved = array_flip(ShippedSkills::reservedSlugs());
        foreach ($this->noteSkills(true) as $skill) {
            if (!isset($reserved[$skill['slug']]) && $this->serving->servedTo($skill, $token, $surface)) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    /** @return list<array> every skill note (verified or pending) with its serving record */
    private function noteSkills(bool $verifiedOnly): array
    {
        $this->serving->ensureRecords();
        $settings = $this->serving->settings();
        $grants = $this->serving->grants();
        $out = [];
        foreach ($this->serving->skillNotes($verifiedOnly) as $row) {
            $record = $settings[(int) $row['id']] ?? null;
            $out[] = [
                'id' => (int) $row['id'],
                'slug' => $record['slug'] ?? SkillSlug::fromTitle($row['title']),
                'title' => $row['title'],
                'description' => (string) ($row['summary'] ?? ''),
                'body' => $row['body_md'],
                'updated_at' => $row['updated_at'],
                'status' => $row['status'],
                'enabled' => $record['enabled'] ?? true,
                'auto' => $record['auto'] ?? true,
                'command' => $record['command'] ?? true,
                'grants' => $grants[(int) $row['id']] ?? [],
            ];
        }

        return $out;
    }

    /**
     * Every row Settings shows: shipped skills, every skill-tagged note
     * (verified or pending) with its serving status, and untaken catalogue
     * entries.
     *
     * @return list<array>
     */
    public function page(): array
    {
        $rows = [];
        foreach ($this->shipped->all(null) as $skill) {
            $rows[] = $skill + ['kind' => 'shipped', 'status' => 'built_in'];
        }
        if (!in_array(ShippedSkills::WRITING, array_column($rows, 'slug'), true) && ($writing = $this->shipped->writingEntry()) !== null) {
            $rows[] = $writing + ['kind' => 'shipped', 'status' => 'switched_off'];
        }
        $noteSkills = $this->noteSkills(false);
        // A pending note has no skill_settings row yet, so its slug here is only
        // derived from its title — the same derivation a served row's real slug
        // came from before it was ever assigned. That derived string can
        // collide with a shipped slug, a served or paused row, or an earlier
        // pending row on this same page; suffixing it by note number keeps
        // every row unique without ever moving the slug a verified note
        // actually serves under.
        $taken = array_fill_keys(ShippedSkills::reservedSlugs(), true);
        foreach ($noteSkills as $skill) {
            if ($skill['status'] !== Note::STATUS_PENDING) {
                $taken[$skill['slug']] = true;
            }
        }
        foreach ($noteSkills as $skill) {
            if ($skill['status'] === Note::STATUS_PENDING) {
                $derived = $skill['slug'];
                if (isset($taken[$derived])) {
                    $suffix = '-'.$skill['id'];
                    $base = $derived;
                    $derived = rtrim(substr($base, 0, SkillSlug::MAX - strlen($suffix)), '-').$suffix;
                    for ($i = 2; isset($taken[$derived]); ++$i) {
                        $nextSuffix = $suffix.'-'.$i;
                        $derived = rtrim(substr($base, 0, SkillSlug::MAX - strlen($nextSuffix)), '-').$nextSuffix;
                    }
                }
                $skill['slug'] = $derived;
            }
            $taken[$skill['slug']] = true;
            $skill['kind'] = 'note';
            $skill['status'] = $skill['status'] === Note::STATUS_PENDING ? 'pending' : ($skill['enabled'] ? 'served' : 'paused');
            $rows[] = $skill;
        }
        foreach ($this->catalogue() as $entry) {
            if (!isset($taken[$entry['slug']])) {
                $rows[] = $entry + ['id' => null, 'kind' => 'catalogue', 'status' => 'offered', 'updated_at' => null];
            }
        }

        return $rows;
    }

    /** @return array{id: ?int, slug: string, title: string, description: string, body: string, updated_at: string, status?: string, enabled?: bool, auto?: bool, command?: bool, grants?: list<int>}|null */
    public function find(string $slug, ?ApiToken $token = null): ?array
    {
        foreach ($this->all($token) as $skill) {
            if ($skill['slug'] === $slug) {
                return $skill;
            }
        }

        return null;
    }

    /**
     * One entry of the shipped catalogue, whether or not this KB has taken it.
     *
     * @return array{slug: string, title: string, description: string, short: string, body: string}|null
     */
    public function catalogueEntry(string $slug): ?array
    {
        foreach ($this->catalogue() as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The skills memex ships. Onboarding offers these; the user chooses; the
     * chosen ones are written into the KB as notes. Nothing here is served to
     * an assistant until that happens.
     *
     * @return list<array{slug: string, title: string, description: string, short: string, body: string}>
     */
    public function catalogue(): array
    {
        $files = glob(rtrim($this->skillDefaultsDir, '/').'/*.md');
        if ($files === false) {
            return [];
        }
        sort($files);

        $reserved = array_flip(ShippedSkills::reservedSlugs());

        $catalogue = [];
        foreach ($files as $file) {
            // A reserved slug is served by memex itself, so a catalogue file
            // claiming one is offering something nobody can take: onboarding
            // would show it as available (the shipped entry has no note id, so
            // it reads as untaken) and `add()` would refuse it 409 every time,
            // because its conflict check DOES find the shipped skill. Skipping
            // it here keeps the two answers in agreement.
            if (isset($reserved[basename($file, '.md')])) {
                continue;
            }
            $parsed = self::parseSkillFile((string) file_get_contents($file));
            if ($parsed === null) {
                continue;
            }
            $catalogue[] = [
                // The filename is the slug of record. Deriving it from the
                // title instead would mean a title edit in the shipped file
                // stopped matching the note a user already took from it.
                'slug' => basename($file, '.md'),
                'title' => $parsed['title'],
                'description' => $parsed['description'],
                'short' => $parsed['short'],
                'body' => $parsed['body'],
            ];
        }

        return $catalogue;
    }

    /**
     * A catalogue file: YAML frontmatter carrying title, description and a
     * one-line `short`, then the instructions. A file without a title is
     * malformed and skipped rather than offered half-formed.
     *
     * `short` exists because the two places an entry is read want different
     * lengths and neither may be a truncation of the other: `description`
     * becomes the note's SUMMARY when somebody takes the skill, so it has to
     * stand on its own, while the catalogue row is one line beside a button.
     * Clamping the summary to a line cuts it mid-sentence; writing the line
     * separately does not. Absent, it falls back to the description.
     *
     * @return array{title: string, description: string, short: string, body: string}|null
     */
    public static function parseSkillFile(string $contents): ?array
    {
        if (!preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $contents, $m)) {
            return null;
        }
        try {
            $meta = Yaml::parse($m[1]);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($meta) || !is_string($meta['title'] ?? null) || trim($meta['title']) === '') {
            return null;
        }

        $description = is_string($meta['description'] ?? null) ? trim($meta['description']) : '';

        return [
            'title' => trim($meta['title']),
            'description' => $description,
            'short' => is_string($meta['short'] ?? null) ? trim($meta['short']) : $description,
            'body' => ltrim($m[2]),
        ];
    }
}
