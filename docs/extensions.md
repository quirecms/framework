# Extensions

Almost everything in Quire beyond the core engine is an extension: the admin panel, single sign-on, comments, reviews. Extensions are Composer packages, so you install them like any other PHP dependency, then enable them.

> Extensions are full PHP code with the same access as Quire itself. Only install extensions you trust.

## Managing extensions

### List extensions

```bash
bin/console quire:extension:list
```

```
 --------------- ------------- -------------- --------------- --------- ----------- ---------------
  ID              Status        Title          Package         Version   Type        Requires
 --------------- ------------- -------------- --------------- --------- ----------- ---------------
  acme-comments   enabled (1)   Comments       acme/comments   1.0.0     Extension
  acme-reviews    enabled (2)   Star reviews   acme/reviews    1.0.0     Extension   acme-comments
  acme-seo        disabled      Seo            acme/seo        2.1.0     Extension
 --------------- ------------- -------------- --------------- --------- ----------- ---------------
```

| Status | Meaning |
|--------|---------|
| `enabled (n)` | Runs. `n` is its position in the boot order: extensions load after the extensions they depend on. |
| `disabled` | Installed, but not enabled |
| `broken` | Enabled, but can't run, for example because an extension it requires isn't enabled. The reason is printed below the table. The rest of the site keeps working. |
| `missing` | Enabled, but its package is no longer installed |

Add `--format=json` for output that scripts can read.

### Enable extensions

```bash
bin/console quire:extension:enable acme-reviews
```

If the extension requires other extensions that aren't enabled yet, Quire lists them and asks before enabling them too. Pass `--no-interaction` to skip the question, or `--dry-run` to see what would change without changing anything.

You can enable several extensions at once: `bin/console quire:extension:enable acme-reviews acme-seo`.

### Disable extensions

```bash
bin/console quire:extension:disable acme-seo
```

Disabling keeps the extension's data, so enabling it again restores it as it was.

An extension can't be disabled while another enabled extension requires it. Disable both together instead:

```bash
bin/console quire:extension:disable acme-comments acme-reviews
```

### Uninstalling an extension

Disable it first, then remove the package:

```bash
bin/console quire:extension:disable acme-seo
composer remove acme/seo
```

If you remove a package that's still enabled, it shows up as `missing` in the list. Quire keeps running without it. Disable it to clear the warning.

### Pinning extensions in production

By default, the enabled extensions are stored in the database, so they can be changed while the site runs. For production images that should never change at runtime, fix the list in `config/packages/quire.yaml` instead:

```yaml
quire:
    extensions:
        pinned: true
        enabled: [acme-comments, acme-reviews]
```

With `pinned: true`, the enable and disable commands refuse to make changes. To change the enabled extensions, edit the list and deploy again.

## Writing an extension

An extension is a Composer package with the type `quire-extension`. Themes use `quire-theme`.

```json
{
    "name": "acme/reviews",
    "type": "quire-extension",
    "description": "Star ratings and reviews for any content.",
    "require": {
        "quire/core": "^0.1"
    },
    "extra": {
        "quire": {
            "title": "Reviews",
            "requires": ["acme-comments"],
            "optional": ["acme-seo"]
        }
    }
}
```

### The `extra.quire` manifest

Every key is optional.

| Key | Default | Description |
|-----|---------|-------------|
| `id` | Derived from the package name: `acme/reviews` → `acme-reviews` | Stable identifier, used in commands and by other extensions. Lowercase letters, digits and single hyphens only. Don't change it after release. |
| `title` | Derived from the package name: `acme/quire-reviews` → `Reviews` | Human-readable name |
| `description` | The package's Composer `description` | One-sentence summary |
| `category` | none | Grouping for admin interfaces, e.g. `content` |
| `icon` | none | Icon name for admin interfaces, e.g. `star` |
| `requires` | `[]` | IDs of extensions that must be enabled for this one to run. They're enabled automatically, after confirmation. |
| `optional` | `[]` | IDs of extensions that, when enabled, load before this one. Use it when your extension integrates with another but works without it. |

`requires` and `optional` list extension **IDs** (`acme-comments`), not package names (`acme/comments`). Also add required extensions to your Composer `require` section, so Composer installs them.

Mistakes in the manifest are reported with the package name and how to fix them, for example:

```
[acme/reviews] Invalid Quire manifest in composer.json: "extra.quire.require" is not a known key. Did you mean "requires"?
```

> Registering routes, templates, settings and other behaviour from an extension is coming next. This page will document it as it lands.
