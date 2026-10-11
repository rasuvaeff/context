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
```

A child deadline is never later than its parent's: `withTimeout()` and
`withDeadline()` take the earlier of the two. A zero or negative timeout is
an immediate deadline; an oversized one saturates at `PHP_INT_MAX` instead of
overflowing.

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
propagates to every descendant immediately.

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

### Testing

Inject a `TimeSource` to drive deadlines deterministically:

```php
$clock = new FakeTimeSource();               // your TimeSource returning nowNs()
[$context] = Context::background($clock)->withTimeout(1.0);
$clock->advanceNs(2_000_000_000);
$context->isExpired();                        // true
```

### Public API

| Class | Description |
|---|---|
| `Context` | Immutable view: `background()`, `withCancel()`, `withTimeout()`, `withDeadline()`, `withValue()`, `value()`, `assertActive()`, `is*()`, `remainingMs()`, `deadlineNs()`, `onTerminate()` |
| `ContextController` | Owner handle: `cancel(?Throwable $reason = null)` |
| `TimeSource` | Monotonic clock seam (`nowNs(): int`) for deterministic tests |
| `HrTimeSource` | Production `hrtime()` implementation |
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
validate and cap it before building a context from it.

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
