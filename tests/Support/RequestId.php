<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests\Support;

final readonly class RequestId
{
    public function __construct(public string $value) {}
}
