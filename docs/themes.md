# Themes

A theme decides how the site looks: it's a Composer package of templates (and, later, assets) that the site uses instead of Quire's plain built-in templates. A site has one active theme. A theme can inherit from another and change only the templates it needs to.

## Using a theme

Install the theme with Composer, then make it the site's theme:

```bash
composer require acme/dark
bin/console quire:theme:list
bin/console quire:theme:use acme-dark
bin/console quire:theme:use --none    # back to Quire's built-in templates
```

`quire:theme:use` checks that the theme, the themes it inherits from and the extensions they require are installed, stores the choice in the `theme.active` setting, and rebuilds the application. When Quire runs on several servers, the others notice the change within a few seconds and rebuild by themselves, as they do when an extension is enabled.

If the active theme can't be used later, e.g. because the theme it inherits from was uninstalled, the site keeps working with Quire's built-in templates and `quire:theme:list` reports the problem.

### Pinning the theme in production

When the extensions are pinned in configuration (see [extensions](extensions.md#pinning-extensions-in-production)), so is the theme:

```yaml
quire:
    extensions:
        pinned: true
        enabled: [acme-comments]
        theme: acme-dark
```

Changing it at runtime is then refused; edit the configuration and deploy again.

## Where templates come from

Quire looks for a template in these places, and uses the first it finds:

1. the site's `templates/` directory
2. the active theme's `templates/`
3. the `templates/` of the themes it inherits from, closest first
4. Quire's built-in templates

So to change one template, copy it to the same path in `templates/` and edit it there. Content templates are found by type and item, most specific first, e.g. `content/post/hello-world.html.twig`, then `content/post/single.html.twig`, then `content/single.html.twig` (see [content](content.md#templates)).

A template that replaces another can still build on it. Each theme's templates are also available under the theme's ID:

```twig
{# templates/content/single.html.twig in the site or a child theme #}
{% extends '@acme-dark/content/single.html.twig' %}

{% block body %}
    <p class="notice">Comments are closed this week.</p>
    {{ parent() }}
{% endblock %}
```

### Changing an extension's templates

Extensions render templates of their own, e.g. `@reviews/summary.html.twig`. A site or a theme changes one by placing a file at `templates/extensions/<namespace>/<path>`, e.g. `templates/extensions/reviews/summary.html.twig`. The order is the same as above: the site's file wins over the theme's, which wins over the extension's. To build on the extension's own version, refer to it with `@!`:

```twig
<div class="card">{% include '@!reviews/summary.html.twig' %}</div>
```

## Writing a theme

A theme is a Composer package of type `quire-theme`:

```
acme-dark/
├── composer.json
├── extend.php          (optional)
└── templates/
    ├── layout.html.twig
    └── content/
        └── single.html.twig
```

```json
{
    "name": "acme/dark",
    "type": "quire-theme",
    "require": {
        "quire/core": "^0.1"
    },
    "extra": {
        "quire": {
            "title": "Dark",
            "requires": ["acme-slider"],
            "theme": {
                "parent": "quire-theme-default"
            }
        }
    }
}
```

`extra.quire` takes the same keys as an extension's (see [extensions](extensions.md#the-extraquire-manifest)), with `requires` listing extensions the theme needs, plus a `theme` section:

| Key | Meaning |
|-----|---------|
| `theme.parent` | ID of the theme this one inherits from. Templates the theme doesn't have come from the parent. |

The theme's ID comes from the package name (`acme/dark` becomes `acme-dark`) unless `extra.quire.id` sets one. IDs of themes and extensions share one namespace.

`templates/layout.html.twig` is the page around every page Quire renders. Quire's own templates extend it and fill the blocks `title`, `head` and `content`, and give it the variables `site_title`, `site_locale` and, when the page has one, `canonical_url`.

### `extend.php`

A theme can have an `extend.php` like an extension's (see [extensions](extensions.md#extendphp-registering-behaviour)). It runs only while the theme is active, or is a parent of the active theme, after the extensions' and before the site's. The parent's runs before the child's.

### Testing a theme

`quire/testing` runs a theme the way it runs extensions:

```php
final class DarkThemeTest extends ExtensionTestCase
{
    protected static array $extensions = ['acme-slider'];

    protected static ?string $theme = 'acme-dark';

    public function testPostsUseTheThemesLayout(): void
    {
        // …
    }
}
```
