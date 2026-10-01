<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserName;
use PHPUnit\Framework\TestCase;

/**
 * The label on a session row. It has one job — letting somebody recognise
 * their own machine — and the failure that matters is a confident wrong
 * answer, so the cases here are the ones where browsers impersonate each
 * other.
 */
class BrowserNameTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function agents(): array
    {
        return [
            'Chrome on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Chrome on macOS',
            ],
            // Edge says Chrome and Safari as well, and comes last in the string.
            'Edge is not Chrome' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0',
                'Edge on Windows',
            ],
            'Opera is not Chrome' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 OPR/112.0.0.0',
                'Opera on Windows',
            ],
            // Chrome says Safari; only real Safari has no other claim.
            'Safari on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
                'Safari on macOS',
            ],
            // An iPhone's user agent says "like Mac OS X"; the phone must win.
            'Safari on iPhone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
                'Safari on iPhone',
            ],
            // Chrome on iOS is CriOS, and it is still Chrome to the person holding it.
            'Chrome on iPhone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/127.0.0.0 Mobile/15E148 Safari/604.1',
                'Chrome on iPhone',
            ],
            'Firefox on Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:129.0) Gecko/20100101 Firefox/129.0',
                'Firefox on Linux',
            ],
            // Android's user agent also says Linux; the phone must win.
            'Chrome on Android' => [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36',
                'Chrome on Android',
            ],
        ];
    }

    /** @dataProvider agents */
    public function testItNamesWhatPeopleActuallySignInWith(string $userAgent, string $expected): void
    {
        self::assertSame($expected, BrowserName::describe($userAgent));
    }

    public function testAnythingItDoesNotKnowIsAdmittedRatherThanGuessed(): void
    {
        // A wrong label is worse than none: this is the row somebody reads
        // before deciding whether a session is theirs.
        self::assertSame('Unknown browser', BrowserName::describe('curl/8.7.1'));
        self::assertSame('Unknown browser', BrowserName::describe(''));
        self::assertSame('Unknown browser', BrowserName::describe(null));
        // Even when the platform is recognisable, a browser we cannot name
        // makes "on Windows" a half-answer that reads like a whole one.
        self::assertSame('Unknown browser', BrowserName::describe('SomeBot/1.0 (Windows NT 10.0)'));
    }

    public function testAHeaderCannotWriteANovelIntoTheColumn(): void
    {
        $long = str_repeat('a', BrowserName::MAX * 3);
        self::assertSame(BrowserName::MAX, mb_strlen((string) BrowserName::store($long)));
        self::assertNull(BrowserName::store('   '));
    }
}
