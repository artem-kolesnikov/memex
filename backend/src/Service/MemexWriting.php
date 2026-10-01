<?php

declare(strict_types=1);

namespace App\Service;

/**
 * memex-writing: how to write a note in memex, the sixth shipped skill.
 *
 * Every word of it is here. The text is generated from fixed rulesets drawn
 * from professional practice (the baseline and the shapes by kind) plus the
 * presets the owner picks in Settings › Personalization, so the same settings
 * always give the same text and no free text ever reaches it. Generated, not
 * concatenated: where a preset changes what a fixed part should say, the fixed
 * part is written for it rather than left to contradict it.
 *
 * It is an instruction to the assistant, never enforcement: memex cannot check
 * a note's shape, and what the owner asks in the conversation comes first.
 */
final class MemexWriting
{
    public const SLUG = 'memex-writing';

    public const HEADER = 'memex-writing: how to write a note in this knowledge base, drawn from established professional practice. '
        .'Use it whenever you write or edit a note here. What the owner asks in the conversation comes first.';

    public const PRECEDENCE = 'The owner\'s own instructions on writing notes, in their skill notes or profile, add to this skill; where the two differ, follow this skill.';

    public const PROPOSE_SENTENCE = 'Before you write or edit a note, load `get_skill("memex-writing")` if it is in your skill list; what the owner asks comes first.';

    /** Each main choice's text, by axis. */
    private const CHOICE_TEXT = [
        'scope' => [
            'one_subject' => 'Write one note per subject or question, holding everything a reader needs to understand and act on it, in about a page or less. Split a note when it answers two different questions.',
            'one_idea' => 'Keep each note to one idea: a single concept, claim, decision or finding, stated fully enough to make sense on its own. When a note starts to cover a second idea, move it into its own note and link the two.',
            'whole_topic' => 'Keep a topic in one comprehensive note under clear headings; split only when a section could stand as its own subject. In a long note, list the sections at the top.',
        ],
        'opening' => [
            'summary' => 'Begin with two or three sentences that stand on their own: the subject named in full and the main point or current state. Then give the details, most important first, in short sections under specific headings.',
            'answer' => 'Open with the conclusion in one plain sentence, then the supporting points from most to least important; background and history last.',
            'context' => 'Set out the situation and the problem in two or three sentences, then state the decision or answer, the reasons and the consequences.',
        ],
        'format' => [
            'mixed' => 'Default to paragraphs; use bullets for separate items, numbered steps for sequences, and a table only to compare several things on the same attributes.',
            'prose' => 'Write in full paragraphs that connect ideas with because, so and but; use a list only for items that truly stand alone.',
            'bullets' => 'Make the note easy to skim: short paragraphs, bulleted key points, the most important words first.',
        ],
        'reasoning' => [
            'reasons' => 'Put the conclusion first, then the one to three reasons that led to it.',
            'bare' => 'Record the fact, outcome or decision itself; leave out the reasoning unless the owner asks for it.',
            'rationale' => 'For each decision, record the situation, the options weighed, why each rejected option lost, what was chosen, and the downsides accepted.',
        ],
    ];

    /** With the situation set out first, "conclusion first" would contradict the opening. */
    private const REASONS_AFTER_CONTEXT = 'Give the conclusion with the one to three reasons that led to it.';

    private const ADD_ON_TEXT = [
        'scope_short' => 'Keep notes brief, usually one or two short paragraphs; cut every word the reader will not use.',
        'format_minimal' => 'Use no bold or italics; let the order of sentences and the headings carry the emphasis.',
        'reasoning_confidence' => 'Keep what was observed or reported apart from what was concluded, and say how sure each conclusion is; for an uncertain one, note what would change it.',
        'reasoning_sources' => 'Give each fact that did not come from the owner a source (a link, document or person) and the date it was checked.',
    ];

