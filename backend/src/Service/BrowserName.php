<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns a user-agent string into something a person recognises: "Chrome on
 * macOS", "Safari on iPhone".
 *
 * This exists because the session list has exactly one job — letting somebody
 * look at a row and say *that one is not me* — and a 180-character user-agent
 * string defeats it. So the raw string is what we store, and this is only for
 * display.
 *
 * ## It is deliberately shallow, and says so when it fails
 *
 * User-agent parsing is a tar pit with no bottom: every browser lies about
 * being every other browser, and the good libraries carry a regularly-updated
 * database of thousands of patterns. We are not doing that for a label. This
 * covers what people actually sign in with, in the order that matters (Edge
 * claims to be Chrome, Chrome claims to be Safari, so the specific cases go
 * first), and anything it does not recognise comes back as "Unknown browser"
 * rather than as a guess.
 *
 * A wrong label here is worse than no label: the whole control is somebody
 * deciding whether to end a session, and "Chrome on Windows" against a machine
 * they have never owned is exactly the wrong thing to be confidently wrong
 * about.
 */
final class BrowserName
{
    /** Longest we will read. A user agent is a header; a header can be anything. */
    public const MAX = 512;

    /** Ordered: the first match wins, because these strings impersonate each other. */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'EdgiOS/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'YaBrowser/' => 'Yandex Browser',
        'Vivaldi/' => 'Vivaldi',
        'Brave/' => 'Brave',
        'FxiOS/' => 'Firefox',
        'Firefox/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chromium/' => 'Chromium',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /**
     * Ordered for the same reason: an iPhone's user agent says "like Mac OS X",
     * and Android's says "Linux".
     */
    private const PLATFORMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'CrOS' => 'ChromeOS',
        'Windows NT' => 'Windows',
        'Macintosh' => 'macOS',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    /**
     * Something the owner of the account can recognise, or an honest admission
     * that we do not know.
     */
    public static function describe(?string $userAgent): string
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return 'Unknown browser';
        }

        $browser = null;
        foreach (self::BROWSERS as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $browser = $name;
                break;
            }
        }

        $platform = null;
        foreach (self::PLATFORMS as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $platform = $name;
                break;
            }
        }

        if ($browser === null) {
            // No recognised browser. Naming the platform alone would suggest we
            // know more than we do, so it is only offered as the qualifier.
            return 'Unknown browser';
        }

        return $platform === null ? $browser : $browser.' on '.$platform;
    }

    /** What we are willing to keep, so a header cannot write a novel into a column. */
    public static function store(?string $userAgent): ?string
    {
        $ua = trim((string) $userAgent);

        return $ua === '' ? null : mb_substr($ua, 0, self::MAX);
    }
}
