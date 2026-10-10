# syntax=docker/dockerfile:1.7
#
# ww-base — the command image: what a command container is made of.
#
# The broker gives a command a container; this is what that container is built
# of — a general-purpose toolbase rather than an application image, because the
# command is arbitrary by design (`npm test`, `git clone`, a compiler, a Python
# script, `find /workspace -name '*.tmp' -delete`) and the point is that all of
# those are possible without any of them being privileged.
#
# Debian trixie-slim — the same release family as the broker image
# (dunglas/frankenphp:1-php8.5-trixie), so the two images' libc and packaging
# assumptions match. Not Alpine: the tooling a session reaches for (Python
# wheels, prebuilt binaries, git's helpers) assumes glibc far more often than
# it assumes musl.
#
# Two things in here are load-bearing rather than convenient.
#
# 1. **/workspace ships owned by uid 1000:gid 1000.** Commands run as
#    1000:1000 and the root filesystem is read-only, so nothing inside a
#    container can chown anything. A fresh workspace volume becomes writable
#    because the daemon populates it: on first mount the empty volume receives
#    the image's /workspace and, in the same pass, its ownership (containerd's
#    fs.copyFileInfo chowns the destination to match the source; read at moby
#    master — daemon/create_unix.go -> daemon/container/container_unix.go ->
#    continuity/fs/copy.go). Ship /workspace root-owned and every session's
#    first command fails to write with a permission error that looks like a
#    broker bug. ww-base/smoke.sh asserts the whole chain against a daemon;
#    tests/Unit/Image/CommandImageTest.php keeps this file honest without one.
#
# 2. **No default command.** debian:trixie-slim carries Cmd: ["bash"], and
#    inheriting that would make a bare `docker run <image>` start a shell
#    nobody asked for. The empty-array forms below clear both defaults, so a
#    bare `docker create` is refused ("no command specified") rather than
#    quietly doing something.
#
# Deliberately absent: sudo (capabilities are dropped to ALL and
# no-new-privileges is set before anything runs — a setuid helper would gain
# nothing), an editor and a pager (a captured output channel is not a
# terminal; PLAN.md §5.1), a compiler toolchain, any runtime beyond Python, and
# — absolutely — any daemon credential or Docker socket path.
#
# A session that needs more derives a private tag it is free to abuse; the
# shared base stays clean (PLAN.md §6.1 — the designed safety valve).
#
# Published by the same bake run as the broker (docker-bake.hcl, target
# `base`) as a suffixed tag of the same repository — the convention
# task-weaver uses for its second runtime role:
#   digitaladapt/work-warp:develop-base        (pushes to main)
#   digitaladapt/work-warp:latest-base         (releases, plus :<version>-base)

FROM debian:trixie-slim

# UTF-8 by default rather than the C locale's ASCII-only guesswork; C.UTF-8 is
# built into glibc, so this costs no locales package and no generation step.
ENV LANG=C.UTF-8

# The toolset — what a session reaches for on day one, not a kitchen sink:
#
#   ca-certificates curl wget openssl   reaching anything at all (only when a
#                                       command opts into its session network
#                                       — the broker's default is `none`) and
#                                       the TLS roots to do it safely
#   git openssh-client                  the ordinary unit of work
#   jq less file rsync                  reading, paging, moving
#   unzip zip zstd xz-utils bzip2       the archive family
#       tar gzip
#   make patch diffutils                building and editing without an editor
#   python3 python3-venv                one scripting language, complete: the
#                                       full standard library (ssl, sqlite3,
#                                       ctypes, http) and a way to make a
#                                       venv — because PEP 668 blocks system
#                                       pip, and the error it prints names a
#                                       tool this image would otherwise be
#                                       missing
#   iproute2 bind9-dnsutils iputils-ping  network diagnosis for the session
#                                       network, when a command is allowed on
#                                       it
#   procps coreutils findutils          process and file inspection
#       grep sed gawk
#   bash bash-completion                a real shell for scripts
#   bc xxd                              arithmetic and byte-level inspection
#
# apt versions are deliberately not pinned (hadolint DL3008): this tag is meant
# to move with trixie's security updates, and a pin nobody bumps carries a
# known vulnerability for the rest of its life.
# hadolint ignore=DL3008
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        wget \
        openssl \
        git \
        openssh-client \
        jq \
        less \
        file \
        rsync \
        unzip \
        zip \
        zstd \
        xz-utils \
        bzip2 \
        tar \
        gzip \
        make \
        patch \
        diffutils \
        python3 \
        python3-venv \
        iproute2 \
        bind9-dnsutils \
        iputils-ping \
        procps \
        coreutils \
        findutils \
        grep \
        sed \
        gawk \
        bash \
        bash-completion \
        bc \
        xxd \
    && rm -rf /var/lib/apt/lists/*

# uid 1000:gid 1000 — the number the broker passes explicitly as the
# container's User (App\Docker\Limits), and the number that owns the workspace
# volume; numeric on purpose, because a name would depend on this image having
# a user with that name while the volume is owned by a number.
#
# `install -d` rather than mkdir+chown: one command that creates each directory
# already owned, so there is no window in which it exists as root.
RUN groupadd --gid 1000 user \
    && useradd --uid 1000 --gid 1000 --no-log-init \
        --home-dir /home/user --shell /bin/bash user \
    && install -d -o 1000 -g 1000 -m 0755 /home/user \
    && install -d -o 1000 -g 1000 -m 0755 /workspace

# HOME is deliberately NOT set: the broker passes a small, fixed environment
# and enlarging it is a broker decision, not an image one. /home/user exists
# for the tools that find it through the passwd entry; a session that wants a
# writable HOME exports one it chooses (see ww-base/README.md).

# The safe default for hand runs; the broker passes User explicitly on every
# create, so this changes nothing in production — it just means the naive
# `docker run <image>` cannot land as root.
USER 1000:1000

# Sensible cwd for hand runs; the broker sets WorkingDir from the request
# (default /workspace), so this is only the fallback.
WORKDIR /workspace

# Clear the base image's defaults. ENTRYPOINT is already empty in
# debian:trixie-slim, but its Cmd is ["bash"] — inherit that and a bare
# `docker run` starts a shell nobody asked for, which is exactly the silent
# behaviour this design removes. The empty-array form parses to an empty value
# (buildkit's frontend, dockerfile/1.7: `CMD []` leaves Cmd: []), and
# ww-base/smoke.sh asserts the resulting refusal behaviourally.
ENTRYPOINT []
CMD []
