# rasuvaeff/context

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/context/v)](https://packagist.org/packages/rasuvaeff/context)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/context/downloads)](https://packagist.org/packages/rasuvaeff/context)
[![Build](https://github.com/rasuvaeff/context/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/context/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/context/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/context/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/context/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/context/php)](https://packagist.org/packages/rasuvaeff/context)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Deadline, cancellation and request-scoped values for PHP

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Requirements

- PHP 8.3+
- No runtime dependencies

## Installation

```bash
composer require rasuvaeff/context
```

## Limitations

The context **stops work; it does not interrupt it.** It guarantees two
things: code that checks the context does not *start* a step after the budget
is gone, and a caller does not *wait* past the budget when the transport maps
`remainingMs()` to its own timeout. Aborting a call that is already in flight
is possible only where the transport supports it:

| Transport | Budget → timeout | Abort an in-flight call |
|---|---|---|
| Retries, pipelines, batches, your own loops | — | Yes: `assertActive()` between steps and attempts |
| Queue consumers | Message TTR → `withTimeout()` | Yes, in your own loop |
| HTTP via Guzzle | Per-request `timeout` = remaining budget ([`rasuvaeff/context-http`](https://github.com/rasuvaeff/context-http)) | No for a synchronous call — it returns at its own timeout |
| HTTP via a generic PSR-18 client | No: PSR-18 has no per-request options — configure the client | No |
| ClickHouse over HTTP | `max_execution_time` ([`rasuvaeff/clickhouse-toolkit`](https://github.com/rasuvaeff/clickhouse-toolkit)) | The server enforces `max_execution_time` |
| MySQL / PostgreSQL via PDO | No: PDO has no per-query timeout (connect only) | Only out of band (`KILL QUERY`, `pg_cancel_backend()`) — not provided |
| Redis | Only by lowering the client's read timeout | No for blocking commands (`BLPOP`) |

Deadlines are **lazy**: there is no background timer. An expired deadline is
discovered by the next `assertActive()`, `remainingMs()`, `isTerminated()` or
`isExpired()`; if nothing observes the context, its `onTerminate()` listeners
never run. Check before every step, not once at the top.

`cancel()` runs listeners synchronously in the code that calls it. A
single-threaded PHP request blocked inside a call cannot cancel itself at the
same time, so a listener helps only once control returns — after a transport
timeout, between steps, or under a fiber/event loop. From a signal handler use
`requestCancel()`, which runs nothing until the next check.

## Usage

A `Context` is an immutable, read-only view passed down the call graph: a
deadline, a cancellation signal and request-scoped values. The owner of a
cancellable branch keeps the matching `ContextController`. Cancellation is
cooperative: code checks the context between steps; nothing is interrupted
mid-call.

### Create and derive

```php
use Rasuvaeff\Context\Context;

$root = Context::background();                       // never terminates by itself

[$request, $requestController] = $root->withTimeout(3.0);   // seconds
[$step, $stepController] = $request->withCancel();          // cancellable branch
[$capped] = $request->withDeadline($absoluteNs);            // monotonic hrtime() ns

$traced = $request->withValue(RequestId::class, new RequestId('r-42'));
$traced->value(RequestId::class)?->value;                    // 'r-42' (nearest ancestor wins)

[$work] = $request->withReserve(0.2);                        // the parent deadline minus 200 ms
$cleanup = $request->withoutCancel();                        // values only: outlives the request
[$job, $jobController] = $message->joinedWith($worker);      // terminates when either does
```

A child deadline is never later than its parent's: `withTimeout()` and
`withDeadline()` take the earlier of the two. A zero or negative timeout is
an immediate deadline; an oversized one saturates at `PHP_INT_MAX` instead of
overflowing.

- **`withReserve($seconds)`** leaves time to act after lower layers return —
  answer 504, NACK a message, save progress. Without a parent deadline it is
  `withCancel()`; a reserve covering the whole budget is already expired.
- **`withoutCancel()`** keeps the values but drops the deadline and the
  cancellation: for audit, compensation and error logging in `finally` after
  the request ended. It has no budget — bound it:
  `[$cleanup] = $request->withoutCancel()->withTimeout(2.0)`.
- **`joinedWith($other)`** obeys two independent sources: a worker's shutdown
  context and a message context received from elsewhere. The first cause
  wins, the deadline is the earlier one, values come from `$this` only. When
  both belong to one tree, derive instead — `$worker->withTimeout($ttr)`
  already obeys both.

### Check

```php
$request->assertActive();        // throws ContextCancelledException / ContextExpiredException
$request->isTerminated();        // cancelled or expired
$request->isCancelled();         // explicit cancellation
$request->isExpired();           // deadline passed
$request->remainingMs();         // null without a deadline; >= 1.0 while active, 0.0 once expired
```

Deadlines use monotonic `hrtime()` and are observed lazily: the next
`assertActive()`, `remainingMs()`, `isTerminated()` or `isExpired()` call
discovers an expired deadline. If the deadline belongs to an ancestor, the
ancestor and its whole subtree are terminated. A controller cancellation
propagates to every descendant immediately. A check costs about 0.2 µs.

```php
$request->sleep(0.5);            // never past the deadline: throws when it comes first

foreach ($request->iterate($rows) as $key => $row) {
    process($row);               // checked before pulling each element
}
```

`sleep()` throws `ContextExpiredException` when the deadline comes before the
end of the sleep and `ContextCancelledException` when a signal handler
requests cancellation meanwhile. `iterate()` keeps keys and checks the context
**before pulling** the next element, so a generator source (a paginated query)
is never advanced after the context terminates; pass `every: 100` for very
cheap elements.

### Cancel and clean up

```php
$unsubscribe = $request->onTerminate(static function (TerminationCause $cause): void {
    // release a resource owned by this operation
});

$unsubscribe();                  // the resource was released early: drop the listener
$requestController->cancel($reason);   // $reason becomes the exception's previous
```

`onTerminate()` runs the listener once when the context terminates
(immediately if it already has) and returns a closure that unregisters it.
A terminated context detaches from its parent and releases its listeners, so
a long-lived parent does not accumulate per-request children. Always cancel
the controller you own in `finally`.

A listener that throws does not stop the others or the cascade; its exception
is rethrown after the cascade, from `cancel()` or from the check that
discovered the termination.

**From a signal handler, never call `cancel()`** — it would run listeners at
an arbitrary point of the interrupted code. `requestCancel()` only records the
reason; the next check of the context (or of a descendant) terminates it, so
listeners run, and their failures surface, in the normal flow:

```php
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static fn() => $workerController->requestCancel(new \RuntimeException('SIGTERM')));
```

`rasuvaeff/context-cli` wraps this with handler restoration and a forced exit
on the second signal.

### Queue workers

Create a context per message with the job's TTR as the deadline, check it
between external calls and acknowledge only when the job completes:

```php
[$jobContext, $jobController] = Context::background()->withTimeout($ttrSeconds);
try {
    foreach ($steps as $step) {
        $jobContext->assertActive();
        $step($jobContext);
    }
    $queue->ack($message);
} catch (ContextExpiredException) {
    $queue->nack($message); // let the queue decide retry/dead-letter
} finally {
    $jobController->cancel();
}
```

### Crossing a process boundary

Deadlines are monotonic nanoseconds of the current process and mean nothing
elsewhere. Send the remaining budget and rebuild it on the other side:

```php
// sender: an HTTP header or a queue message attribute
$headers['X-Request-Timeout-Ms'] = (string) (int) $context->remainingMs();

// receiver: never longer than its own limit
[$context] = Context::background()->withTimeout(min($limitSeconds, $headerMs / 1000));
```

Validate the incoming value (an integer in a bounded range) before trusting
it. `rasuvaeff/context-http` implements this for PSR-15/PSR-18.

To carry request-scoped values too, use a `WireCodec`. Only classes
implementing `WireValue` travel, under stable aliases you choose:

```php
final readonly class TenantId implements WireValue
{
    public function __construct(public string $value) { /* validate */ }
    public function toWire(): string { return $this->value; }
    public static function fromWire(string $wire): static { return new static($wire); }
}

$codec = new WireCodec(['tenant' => TenantId::class]);

$message->attributes = $codec->encode($context);    // ['budget_ms' => 790, 'values' => ['tenant' => 'acme']]

[$context, $controller] = $codec->decode($message->attributes, Context::background(), maxBudgetMs: 30_000);
```

`decode()` treats its input as untrusted and never throws on it: the budget is
capped by `maxBudgetMs` and by the parent, a malformed budget is ignored,
unknown aliases are dropped, and a value whose `fromWire()` throws is dropped
(pass `onInvalid` to log it). Cancellation is not carried — it is an event,
not state.

### Testing

The package ships deterministic clocks in `Rasuvaeff\Context\Testing`:

```php
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Context\Testing\SteppingTimeSource;

$clock = new FakeTimeSource();               // also the Sleeper: sleep() advances it
[$context] = Context::background($clock)->withTimeout(1.0);
$clock->advanceMs(2_000);
$context->isExpired();                        // true

$racy = new SteppingTimeSource(600_000_000);  // +0.6 s on every read
```

`SteppingTimeSource` moves the clock between two consecutive checks inside one
call — the way to prove an adapter never acts on an expired budget.

### Writing an adapter

- **Read `remainingMs()` before `assertActive()`.** Read after the check, a
  deadline passing in between yields `0.0` — and `0` means *no timeout* to
  curl, to ClickHouse's `max_execution_time` and to MySQL's
  `MAX_EXECUTION_TIME`. Read first, a passing check guarantees at least 1 ms.
- Map the transport's timeout error to `ContextExpiredException` with the
  original as `previous` when the context has expired by then; rethrow it
  untouched otherwise (a timeout you did not set).
- Test the race with `SteppingTimeSource`.

### Public API

| Class | Description |
|---|---|
| `Context` | Immutable view: `background(?TimeSource, ?Sleeper)`, `withCancel()`, `withTimeout()`, `withDeadline()`, `withReserve()`, `withoutCancel()`, `joinedWith()`, `withValue()`, `value()`, `assertActive()`, `is*()`, `remainingMs()`, `deadlineNs()`, `sleep()`, `iterate()`, `onTerminate()` |
| `ContextController` | Owner handle: `cancel(?Throwable $reason = null)`, `requestCancel(?Throwable $reason = null)` |
| `TimeSource` | Monotonic clock seam (`nowNs(): int`) for deterministic tests |
| `HrTimeSource` | Production `hrtime()` implementation |
| `Sleeper` | Sleep seam (`sleepNs(int)`) behind `sleep()`; may return early |
| `UsleepSleeper` | Production `usleep()` implementation |
| `WireCodec` | Budget and allow-listed values across a process boundary: `encode()`, `decode()` |
| `WireValue` | A value that may cross the boundary: `toWire()`, `fromWire()` |
| `Testing\FakeTimeSource` | Deterministic clock and sleeper: `advanceNs()`, `advanceMs()` |
| `Testing\SteppingTimeSource` | A clock that moves on every read, for race tests |
| `TerminationCause` | Cancellation or deadline cause passed to listeners |
| `ContextException` | Base context exception (`RuntimeException`) |
| `ContextCancelledException` | Explicit cancellation detected by `assertActive()`; the reason is `previous` |
| `ContextExpiredException` | Deadline expiration detected by `assertActive()`; `deadlineNs()`, optional `previous` |

## Security

Context values are request-scoped metadata, not a service locator; keys are
class strings and a value must be an instance of its key. The package never
interrupts a blocking syscall: transport adapters must map the remaining
budget to their own timeouts or register an `onTerminate()` cleanup
listener. A budget received from another process is untrusted input —
validate and cap it before building a context from it. `WireCodec::decode()`
does so and accepts values only through `WireValue::fromWire()` on an
allow-list of classes, never `unserialize()`: validate in `fromWire()` and
throw on anything unexpected.

## Examples

See [examples/](examples/) for runnable scripts.
Examples are expected to execute without fatal errors and stay aligned with the
documented public API.

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | Derivation, request-scoped values and cancellation | no |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## License

[BSD-3-Clause](LICENSE.md)
