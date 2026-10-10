# AGENTS.md — context

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/context` provides dependency-free deadline, cancellation and
request-scoped values for PHP. The public API is `Rasuvaeff\\Context\\Context`
and its paired `ContextController`; derived contexts propagate termination to
their descendants.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Deadlines are monotonic.** All deadline checks use `TimeSource`; never
   replace it with wall-clock time or a sleep-based timer in the core.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- A `Context` is the read-only view passed down the call graph. Keep the
  `ContextController` at the owner of a cancellable branch.
- Deadline observation is lazy. Observing a child whose effective deadline is
  owned by an ancestor terminates that ancestor and its complete subtree.
- `withValue()` accepts only an object matching its class-string key.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- **Validation patterns anchor with `\z`, never `$`.** In PCRE `$` also matches
  immediately before a trailing newline, so `/^…$/` silently accepts one `\n` —
  the value the whitelist was supposed to reject then travels on as if it were
  clean. `/D` does the same job. Cover it with a data-provider case carrying
  `"value\n"`, or the regression comes back unnoticed.
<!-- If this package ships yiisoft/db-migration migrations, keep these too;
     delete otherwise. Full rationale: "Пакеты с миграциями" in the monorepo
     AGENTS.md. -->
- **Migrations live in `src/Migration/` under the package namespace**, never as
  a global class in `migrations/`: a class outside PSR-4 is not autoloadable, so
  any DI definition mentioning it makes `Yiisoft\Di\Container` fatal at build
  time in every request. Register with
  `MigrationService::setSourceNamespaces()`, not `setSourcePaths()`.
- **The table name is a typed value object, never a scalar.**
  `yiisoft/db-migration` builds migrations through `Injector::make()`, which
  resolves arguments by name or by type and never reads a container definition
  keyed by the migration's own class — a `string $table` has no type to resolve,
  so `M...::class => ['__construct()' => ['table' => …]]` silently yields the
  default. `config/di.php` builds the VO from params and passes it to the
  migration *and* the runtime class, so the two cannot disagree; index names are
  derived from it (PostgreSQL scopes index names per schema, not per table).
- **Test the migration through the real `Injector`**, with and without a
  container binding, and assert the created column set plus each index's
  columns — migrations under `src/` are mutated by infection, and
  `ArrayItemRemoval` in `createTable`/`createIndex` escapes otherwise.
- Changing a migration's FQCN is a **major** and needs `UPGRADE.md`
  (`UPDATE migration SET name = …` before the first `migrate:up`). `bc-check`
  stays silent — the old class was outside PSR-4, so roave never saw it.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
