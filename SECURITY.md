# Security policy

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues, discussions or pull requests.**

Report them privately through GitHub instead:

1. Go to the [Security tab](https://github.com/quirecms/framework/security) of this repository.
2. Click **Report a vulnerability**.
3. Describe the issue. Include as much of the following as you can:
   - the affected package (e.g. `quire/core`) and version or commit;
   - the type of issue (e.g. SQL injection, cross-site scripting, authentication bypass);
   - steps to reproduce, or a proof of concept;
   - the impact, i.e. what an attacker could achieve.

Only the maintainers can see your report.

## What happens next

- We acknowledge your report within **3 working days**.
- We confirm or rule out the issue, and keep you updated while we work on a fix.
- We aim to release a fix within **90 days** of the report, sooner for critical issues.
- Once a fix is released, we publish a security advisory and credit you, unless you prefer to stay anonymous.

Please give us a reasonable chance to fix the issue before you disclose it publicly.

## Supported versions

Quire is in early development and hasn't had a stable release yet. Until 1.0, security fixes go only into the latest code on the `main` branch.

After 1.0, this section will list which release lines receive security fixes.

## Scope

This policy covers the code in this repository: `quire/core`, the first-party extensions and themes, and the Docker setup.

Vulnerabilities in third-party extensions should be reported to their authors. Vulnerabilities in Quire's dependencies (Symfony, Doctrine, FrankenPHP, PostgreSQL, …) should be reported to those projects. If you're not sure where an issue belongs, report it here and we'll help route it.
