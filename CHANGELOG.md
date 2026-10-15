# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.3.0 — 2026-10-15

- Added: `Testing\FakeTimeSource` and `Testing\SteppingTimeSource` (#8).
- Added: `Sleeper`, `UsleepSleeper` and `Context::sleep()`; `background()`
  takes an optional `Sleeper` (#9).
- Added: `Context::withoutCancel()` (#10).
- Added: `Context::withReserve()` (#11).
- Added: `Context::iterate()` (#12).
- Added: `ContextController::requestCancel()` — deferred cancellation safe
  from signal handlers; listener failures surface from the next check (#13).
- Added: `Context::joinedWith()` — the internal state is now a DAG; a
  terminated node detaches from both parents (#14).
- Added: `WireCodec` and `WireValue` (#15).
- Fixed: `withTimeout()` truncated the duration to whole nanoseconds, so
  `withTimeout(1.015)` reported `remainingMs() === 1014.0` right away.
- Docs: "Writing an adapter" — read the budget before `assertActive()`.

## 0.2.0 — 2026-10-11

- `Context::onTerminate()` returns an unsubscribe closure
  (`\Closure(): void`) instead of `void`; calling it removes the listener,
  and it is a no-op once the listener ran. Source-compatible: callers that
  ignore the return value are unaffected (#1).
- A terminated context state detaches from its parent and releases its
  listeners and children, so a long-lived parent no longer accumulates every
  cancelled or expired child it derived. `withValue()` now shares its
  parent's state instead of attaching a new child per call (#1).
- `withTimeout()` no longer overflows: a timeout beyond the representable
  range saturates at a `PHP_INT_MAX` deadline, and a zero or negative timeout
  yields an immediate deadline (the context is expired on first
  observation) (#2).
- `ContextExpiredException` accepts an optional `$previous` throwable, so a
  transport adapter can keep the underlying timeout as the cause.
- Documented propagating a budget across a process boundary: send
  `remainingMs()`, rebuild with `withTimeout($ms / 1000)` on the receiving
  side (#4).
- README/llms.txt placeholders replaced with the real API reference, Russian
  README added, property tests for subtree cancellation and nearest-ancestor
  value lookup, regression-corpus caching in CI (#3).

## 0.1.0 — 2026-10-10

- Initial release: `Context` (`background()`, `withCancel()`,
  `withTimeout()`, `withDeadline()`, `withValue()`), `ContextController`,
  monotonic `TimeSource`/`HrTimeSource`, `TerminationCause`,
  `ContextException` with `ContextCancelledException` and
  `ContextExpiredException`; lazy deadline observation that terminates the
  owning ancestor's subtree; `onTerminate()` listeners with aggregated
  listener failures; queue-worker recipe in the documentation.
