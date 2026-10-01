<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\CurationPreset;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The curation instruction set: shipped CANON plus this vault's BRIEF, composed
 * into the one skill agents load.
 *
 * It lives here rather than in the knowledge base (operator, 2026-08-27), and
 * the reason is the failure the operator's own vault demonstrates: a charter
 * note is a frozen copy. His was written before 2026-08-26 and never received
 * the shipped corrections, so every pass since has been told the work-queue
 * verbs would refuse an agent-role token and to fall back to browsing — the
 * improvised pass the charter exists to prevent — and step 4 never asked for
 * `started_at`, which is why the digest can attribute 76 of 678 rows. Nothing
 * in the note mechanism could ever have delivered the fix.
 *
 * Four more ways it died silently while it was a note, all in the code: a
 * curator-role connection could patch it and have that apply unreviewed;
 * dropping `skill` from the one note unpublished it; a title collision could
 * hand the slug to something else; and the recovery its own description
 * promised was never implemented.
 *
 * The rule this amends — `nothing served that is not in the KB` — survives,
 * because the charter is not knowledge. It is the configuration of a plug memex
 * ships, the same class of thing as the review gate, and the desk shows both
 * layers in full. {@see ShippedSkills} holds the same test for everything else:
 * a skill ships when it is about operating memex, and stays a note when it is
 * about how one owner wants their own work done.
 */
class CurationCharter
{
    /**
     * Reserved: {@see SkillLibrary} serves this slug from here and refuses to
     * let a note claim it, so a title collision can no longer hand an agent
     * something else under the charter's name.
     */
    public const SLUG = SkillLibrary::CURATION_CHARTER;

