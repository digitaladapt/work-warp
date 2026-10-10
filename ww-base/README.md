# ww-base — the command image

The thing commands actually run *in*. The broker gives a command a container;
this directory is what that container is made of.

- Built from `Dockerfile` (Debian trixie-slim plus a toolset), with a tiny
  context — nothing here is `COPY`ed into the image, because the image is
  *environment* and the workspace volume is *code* (PLAN.md §6.1).
- Published by the same bake run as the broker, as suffixed tags of the same
  repository — the task-weaver pattern for a second runtime role:

  | Built when | Tags |
  |---|---|
  | push to `main` | `digitaladapt/work-warp:develop-base` |
  | release (`v*.*.*`) | `digitaladapt/work-warp:latest-base` and `digitaladapt/work-warp:<version>-base` |

## The contract — two things are load-bearing

1. **`/workspace` ships owned by `uid 1000:gid 1000`.** Commands run as
   `1000:1000` and the root filesystem is read-only, so nothing inside a
   container can `chown` anything. A fresh workspace volume becomes writable
   because the daemon *populates* it: when an empty volume is first mounted,
   the daemon copies the image's `/workspace` into it and chowns the volume
   root to match the source (moby `populateVolumes` → `copyExistingContents` →
   containerd/continuity `copyFileInfo`). If this image shipped `/workspace`
   owned by root, every session's first command would fail to write, with a
   permission error that looks like a broker bug.
2. **No default command.** No `ENTRYPOINT`, and `CMD []` clears the base
   image's `Cmd: ["bash"]`, so a bare `docker run <image>` fails loudly instead
   of quietly doing something. The broker passes `Cmd` on every create anyway —
   this half is about hand runs.

Both are checked end to end, on a daemon, by `ww-base/smoke.sh`; the parts
checkable without one are guarded by `tests/Unit/Image/CommandImageTest.php`.

## What's inside, and what is not

Inside: bash, coreutils, findutils, grep/sed/gawk, git and openssh-client,
curl, wget and ca-certificates, openssl, jq, less, file, rsync, the archive
family (tar, gzip, bzip2, xz, zstd, zip, unzip), make, patch, diffutils,
Python 3 with the full standard library and `venv` — not `python3-minimal`,
which lacks `http`, `sqlite3` and `ctypes` and cannot even make a venv —
iproute2, dig, ping, procps, bc, xxd.

Deliberately not inside: `sudo` (capabilities are dropped to `ALL` and
`no-new-privileges` is set — there is nothing to gain), an editor (the capture
channel is not a terminal: `git commit -m` and `sed -i` are the forms that
work), a compiler toolchain, any language runtime beyond Python, and no daemon
credential or Docker socket path of any kind.

A session that needs something else derives a private tag it is free to abuse;
the shared base stays clean (PLAN.md §6.1 — the designed safety valve).

## Running and building

```bash
# build just the command image, locally, no push
docker buildx bake base

# the full default group (broker + command image)
docker buildx bake

# outside CI there is no GHA cache service — drop the cache settings
docker buildx bake base --set 'base.cache-to=' --set 'base.cache-from='
```

Note for editors of `docker-bake.hcl`: `dockerfile` is resolved **relative to
`context`** (buildx ≥ 0.12), so the `base` target says
`context = "ww-base"` with `dockerfile = "Dockerfile"`.

## Testing it

```bash
bash ww-base/smoke.sh digitaladapt/work-warp:develop-base
```

`smoke.sh` needs a real daemon and a locally available image (`docker pull`, or
a locally built tag). It checks the image's shape — no default command, user
`1000:1000`, `/workspace` ownership, the toolset — and then runs the production
shape the broker uses (`--read-only`, `--cap-drop=ALL`,
`--no-new-privileges`, `--network none`, user `1000:1000`) against a **fresh**
volume, because that is the only way to exercise the volume-population path
that makes sessions writable.

The repository's test suite deliberately has no daemon anywhere in it
(CONTRIBUTING.md), so this script is the other half of the gate — run it on the
machine that has the daemon.

## Two things a session should know

- **`HOME` is deliberately unset.** The broker passes a small, fixed
  environment, and enlarging it is a broker decision, not an image one. Tools
  that want a home directory (`git config --global`, `ssh`'s `known_hosts`)
  need one named: `export HOME=/workspace` (persists in the volume) or
  `HOME=/tmp` (scratch). The broker mounts `/tmp` `noexec,nosuid` — writing a
  config file there is fine; expecting to execute something out of it is not.
- **This is not a terminal.** No `TERM`, no pager, no editor — and five
  variables are stamped on every command so nothing waits for a terminal that
  is not there (PLAN.md §5.1). Write commands in their non-interactive form:
  `git commit -m`, `sed -i`, `python3 -c`.
