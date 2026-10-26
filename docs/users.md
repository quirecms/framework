# Users

Quire keeps its users in PostgreSQL, like everything else. A user has an email, a username and a display name, a password (stored only as a hash), roles, and a status: active or suspended. Users who sign in another way, e.g. with single sign-on, can do without an email or a password.

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

Admins manage users in the [admin interface](admin.md#users-and-roles), or on the command line:

```bash
bin/console quire:user:create grace@example.com --name="Grace Hopper" --role=editor
bin/console quire:user:create grace@example.com --role=editor --invite   # she chooses her password
bin/console quire:user:invite grace
bin/console quire:user:invite kim@example.com lin@example.com --role=editor   # adds them first
bin/console quire:user:update grace --name="Grace Hopper" --email=grace@example.org
bin/console quire:user:list
bin/console quire:user:list --role=admin
bin/console quire:user:password grace
bin/console quire:user:role grace --add=admin --remove=editor
bin/console quire:user:suspend grace
bin/console quire:user:activate grace
bin/console quire:user:delete grace --reassign=ada
```

Commands that take a user accept their ID, email or username.

**Deleting a user** who wrote content needs a choice: `--reassign=<user>` gives their content to another user, and `--keep-content` keeps it without an author. Revisions forget who made them either way. Extensions deal with what they keep about the user on the `Quire\User\Event\UserDeleting` event. To keep someone from signing in but keep their account, suspend them instead.

- **Passwords** are asked for twice, without being shown, and must have at least 8 characters. Without a terminal, a strong password is generated and shown once. Passwords are never options, so they don't end up in the shell history or the list of running processes. `--no-password` (when creating) and `--remove` (on `quire:user:password`) leave a user without a password, for users who sign in another way.
- **Usernames** are derived from the email unless you choose one: `grace.hopper@example.com` becomes `grace-hopper`, or `grace-hopper-2` when that's taken. Emails are unique regardless of case.
- **Invitations** email the user a link to choose their password, so nobody else ever knows it. The link works once, for seven days; inviting again replaces it. It works whether or not [password reset](#registration-and-password-reset) is on, but not when signing in with a password is off.
- **Suspended users** can't sign in, and are signed out on their next request. Changing a password signs the user out everywhere, too.
- **The site keeps an active admin:** the last one can't lose the role, be suspended or be deleted, so nobody is locked out of the admin by mistake. Make another user an admin first. (Not when Quire runs in an application whose own users can be admins: those are [mapped in](#users-of-the-application-quire-is-installed-in) on every request.)

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

### Registration and password reset

Three settings, all off by default, let visitors help themselves:

```bash
bin/console quire:settings:set quire.auth.registration true          # anyone can create an account, as a member
bin/console quire:settings:set quire.auth.email_verification true    # …and confirms their email before signing in
bin/console quire:settings:set quire.auth.password_reset true        # "Forgot your password?" emails a link
```

The sign-in page then links to `/register` and `/forgot-password`. Pages of settings that are off answer "404 Not Found".

Emails need a mailer: set `MAILER_DSN` (see [Docker](docker.md#environment-variables)); in development, Mailpit catches them. They come from `quire.mail.from`, or from the site title and `noreply@` the site's domain. Their links use the site's address (`DEFAULT_URI`, Symfony's `framework.router.default_uri`), never the host a request named, so a forged `Host` header can't send someone's link to another site. Without that address, Quire uses the request's host only when `framework.trusted_hosts` checks it, and refuses to send otherwise. Themes can replace the emails in `templates/email/` and the pages in `templates/security/`.

- **Links work once**, for an hour (password reset) or two days (email confirmation), and only the newest one does. A link sent to an email the user has since changed doesn't work. Tokens are stored as hashes, so the database alone gives nobody working links.
- **Nobody learns who has an account.** "Forgot your password?" answers the same for every email. With email confirmation on, registering with an email that has an account also answers "Check your inbox", and emails its owner that someone tried. (Without confirmation, the new user is signed in straight away, so the page has to say the email is taken.)
- **Limits:** each form accepts 5 submissions in 15 minutes from one address, and each account gets at most one email every 2 minutes. A hidden field catches bots that fill in every field. With several servers, give `cache.app` a shared store (e.g. Redis) so the limits count across them.
- **Resetting a password** signs the user out everywhere else, and confirms their email, since they read the link. The token leaves the address bar on arrival, so it can't leak through the browser history or `Referer` headers.
- **Registered users are members.** Extensions hear about them through the `UserRegistered` event, e.g. to send a welcome email. Users created on the command line count as having confirmed their email; changing a user's email unconfirms it.
- **Unconfirmed users** who try to sign in are told to confirm first, with a link to send a new email (`/verify-email`).

### Other ways to sign in

Extensions add ways to sign in, e.g. single sign-on, as Symfony authenticators with `Extend\Auth` (see [extensions](extensions.md#extendauth)). They're used by the firewall that lists `Quire\User\Security\ExtensionAuthenticators`, as Quire's `security.yaml` does, so installing such an extension needs no change to the security configuration. A link to them goes on the sign-in page through the `auth.login_options` slot.

Accounts at other providers are linked to users with `UserWriter::linkIdentity()`, e.g. on their first sign-in. An authenticator that signs people in this way should:

- **Find the user** with `UserRepository::findByIdentity()`, and on the first sign-in create one (as a member, like registration does) and link the account in one database transaction, so a refused link leaves no user behind.
- **Send the user back** to the page that asked them to sign in. The firewall keeps its URL in the session; Symfony's `TargetPathTrait` reads it. A `next` path from the sign-in page's link (`target_path` in the slot) must be a path on the site, never another site's address.
- **Report failures on the sign-in page**: store the exception in the session under `SecurityRequestAttributes::AUTHENTICATION_ERROR` and redirect to `/login`. A `CustomUserMessageAuthenticationException` shows its message there.

Quire's test extension does all three, in `tests/Fixtures/extensions/hello/src/Security/SsoAuthenticator.php` of the [framework repository](https://github.com/quirecms/framework). Listeners of `Quire\User\Event\IdentityLinking` can refuse a link, e.g. for accounts outside the organisation:

```php
#[AsEventListener]
public function __invoke(IdentityLinking $event): void
{
    if ('google' === $event->provider && !str_ends_with((string) ($event->claims['email'] ?? ''), '@example.com')) {
        $event->cancel('Only accounts of example.com can sign in.');
    }
}
```

### Events

| Event | When |
|-------|------|
| `Quire\User\Event\UserLoggingIn` | Before a user signs in, however they sign in. Cancel it to refuse, with a reason shown on the sign-in page. |
| `Quire\User\Event\UserLoggedIn` | After a user signed in |
| `Quire\User\Event\UserLoggedOut` | After a user signed out |
| `Quire\User\Event\UserCreated` | After a user was created, however (see `UserRegistered` for registrations) |
| `Quire\User\Event\UserUpdated` | After a user's profile, roles, status, password or email confirmation changed; compare `$before` with `$user` |
| `Quire\User\Event\UserInvited` | After a user was invited by email to choose their password |
| `Quire\User\Event\UserDeleting` | Before a user is deleted, in its transaction: deal with your data about them |
| `Quire\User\Event\UserDeleted` | After a user was deleted |

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

Visitors who aren't signed in are guests. Extensions can add roles, e.g. `moderator`. What each role may do is set by [permissions](permissions.md).

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

## Users of the application Quire is installed in

When Quire is added to an application that has users of its own, such as a tournament manager with organisers, Quire can use them: whoever the application signs in is a user in Quire too. Map the application's Symfony roles to Quire's in `config/packages/quire.yaml`:

```yaml
quire:
    users:
        host:
            roles:
                ROLE_ORGANIZER: editor
                ROLE_SUPER_ADMIN: [admin]
            display_name: fullName   # optional: a property of the application's user
            email: email             # optional
```

- **Quire signs nobody in.** The application's firewall does, and Quire's sign-in page and `/login` and `/logout` URLs are gone (set `quire.users.login: true` to keep them). Pages that need signing in use the firewall's own way of asking, e.g. the application's sign-in page.
- **Each of the application's users gets a linked Quire user** on their first visit, so content can name them as its author. Quire never reads or changes the application's tables. The link is an identity with the provider `host` and the user identifier as its subject.
- **Roles are mapped on every request**, from the user's Symfony roles including the role hierarchy. A user who stops being an organiser in the application is no longer an editor in Quire on their next request; nothing needs syncing. Roles given in Quire, with `quire:user:role`, add to the mapped ones. The mapped roles are kept with the link for display only.
- **Suspending** a linked user in Quire takes their Quire roles away; they stay signed in to the application.
- **Rules that depend on the application's data**, e.g. "organisers edit the news of their own tournaments", are [rules](permissions.md#rules) in an extension of the application. They get the application's user.

`Quire\User\CurrentUser` gives the Quire user of whoever is signed in, whichever way they were signed in:

```php
$author = $this->currentUser->get();   // null for visitors
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
            custom_authenticators: [Quire\User\Security\ExtensionAuthenticators]
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

`Quire\User\Security\UserChecker` refuses suspended users, whichever way they sign in. Sessions are kept in PostgreSQL by `framework.session.handler_id: quire.session_handler` in `config/packages/framework.yaml`; the handler uses a database connection of its own and the table `quire.session`. To use an application's own users instead, see [above](#users-of-the-application-quire-is-installed-in).
