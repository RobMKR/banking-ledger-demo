<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Event;

use Ledger\Domain\Event\EventOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventOutcome::class)]
final class EventOutcomeTest extends TestCase
{
    /** @return iterable<string, array{EventOutcome, bool, bool, bool}> */
    public static function outcomes(): iterable
    {
        //                                       rejection  error  changed the ledger
        yield 'posted'    => [EventOutcome::POSTED,                     false, false, true];
        yield 'approved'  => [EventOutcome::APPROVED,                   false, false, false];
        yield 'declined'  => [EventOutcome::DECLINED,                   false, false, false];
        yield 'orphan'    => [EventOutcome::REJECTED_ORPHAN_SETTLEMENT, true,  true,  false];
        yield 'duplicate' => [EventOutcome::REJECTED_DUPLICATE_EVENT_ID, true, true,  false];
        yield 'invalid'   => [EventOutcome::REJECTED_INVALID_EVENT,     true,  true,  false];
    }

    #[DataProvider('outcomes')]
    public function testClassifiesEveryOutcome(
        EventOutcome $outcome,
        bool $rejection,
        bool $error,
        bool $changedTheLedger,
    ): void {
        self::assertSame($rejection, $outcome->isRejection());
        self::assertSame($error, $outcome->isError());
        self::assertSame($changedTheLedger, $outcome->changedTheLedger());
    }

    /**
     * A decline is not an error, and the distinction is not cosmetic. Auth-B declining is the
     * non-negotiable available-balance rule working exactly as specified; filing it under
     * errors would report correct behaviour as a fault.
     */
    public function testADeclineIsNotAnError(): void
    {
        self::assertFalse(EventOutcome::DECLINED->isError());
        self::assertFalse(EventOutcome::DECLINED->isRejection());
    }

    /** Only a POSTED event wrote to the ledger. Approvals reserve; rejections do nothing. */
    public function testOnlyPostingChangesTheLedger(): void
    {
        $changed = array_filter(
            EventOutcome::cases(),
            static fn (EventOutcome $o): bool => $o->changedTheLedger(),
        );

        self::assertSame([EventOutcome::POSTED], array_values($changed));
    }
}
