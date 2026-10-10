<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Rasuvaeff\Context\Context;

final readonly class CorrelationId
{
    public function __construct(public string $value) {}
}

[$context, $controller] = Context::background()->withTimeout(2.0);
$context = $context->withValue(CorrelationId::class, new CorrelationId('demo'));

echo $context->value(CorrelationId::class)?->value . PHP_EOL;
echo $context->remainingMs() . PHP_EOL;

$controller->cancel();
echo $context->isCancelled() ? "cancelled\n" : "active\n";
