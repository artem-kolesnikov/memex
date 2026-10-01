<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;

/**
 * The faces a connected assistant can wear, and the gate an uploaded one passes.
 *
 * ## Two catalogues, and why the second one exists
 *
 * The **glyphs** are neutral marks plus the four brands Font Awesome already
 * ships. They were once the whole of it, on the reasoning that vendor logos
 * drawn from memory would be worse than none — a wrong-looking Claude logo in
 * the repo is both a legal question and a lie about what the artwork is. That
 * note ended by saying real files, supplied deliberately, would belong here
 * beside them, because the catalogue is a list and not a limit.
 *
 * The operator supplied them on 2026-08-23, so the **logos** are the second
 * catalogue: twenty-two provider marks under a `logo:` prefix, shipped as files
 * under `frontend/public/logos/` with `NOTICE.md` recording, per file, what the
 * mark is, whose trademark it is, where the file came from and whether we
 * changed anything. Using a vendor's mark to identify that vendor's product is
 * ordinary nominative use; the record is what keeps it checkable.
 *
 * ## Why a logo is a pair of URLs and a glyph is a class
 *
 * A glyph inherits `currentColor`, so one entry serves both themes. A logo
 * cannot: the mark is the mark. Marks with real colour (Gemini, Claude,
 * Mistral…) read on either background from one file, and marks that are
 * monochrome by design (OpenAI, Anthropic, Grok…) need one file per
 * background. The entry therefore always answers with **two URLs**, equal when
 * one file does both, and the client shows one or the other by theme — in CSS,
 * so a theme switch does not need a round trip and the server never has to know
 * which theme a viewer is in.
 *
 * The paths point at the SPA's static root, not at an API route. That is the
 * same coupling `/api/tokens/{id}/icon` has in the other direction and it is
 * deliberate: one place decides what a mark is called.
 *
 * ## Why keys and not class names
 *
 * A stored `fa-solid fa-robot` would be a Font Awesome version away from
 * breaking, in a database column, on every team. `builtin:robot` is ours; the
 * mapping to a class lives in one place and can be changed.
 */
final class AgentIcons
{
    public const PREFIX = 'builtin:';

    /**
     * A second namespace rather than more `builtin:` keys, so a stored value
     * says which catalogue it came from. The two are drawn differently and can
     * be retired independently; one prefix would have made a logo removed from
     * a later build indistinguishable from a glyph removed from one.
     */
    public const LOGO_PREFIX = 'logo:';

