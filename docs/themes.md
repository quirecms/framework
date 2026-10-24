# Themes

A theme decides how the site looks: it's a Composer package of templates and assets that the site uses instead of Quire's plain built-in templates. A site has one active theme. A theme can inherit from another and change only the templates it needs to.

## The default theme

New sites use `quire/theme-default` (ID `quire-theme-default`): plain semantic HTML and CSS, no JavaScript, responsive, with a light and a dark look that follows the visitor's system setting. It meets WCAG 2.2 AA: every colour combination has at least 4.5:1 contrast, it has a skip link, landmarks and visible focus, and it's checked with axe. It writes right-to-left languages mirrored, and it comes in English and German.

Its settings:

| Setting | Values | Default |
|---------|--------|---------|
| `theme.quire-theme-default.accent` | `blue`, `green`, `purple`, `orange`, `red`: the colour of links and highlights | `blue` |
| `theme.quire-theme-default.show_dates` | Whether posts show their date | `true` |

To change more, make a child theme with `"parent": "quire-theme-default"` (see [Writing a theme](#writing-a-theme)), or override single templates in the site's `templates/`. Its stylesheet is built on CSS custom properties, so a few lines restyle it:

```css
/* assets/styles/app.css of the site, or a child theme's stylesheet */
:root { --font-body: Georgia, serif; --measure: 40rem; }
```

Its templates are the reference for writing your own: each one starts with a comment listing the variables, blocks and slots it has.

## Using a theme

Install another theme with Composer, then make it the site's theme:

```bash
composer require acme/dark
bin/console quire:theme:list
bin/console quire:theme:use acme-dark
bin/console quire:theme:use --none    # Quire's minimal built-in templates
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

## Your first theme

Generate a theme, and the site uses it right away:

```bash
bin/console quire:make:theme acme/dark
```

This creates `themes/dark/`, a child of the default theme: it looks the same until you change it, installs it with Composer (from the local directory, so edits show up immediately), and makes it the site's theme. Then:

1. **Change the look** in `themes/dark/assets/styles.css`. The default theme is built on CSS custom properties, so a few lines go a long way:
   ```css
   :root { --font-body: Georgia, serif; --measure: 40rem; }
   ```
2. **Change a template** by giving the theme its own version: copy e.g. `content/single.html.twig` from the default theme (`vendor/quire/theme-default/templates/`) to `themes/dark/templates/content/single.html.twig` and edit it, or extend the original and change one block:
   ```twig
   {% extends '@quire-theme-default/content/single.html.twig' %}

   {% block content %}
       <p class="notice">Comments are closed this week.</p>
       {{ parent() }}
   {% endblock %}
   ```
3. **Check it** with `bin/console quire:theme:lint` (see [Checking a theme](#checking-a-theme)), and run its tests with `vendor/bin/phpunit -c themes/dark`.
4. **Share it**: the theme is a Composer package. Push `themes/dark/` to a Git repository of its own, then install it on other sites from there (a `vcs` repository in their `composer.json`), from [Packagist](https://packagist.org) once you submit it, or from Private Packagist or Satis. Version it with Git tags.

To start from a blank page instead, without inheriting the default theme, pass `--no-parent`: the theme then has its own layout and content templates, which render every standard slot. `--parent=<id>` inherits from another theme, and `--no-install` only creates the files.

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

The admin interface renders `admin.head` in its `<head>`: the stylesheets and scripts of the group `admin` (see [extensions](extensions.md#extendassets)). Themes don't render it.

Themes can add slots of their own; name them after the theme, e.g. `acme-dark.hero`. To leave out a wrapper when nothing fills a slot, check with `quire_slot_filled()`:

```twig
{% if quire_slot_filled('sidebar') %}<aside>{{ quire_slot('sidebar') }}</aside>{% endif %}
```

## The template API

Every template, of a theme, an extension or the site, has:

| Name | What it is |
|------|------------|
| `site` | `site.title`, `site.description`, `site.locale` (the site's language), `site.url` (the homepage's absolute URL) and `site.direction` (`ltr`, or `rtl` for languages such as Arabic and Hebrew) |
| `theme` | `theme.id`, `theme.title` and `theme.settings` (see [theme settings](#theme-settings)) |
| `quire_slot(name, context)`, `quire_slot_filled(name)` | Render a [slot](#slots), check whether anything fills it |
| `quire_setting(key)` | A setting's value, for settings exposed to templates; others raise an error, so private settings such as API keys can't end up in a page |
| `quire_extension_enabled(id)` | Whether an extension is enabled, e.g. to show a link to its page |
| `quire_asset(path)` | The versioned URL of an [asset](#assets), e.g. `quire_asset('theme/logo.svg')` |
| `quire_seo()` | In the `<head>`: the page's description, canonical URL, Open Graph and Twitter tags, and structured data (see [SEO](#seo)) |
| `date\|quire_date(style)` | A date written the way the page's language writes it, e.g. "March 4, 2025" or "4. März 2025"; styles `long` (default), `medium`, `short`, `full` |
| `app.request.locale` | The page's language: the site's, or a content item's own, e.g. for `<html lang="…">` |
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

A theme's scripts import npm packages by name when it lists them in `extra.quire.importmap` of its `composer.json`, e.g. `{"@hotwired/stimulus": "3.2.2"}`: sites download them, so don't copy libraries into `assets/`. See [JavaScript packages](extensions.md#javascript-packages).

The site has its own `assets/` directory and `importmap.php`, loaded on every page after the theme's: put site-specific styles in `assets/styles/app.css`, and add JavaScript packages with `bin/console importmap:require <package>`.

Themes that want Sass, Tailwind or a bundler can use Symfony's `symfonycasts/tailwind-bundle`, `symfonycasts/sass-bundle` or `pentatrion/vite-bundle` and ship the built files in `assets/`; Quire neither requires nor prevents them.

## Previews

Users who may preview unpublished items (see [permissions](permissions.md#abilities-of-content)) see them at their URL. The content template then gets `preview` set to true. A theme's own `content/single.html.twig` should say so on the page, as the default theme does with a `.notice`, so nobody mistakes a draft for what visitors see.

## The sign-in page

Quire's sign-in page, `security/login.html.twig`, extends the theme's `layout.html.twig`, so it looks like the rest of the site; style it with the classes it uses (`sign-in`, `form`, `form-field`, `form-check`, `form-error`), or replace it with a `templates/security/login.html.twig` of the theme. A replacement keeps:

- the form posting `_username`, `_password`, `_remember_me` and `_csrf_token` (`csrf_token('authenticate')`) to `path('quire_login')`, and `_target_path` when `target_path` is set;
- the error, `error.messageKey|trans(error.messageData, 'security')`;
- the slot `auth.login_options`, with `{ target_path }`, where extensions add other ways to sign in, e.g. "Continue with Google".

The variables are listed at the top of Quire's template.

The account pages work the same way, with the same classes plus `form-hint`, and can be replaced the same way: `security/register.html.twig`, `forgot_password.html.twig`, `reset_password.html.twig`, `resend_verification.html.twig`, `check_inbox.html.twig` and `link_expired.html.twig`. Each form posts its `_csrf_token` (`csrf_token('register')`, `'forgot_password'`, `'reset_password'`, `'resend_verification'`) and its fields, as Quire's templates show. So do the emails, in `templates/email/`: `layout.html.twig`, and an `.html.twig` and a `.txt.twig` for each of `verify_email`, `password_reset` and `account_exists`. Style emails inline: email clients ignore most CSS.

## Languages

Quire's pages are in the site's language (`site.locale`), and an item's page in the item's own language when it's a translation. Write the theme's texts in English in the templates and translate them with Symfony's translator, in a domain named after the theme:

```twig
<a class="skip-link" href="#content">{{ 'Skip to content'|trans({}, 'acme-dark') }}</a>
{{ 'Page {page} of {count}'|trans({ page: 2, count: 5 }, 'acme-dark') }}
```

The translations go in the theme's `translations/` directory, in ICU MessageFormat, one file per language: `acme-dark+intl-icu.en.yaml` (the English texts as they are, which other languages fall back to) and e.g. `acme-dark+intl-icu.de.yaml`. Extensions do the same with their ID as the domain. When several have the same text, the active theme's translation wins over its parent's, and the site's own `translations/` win over all of them, so a site can change any wording.

Set `<html dir="{{ site.direction }}">` and use logical CSS properties (`margin-inline-start` rather than `margin-left`), and the theme works for right-to-left languages too.

## SEO

`quire_seo()` in the layout's `<head>` prints what search engines and social networks read: the description (the item's excerpt, or the site description), the canonical URL, Open Graph and Twitter tags, and structured data (JSON-LD), a `BlogPosting` for posts and a `WebSite` for the homepage. Extensions change them by listening to `Quire\Theme\Event\SeoTagsBuilding`:

```php
#[AsEventListener]
public function __invoke(SeoTagsBuilding $event): void
{
    $event->tags->imageUrl ??= $this->covers->url($event->content);
    $event->tags->meta['robots'] = 'max-image-preview:large';
}
```

## Error pages

A theme provides the pages for errors in `templates/bundles/TwigBundle/Exception/`: `error404.html.twig` for addresses where nothing is published, `error.html.twig` for everything else, as in any Symfony application. They're shown in production; development shows the error itself. The default theme has both.

## Checking a theme

```bash
bin/console quire:theme:lint             # the site's theme
bin/console quire:theme:lint acme-dark   # any installed theme
```

It checks, without rendering a page, the templates the site would use with the theme: the theme's, its parents' where it has none, and Quire's built-in ones where neither has one. It reports templates that don't compile (including functions nothing provides, e.g. of an extension that isn't enabled), standard slots no template renders, and files linked with `quire_asset()` that don't exist, and warns when no template calls `quire_seo()`. It exits with an error code when something is wrong, so it can run in CI.

The theme generated by `quire:make:theme` runs it in its own tests.

### Accessibility

The default theme is checked with [axe-core](https://github.com/dequelabs/axe-core) against WCAG 2.2 A and AA, in light and dark mode and at desktop and phone widths, in Quire's CI. Check your own theme the same way: with the site running, run the script in `tests/Accessibility/` in the Playwright image, passing the site's address and the pages to check:

```bash
playwright=$(sed -n 's/.*"playwright": "\([^"]*\)".*/\1/p' tests/Accessibility/package.json)
docker run --rm --network host -v "$PWD/tests/Accessibility:/work" -w /work "mcr.microsoft.com/playwright:v${playwright}-noble" \
    sh -c 'npm ci && node check.mjs https://quire.localhost / /blog/hello-world /about'
```

The image's version has to be the one of the `playwright` package in `tests/Accessibility/package.json`, because the package only works with its own browsers; the first line reads it from there.

Automated checks find only some accessibility problems: also use the site with the keyboard alone and with a screen reader.
