<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\VaultSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The skills memex ships and serves itself, ahead of the knowledge base's own.
 *
 * A skill ships when it is about operating MEMEX — the same class of thing as
 * the review gate. It stays a note when it is about how one owner wants their
 * work done, which is their content and nobody else's business.
 *
 * The reason they cannot be notes is the failure the charter demonstrated: a
 * note is a frozen copy that never receives a shipped correction. The operator's
 * own recall note came in with the vault migration and was still directing
 * agents to a settings pane renamed weeks earlier, while the server's own
 * instructions had been right the whole time — and an invited user had no copy
 * at all, because the note lived in one knowledge base.
 *
 * Two consequences the design has to carry, both stated rather than assumed.
 * These are instructions the user never chose, so they must be READABLE by the
 * user (the Skills page lists what is served, in full). And a shipped slug
 * is reserved in {@see SkillLibrary}, so a note can never claim it and hand an
 * agent something else under a name memex vouches for.
 */
class ShippedSkills
{
    public const RECALL = 'memex-recall';
    public const GUIDE = UserGuide::SLUG;
    /** How to find, read and maintain the owner's profile note (tagged `user-profile`). */
    public const PROFILE = 'memex-profile';
    public const SKILLS = 'memex-skills';
    /** How to write a note here; generated from the owner's presets and served only while they keep it on. */
    public const WRITING = MemexWriting::SLUG;

    /**
     * Slugs no note may claim. The charter's is registered here rather than
     * read from {@see CurationCharter} so that the reservation list is one
     * list: a shipped skill that forgot to reserve its slug is a shadowing bug
     * that only appears once a user happens to title a note that way.
     *
     * @var list<string>
     */
    private const RESERVED = [SkillLibrary::CURATION_CHARTER, self::RECALL, self::GUIDE, self::PROFILE, self::SKILLS, self::WRITING];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurationCharter $charter,
        private readonly LoggerInterface $logger,
        private readonly ShippedText $text,
        private readonly string $shippedSkillsDir,
    ) {
    }

    /** @return list<string> */
    public static function reservedSlugs(): array
    {
        return self::RESERVED;
    }

    /**
     * Everything memex serves of its own, charter first.
     *
     * The charter is asked for rather than read from disk here because it is
     * the one shipped skill that COMPOSES: its canon carries this knowledge base's brief
     * at the end, and serving it records who was served what.
     *
     * @return list<array{id: null, slug: string, title: string, description: string, body: string, updated_at: string}>
     */
    public function all(?ApiToken $token = null): array
    {
        $skills = [$this->charter->skill($token)];

        foreach ([self::RECALL, self::GUIDE, self::PROFILE, self::SKILLS] as $slug) {
            $file = $this->fileFor($slug);
            if ($file === null) {
                continue;
            }
            $skills[] = $file;
        }

        $writing = $this->writing();
        if ($writing !== null) {
            $skills[] = $writing;
        }

        return $skills;
    }

    /**
     * memex-writing as it reads for this knowledge base's presets, whether or not the
     * owner has it switched on — the Skills page shows it either way and says
     * which. {@see self::all()} serves it only while it is on.
     *
     * @return array{id: null, slug: string, title: string, description: string, short: string, body: string, updated_at: string}|null
     */
    public function writingEntry(): ?array
    {
        $file = $this->fileFor(self::WRITING);
        if ($file === null) {
            return null;
        }
        $file['body'] = (string) MemexWriting::text(['writing' => true] + $this->personalization());

        return $file;
    }

    /** @return array{id: null, slug: string, title: string, description: string, short: string, body: string, updated_at: string}|null */
    private function writing(): ?array
    {
        return $this->personalization()['writing'] === true ? $this->writingEntry() : null;
    }

    /** @return array<string, string|bool> */
    private function personalization(): array
    {
        return Personalization::read($this->em->getRepository(VaultSettings::class)->current()->getPersonalization());
    }

    /** @return array{id: null, slug: string, title: string, description: string, body: string, updated_at: string}|null */
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
     * A file-backed shipped skill.
     *
     * An unreadable or malformed file returns null rather than a placeholder:
     * the charter substitutes a refusal when its canon will not load, because
     * an agent that curates without rules does damage. Nothing here is like
     * that — an assistant without the recall skill still reads and writes
     * correctly from the server's own `initialize` instructions, so the honest
     * failure is that the skill is absent and logged.
     *
     * @return array{id: null, slug: string, title: string, description: string, body: string, updated_at: string}|null
     */
    private function fileFor(string $slug): ?array
    {
        $path = rtrim($this->shippedSkillsDir, '/').'/'.$slug.'.md';
        $raw = is_readable($path) ? file_get_contents($path) : false;
        $parsed = $raw === false ? null : SkillLibrary::parseSkillFile($raw);

        if ($parsed === null) {
            $this->logger->error('A shipped skill could not be read', ['slug' => $slug, 'file' => $path]);

            return null;
        }

        return [
            // Null, like the charter's: callers key on it to tell a shipped
            // skill from one of this knowledge base's own notes, and a missing
            // key is an undefined-index read rather than an answer.
            'id' => null,
            'slug' => $slug,
            'title' => $parsed['title'],
            'description' => $parsed['description'],
            'short' => $parsed['short'],
            'body' => $this->text->render($parsed['body']),
            // Deploy time, not edit time: CI checks the repository out fresh and
            // rsync -a preserves THAT mtime, so this moves on every deploy
            // whether or not a word changed. Good enough while nothing renders
            // it; anything that surfaces it to a reader needs a real version.
            'updated_at' => date('Y-m-d H:i:s', (int) filemtime($path)),
        ];
    }
}
