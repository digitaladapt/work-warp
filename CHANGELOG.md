# Changelog

All notable changes to WorkWarp are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning: [SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **The broker's request vocabulary, and the one place a container is
  assembled.** `src/Exec/ExecRequest.php` is typed input with construction as
  the authority: an invalid request cannot exist as an object, so the rest of
  the codebase does not re-check it. `src/Docker/ContainerSpec.php` builds the
  `containers/create` payload field by field from that input — which is the
  whole security argument, expressed as a test rather than a paragraph — and
  `src/Session/SessionName.php` is the only source of the resource names a
  session owns, so the `ww-` prefix is generated rather than supplied.

  `cmd` stays an arbitrary **argv array**. That is load-bearing in both
  directions: the container is what is constrained, and a schema that forced
  `"cd x && npm test"` back into a single string would reintroduce shell parsing
  to the one field where an argument could be reinterpreted.

- **The Symfony 8.1 / PHP 8.5 bootstrap**, per the house structure and the
  FrankenPHP base image: composer manifests, config, the canonical vendored
  config leaves, a multi-stage Dockerfile, Caddy config, php.ini and entrypoint.
  Health and readiness are split — `/health` touches nothing, `/ready` reports
  whether Docker is configured at all.

- **PHPStan level 6 with an empty baseline**, php-cs-fixer, PHPUnit and
  `composer audit` — all green, all wired to the shared CI workflow.

- `.env.example` and `.env.test` with every variable documented inline;
  `.env` is never committed.

### Added

- **The exec budget, enforced by the filesystem rather than by a counter.**
  `App\Exec\Slots` is a pool of N file locks — one slot per permitted concurrent
  command — so "how many are running" is "how many slots are taken" and a
  crashed holder frees its slot by the kernel closing a file descriptor. There
  is nothing to reconcile after a restart, because nothing was ever counted in a
  variable. `reserve()` refuses rather than queueing, with a message that says
  so; waiting is opt-in.

  The unit is the **workspace**, not the session. Today `POST /v1/sessions`
  creates both together so they coincide, but a workspace is what contends for
  disk and daemon, and two sessions sharing one should share one budget. See
  `docs/design/DOCKER-ACCESS.md` §6b for the two cases and why the distinction
  is cheap to honour now and expensive to retrofit.

### Fixed

- `Slots` exceeded its own capacity on the first run. A `Lock` is re-entrant
  with respect to itself — a second `acquire()` on the same object succeeds — so
  handing out one cached `Lock` per slot issued three reservations from a pool
  of two, while the cross-process behaviour was correct the whole time. Each
  reservation now takes a fresh object, so the same-process case goes through
  exactly the same conflict path a foreign process does.

- A wait deadline built with `DateTimeImmutable::modify('+1.000 seconds')` never
  arrived: PHP's relative date format accepts fractional units and silently
  ignores them, so `reserve(wait: 5.0)` refused instantly instead of waiting.
  Deadline arithmetic is on floats now, and the clock fake advances by epoch
  seconds for the same reason.

- `SessionName`'s pattern, the environment-key pattern and the numeric-timeout
  check all anchored with `$`, which in PCRE also matches before a trailing
  newline — so `"refactor\n"` was accepted as a session name and would have
  produced a resource name with a newline in it. Now anchored with `\z`. Found
  by the test that lists the hostile names a name must not smuggle through.

- The **Tests workflow could not boot at all**: `composer install`'s
  post-install `cache:clear` died with `Environment variable not found:
  "DEFAULT_URI"`. The shared workflow copies `.env.test` over `.env`, and
  `.env.test` did not define the variable that `config/packages/routing.yaml`
  reads with no fallback — only the untracked local `.env` had it, so the boot
  passed on a developer machine and failed on every CI run. `DEFAULT_URI` now
  lives in `.env.test`, and a new `CommittedEnvironmentTest` fails whenever a
  `%env()` reference without a fallback is added to `config/` without a
  definition in the committed test environment. The unused `.env.ci` is gone
  with it: its header described a copy step the shared workflow does not have,
  nothing read the file, and a second env file that looks load-bearing but is
  not is worse than none.

- `composer lint` ran `php-cs-fixer fix -d`, and `-d` is not an option — the
  contributor gate documented in CONTRIBUTING.md failed before it could check
  anything. It now matches the house script: `--dry-run --diff`.

### Notes

- `WW_MAX_JOBS` from `bin/ww-run` becomes `WW_MAX_CONCURRENCY`, enforced with
  `symfony/lock` rather than counted in a variable, so the semaphore survives a
  restart and cannot drift after a crash.
- The socket proxy is retained *behind* the broker as defence-in-depth. It
  cannot scope by label, filter a request body, or hold a clock; the broker can
  do all three, which is why the credential moved.
