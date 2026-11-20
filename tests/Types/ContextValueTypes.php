<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests\Types;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Tests\Support\TenantId;

/**
 * Never executed: psalm checks that `value()` keeps the type of its key.
 */
final readonly class ContextValueTypes
{
    public function valueHasTheTypeOfItsKey(Context $context): ?string
    {
        return $context->value(TenantId::class)?->value;
    }
}
