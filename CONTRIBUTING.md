# Contributing to WorkWarp

Thanks for considering a contribution!

## Before you touch anything

Read [`AGENTS.md`](AGENTS.md) — branch naming, the PR workflow, and the
permission boundaries. Then [`docs/design/PLAN.md`](docs/design/PLAN.md): it
records the decisions, the evidence behind them, and what is still open.

Three things the design rests on, which a well-meaning change can quietly break:

1. **The broker is guardrails, not a boundary.** Don't describe it as one, and
   don't let a change imply it is one.
2. **Dangerous operations must stay unexpressible.** If you find yourself adding
   a field to a request object and then validating it, stop: the point is that
   there is no field. A new passthrough needs a design note, not a reviewer.
3. **State lives in the volume, never in a process.** A session lasts months and
   long-lived processes leak. Nothing the broker needs may live only in its
   memory.

## Development setup

```bash
composer install
composer lint && composer stan && composer test
```

All of these must pass, plus `composer audit`, before a PR is mergeable.
Coverage is measured in CI (pcov) against a floor that only rises.

The PHP toolchain is not in the base image; `~/install-php85.sh` restores it
after a container rebuild and is safe to re-run.

## Conventions

- PHP 8.5, Symfony 8.1, `declare(strict_types=1)` everywhere.
- Config files under the root (`phpstan.neon.dist`, `.php-cs-fixer.dist.php`,
  `phpunit.dist.xml`, `.editorconfig`) are **vendored leaves**: sync them by
  overwriting, never by hand-editing or merging. If one needs to change, change
  it at the source and re-sync, or the copies drift apart.
- Watch for Flex recipes modifying a vendored leaf after you copy it into place.
  This has already caused one bug: the phpunit recipe appended
  `<env name="APP_ENV" value="dev"/>` to `phpunit.dist.xml`, which overrode the
  test environment and broke every functional test in a way that looked like a
  config typo somewhere else.
- Tests assert *what the broker builds*, never what it reaches. A test needing a
  Docker daemon can only run on a developer's machine, and the pieces worth
  testing here are exactly the pieces that must not depend on one.
- Where a claim can be measured, measure it and say how. Several comments in
  this repo record a real observation that contradicted a plausible guess — keep
  doing that, including when the guess was yours.

## Adding to the request vocabulary

`src/Exec/` is the caller's vocabulary and `src/Docker/ContainerSpec.php` is the
only place a container is assembled. If you add a field:

1. Validate it in the request object, so an invalid request cannot exist as a
   value at all.
2. Add it to `ContainerSpec` explicitly, or don't — but never pass a map
   through.
3. Add the test that asserts the resulting payload *cannot* say something
   dangerous. `ContainerSpecTest::test_the_payload_cannot_mention_anything_that_would_widen_the_container`
   is the pattern.