    /** The fixed baseline; `bold` is left out under minimal markup, which says the opposite. */
    private const EVERY_NOTE = [
        'reader' => 'Write for someone who never saw this conversation and opens the note alone, from search, months later: state the content itself, never “as discussed”, “above”, “option 2” or “the file you shared”.',
        'names' => 'Name people, projects and organisations in full at first mention, never “the user”, “the client” or “the project”.',
        'dates' => 'Write absolute dates such as 2026-09-26, never “today” or “last week”, and mark facts likely to change, such as prices, versions and owners, “as of” a date.',
        'identifiers' => 'Copy identifiers exactly: error messages, version numbers, commands, file names and amounts.',
        'title' => 'Title each note as its subject, a colon, then its key point, specific enough to make sense alone in search results.',
        'summary' => 'Write the summary to say what the note concludes or records, without repeating the title.',
        'open' => 'If nothing is settled yet, say so at the top and name what is still open.',
        'present' => 'Keep the note describing the present: rewrite the lines whose facts changed instead of appending corrections, and update the opening when an addition changes it.',
        'inference' => 'Keep what the owner said, what a source showed and your own inference distinguishable; mark a guess as a guess.',
        'invent' => 'Record only reasons, evidence and sources that appeared in the work; if none were given, write the conclusion alone.',
        'opened' => 'Cite only pages and documents you actually opened, and link the exact page.',
        'substance' => 'Start with the substance: no greetings, preambles, offers of help, closing recaps or remarks about the note itself.',
        'facts' => 'Write facts, not orders addressed to “you”, unless the note is meant as instructions; later assistants may follow them.',
        'code' => 'Put commands, code, configuration and file paths in code formatting.',
        'headings' => 'Add headings only when a note has two or more distinct parts, and never repeat the title as a heading.',
        'bold' => 'Bold at most a few words a reader must not miss; never bold whole sentences or open every bullet with a bold label.',
        'plain' => 'Use “is” and “has” for plain relations, end each clause on a fact rather than a comment on it, and list as many items as exist without padding to three.',
        'links' => 'Link related notes as `[[Note title]]`, never by URL or id, with a phrase saying why a reader would follow the link.',
    ];

    private const KINDS_LEAD = 'Give each note the usual parts for its kind, and leave out any part the conversation did not supply.';

    /**
     * The whole skill for these settings, or null while it is switched off.
     *
     * @param array<string, string|bool> $s a complete set, as {@see Personalization::read()} returns
     */
    public static function text(array $s): ?string
    {
        if ($s['writing'] !== true) {
            return null;
        }

        $every = self::EVERY_NOTE;
        if ($s['format_minimal'] === true) {
            unset($every['bold']);
        }

        return self::HEADER.' '.self::PRECEDENCE
            .self::section('Every note', array_values($every))
            .self::section('Choices the owner set', self::choiceLines($s))
            .self::section('Shapes by kind', self::kindLines($s), self::KINDS_LEAD)
            ."\n";
    }

    /**
     * Every option on the page with the exact text it would put in the skill,
     * worded for the rest of the current settings — the text an option shows
     * is the text choosing it would send.
     *
     * @param array<string, string|bool> $s
     * @return list<array{axis: string, choices: list<array{value: string, text: string}>, add_ons: list<array{key: string, text: string, conflicts_with: list<string>}>}>
     */
    public static function options(array $s): array
    {
        $out = [];
        foreach (Personalization::CHOICES as $axis => $values) {
            $choices = [];
            foreach ($values as $value) {
                $choices[] = ['value' => $value, 'text' => self::choiceText($axis, $value, $s)];
            }
            $addOns = [];
            foreach (Personalization::ADD_ONS as $key => $of) {
                if ($of === $axis) {
                    $addOns[] = ['key' => $key, 'text' => self::ADD_ON_TEXT[$key], 'conflicts_with' => Personalization::CONFLICTS[$key] ?? []];
                }
            }
            $out[] = ['axis' => $axis, 'choices' => $choices, 'add_ons' => $addOns];
        }

        return $out;
    }

