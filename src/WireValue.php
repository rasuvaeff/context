<?php

declare(strict_types=1);

namespace Rasuvaeff\Context;

/**
 * A context value that may cross a process boundary through {@see WireCodec}.
 * `fromWire()` receives untrusted input: validate and throw on anything
 * unexpected — the codec then drops the value.
 *
 * @api
 */
interface WireValue
{
    public function toWire(): string;

    public static function fromWire(string $wire): static;
}
