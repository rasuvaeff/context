<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Tests\Support;

use Rasuvaeff\Context\WireValue;

final readonly class Locale implements WireValue
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z]{2}\z/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid locale "%s"', $value));
        }
    }

    #[\Override]
    public function toWire(): string
    {
        return $this->value;
    }

    #[\Override]
    public static function fromWire(string $wire): static
    {
        return new self($wire);
    }
}
