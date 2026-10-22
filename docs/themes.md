# Themes

A theme decides how the site looks: it's a Composer package of templates and assets that the site uses instead of Quire's plain built-in templates. A site has one active theme. A theme can inherit from another and change only the templates it needs to.

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
├── assets/
│   ├── style.css
│   └── logo.svg
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

`templates/layout.html.twig` is the page around every page Quire renders. Quire's own templates extend it and fill the blocks `title`, `head` and `content`, and give it `canonical_url` when the page has one. A theme's templates must render the [standard slots](#slots).

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

## Slots

Slots are the places in a theme's templates where extensions add their parts, e.g. reviews below a post. A theme renders a slot with `quire_slot()`, passing what the slot is about:

```twig
<article>
    <h1>{{ content.title }}</h1>
    {{ body|raw }}
    {{ quire_slot('content.after_body', { content }) }}
</article>
```

Extensions fill slots in their `extend.php` (see [`Extend\Views`](extensions.md#extendviews)), with a template, which gets the context as variables, or with a PHP class:

```php
new Extend\Views()
    ->namespace('reviews', __DIR__.'/templates')
    ->slot('content.after_body', '@reviews/summary.html.twig', priority: 10)
```

A slot shows its fragments by priority, highest first. In debug mode, an HTML comment before each fragment names the extension it comes from, e.g. `<!-- slot content.after_body: acme-reviews -->`. When a fragment fails in production, it's left out and the error is logged, so one extension can't break every page.

Every theme renders these standard slots, so extensions work with any theme. Quire's built-in templates render them too:

| Slot | Where | Context |
|------|-------|---------|
| `head` | Inside `<head>`, e.g. meta tags and stylesheets | none |
| `body_start` | Right after `<body>` | none |
| `body_end` | Right before `</body>`, e.g. scripts | none |
| `header.nav` | The navigation in the site header | none |
| `content.before_title` | Before a content item's title | `content` |
| `content.after_title` | After the title, e.g. date and author | `content` |
| `content.before_body` | Before the body | `content` |
| `content.after_body` | After the body, e.g. reviews or share buttons | `content` |
| `content.footer` | At the end of the item, e.g. comments | `content` |
| `sidebar` | The sidebar, when the theme has one | none |
| `footer` | The site footer | none |

Themes can add slots of their own; name them after the theme, e.g. `acme-dark.hero`. To leave out a wrapper when nothing fills a slot, check with `quire_slot_filled()`:

```twig
{% if quire_slot_filled('sidebar') %}<aside>{{ quire_slot('sidebar') }}</aside>{% endif %}
```

## The template API

Every template, of a theme, an extension or the site, has:

| Name | What it is |
|------|------------|
| `site` | `site.title`, `site.description`, `site.locale` (for `<html lang>`) and `site.url` (the homepage's absolute URL) |
| `theme` | `theme.id`, `theme.title` and `theme.settings` (see [theme settings](#theme-settings)) |
| `quire_slot(name, context)`, `quire_slot_filled(name)` | Render a [slot](#slots), check whether anything fills it |
| `quire_setting(key)` | A setting's value, for settings exposed to templates; others raise an error, so private settings such as API keys can't end up in a page |
| `quire_extension_enabled(id)` | Whether an extension is enabled, e.g. to show a link to its page |
| `quire_asset(path)` | The versioned URL of an [asset](#assets), e.g. `quire_asset('theme/logo.svg')` |
| `quire_content()`, `quire_url()`, `quire_listing_url()`, `quire_home_url()`, `quire_body()`, `quire_excerpt()` | Content and URLs (see [content](content.md)) |

## Theme settings

A theme declares its settings, such as colours or toggles, in its `extend.php`. Their keys start with `theme.`, the theme's ID and a dot:

```php
return [
    new Extend\Settings()
        ->define('theme.acme-dark.accent', 'string', '#7c3aed', label: 'Accent colour', widget: 'color')
        ->define('theme.acme-dark.show_dates', 'bool', true, label: 'Show dates on posts'),
];
```

Site admins change them like other settings (`quire:settings:set theme.acme-dark.accent "#0ea5e9"`), and templates read them without the prefix:

```twig
<style>:root { --accent: {{ theme.settings.accent }}; }</style>
{% if theme.settings.show_dates %}…{% endif %}
```

`theme.settings` includes the settings of the themes the active theme inherits from. When a child theme declares a setting with the same name as its parent's, the child's wins.

## Assets

A theme's `assets/` directory holds its stylesheets, scripts, images and fonts. They're handled by Symfony's AssetMapper, with no Node.js and no build step: every file gets a version in its URL (`/assets/acme-dark/style-3f2a1b.css`), so browsers cache it for good and fetch it again only when it changes.

Files are available as `<theme-id>/<path>`, and as `theme/<path>` for the active theme: that's the active theme's file, or its parent's when it doesn't have one. So a child theme replaces a file of its parent by having one at the same path, just like templates.

Add the stylesheets and scripts every page needs in the theme's `extend.php`:

```php
return [
    new Extend\Assets()
        ->stylesheet('theme/style.css')
        ->script('theme/menu.js'),
];
```

They're added to the [`head` slot](#slots), so the layout only has to render it. Link other files with `quire_asset()`:

```twig
<img src="{{ quire_asset('theme/logo.svg') }}" alt="{{ site.title }}">
```

A stylesheet can refer to its neighbours with relative URLs (`url('fonts/inter.woff2')`), and a script can import its neighbours with relative paths (`import './menu-toggle.js'`); AssetMapper versions those too. `quire_asset()` raises an error for a file that doesn't exist, rather than linking to nothing.

The site has its own `assets/` directory and `importmap.php`, loaded on every page after the theme's: put site-specific styles in `assets/styles/app.css`, and add JavaScript packages with `bin/console importmap:require <package>`.

Themes that want Sass, Tailwind or a bundler can use Symfony's `symfonycasts/tailwind-bundle`, `symfonycasts/sass-bundle` or `pentatrion/vite-bundle` and ship the built files in `assets/`; Quire neither requires nor prevents them.
