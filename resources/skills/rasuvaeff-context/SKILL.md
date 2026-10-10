---
name: rasuvaeff-context
description: >-
  Deadline, cancellation and request-scoped values for PHP with
  rasuvaeff/context — Context, ContextController, deadlines, termination
  listeners and cooperative checks. Use when propagating request or job
  budgets, cancellation or correlation metadata through application code.
---

# rasuvaeff/context

Dependency-free cooperative context for PHP. Namespace `Rasuvaeff\Context\`.

## Rules

1. The owner keeps `ContextController`; callees receive only `Context`.
2. Derivation methods return `[Context, ContextController]`:
   `withCancel()`, `withTimeout(float $seconds)`, and `withDeadline(int $ns)`.
3. Call `assertActive()` before every retry, batch, queue step or external
   operation. Deadline observation is lazy unless an optional scheduler is
   installed.
4. `isCancelled()` means explicit cancellation; `isExpired()` means deadline;
   `isTerminated()` is their union. `ContextException` subclasses distinguish
   the causes.
5. `withValue(class-string<T>, T)` is for request-scoped metadata, not service
   lookup. Values must be objects matching their class-string key.
6. A context cannot interrupt an arbitrary blocking syscall. Map remaining
   budget into a transport timeout or register an `onTerminate()` cleanup hook.

## Canonical usage

```php
use Rasuvaeff\Context\Context;

[$context, $controller] = Context::background()->withTimeout(3.0);
try {
    $context->assertActive();
    doWork($context);
} finally {
    $controller->cancel();
}
```

For queues, derive one context per message from its TTR, check it between
steps, ACK only after completion, and NACK/dead-letter on
`ContextExpiredException`. Read `vendor/rasuvaeff/context/llms.txt` for the
complete API and edge-case semantics.
