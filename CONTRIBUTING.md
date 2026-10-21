# Contributing to Quire

Thanks for helping to improve Quire! This guide explains how to set up the project, what we expect from changes, and how to get them merged.

## Before you start

- **Security issues:** don't open a public issue. Follow the [security policy](SECURITY.md).
- **Bugs:** search the [existing issues](https://github.com/quirecms/framework/issues) first. If yours is new, open an issue with steps to reproduce, what you expected, and what happened instead.
- **New features and larger changes:** open an issue to discuss them before writing code. Quire keeps its core deliberately small, so many features are better built as extensions. Agreeing on the approach first saves you from writing code that can't be merged.
- **Small fixes** (typos, docs, obvious bugs) can go straight to a pull request.

## Setting up

You need Docker with Docker Compose v2.10 or later. PHP, Composer and PostgreSQL all run inside the containers.

```bash
git clone https://github.com/quirecms/framework.git quire
cd quire
docker compose up --wait
docker compose exec php bin/console -e test doctrine:database:create --if-not-exists
```

The site runs at <https://quire.localhost>. See the [README](README.md) for other ports and [docs/docker.md](docs/docker.md) for details of the Docker setup.

On Linux, run commands in the container as your own user so the files they create aren't owned by root:

```bash
docker compose exec -u "$(id -u):$(id -g)" -e HOME=/tmp php composer test
```

## Repository layout

This is a monorepo. Develop everything here, even code that's published as separate packages.

| Path | Contents |
|------|----------|
| `packages/core/` | `quire/core`, the engine |
| `packages/…` | First-party extensions, themes and tools (more to come) |
| `config/`, `src/`, `templates/`, `public/`, `tests/` | The application used to run and test the packages |

The application installs the packages from `packages/` through Composer path repositories, so your changes apply immediately.

## Making changes

1. Fork the repository and create a branch from `main`, named after what it does, e.g. `fix-health-check-timeout` or `add-settings-extender`.
2. Make your change, with tests (see below).
3. Run all checks:

   ```bash
   docker compose exec php composer qa
   ```

   CI runs the same checks, and a pull request can only be merged when they pass. `composer fix` applies coding-standard fixes and automated refactorings for you.
4. Open a pull request against `main`.

### Code guidelines

- Every PHP file starts with `declare(strict_types=1);`.
- Classes are `final` unless they're designed to be extended.
- Use constructor injection. Don't use service locators or static facades.
- Code must pass PHPStan at its strictest level (`level: max`). Fix the underlying type problem instead of adding ignores or baseline entries.
- Mark classes and interfaces that extensions may rely on with `@api`. They're covered by semantic versioning, so changing them is a breaking change.
- Long-running workers reuse services across requests. Don't keep request-specific state in services, or implement `Symfony\Contracts\Service\ResetInterface`.
- Quire stores all data in PostgreSQL. Don't add file-based storage.

### Tests

- Fixes need a test that fails without the fix.
- New features need tests for their normal behaviour and their edge cases.
- Unit tests for a package live in that package's `tests/` folder (e.g. `packages/core/tests/`). Tests that need the whole application live in the root `tests/` folder.
- Run a single test with `composer test -- --filter NameOfTest`.

### Commit messages

- Write the subject line in the imperative, e.g. "Add settings extender", not "Added settings extender". Keep it under about 72 characters.
- Use the body to explain *why* the change is needed, if that isn't obvious.
- Pull requests are usually squash-merged, so the pull request title and description become the final commit message. Write them with that in mind.

## Pull requests

A good pull request:

- does one thing; unrelated changes go in separate pull requests;
- explains what it changes and why, and links the related issue (`Fixes #123`);
- includes tests, and documentation updates when behaviour visible to users changes;
- passes CI.

A maintainer will review it. We may ask for changes. Please push new commits instead of force-pushing, so the review is easier to follow; we squash them when merging.

## License

By contributing, you agree that your contributions are licensed under the [MIT license](LICENSE), the same license as the project.
