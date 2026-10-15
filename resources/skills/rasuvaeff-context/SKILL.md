---
name: rasuvaeff-context
description: >-
  Deadline, cancellation and request-scoped values for PHP with
  rasuvaeff/context — Context, ContextController, withTimeout/withReserve/
  withoutCancel/joinedWith, sleep(), iterate(), requestCancel() for signal
  handlers, WireCodec for crossing process boundaries, and the Testing clocks.
  Use when propagating a request or job budget, cancellation or correlation
  metadata through code, writing a transport adapter that maps the remaining
  budget to a timeout, stopping a CLI worker gracefully, or testing any of it.
---

# rasuvaeff/context

Dependency-free cooperative context (deadline + cancellation + values), like
Go's `context.Context`. Namespace `Rasuvaeff\Context\`.

## Safety rules — verify these on every change

1. **Pass `Context` explicitly; never store it in a service or DI.** It is
   request-scoped. The owner keeps the `ContextController` and cancels it in
   `finally`; callees receive only the `Context`.

2. **Nothing is interrupted — check between steps.** Call `assertActive()`
   before each retry attempt, batch item, queue step or external call. Loops:
   `foreach ($ctx->iterate($items) as $k => $v)` (checks before pulling each
   element). Waits: `$ctx->sleep($s)`, never `usleep()`. A deadline is
   discovered lazily — an unobserved context never runs its listeners.

3. **Adapters read the budget BEFORE `assertActive()`.**
   ```php
   $remainingMs = $ctx->remainingMs();   // first
   $ctx->assertActive();                 // then: guarantees >= 1 ms
   ```
   Read after the check, an expiry in between yields `0.0`, and `0` means
   **no timeout** to curl, ClickHouse `max_execution_time` and MySQL
   `MAX_EXECUTION_TIME`. Map the transport's timeout error to
   `ContextExpiredException` (original as `previous`) only when the context
   has expired; otherwise rethrow it.

4. **Signal handlers call `requestCancel()`, never `cancel()`.** `cancel()`
   runs listeners synchronously — inside a handler that is an arbitrary point
   of the interrupted code. `requestCancel()` only records the reason; the
   next check applies it. A real signal wakes `$ctx->sleep()` early.

5. **Derive, don't join.** A job under a worker is `$worker->withTimeout($ttr)`.
   `joinedWith()` is only for two *independent* sources (worker shutdown +
   a message context received from elsewhere); values come from `$this` only,
   both must share a `TimeSource`.

6. **Cleanup after the request ended needs `withoutCancel()` + a budget.**
   `[$cleanup] = $request->withoutCancel()->withTimeout(2.0);` — the bare
   `withoutCancel()` has no deadline at all.

7. **Budgets crossing a process boundary are untrusted.** Send the remaining
   milliseconds, never `deadlineNs()` (monotonic, process-local). Use
   `WireCodec`: values only via `WireValue::fromWire()` (validate, throw on bad
   input — never `unserialize()`), aliases from an allow-list, and
   `decode($wire, $parent, maxBudgetMs:)` falls back to `maxBudgetMs` when the
   budget is missing or malformed.

8. **Tests use the shipped clocks, never real time.**
   `Testing\FakeTimeSource` (also the `Sleeper`: `sleep()` advances it) and
   `Testing\SteppingTimeSource($stepNs)` — moves on every read, the way to
   prove an adapter never acts on an expired budget (rule 3).

## Decision table

| Need | Use |
|---|---|
| Request/job budget | `[$ctx, $c] = Context::background()->withTimeout($seconds)` |
| Leave time to answer 504 / NACK | `[$work] = $ctx->withReserve(0.2)` |
| Cancellable sub-branch | `[$branch, $c] = $ctx->withCancel()` |
| Absolute monotonic deadline | `$ctx->withDeadline($ns)` (earlier of parent's and yours) |
| Request metadata | `$ctx->withValue(TenantId::class, new TenantId('t'))` / `->value(TenantId::class)` |
| Graceful CLI shutdown | `pcntl_signal(SIGTERM, fn () => $c->requestCancel(...))` (or `rasuvaeff/context-cli`) |
| Release a resource on termination | `$unsubscribe = $ctx->onTerminate(fn (TerminationCause $t) => ...)` |
| Hand budget + values to another process | `WireCodec::encode()` / `decode()` |
| HTTP (PSR-15/PSR-18/Guzzle) | `rasuvaeff/context-http` |

## Canonical usage

```php
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;

[$job, $controller] = $worker->withTimeout($ttrSeconds);
try {
    foreach ($job->iterate($steps) as $step) {
        $step($job);
    }
    $queue->ack($message);
} catch (ContextExpiredException) {
    $queue->nack($message);
} finally {
    $controller->cancel();
}
```

Predicates: `isCancelled()` (explicit), `isExpired()` (deadline),
`isTerminated()` (either); `assertActive()` throws `ContextCancelledException`
(reason as `previous`) or `ContextExpiredException`. A listener that throws
does not stop the cascade; its exception surfaces from `cancel()` or from the
check that discovered the termination.

## Full API

Every method, edge case (zero/negative/oversized timeouts, `every:` in
`iterate()`, joined deadlines) and the transport limitations matrix ship with
the package: read `vendor/rasuvaeff/context/llms.txt` and the README
"Limitations" section before guessing a method name.
