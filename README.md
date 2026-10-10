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
<!-- Add runtime dependencies here, e.g.:
- `yiisoft/db` ^2.0
-->

## Installation

```bash
composer require rasuvaeff/context
```

## Usage

<!-- Add usage examples here. For each public class, show:
- How to create/construct
- Key methods with examples
- Table of public API
-->

```php
use Rasuvaeff\Context\Context;

[$context, $controller] = Context::background()->withTimeout(3.0);
$context->assertActive();
$context->onTerminate(static function (): void {
    // release a resource owned by this operation
});
$controller->cancel();
```

For queue workers, create a context for each message and use the job's TTR as
the deadline. Check it between external calls and acknowledge only when the
job completes; an expired context should be retried or dead-lettered according
to the queue's policy:

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

Deadlines use monotonic `hrtime()`. The core discovers an expired deadline on
the next `assertActive()`, `remainingMs()`, `isTerminated()` or `isExpired()`
call. If that deadline belongs to an ancestor, the ancestor and all its
descendants are terminated. A controller cancellation propagates immediately.

### Public API

| Class | Description |
|---|---|
| `Context` | Immutable view with derivation, values, deadline and checks |
| `ContextController` | Owner handle for explicit cancellation |
| `TimeSource` | Monotonic clock seam for deterministic tests |
| `HrTimeSource` | Production `hrtime()` implementation |
| `TerminationCause` | Cancellation or deadline cause passed to listeners |
| `ContextException` | Base context exception |
| `ContextCancelledException` | Explicit cancellation detected by `assertActive()` |
| `ContextExpiredException` | Deadline expiration detected by `assertActive()` |

## Security

<!-- Describe what is safe and what requires caution.
For SQL/query packages:
- User values are bound as parameters (safe)
- Identifiers (table/column names) must be trusted/hard-coded
- Raw SQL only in trusted context
-->

Context values are request-scoped metadata, not a service locator. The package
does not interrupt a blocking syscall; transport adapters must map the
remaining budget or register an `onTerminate()` cleanup listener.

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
