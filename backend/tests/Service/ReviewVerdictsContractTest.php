<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `ReviewVerdicts` passes `NoteWriter`'s result straight back (2026-08-20).
 *
 * Written after breaking it. Extracting the verdict logic out of three
 * controllers, an approve method was given the return type `int` while the
 * `NoteWriter` call behind it returns an array. PHP checks a declared return
 * type at the moment of returning — **after** the method body has run — so the
 * work applied in full, the notes were written, the rows were deleted, and THEN
 * the request died with a TypeError and a 500. The operator saw "nothing
 * changed" over three notes that had just changed, clicked again, and got a 404
 * because the row was already gone.
 *
 * Nothing caught it: 110 tests passed, `lint:container` passed, and the path
 * that applies a proposal needs a database, so it is not exercised by this
 * suite. What CAN be checked without one is the delegation contract itself,
 * which is where the mistake actually lived.
 */
final class ReviewVerdictsContractTest extends TestCase
{
    /**
     * @return array<string, array{string, string}> verdict method => writer method it hands back
     */
    public static function delegations(): array
    {
        return [
            'approveProposal delegates to applyProposal' => ['approveProposal', 'applyProposal'],
        ];
    }

    /** @dataProvider delegations */
    public function testTheDeclaredReturnTypeMatchesWhatItHandsBack(string $verdictMethod, string $writerMethod): void
    {
        $returns = (new ReflectionMethod(ReviewVerdicts::class, $verdictMethod))->getReturnType();
        $source = (new ReflectionMethod(NoteWriter::class, $writerMethod))->getReturnType();

        self::assertNotNull($returns, ReviewVerdicts::class."::$verdictMethod has no declared return type");
        self::assertNotNull($source, NoteWriter::class."::$writerMethod has no declared return type");
        self::assertSame(
            (string) $source,
            (string) $returns,
            "ReviewVerdicts::$verdictMethod returns NoteWriter::$writerMethod's result unchanged, so the two "
            .'declarations must agree. A mismatch does not fail early — the work commits first and the '
            .'TypeError fires on the way out, which reads to the operator as "nothing changed" over data '
            .'that has already changed.'
        );
    }

    public function testTheVerdictMethodsThatReturnNothingSaySo(): void
    {
        // The reject and note paths hand nothing back. An accidental return type
        // here would be the same trap in the other direction.
        foreach (['rejectProposal', 'approveNote', 'rejectNote'] as $method) {
            $type = (new ReflectionMethod(ReviewVerdicts::class, $method))->getReturnType();
            self::assertSame('void', (string) $type, "ReviewVerdicts::$method should return void");
        }
    }
}
