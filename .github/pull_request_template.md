<!--
The title is the squash commit's subject: imperative, at most 72 characters, no trailing period,
with no internal references, e.g. "Refuse a release whose tag isn't signed".
This description becomes the commit's body. See "Pull requests, commits and branches" in CONTRIBUTING.md.
-->

## Why

<!-- The problem, or what someone running a site couldn't do. Required. -->

## What changes

<!-- Bullets: behavior, not files. -->

-

## Tested

<!-- What ran, and what didn't (NOT RUN, and why). -->

-

## Refs

<!-- Only what anyone can open: an issue (Fixes #123), a pull request, a published advisory, an RFC, upstream docs. Delete the section when there's none. -->

-

## Checklist

- [ ] Tests cover the change (a fix has a test that fails without it)
- [ ] `docker compose exec php composer qa` passes
- [ ] User-facing changes are in `docs/`, and in the next release's notes in `docs/releases/`
- [ ] Theme or admin templates and CSS still pass the accessibility check (axe-core), and approved screenshots are updated on purpose
- [ ] `@api` changes follow semantic versioning (deprecate before removing)
- [ ] New or changed texts are translatable, with a translation in each language the package ships, and valid ICU
