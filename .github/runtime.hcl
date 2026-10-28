# For CI (docker/bake-action, with the Compose files): the site's images built on the runtime images
# of this commit (runtime/Dockerfile), in place of the published ones, so a change to the runtime is
# tested before it's published.
target "runtime-builder" {
  context = "runtime"
  target  = "builder"
}

target "runtime" {
  context = "runtime"
  target  = "runtime"
}

target "php" {
  contexts = {
    "ghcr.io/quirecms/runtime:8.5-builder" = "target:runtime-builder"
    "ghcr.io/quirecms/runtime:8.5"         = "target:runtime"
  }
}

target "worker" {
  contexts = {
    "ghcr.io/quirecms/runtime:8.5-builder" = "target:runtime-builder"
    "ghcr.io/quirecms/runtime:8.5"         = "target:runtime"
  }
}