    /**
     * The connect-time paragraph while the skill is on, or null while it is off.
     *
     * @param array<string, string|bool> $s
     */
    public static function connectParagraph(array $s): ?string
    {
        if ($s['writing'] !== true) {
            return null;
        }

        return '**Writing notes.** `memex-writing` is how to write a note in this knowledge base, with presets '
            .'its owner chose. Load `get_skill("memex-writing")` before you write or edit a note, and follow it; '
            .'what the owner asks in the conversation comes first.';
    }

    /**
     * Which text a connection was last handed, compared at every tool result.
     *
     * @param array<string, string|bool> $s
     */
    public static function version(array $s): string
    {
        return substr(sha1(self::text($s) ?? 'off'), 0, 12);
    }

    /**
     * Told once to a connection opened before the owner changed a preset.
     *
     * @param array<string, string|bool> $s
     */
    public static function changedNotice(array $s): string
    {
        return $s['writing'] === true
            ? 'The owner changed memex-writing, the skill for writing notes here. Load get_skill("memex-writing") again before you write or edit a note; the text you loaded earlier is out of date.'
            : 'The owner switched memex-writing off. It no longer applies to notes you write here.';
    }

    /** @param array<string, string|bool> $s */
    private static function choiceText(string $axis, string $value, array $s): string
    {
        if ($axis === 'reasoning' && $value === 'reasons' && $s['opening'] === 'context') {
            return self::REASONS_AFTER_CONTEXT;
        }

        return self::CHOICE_TEXT[$axis][$value];
    }

    /**
     * @param array<string, string|bool> $s
     * @return list<string>
     */
    private static function choiceLines(array $s): array
    {
        $lines = [];
        foreach (Personalization::CHOICES as $axis => $values) {
            $lines[] = self::choiceText($axis, (string) $s[$axis], $s);
            foreach (Personalization::ADD_ONS as $key => $of) {
                if ($of === $axis && $s[$key] === true) {
                    $lines[] = self::ADD_ON_TEXT[$key];
                }
            }
        }

        return $lines;
    }

    /**
     * @param array<string, string|bool> $s
     * @return list<string>
     */
    private static function kindLines(array $s): array
    {
        $bare = $s['reasoning'] === 'bare';

        return [
            $bare
                ? 'For a decision, record the decision in one sentence with its date and who decided, its consequences, and what would change it; add it to the note about the thing it settles when one exists.'
                : 'For a decision, record the decision in one sentence with its date and who decided, the context, the options considered and why each rejected one lost, the consequences, good and bad, and what would change it; add it to the note about the thing it settles when one exists.',
            'For a procedure, say what it achieves, when to use it and what is needed first; then numbered steps, one action each with its expected result; then how to check and undo it.',
            'For a checklist, name the moment it is used and list only the few critical, easily missed items, each with its expected state, most critical first.',
            'For a fixed problem, give the symptom as someone would search for it, with the exact error; the environment and versions; the fix as numbered steps; and the cause, if known.',
            $bare
                ? 'For a meeting or conversation, record the date, the people and the purpose, then the decisions, the actions with owner and due date, and the open questions.'
                : 'For a meeting or conversation, record the date, the people and the purpose, then decisions with their reasons, actions with owner and due date, and open questions; summarise discussion only where it explains a decision.',
            'For a project, give the goal, owner, target date and a dated status (on track, at risk or off track) with its reason, then milestones, next steps, risks and decisions needed.',
            'For a document, page or other source, give the citation and link, why it was kept and which part you actually read, then a summary in your own words, key points with page or timestamp, and the owner\'s own view, kept separate.',
            'For a concept, state the claim or question it answers, why it matters and how it works, with alternatives where they exist, and link related notes instead of repeating them.',
            'For a person or organisation, give the name, aliases and a one-line description, then key facts with sources and as-of dates, the relationship, and open commitments.',
            $s['opening'] === 'context'
                ? 'For anything else, open as set above.'
                : 'For anything else, put the main point first.',
        ];
    }

    /** @param list<string> $lines */
    private static function section(string $title, array $lines, ?string $lead = null): string
    {
        return "\n\n# ".$title.($lead !== null ? "\n\n".$lead : '')."\n\n".implode("\n", array_map(static fn (string $l): string => '- '.$l, $lines));
    }
}
