# Users

Quire keeps its users in PostgreSQL, like everything else. A user has an email, a username and a display name, a password (stored only as a hash), roles, and a status: active or suspended. Users who sign in another way, e.g. with single sign-on, can do without an email or a password.

> Signing in through the website, permissions and single sign-on come in the next releases. Until then, users are managed on the command line.

## The first admin

A new site gets its first admin from the installer, which also prepares the database and asks for the site's title and language:

```bash
bin/console quire:install
```

Without a terminal, e.g. in a deployment script, pass the admin's email; a strong password is generated and shown once:

```bash
bin/console quire:install --no-interaction --admin-email=ada@example.com --title="Ada's notes"
```

Once the site has an admin, the installer changes nothing.

## Managing users

```bash
bin/console quire:user:create grace@example.com --name="Grace Hopper" --role=editor
bin/console quire:user:list
bin/console quire:user:list --role=admin
bin/console quire:user:password grace
bin/console quire:user:role grace --add=admin --remove=editor
bin/console quire:user:suspend grace
bin/console quire:user:activate grace
```

Commands that take a user accept their ID, email or username.

- **Passwords** are asked for twice, without being shown, and must have at least 8 characters. Without a terminal, a strong password is generated and shown once. Passwords are never options, so they don't end up in the shell history or the list of running processes. `--no-password` (when creating) and `--remove` (on `quire:user:password`) leave a user without a password, for users who sign in another way.
- **Usernames** are derived from the email unless you choose one: `grace.hopper@example.com` becomes `grace-hopper`, or `grace-hopper-2` when that's taken. Emails are unique regardless of case.
- **Suspended users** can't sign in, and are signed out on their next request. Changing a password signs the user out everywhere, too.

## Roles

| Role | For |
|------|-----|
| `member` | Registered users, e.g. who comment |
| `editor` | Writing and publishing content |
| `admin` | Running the site |

Visitors who aren't signed in are guests. What each role may do, and roles of extensions, come with permissions in a later release.

## In code

`Quire\User\User` is the user. It's also the user of Symfony's security, so `$this->getUser()` in a controller, `Security::getUser()` in a service and `app.user` in a template give you a Quire user:

```twig
{% if app.user %}Signed in as {{ app.user.displayName }}{% endif %}
```

Its Symfony roles are `ROLE_USER` plus `ROLE_<NAME>` for each of its roles, e.g. `ROLE_EDITOR`.

Find users with `Quire\User\UserRepository` (by ID, email, username, or an identity at another provider), and change them with `Quire\User\UserWriter`, which keeps the rules: unique emails and usernames, hashed passwords, known roles.

```php
$user = $writer->create(new CreateUser('Grace Hopper', 'grace@example.com', password: $password, roles: [Role::EDITOR]));
$writer->update(new UpdateUser($user->id)->displayName('Grace B. Hopper'));
$writer->linkIdentity($user->id, 'github', '1234567', ['login' => 'grace']);
```

## Security configuration

Quire adds two things to Symfony's security configuration: a password hasher for its users, and a user provider named `quire_users`. Which firewalls use them is up to the application. The application in this repository uses them for its main firewall, in `config/packages/security.yaml`:

```yaml
security:
    firewalls:
        main:
            lazy: true
            provider: quire_users
            user_checker: Quire\User\Security\UserChecker
```

`Quire\User\Security\UserChecker` refuses suspended users, whichever way they sign in. An application that Quire was added to keeps its own firewalls and users; using them for Quire is described with single sign-on and host applications in a later release.
