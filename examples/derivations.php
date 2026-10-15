<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Context\WireCodec;
use Rasuvaeff\Context\WireValue;

final readonly class TenantId implements WireValue
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z0-9-]{1,32}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid tenant id');
        }
    }

    public function toWire(): string
    {
        return $this->value;
    }

    public static function fromWire(string $wire): static
    {
        return new static($wire);
    }
}

$clock = new FakeTimeSource();
[$request, $requestController] = Context::background($clock)->withTimeout(1.0);
$request = $request->withValue(TenantId::class, new TenantId('acme'));

// Leave 200 ms to answer after the work below returns.
[$work] = $request->withReserve(0.2);
echo 'work budget: ' . $work->remainingMs() . " ms\n";                  // 800

// Process a batch, checking the context before pulling each element.
$processed = 0;

try {
    foreach ($work->iterate(range(1, 10)) as $item) {
        $processed++;
        $work->sleep(0.1);                                              // 100 ms per item
    }
} catch (ContextExpiredException) {
    echo "batch stopped by the reserve after {$processed} items\n";      // 8
}

// The worker shuts down; a joined context obeys both.
[$worker, $workerController] = Context::background($clock)->withCancel();
[$job] = $request->joinedWith($worker);
$workerController->requestCancel(new RuntimeException('SIGTERM'));      // safe in a signal handler
echo 'job cancelled: ' . var_export($job->isCancelled(), true) . "\n";  // true

// Cleanup that must run after the request ended, with its own budget.
$requestController->cancel();
[$cleanup] = $request->withoutCancel()->withTimeout(2.0);
echo 'cleanup sees tenant: ' . $cleanup->value(TenantId::class)?->value . "\n";

// Hand the remaining budget and the tenant to another process.
[$outgoing] = Context::background($clock)->withTimeout(0.75);
$codec = new WireCodec(['tenant' => TenantId::class]);
$wire = $codec->encode($outgoing->withValue(TenantId::class, new TenantId('acme')));
echo json_encode($wire, JSON_THROW_ON_ERROR) . "\n";                    // {"budget_ms":750,"values":{"tenant":"acme"}}

[$received] = $codec->decode($wire, Context::background(), maxBudgetMs: 30_000);
echo 'received tenant: ' . $received->value(TenantId::class)?->value . "\n";