    /**
     * The shipped marks, in the order the picker offers them.
     *
     * Grouped by what somebody is actually choosing between: something that
     * says "an assistant", something that says "a machine of mine", and the
     * handful of real logos we can legitimately draw.
     *
     * @var array<string, array{icon: string, label: string, group: string}>
     */
    private const CATALOGUE = [
        'robot' => ['icon' => 'fa-solid fa-robot', 'label' => 'Robot', 'group' => 'Assistants'],
        'brain' => ['icon' => 'fa-solid fa-brain', 'label' => 'Brain', 'group' => 'Assistants'],
        'comment' => ['icon' => 'fa-solid fa-comment-dots', 'label' => 'Chat', 'group' => 'Assistants'],
        'wand' => ['icon' => 'fa-solid fa-wand-magic-sparkles', 'label' => 'Wand', 'group' => 'Assistants'],
        'feather' => ['icon' => 'fa-solid fa-feather', 'label' => 'Feather', 'group' => 'Assistants'],
        'atom' => ['icon' => 'fa-solid fa-atom', 'label' => 'Atom', 'group' => 'Assistants'],

        'terminal' => ['icon' => 'fa-solid fa-terminal', 'label' => 'Terminal', 'group' => 'Machines'],
        'server' => ['icon' => 'fa-solid fa-server', 'label' => 'Server', 'group' => 'Machines'],
        'laptop' => ['icon' => 'fa-solid fa-laptop', 'label' => 'Laptop', 'group' => 'Machines'],
        'mobile' => ['icon' => 'fa-solid fa-mobile-screen', 'label' => 'Phone', 'group' => 'Machines'],
        'gear' => ['icon' => 'fa-solid fa-gears', 'label' => 'Gears', 'group' => 'Machines'],
        'bolt' => ['icon' => 'fa-solid fa-bolt', 'label' => 'Bolt', 'group' => 'Machines'],

        'flask' => ['icon' => 'fa-solid fa-flask', 'label' => 'Flask', 'group' => 'Work'],
        'book' => ['icon' => 'fa-solid fa-book-open', 'label' => 'Book', 'group' => 'Work'],
        'magnifier' => ['icon' => 'fa-solid fa-magnifying-glass', 'label' => 'Research', 'group' => 'Work'],
        'pen' => ['icon' => 'fa-solid fa-pen-nib', 'label' => 'Writing', 'group' => 'Work'],
        'broom' => ['icon' => 'fa-solid fa-broom', 'label' => 'Curation', 'group' => 'Work'],
        'star' => ['icon' => 'fa-solid fa-star', 'label' => 'Star', 'group' => 'Work'],

        // Sign-in providers rather than assistants, and they stay here rather
        // than moving to the logo catalogue below: they are the same four marks
        // the sign-in screen uses, from the dependency we already have.
        'github' => ['icon' => 'fa-brands fa-github', 'label' => 'GitHub', 'group' => 'Logos'],
        'google' => ['icon' => 'fa-brands fa-google', 'label' => 'Google', 'group' => 'Logos'],
        'microsoft' => ['icon' => 'fa-brands fa-microsoft', 'label' => 'Microsoft', 'group' => 'Logos'],
        'apple' => ['icon' => 'fa-brands fa-apple', 'label' => 'Apple', 'group' => 'Logos'],
    ];

    /** Where the shipped logo files are served from — the SPA's static root. */
    private const LOGO_PATH = '/logos/';

    /**
     * The provider marks, in the order the picker offers them.
     *
     * Ordered by what somebody is most likely to be naming: the assistants that
     * actually connect first, then the rest, then memex itself. `file` means
     * one image serves both themes; `light`/`dark` name the file for a light
     * and a dark background respectively. Nothing here is grouped, because the
     * picker separates logos from glyphs with a tab and a second division
     * inside that would be one too many.
     *
     * memex's own mark is in the list on purpose: it is the natural face for the
     * connection that does the curation work.
     *
     * @var array<string, array{label: string, file?: string, light?: string, dark?: string}>
     */
    private const LOGOS = [
        'claude' => ['label' => 'Claude', 'file' => 'claude.svg'],
        'claude-code' => ['label' => 'Claude Code', 'file' => 'claude-code.png'],
        'anthropic' => ['label' => 'Anthropic', 'light' => 'anthropic-on-light.svg', 'dark' => 'anthropic-on-dark.svg'],
        'chatgpt' => ['label' => 'ChatGPT', 'light' => 'chatgpt-on-light.svg', 'dark' => 'chatgpt-on-dark.svg'],
        'codex' => ['label' => 'Codex', 'file' => 'codex.svg'],
        'openai' => ['label' => 'OpenAI', 'light' => 'openai-on-light.svg', 'dark' => 'openai-on-dark.svg'],
        'gemini' => ['label' => 'Gemini', 'file' => 'gemini.svg'],
        'notebooklm' => ['label' => 'NotebookLM', 'light' => 'notebooklm-on-light.svg', 'dark' => 'notebooklm-on-dark.svg'],
        'deepmind' => ['label' => 'Google DeepMind', 'file' => 'deepmind.svg'],
        'github-copilot' => ['label' => 'GitHub Copilot', 'light' => 'github-copilot-on-light.svg', 'dark' => 'github-copilot-on-dark.png'],
        'microsoft-copilot' => ['label' => 'Microsoft Copilot', 'file' => 'microsoft-copilot.svg'],
        'grok' => ['label' => 'Grok', 'light' => 'grok-on-light.svg', 'dark' => 'grok-on-dark.svg'],
        'deepseek' => ['label' => 'DeepSeek', 'file' => 'deepseek.svg'],
        'qwen' => ['label' => 'Qwen', 'file' => 'qwen.svg'],
        'kimi' => ['label' => 'Kimi', 'light' => 'kimi-on-light.svg', 'dark' => 'kimi-on-dark.svg'],
        'minimax' => ['label' => 'MiniMax', 'file' => 'minimax.svg'],
        'mistral' => ['label' => 'Mistral', 'file' => 'mistral.svg'],
        'ollama' => ['label' => 'Ollama', 'light' => 'ollama-on-light.svg', 'dark' => 'ollama-on-dark.svg'],
        'perplexity' => ['label' => 'Perplexity', 'file' => 'perplexity.png'],
        'openclaw' => ['label' => 'OpenClaw', 'file' => 'openclaw.svg'],
        'hermes' => ['label' => 'Hermes', 'light' => 'hermes-on-light.svg', 'dark' => 'hermes-on-dark.svg'],
        'memex' => ['label' => 'memex', 'file' => 'memex.svg'],
    ];

