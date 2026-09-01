<?php

declare(strict_types=1);

namespace Ledger\Domain\Event\Exception;

final class InvalidEventId extends EventException
{
    public static function blank(): self
    {
        return new self('An event id cannot be blank. Every event must be identifiable.');
    }
}