    /**
     * The charter's opening line, in every version ever shipped — it has been
     * the literal first line since the file was created, through the rename
     * from `curator-charter.md`.
     *
     * It lives here rather than on the command that uses it because this class
     * owns the charter's identity: `RetireCharterNotesCommand` asks whether a
     * note IS a copy of what this class serves, and the answer is not a
     * one-shot deploy script's private business.
     */
    public const CHARTER_MARKER = 'You are the Curator';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $curationCanonFile,
    ) {
    }

    /**
     * The shipped rules.
     *
     * A missing or malformed file is a broken deploy, and the fallback says so
     * IN the instructions rather than serving an empty body under a plausible
     * title — a charter that silently arrives empty is the failure this whole
     * class exists to remove, and it would be indistinguishable from a working
     * one until an agent improvised a pass (Codex, on review).
     *
     * @return array{title: string, description: string, body: string}
     */
    public function canon(): array
    {
        $raw = is_readable($this->curationCanonFile) ? file_get_contents($this->curationCanonFile) : false;
        $parsed = $raw === false ? null : SkillLibrary::parseSkillFile($raw);
        if ($parsed !== null) {
            return $parsed;
        }

        $this->logger->error('The curation canon could not be read', ['file' => $this->curationCanonFile]);

        return [
            'title' => 'memex — curation',
            'description' => 'The curation instructions could not be loaded on this server.',
            'body' => "# The instructions are unavailable\n\n"
                ."memex could not load its curation rules, so you have not been given them. **Do not "
                ."curate this knowledge base on your own judgment** — tell the operator that the "
                ."curation instructions failed to load and stop there.\n",
        ];
    }

    /**
     * The vault's briefs, `Standard` first.
     *
     * @return list<CurationPreset>
     */
    public function presets(): array
    {
        $this->standard();

        return $this->em->getRepository(CurationPreset::class)
            ->findBy([], ['standard' => 'DESC', 'name' => 'ASC']);
    }

    /**
     * The fallback every connection lands on, seeded on first read.
     *
     * Looked up by its FLAG, never as "the first row". A vault whose first brief
     * was one the owner created themselves has no Standard yet, and taking the
     * first row there silently makes a user brief the fallback for every
     * connection that was never pointed anywhere.
     */
    public function standard(): CurationPreset
    {
        $repo = $this->em->getRepository(CurationPreset::class);
        $standard = $repo->findOneBy(['standard' => true]);
        if ($standard !== null) {
            return $standard;
        }

        // Two concurrent readers race for it; the unique index decides and the
        // loser reads what the winner wrote.
        try {
            $standard = new CurationPreset(CurationPreset::DEFAULT_NAME, CurationBriefFields::defaults(), true);
            $this->em->persist($standard);
            $this->em->flush();

            return $standard;
        } catch (UniqueConstraintViolationException) {
            $this->em->clear(CurationPreset::class);

            return $repo->findOneBy(['standard' => true])
                ?? throw new \RuntimeException('No Standard brief in this vault');
        }
    }

    /** The brief a connection runs under; the owner's own view falls back to Standard. */
    public function presetFor(?ApiToken $token): CurationPreset
    {
        return $token?->getCurationPreset() ?? $this->standard();
    }

    /** @return array{id: int|null, slug: string, title: string, description: string, body: string, updated_at: string} */
    public function skill(?ApiToken $token): array
    {
        $canon = $this->canon();
        $preset = $this->presetFor($token);

        return [
            'id' => null,
            'slug' => self::SLUG,
            'title' => $canon['title'],
            'description' => $canon['description'],
            'short' => $canon['short'] ?? $canon['description'],
            'body' => rtrim($canon['body'])."\n\n".self::briefText($preset),
            'updated_at' => $preset->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The two forms of the prompt a user carries to their agent.
     *
     * Composed here rather than in the browser so {@see self::briefText()}
     * stays the only implementation: a second one in TypeScript would drift,
     * and the thing it would drift from is what agents are actually served.
     *
     * @param array<string, mixed> $fields
     *
     * @return array{short: string, full: string}
     */
    public function prompts(string $profileName, array $fields): array
    {
        $brief = self::briefFrom($profileName, CurationBriefFields::normalize($fields));

        return [
            'short' => self::SHORT_PROMPT,
            'full' => self::SHORT_PROMPT."\n\n---\n\n".rtrim($this->canon()['body'])."\n\n".$brief,
        ];
    }

    public const SHORT_PROMPT = <<<'TXT'
        Load the memex-curation skill from memex and follow it.
        Note the time before you start and send it as started_at when you close the run with log.
        TXT;

    /**
     * The brief as the agent reads it. Generated from the fields rather than
     * stored as text, so editing a setting can never leave prose behind that
     * contradicts it.
     */
    public static function briefText(CurationPreset $preset): string
    {
        return self::briefFrom($preset->getName(), CurationBriefFields::normalize($preset->getFields()));
    }

    /** @param array<string, mixed> $f */
    private static function briefFrom(string $name, array $f): string
    {
        $lines = [
            '# Your brief — '.$name,
            '',
            'These are the operator\'s settings for this knowledge base, not your judgment to '
                .'revise. Where they narrow the canon above, they win.',
            '',
            '- **Notes per run: '.$f['notes_per_run'].'.** Pass it as `limit` and stop there.',
        ];

        $lines[] = $f['work_first'] === ''
            ? '- **Work the queue in the order it is ranked.** Take it from the top; do not filter to a band unless the run demands it.'
            : '- **Work `'.$f['work_first'].'` first.** Pass it as `reason`, and fall back to the ranked order once that band is empty.';

        $lines[] = '- **Settled notes come round after '.$f['cooldown_days'].' days.** Pass it as `cooldown_days`.'
            .($f['cooldown_days'] === 0 ? ' Zero means a deliberate re-sweep of everything.' : '');

        if ($f['never_touch_tags'] !== []) {
            $lines[] = '- **Never change a note tagged '.self::andList(array_map(
                static fn (string $t): string => '`'.$t.'`',
                $f['never_touch_tags'],
            )).'.** Read them if they are evidence; leave them exactly as they are. If one is genuinely broken, say so in your run summary rather than editing it.';
        }

        $lines[] = '- **Merges and renames: '.$f['boldness'].'.** '.match ($f['boldness']) {
            'conservative' => 'File one only when the case is unambiguous, and prefer leaving two notes over merging the wrong pair.',
            'bold' => 'Where the evidence supports it, file it — the operator reviews every merge and would rather refuse one than never see it.',
            default => 'File a merge when you would defend it to the operator in one line, and leave the marginal pairs alone.',
        };

        $lines[] = $f['report_back'] === []
            ? '- **Say nothing back in conversation beyond what the run needed.** The log is the record.'
            : '- **Tell the user, in conversation, what this run '.self::andList(array_map(
                static fn (string $k): string => match ($k) {
                    'examined' => 'examined',
                    'changed' => 'changed',
                    'filed' => 'filed for review',
                    'skipped' => 'skipped, and why',
                    default => 'observed about the collection',
                },
                $f['report_back'],
            )).'.** memex will never tell them a pass ran; only you can.';

        return implode("\n", $lines)."\n";
    }

    /** @param list<string> $items */
    private static function andList(array $items): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