    /** @return array<int, array{key: string, icon: string, label: string, group: string}> */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $key => $entry) {
            $out[] = ['key' => self::PREFIX.$key, ...$entry];
        }

        return $out;
    }

    /**
     * The provider marks, as the picker's Logos tab receives them.
     *
     * Both URLs are always present and are equal when one file serves both
     * themes, so the client has one shape to render and no branch of its own.
     *
     * @return array<int, array{key: string, label: string, light_url: string, dark_url: string}>
     */
    public static function logos(): array
    {
        $out = [];
        foreach (self::LOGOS as $key => $entry) {
            $light = $entry['file'] ?? $entry['light'];
            $dark = $entry['file'] ?? $entry['dark'];
            $out[] = [
                'key' => self::LOGO_PREFIX.$key,
                'label' => $entry['label'],
                'light_url' => self::LOGO_PATH.$light,
                'dark_url' => self::LOGO_PATH.$dark,
            ];
        }

        return $out;
    }

    /** Whether a stored `icon_key` names a mark this build still ships — glyph or logo. */
    public static function isShipped(string $key): bool
    {
        return self::isGlyph($key) || self::isLogo($key);
    }

    /** Whether the key names a GLYPH — the set a person may wear as a face. */
    public static function isGlyph(string $key): bool
    {
        return str_starts_with($key, self::PREFIX)
            && isset(self::CATALOGUE[substr($key, strlen(self::PREFIX))]);
    }

    private static function isLogo(string $key): bool
    {
        return str_starts_with($key, self::LOGO_PREFIX)
            && isset(self::LOGOS[substr($key, strlen(self::LOGO_PREFIX))]);
    }

    /**
     * How a mark is drawn, whatever kind it is — the one producer.
     *
     * There are three kinds now (a glyph, an uploaded image, a shipped logo)
     * and at least four places that draw one: the connections table, the
     * picker, and both byline paths. Every caller asks here and gets the same
     * three fields, so a new kind is added once. This codebase has already paid
     * for the other arrangement — the notes list and the note page built a
     * byline two different ways and disagreed about the name.
     *
     * `$uploadUrl` is the caller's business because only it knows the route.
     * A dark URL equal to the light one is normal, not a special case.
     *
     * @return array{icon: ?string, icon_url: ?string, icon_url_dark: ?string}
     */
    public static function markFor(?string $key, ?string $uploadUrl = null): array
    {
        if ($uploadUrl !== null) {
            return ['icon' => null, 'icon_url' => $uploadUrl, 'icon_url_dark' => $uploadUrl];
        }
        $logo = self::urlsFor($key);
        if ($logo !== null) {
            return ['icon' => null, 'icon_url' => $logo['light'], 'icon_url_dark' => $logo['dark']];
        }

        return ['icon' => self::classesFor($key), 'icon_url' => null, 'icon_url_dark' => null];
    }

    /**
     * The two image URLs for a logo key, or null for anything else.
     *
     * Null covers a glyph, an upload, and a logo dropped from a later build —
     * the same "render nothing rather than a broken box" contract
     * {@see classesFor()} has, for the same reason.
     *
     * @return array{light: string, dark: string}|null
     */
    public static function urlsFor(?string $key): ?array
    {
        if ($key === null || !self::isLogo($key)) {
            return null;
        }
        $entry = self::LOGOS[substr($key, strlen(self::LOGO_PREFIX))];

        return [
            'light' => self::LOGO_PATH.($entry['file'] ?? $entry['light']),
            'dark' => self::LOGO_PATH.($entry['file'] ?? $entry['dark']),
        ];
    }

    /**
     * The Font Awesome classes for a key, or null.
     *
     * Null for a key this build does not know — a mark removed from the
     * catalogue after somebody chose it. The caller renders nothing rather
     * than an empty box, which is the same outcome as never having set one.
     */
    public static function classesFor(?string $key): ?string
    {
        if ($key === null || !self::isGlyph($key)) {
            return null;
        }

        return self::CATALOGUE[substr($key, strlen(self::PREFIX))]['icon'];
    }

    /**
     * Turn an uploaded file into the PNG that gets stored, or explain why not.
     *
     * **Re-encoded rather than validated.** Checking a magic number tells you
     * the first eight bytes look like a PNG; decoding the whole thing and
     * writing a new one from the pixels is what makes the stored bytes provably
     * an image. It also drops every chunk we did not put there — EXIF, colour
     * profiles, and anything hiding in a comment — which is the half of this
     * that is about safety rather than tidiness.
     *
     * Output is always PNG, whatever came in, so the serving path has one
     * content type and no branch.
     *
     * @throws \InvalidArgumentException with a message meant for the operator
     */
    /**
     * A PHOTOGRAPH, for a person's avatar. JPEG rather than PNG, and that is
     * the whole point: PNG is lossless, so a 160 KB photo re-encodes to several
     * hundred KB and is refused for being "too detailed" — which was true of
     * the format, never of the picture. Quality steps down before anything is
     * refused, so a refusal means the image is genuinely unusable rather than
     * merely large.
     *
     * Flattened onto white because the result is drawn inside a circle, where
     * transparency has nothing to show through to.
     */
    public static function normalisePhoto(string $bytes, int $size, int $maxBytes): string
    {
        $source = self::decode($bytes);
        $source = self::uprightJpeg($source, $bytes);

        try {
            foreach ([88, 74, 60, 45] as $quality) {
                $jpeg = self::toJpeg($source, $size, $quality);
                if (strlen($jpeg) <= $maxBytes) {
                    return $jpeg;
                }
            }
        } finally {
            imagedestroy($source);
        }

        throw new \InvalidArgumentException('That image could not be made small enough. Try a smaller one.');
    }

    /**
     * The most pixels memex will decode.
     *
     * The compressed-size gate does not bound this: a valid 10,000x10,000 PNG
     * of one flat colour is under 100 KB and 100 million pixels, and GD wants
     * four bytes of memory for each of them. The ceiling is set against the
     * box's 128 MB `memory_limit` rather than against any camera — a photograph
     * larger than this already exhausted the worker instead of being refused,
     * so this turns a 500 into a sentence.
     */
    private const MAX_PIXELS = 20_000_000;

    /** The shared gate: it is an image, it is a kind we read, and GD can decode it. */
    private static function decode(string $bytes): \GdImage
    {
        if ($bytes === '') {
            throw new \InvalidArgumentException('That file is empty.');
        }
        // Cheap gate before handing anything to the decoder: a file far larger
        // than anything worth storing cannot become one, and refusing it here
        // costs nothing.
        if (strlen($bytes) > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('That file is larger than 8 MB.');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new \InvalidArgumentException('That does not look like an image memex can read. PNG or JPEG, please.');
        }
        if (!in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new \InvalidArgumentException('It must be a PNG or a JPEG.');
        }
        // Before the decode, because the decode is what it protects.
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new \InvalidArgumentException(sprintf(
                'That image is %dx%d, which is more than memex will open. Try one under %d megapixels.',
                $info[0],
                $info[1],
                (int) (self::MAX_PIXELS / 1_000_000),
            ));
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new \InvalidArgumentException('That image could not be decoded.');
        }

        return $source;
    }

    /**
     * A phone writes the sensor's pixels and an EXIF tag saying which way up
     * they go. GD reads the pixels and not the tag, so a portrait photograph
     * is stored on its side unless the rotation is applied here.
     *
     * `exif_read_data` is guarded rather than required: a box without the
     * extension stores the picture unrotated, which is what happened before.
     */
    private static function uprightJpeg(\GdImage $source, string $bytes): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $source;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0;

        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        $mirrored = in_array($orientation, [2, 4, 5, 7], true);
        if ($angle === 0 && !$mirrored) {
            return $source;
        }

        $out = $source;
        if ($angle !== 0) {
            $rotated = imagerotate($out, $angle, 0);
            if ($rotated === false) {
                return $source;
            }
            imagedestroy($out);
            $out = $rotated;
        }
        if ($mirrored) {
            imageflip($out, IMG_FLIP_HORIZONTAL);
        }

        return $out;
    }

    private static function toJpeg(\GdImage $source, int $max, int $quality): string
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1.0, $max / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($tw, $th);
        imagefilledrectangle($out, 0, 0, $tw, $th, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $source, 0, 0, 0, 0, $tw, $th, $w, $h);

        ob_start();
        imagejpeg($out, null, $quality);
        $jpeg = (string) ob_get_clean();
        imagedestroy($out);

        return $jpeg;
    }

    public static function normaliseUpload(
        string $bytes,
        int $size = ApiToken::ICON_SIZE,
        int $maxBytes = ApiToken::MAX_ICON_BYTES,
    ): string {
        if ($bytes === '') {
            throw new \InvalidArgumentException('That file is empty.');
        }
        // Cheap gate before handing anything to the decoder: a file far larger
        // than the stored ceiling cannot become an icon, and refusing it here
        // costs nothing.
        if (strlen($bytes) > 4 * 1024 * 1024) {
            throw new \InvalidArgumentException('That file is larger than 4 MB. Icons are small — try a 256×256 PNG.');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new \InvalidArgumentException('That does not look like an image memex can read. PNG or JPEG, please.');
        }
        if (!in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new \InvalidArgumentException('Icons must be PNG or JPEG.');
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new \InvalidArgumentException('That image could not be decoded.');
        }

        try {
            $png = self::toSquarePng($source, $size);
        } finally {
            imagedestroy($source);
        }

        if (strlen($png) > $maxBytes) {
            // Reachable in principle — a photographic 256×256 PNG can be large.
            // Named rather than silently re-compressed, because an icon that
            // quietly became mush is worse than one that was refused.
            throw new \InvalidArgumentException(
                'That image is too detailed to store as an icon. A flat logo works better than a photograph.'
            );
        }

        return $png;
    }

    /**
     * Scale to fit {@see ApiToken::ICON_SIZE} and re-encode, preserving
     * transparency and never enlarging.
     *
     * Fit rather than crop: a logo is the one kind of image where cutting off
     * the edges to fill a square is exactly the wrong thing to do.
     */
    private static function toSquarePng(\GdImage $source, int $max): string
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1.0, $max / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($tw, $th);
        // Without these an image with alpha comes out on a black square.
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefilledrectangle($out, 0, 0, $tw, $th, $transparent);
        imagecopyresampled($out, $source, 0, 0, 0, 0, $tw, $th, $w, $h);

        ob_start();
        imagepng($out, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($out);

        return $png;
    }
}
