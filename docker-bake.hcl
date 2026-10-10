# WorkWarp build config — two images from one repository.
#
#   app   — the broker (FrankenPHP, multi-stage). Published as :<TAG> and,
#           on a release, :<VERSION>.
#   base  — the command image commands run *in* (ww-base/). Published as
#           :<TAG>-base and, on a release, :<VERSION>-base — the same suffix
#           convention task-weaver uses for its second runtime role.
#
# CI (develop.yaml / docker.yaml) invokes this with --file so the compose
# file that lives in the same directory is not merged in as extra targets.
#
# DOCKERHUB_TARGET is the org/repo (Gitea Settings → Variables)
# CI sets TAG=latest + VERSION=<v-stripped> for tag pushes,
#         TAG=develop  for pushes to main.

variable "DOCKERHUB_TARGET" {
  default = "digitaladapt/work-warp"
  description = "Docker Hub repo/org (Gitea repo variable DOCKERHUB_TARGET)."
}

variable "TAG" {
  default = "latest"
  description = "Base tag for this build: latest (release), develop (main push), or a version. The command image suffixes it: <TAG>-base."
}

variable "VERSION" {
  default = ""
  description = "Optional full version (v stripped) to also tag with; empty for develop builds."
}

group "default" {
  targets = ["app", "base"]
}

target "app" {
  dockerfile = "Dockerfile"
  target     = "app"
  context    = "."
  platforms  = ["linux/amd64", "linux/arm64"]

  # Layer cache. One scope per target: the gha backend keys its cache by
  # scope, and the two images should not share one cache record. The shared
  # docker-publish.yaml sets cache-from/cache-to for its `action` backend but
  # NOT for `bake`, so specifying it here is what keeps CI builds warm.
  #
  # Local builds outside CI have no GHA cache service, so override:
  #   docker buildx bake --set '*.cache-to=' --set '*.cache-from='
  cache-from = ["type=gha,scope=work-warp-app"]
  cache-to   = ["type=gha,mode=max,scope=work-warp-app"]

  tags = concat(
    ["${DOCKERHUB_TARGET}:${TAG}"],
    VERSION != "" ? ["${DOCKERHUB_TARGET}:${VERSION}"] : [],
  )
}

# The command image. ww-base/Dockerfile builds it from the ww-base/ directory
# alone — it needs nothing from the rest of the repository, and its context
# is deliberately not the repository root. See ww-base/README.md for the
# contract it has to keep (uid 1000 owning /workspace, no default command)
# and ww-base/smoke.sh for the half of that contract CI cannot check.
#
# `dockerfile` is resolved RELATIVE TO `context` (buildx ≥ 0.12; verified in
# v0.12–v0.37 sources and empirically against v0.37), so it is spelled
# "Dockerfile" here, not "ww-base/Dockerfile" — the latter would resolve to
# ww-base/ww-base/Dockerfile and fail with an lstat error. This is a trap
# worth a comment because the failure names a doubled path and looks nothing
# like a bake-file mistake.
target "base" {
  dockerfile = "Dockerfile"
  context    = "ww-base"
  platforms  = ["linux/amd64", "linux/arm64"]

  cache-from = ["type=gha,scope=work-warp-base"]
  cache-to   = ["type=gha,mode=max,scope=work-warp-base"]

  tags = concat(
    ["${DOCKERHUB_TARGET}:${TAG}-base"],
    VERSION != "" ? ["${DOCKERHUB_TARGET}:${VERSION}-base"] : [],
  )
}
