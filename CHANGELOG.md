# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.5.2 — 2026-11-27

- Docs: the agent skill showed `fn () => $c->requestCancel(...)` for a SIGTERM handler — a first-class callable that cancels nothing; it is now `requestCancel()`.

## 0.5.1 — 2026-11-21

- **Changed:** PHP 8.6 is supported: the `php` constraint is widened to
  `8.3 - 8.6` (#33). CI runs PHP 8.6 as a regular matrix entry; the
  backward-compatibility job runs on PHP 8.5.

## 0.5.0 — 2026-11-20

- Added: `Context::budgetMs(): ?int` — the remaining budget in whole
  milliseconds (at least 1), read before the active check; throws on a
  terminated context. `WireCodec::encode()` uses it (#25).
- Added: `Context::cause(): ?TerminationCause` reads why a context ended
  without catching an exception; it observes pending cancellation and the
  deadline like the other checks (#26).
- Added: `WireCodec::encodeValues()` and `decodeValues()` carry only the
  allow-listed values, for transports that send the deadline on their own;
  `encode()`/`decode()` are built on them (#29).
- Added: static `WireCodec::budgetFromWire()` — the single rule for an
  incoming budget, shared by `decode()` and adapters such as `context-http`'s
  budget header (refs rasuvaeff/context-http#17). Accepted input is unchanged.
- Added: generics on `Context::withValue()` and `value()` —
  `class-string<T>` key, `T` value, `T|null` result; a psalm type fixture
  pins it (#24).
- Changed: the dead parent link of `Context` and its fallback in `value()` are
  removed; derived contexts no longer keep their ancestors' `Context` objects
  alive (#23).
- Fixed: a state collected with an unobserved `requestCancel()` now gives the
  process-wide pending counter back, so later checks in a long-running worker
  skip the ancestor walk again; the seconds-to-nanoseconds conversion is a
  single private helper (#28).

## 0.4.0 — 2026-11-20

- Added: `ListenerFailuresException` (`RuntimeException`) with `errors()`: when
  several termination listeners fail, every original exception is now
  reachable, not only the last. Message and `previous` chain are unchanged (#21).
- Fixed: `WireCodec` threw `TypeError` on a numeric alias (`'1'` becomes an int
  array key); numeric aliases are now accepted and round-trip (#22).
- Docs: `remainingMs()` of a cancelled context (the real remainder before the
  deadline, `1.0` after it) is documented and pinned by a test (#27).
- Docs: README lists `context-http`, `context-cli`, `context-db` and
  `context-process`, the database row no longer says support is missing, the
  examples table lists `derivations.php`; `llms.txt` mentions `UsleepSleeper`.
  `infection/infection` raised to `^0.35` (#30).
- Mutation testing now counts code the tests execute without naming it in `#[Covers]`
  (`infection --with-uncovered`), with `PublicVisibility` off and default-parameter
  signature lines ignored; tests added for value/key mismatch, default check
  interval, clock ceiling saturation, chained listener failures, the fake clock
  default and nanosecond reserve rounding. `iterate()` and `FakeTimeSource`
  constructor signatures reformatted onto several lines (no behaviour change).

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
- Added: `WireCodec` and `WireValue` (#15). A missing or malformed incoming
  budget falls back to `maxBudgetMs`; bad input never lifts the limit.
- Fixed: `withTimeout()` truncated the duration to whole nanoseconds, so
  `withTimeout(1.015)` reported `remainingMs() === 1014.0` right away.
- Docs: "Writing an adapter" — read the budget before `assertActive()`.
- Docs: the agent skill (`resources/skills/rasuvaeff-context/SKILL.md`) is
  rewritten for 0.3 — safety rules, decision table; it no longer mentions a
  scheduler that does not exist.

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
