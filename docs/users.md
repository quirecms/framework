# Users

Quire keeps its users in PostgreSQL, like everything else. A user has an email, a username and a display name, a password (stored only as a hash), roles, and a status: active or suspended. Users who sign in another way, e.g. with single sign-on, can do without an email or a password.

> Permissions and single sign-on come in the next releases.

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

## Signing in

Users sign in at `/login` (under Quire's prefix, when it has one) with their email or username and their password, and sign out with the link the sign-in page shows them. The page comes from the theme, so it looks like the rest of the site; it's translated, and themes can replace it (see [themes](themes.md#the-sign-in-page)).

- **Failed attempts** say "Invalid credentials" whether or not the account exists, so the sign-in page can't be used to find out who has an account. A suspended account is only reported to someone who typed its correct password.
- **Guessing passwords:** after 5 failed attempts for an account from one address within a minute, that account can't sign in from that address for a minute.
- **"Keep me signed in on this device"** keeps a user signed in for 30 days with a signed cookie, which stops working when the password changes.
- **After signing in**, users go back to the page they came from when a link passes it, e.g. `/login?next=/drafts`, and to the homepage otherwise. Addresses of other sites are ignored, so a link to the sign-in page can't send people elsewhere.
- **The sign-in page is never cached and can't be shown inside other sites**, so it can't be framed to capture passwords.
- **Sessions are kept in PostgreSQL**, so users stay signed in across restarts and deployments, and on every server when there are several.

Turn off signing in with a password, e.g. when everyone signs in with single sign-on:

```bash
bin/console quire:settings:set quire.auth.password_login false
```

The sign-in page then shows only the other ways to sign in that extensions add.

### Events

| Event | When |
|-------|------|
| `Quire\User\Event\UserLoggingIn` | Before a user signs in, however they sign in. Cancel it to refuse, with a reason shown on the sign-in page. |
| `Quire\User\Event\UserLoggedIn` | After a user signed in |
| `Quire\User\Event\UserLoggedOut` | After a user signed out |

```php
#[AsEventListener]
public function __invoke(UserLoggingIn $event): void
{
    if ($this->maintenance->isOn() && !$event->user->hasRole(Role::ADMIN)) {
        $event->cancel('Only admins can sign in during maintenance.');
    }
}
```

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

Quire adds two things to Symfony's security configuration: a password hasher for its users, and a user provider named `quire_users`. Which firewalls use them, and how, is up to the application, so an application that Quire was added to keeps its own security setup. The application in this repository signs users in on Quire's sign-in page, in `config/packages/security.yaml`:

```yaml
security:
    firewalls:
        main:
            lazy: true
            provider: quire_users
            user_checker: Quire\User\Security\UserChecker
            form_login:
                login_path: quire_login
                check_path: quire_login
                enable_csrf: true
                default_target_path: quire_home
            logout:
                path: quire_logout
                target: quire_home
                enable_csrf: true
            remember_me:
                secret: '%kernel.secret%'
                lifetime: 2592000
                signature_properties: [password]
            login_throttling:
                max_attempts: 5
                interval: '1 minute'
```

`Quire\User\Security\UserChecker` refuses suspended users, whichever way they sign in. Sessions are kept in PostgreSQL by `framework.session.handler_id: quire.session_handler` in `config/packages/framework.yaml`; the handler uses a database connection of its own and the table `quire.session`. Using an application's own users for Quire is described with single sign-on and host applications in a later release.
