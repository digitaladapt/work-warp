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

- `.env.example`, `.env.test` and `.env.ci` with every variable documented
  inline; `.env` is never committed.

### Fixed

- `SessionName`'s pattern, the environment-key pattern and the numeric-timeout
  check all anchored with `$`, which in PCRE also matches before a trailing
  newline — so `"refactor\n"` was accepted as a session name and would have
  produced a resource name with a newline in it. Now anchored with `\z`. Found
  by the test that lists the hostile names a name must not smuggle through.

### Notes

- `WW_MAX_JOBS` from `bin/ww-run` becomes `WW_MAX_CONCURRENCY`, enforced with
  `symfony/lock` rather than counted in a variable, so the semaphore survives a
  restart and cannot drift after a crash.
- The socket proxy is retained *behind* the broker as defence-in-depth. It
  cannot scope by label, filter a request body, or hold a clock; the broker can
  do all three, which is why the credential moved.
