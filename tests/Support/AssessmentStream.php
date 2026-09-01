<?php

declare(strict_types=1);

namespace Ledger\Tests\Support;

use Ledger\Domain\Event\EventStream;
use Ledger\Infrastructure\EventSource\AssessmentScenarioSource;

/**
 * The brief's ten events, for tests.
 *
 * Delegates rather than transcribing: the stream is written down exactly once, in
 * AssessmentScenarioSource, because a second copy is a second chance to mistype 620.00 and a
 * green suite proving the wrong figures is worse than a red one.
 */
final class AssessmentStream
{
    public const ACC1 = AssessmentScenarioSource::ACC1;
    public const ACC2 = AssessmentScenarioSource::ACC2;

    /** In the order the brief lists them, E10 out of day order and all. */
    public static function asListed(): EventStream
    {
        return AssessmentScenarioSource::stream();
    }

    /** Each of the ten emitted twice, as a retry or an operator re-run would. */
    public static function withDuplicates(): EventStream
    {
        return AssessmentScenarioSource::streamWithDuplicates();
    }
}
