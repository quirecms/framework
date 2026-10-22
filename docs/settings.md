# Settings

Site settings are values you can change while the site runs, without editing code or redeploying: the site title, how many reviews to show per page, whether comments need approval, and so on. Quire core and each extension declare their own settings, and the values live in PostgreSQL.

## Viewing settings

```bash
bin/console quire:settings:list
```

```
 ------------------ ---------------- ---------- -------- -------------
  Key                Value            Source     Type     Declared by
 ------------------ ---------------- ---------- -------- -------------
  site.description                    default    string   core
  site.title         My site          database   string   core
 ------------------ ---------------- ---------- -------- -------------
```

Pass a prefix to see only some settings, e.g. `bin/console quire:settings:list acme-reviews.`. Sensitive settings, such as API keys, are shown as `(hidden)`.

The **Source** column shows where each value comes from:

| Source | Meaning |
|--------|---------|
| `environment` | An environment variable sets it (see below), and it can't be changed with commands |
| `database` | Someone changed it from its default |
| `default` | The value the setting was declared with |

To print a single value, e.g. in a script:

```bash
bin/console quire:settings:get site.title
```

## Changing settings

```bash
bin/console quire:settings:set site.title "My site"
bin/console quire:settings:set acme-reviews.require_approval false
bin/console quire:settings:set acme-reviews.per_page 50
bin/console quire:settings:set acme-reviews.tags '["php", "cms"]'
```

Values are checked against the setting's type:

| Type | Accepted values |
|------|-----------------|
| `string` | Any text |
| `bool` | `true`, `false`, `1`, `0`, `yes`, `no`, `on`, `off` |
| `int` | Whole numbers, e.g. `20` |
| `float` | Numbers, e.g. `1.5` |
| `enum` | One of the setting's choices |
| `list` | A JSON list of strings, e.g. `'["a", "b"]'` |
| `json` | Any JSON value |

Changes apply to the next request, with no restart.

To go back to the declared default:

```bash
bin/console quire:settings:set site.title --default
```

## Setting values with environment variables

Every setting can be fixed with an environment variable, which takes precedence over the value in the database. This is useful for values that differ per environment, or that must not be changed at runtime.

The variable name is `QUIRE_SETTING__` followed by the key in capitals, with `.` replaced by `__` and `-` by `_`:

| Setting | Environment variable |
|---------|----------------------|
| `site.title` | `QUIRE_SETTING__SITE__TITLE` |
| `acme-reviews.per_page` | `QUIRE_SETTING__ACME_REVIEWS__PER_PAGE` |

Values use the same format as `quire:settings:set`. A setting fixed by an environment variable can't be changed with commands; you'll get an error naming the variable.

> Don't use settings for secrets such as database passwords or OAuth client secrets. Use [Symfony secrets](https://symfony.com/doc/current/configuration/secrets.html) or plain environment variables for those.

## Core settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `site.title` | string | `Quire` | Shown in the browser tab and the site header |
| `site.description` | string | (empty) | A short description of the site, used by search engines and when the site is shared |
| `site.locale` | string | `en` | The language of the site and of new content, as a language tag such as `en`, `de` or `pt-BR` |
| `quire.auth.password_login` | bool | `true` | Whether users can sign in with a password (see [users](users.md#signing-in)) |
| `theme.active` | string | `quire-theme-default` | The site's theme. Change it with `quire:theme:use` (see [themes](themes.md)). |
| `quire.content.revisions_keep` | int | `50` | How many revisions to keep per content item. Older ones are deleted when a new one is saved. `0` keeps all. |

Extensions and themes document their own settings. Templates can read the settings marked as exposed, among them `site.title`, `site.description` and `site.locale` (through the `site` variable) and every theme setting.
