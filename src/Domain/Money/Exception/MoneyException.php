<?php

declare(strict_types=1);

namespace Ledger\Domain\Money\Exception;

/** Base for every failure originating in the money layer. */
abstract class MoneyException extends \DomainException
{
}
