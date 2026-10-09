# Changelog

All notable changes to WorkWarp are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning: [SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`exec` — the 95% win (DOCKER-ACCESS.md §10 step 2).**
  `POST /v1/sessions/{name}/exec` runs one command in one ephemeral container
  and returns `{exit_code, stdout, stderr, truncated, timed_out}`. The
  lifecycle is `ExecRunner`'s and follows the design's order: the session must
  already exist (a mistyped name is a `404`, never a new durable object), a
  slot is held for the whole run, the session's network is created lazily the
  first time a command asks to reach it, the container is built by
  `ContainerSpec` and started, the broker waits against its own deadline,
  kills at expiry, reads the capped logs, and removes the container before
  the slot goes back.

  Two choices are load-bearing and deliberate. **Polling rather than a
  blocking wait**: `POST /containers/{id}/wait` would hold a request open for
  the length of a test suite and hand the timeout to whatever client happened
  to be in the middle; the loop polls the injected clock instead, which keeps
  the deadline the broker's and the loop testable in microseconds. **A hard
  kill at the deadline**: a command that outran its timeout is over, the exit
  code says how (137), and `timed_out: true` says why — a timeout is a `200`
  with a flag, not a `5xx`, because a killed command ran.

  The failure vocabulary is the one the session endpoint established, extended
  where the design already said what it means: `404` no such session, `422`
  anything inexpressible (`SessionName` / `ExecRequest`), `429` when every slot
  is taken (the broker does not queue, §6b), `501` for `stdin`, `503`/`502`
  for the daemon unreachable / refusing.

- **`stdin` is refused with `501`, on purpose, and the field stays.**
  Delivering it needs the daemon's connection-hijacking `attach` path
  (Docker's own spec: the request upgrades and the socket goes raw), which the
  broker's HTTP client does not implement. The alternative was worse: a
  broker that accepted `stdin` and silently ran the command without it looks
  like a command that read an empty file. The refusal names the workaround
  (a file in the workspace) and the field keeps its validation, so lifting
  this is connection-hijack support rather than an API change.

- **The pager guard is the broker's, and the caller cannot take it.**
  `PAGER=cat GIT_PAGER=cat TERM=dumb NO_COLOR=1 CI=1` are stamped on every
  command — PLAN.md §5.1's direct fix for the three-day-old pagers — and the
  names are now *refused* in a request's `env`: "who wins when a variable is
  duplicated" is decided differently by each libc (glibc's `getenv` returns
  the first match), and a guard that can be overridden is not a guard.

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

- **The Docker client, as a seam.** `App\Docker\DockerApi` is the whole of what
  the broker can ask the daemon to do — create from a name and a payload
  somebody else assembled, start, inspect, kill, remove, logs, and the
  workspace volume and session network — with no raw-endpoint escape hatch, so
  the shape of the door is part of the vocabulary. `HttpDockerApi` is the real
  one, and `config/services_test.yaml` swaps in a scripted double: CI has no
  daemon, and the integration tests assert what the broker *asked Docker to
  do* rather than what it reached.

  Failures leave as two typed shapes, deliberately: `DockerUnavailable`
  (nothing was asked; `503`) and `DockerRefused` (asked and told no, in the
  daemon's own words; `502`). A `unix://` `WW_DOCKER_HOST` is refused by name
  before any request leaves — reaching the daemon socket directly is the exact
  thing the proxy exists to prevent.

- **The log reader, for output that must not be trusted to fit.**
  `GET /containers/{id}/logs` on a non-TTY container answers in Docker's
  multiplexed format — `[stream(1), pad(3), size(4 big-endian)]` frames — and
  the reader parses them from a buffer (they split across chunks at any byte),
  splits stdout from stderr, caps each stream, and **stops reading the moment
  a cap is hit**, cancelling the transfer rather than draining output it has
  already decided to discard. A lost connection keeps what arrived and says
  `truncated`; a frame header claiming megabytes is refused rather than
  buffered towards.

- **`POST /v1/sessions`, and the gate in front of it.** Creating a session
  that exists adopts it (`200`) rather than failing — a client retrying after
  a timeout should not have to guess whether the first attempt landed — and a
  fresh one answers `201`. The workspace volume is created here and carries
  the `ww.` labels; `App\Docker\Labels` now owns that vocabulary (the volume
  is its second user; the constants used to live inside `ContainerSpec`).

  Everything under `/v1` requires `Authorization: Bearer $WW_TOKEN`, checked
  **before routing** so an unauthenticated caller gets one answer for every
  path and cannot probe which routes exist. An unset token answers `503`
  rather than allowing through: that is the one condition under which the
  broker would accept anonymous callers while claiming to be the only thing
  holding the Docker credential. `/health` and `/ready` stay open — they touch
  nothing.

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

### Changed

- **`Limits` carries the output cap** (`outputByteCap`, 1 MiB per stream). It
  is policy, like every other field there, and the reader stops at it rather
  than draining and discarding. Per stream, not per command: a command that
  fills both streams is two facts.

- **`docker/php.ini` sets `max_execution_time = 0`.** A command may run for
  hours, and the SAPI default of 30 s would turn every long run into a
  truncated request. The bound that matters is the request's own timeout plus
  the kill grace, and `ExecRunner` enforces it.

- **`deploy/compose.yaml` now states what the host actually runs.** The stack was
  rolled out on 2026-10-07 as a working-tree change on the host; the repo copy
  still described the pre-rollout topology (terminal sharing the proxy's network,
  `DOCKER_HOST` pointed at the proxy, `:latest` on the proxy image, the network
  called `lyra-control`). The preserved host diff is now applied: `lyra-terminal`
  is on `public` + `api` with **no `DOCKER_HOST`**, networks are `backend` and
  `api`, and the proxy is pinned to `tecnativa/docker-socket-proxy:v0.5.0` — the
  version every measurement in `deploy/README.md` was taken against.

  `deploy/README.md` was swept to match: the rollout is recorded as executed
  rather than proposed, the two settled decisions (image pin, network rename) are
  marked done, the safeguards table no longer claims `cap_drop: ALL` or
  `no-new-privileges` on the proxy — measured null, and never in the file — and
  the open questions now say which were answered on 2026-10-07. The same sweep
  hit `docs/design/`: §0b/§0e of `DOCKER-ACCESS.md` and §10 of `PLAN.md` now
  record the resolutions instead of asking for them.

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
