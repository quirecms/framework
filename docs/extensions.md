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

Enabling rebuilds the application and runs the extensions' database migrations. If that fails, for example because a migration has an error, the extensions are disabled again and the error is shown.

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

This keeps the extension's data in the database, in case you install it again. To delete the data for good, disable it with `--purge` before removing the package:

```bash
bin/console quire:extension:disable acme-seo --purge
composer remove acme/seo
```

`--purge` lets the extension clean up after itself, rolls back its database migrations (dropping its tables) and deletes its settings. It can't be undone, so back up the database first if you might need the data. You'll be asked to confirm; in scripts and other places without a terminal, add `--force`.

Only extensions that are currently running can be purged, because their migrations must be loaded to roll them back.

If you remove a package that's still enabled, it shows up as `missing` in the list. Quire keeps running without it. Disable it to clear the warning.

### Pinning extensions in production

By default, the enabled extensions are stored in the database, so they can be changed while the site runs. For production images that should never change at runtime, fix the list in `config/packages/quire.yaml` instead:

```yaml
quire:
    extensions:
        pinned: true
        enabled: [acme-comments, acme-reviews]
```

With `pinned: true`, the enable and disable commands refuse to make changes. To change the enabled extensions, edit the list and deploy again. The site's theme is then pinned too, with `theme:` next to `enabled:` (see [themes](themes.md#pinning-the-theme-in-production)).

## Writing an extension

### Quick start

```bash
bin/console quire:make:extension acme/reviews
```

This creates a working extension in `extensions/reviews/`, installs it with Composer and enables it. The extension has:

- a page at `/reviews`: a route, a controller and a Twig template;
- a setting, `acme-reviews.greeting`, shown on that page;
- a database migration creating a table the page reads from;
- passing tests, using [`quire/testing`](#testing-your-extension).

Open `/reviews`, then change the files to build your feature. Run the extension's tests with `vendor/bin/phpunit -c extensions/reviews`.

| Option | Purpose |
|--------|---------|
| `--title "Product Reviews"` | Human-readable name, instead of one derived from the package name |
| `--dir path/to/dir` | Create the extension somewhere else, e.g. for its own repository |
| `--no-install` | Only create the files |

Extensions in `extensions/` are installed through a Composer path repository, so changes to their files apply immediately. To publish one later, move its directory into its own repository: the generated files work there as they are.

### How an extension is structured

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
| `importmap` | `{}` | npm packages that your scripts import, with exact versions, e.g. `{"@hotwired/turbo": "8.0.23"}`. See [JavaScript packages](#javascript-packages). |

`requires` and `optional` list extension **IDs** (`acme-comments`), not package names (`acme/comments`). Also add required extensions to your Composer `require` section, so Composer installs them.

Mistakes in the manifest are reported with the package name and how to fix them, for example:

```
[acme/reviews] Invalid Quire manifest in composer.json: "extra.quire.require" is not a known key. Did you mean "requires"?
```

### `extend.php`: registering behaviour

An extension declares what it adds in an `extend.php` file at the root of its package. The file returns a list of **extenders**:

```php
<?php // extend.php

declare(strict_types=1);

use Acme\Reviews;
use Quire\Extend;

return [
    new Extend\Services()
        ->autoload('Acme\\Reviews\\', __DIR__.'/src', exclude: [__DIR__.'/src/Entity']),

    new Extend\Routes()
        ->get('/reviews', 'acme_reviews_index', Reviews\ListReviews::class)
        ->post('/reviews', 'acme_reviews_store', [Reviews\ReviewsController::class, 'store']),

    new Extend\Views()
        ->namespace('reviews', __DIR__.'/templates'),

    new Extend\Events()
        ->listen(SomeEvent::class, Reviews\OnSomeEvent::class),
];
```

Quire runs `extend.php` while it builds the application, in boot order. The result is compiled and cached, so extensions add no work to each request.

Mistakes are reported with the extension's ID and how to fix them:

```
[acme-reviews] Extend\Routes::add("acme_reviews_index"): The controller class "Acme\Reviews\ListReview" doesn't exist. Check the class name and that your package's Composer autoload covers it.
```

> Don't declare functions or classes inside `extend.php`. Put them in your package's `src/` directory, so Composer autoloads them.

#### `Extend\Routes`

Adds pages and endpoints.

| Method | Purpose |
|--------|---------|
| `get($path, $name, $controller)` | A `GET` (and `HEAD`) route |
| `post($path, $name, $controller)` | A `POST` route |
| `add($path, $name, $controller, $methods, $requirements, $defaults)` | Any HTTP methods, with requirements (e.g. `['id' => '\d+']`) and default values |
| `controllers($namespace, $directory, $prefix)` | Registers every controller in a directory and loads routes declared on them with Symfony's `#[Route]` attribute. `$prefix` is added to their paths. |

A controller is an invokable class (`Reviews\ListReviews::class`) or a `[class, method]` pair. Controllers are registered as services automatically, so constructor dependencies are injected.

Routes belong to a group: `site` (the default) for the public pages, or `admin` for the admin interface, `new Extend\Routes(group: 'admin')`. Admin routes are under the admin's path, `/admin` by default, and only users with the ability `quire.admin.access` can open them (see [admin pages](#admin-pages)).

A route with the same name as an existing one replaces it. Extensions can override core routes, and the site's own `extend.php` can override extension routes.

#### `Extend\Assets`

Adds stylesheets and JavaScript modules to the site's pages. Put the files in your extension's `assets/` directory: they're available as `<extension-id>/<path>`, with a version in their URL so browsers cache them for good.

```php
new Extend\Assets()
    ->stylesheet('acme-reviews/reviews.css')
    ->script('acme-reviews/reviews.js'),
```

| Method | Purpose |
|--------|---------|
| `stylesheet($path, $group = 'site')` | A `.css` file, linked in the `<head>` of every page |
| `script($path, $group = 'site')` | A `.js` module, loaded through the importmap. Files it imports with relative paths (`import { chart } from './chart.js'`) get versioned URLs and are preloaded; packages of the site's importmap can be imported by name. |
| `entrypoint($name, $group = 'site')` | An entrypoint of the site's `importmap.php`; mostly for the site's own `extend.php` |

`$group` is `site` for the public pages and `admin` for the admin interface. Quire adds the files to the `head` slot, in the order extensions boot, then themes, then the site, so the site's styles can override everyone's. Templates link other files with `quire_asset()`, e.g. `<img src="{{ quire_asset('acme-reviews/star.svg') }}">`. No Node.js is needed. See [themes](themes.md#assets).

##### JavaScript packages

Don't copy libraries into `assets/`. List the npm packages your scripts import in the manifest instead, with exact versions, and import them by name:

```json
"extra": {
    "quire": {
        "importmap": {
            "chart.js": "4.5.0",
            "@kurkle/color": "0.3.4"
        }
    }
}
```

```js
// assets/reviews.js
import { Chart } from 'chart.js';
```

Sites download the packages of every installed extension and theme when Composer installs them, and again when an extension is enabled, into `assets/vendor/`, like the packages of their own `importmap.php` (Symfony's [AssetMapper](https://symfony.com/doc/current/frontend/asset_mapper.html) does it, from the jsDelivr CDN). Production images download them when they're built, so they need no network access when they run. `bin/console importmap:audit` reports known vulnerabilities in them, and `importmap:outdated` newer versions.

- **List what the packages import too.** Packages that import other packages need those in the list as well, e.g. `chart.js` imports `@kurkle/color`. To find them, run `bin/console importmap:require chart.js` in a site and look at what it adds to `importmap.php`. A missing one is reported when the page is built, in development.
- **One version per site.** When two extensions list a package in different versions, the site can't be built, and the error names them. The site settles it by listing the package in its own `importmap.php` (`bin/console importmap:require chart.js@4.5.0`): its own version always wins.
- **CSS packages** are listed with the file, e.g. `"bootstrap/dist/css/bootstrap.min.css": "5.3.8"`, and imported from a script (`import 'bootstrap/dist/css/bootstrap.min.css';`).

#### `Extend\Views`

| Method | Purpose |
|--------|---------|
| `namespace($name, $directory)` | Makes the Twig templates in `$directory` available as `@name/...`, e.g. `{% include '@reviews/stars.html.twig' %}`. Sites and themes can override them (see [themes](themes.md#changing-an-extensions-templates)). |
| `slot($slot, $fragment, $priority = 0)` | Adds a fragment to a slot of the theme, e.g. `content.after_body`: a template (`'@reviews/summary.html.twig'`) or a class implementing `Quire\Theme\SlotRendererInterface`. Higher priorities come first. See [slots](themes.md#slots). |
| `global($name, $value)` | Adds a variable to every template. Values must be scalars or arrays of scalars. |

#### `Extend\Events`

| Method | Purpose |
|--------|---------|
| `listen($event, $listener, $method = '__invoke', $priority = 0)` | Calls `$listener` (a service) when `$event` is dispatched. Higher priorities run earlier. |
| `subscribe($subscriber)` | Registers a Symfony `EventSubscriberInterface` |

Classes with Symfony's `#[AsEventListener]` attribute work too, when they're registered through `Extend\Services::autoload()`.

#### `Extend\Console`

| Method | Purpose |
|--------|---------|
| `command($class)` | Adds a `bin/console` command. The class needs Symfony's `#[AsCommand]` attribute. |

Commands registered through `Extend\Services::autoload()` are picked up without this extender.

#### `Extend\Services`

| Method | Purpose |
|--------|---------|
| `autoload($namespace, $directory, $exclude = [])` | Registers every class in a directory as an autowired, autoconfigured service. Exclude directories holding non-services, such as entities and value objects. |
| `load($file)` | Loads a Symfony service configuration file (`.php` or `.yaml`) |
| `compilerPass($pass)` | Adds a compiler pass, for advanced container changes |

#### `Extend\Settings`

Declares settings that site admins can change at runtime (see [Settings](settings.md)).

```php
new Extend\Settings()
    ->define('acme-reviews.require_approval', 'bool', true, label: 'Require approval', help: 'New reviews stay hidden until a moderator approves them.')
    ->define('acme-reviews.per_page', 'int', 20, label: 'Reviews per page')
    ->define('acme-reviews.sort', 'enum', 'newest', choices: ['newest', 'oldest'])
    ->define('acme-reviews.api_key', 'string', null, nullable: true, sensitive: true),
```

| Argument | Description |
|----------|-------------|
| `key` | Must start with your extension's ID and a dot, e.g. `acme-reviews.per_page`, so it can't clash with other extensions. Lowercase words separated by dots. |
| `type` | `string`, `bool`, `int`, `float`, `enum`, `list` (of strings) or `json` |
| `default` | Used until someone changes the setting. Must fit the type. |
| `label`, `help`, `group`, `widget` | How admin interfaces present the setting. `widget` is a hint such as `textarea` or `color`. |
| `choices` | The allowed values, for `enum` settings |
| `nullable` | Whether the setting may be empty (`null`) |
| `sensitive` | Hides the value in listings, e.g. for API keys |
| `exposed` | Lets templates read it with `quire_setting()`, e.g. a footer text. Sensitive settings can't be exposed. |

Read settings by injecting `Quire\Settings\Settings`:

```php
final readonly class ListReviews
{
    public function __construct(private Settings $settings) {}

    public function __invoke(): Response
    {
        $perPage = $this->settings->getInt('acme-reviews.per_page');
        // ...
    }
}
```

`get()` returns any type. The typed getters `getString()`, `getBool()`, `getInt()`, `getFloat()` and `getList()` save you from checking the type yourself. When a setting changes, Quire dispatches `Quire\Settings\Event\SettingChanged`.

The site's own `extend.php` declares settings under `custom.`, e.g. `custom.footer_text`.

#### `Extend\ContentType`

Registers a content type (see [Content](content.md)):

```php
use Quire\Content\Type\Capability;

new Extend\ContentType('product')
    ->label('Product', 'Products')
    ->capabilities(Capability::REVISIONS, Capability::LISTABLE, Capability::SEARCHABLE)
    ->field('price', 'float', required: true, min: 0, sortable: true, label: 'Price')
    ->field('color', 'enum', choices: ['red', 'green', 'blue'], filterable: true)
    ->permalink('/products/{slug}')
    ->sortBy('title'),
```

| Method | Description |
|--------|-------------|
| `label($singular, $plural)` | Names shown in admin interfaces. Defaults to the ID, e.g. `Product` and `Products`. |
| `capabilities(...)` | What the type can do. Use the `Capability` constants for core's flags, and plain strings for your own (e.g. `'reviewable'`). Defaults to none. |
| `permalink($pattern)` | Default URL pattern, made of static text and the placeholders `{slug}`, `{path}` (the slugs of the item's ancestors and its own, for hierarchical types), `{id}`, `{year}`, `{month}` and `{day}`. It needs `{slug}`, `{path}` or `{id}`. Defaults to `/{type}/{slug}`, or `/{type}/{path}` for hierarchical types. Sites change it with the setting `quire.permalinks.<type>` (see [URLs](content.md#urls)). Listable types also get a list, by default at the pattern's static start (`/products`). |
| `field($name, $type, ...)` | Declares a typed field (see below) |
| `sortBy($field, $direction)` | Default order of listings: `published_at`, `created_at`, `updated_at`, `title`, `position` or a sortable field such as `fields.price`, `asc` or `desc`. Defaults to newest first, or by position for hierarchical types. |

Type IDs are lowercase letters, digits and underscores, starting with a letter, e.g. `product` or `recipe_card`. They must be unique across the site.

To add capabilities or fields to a type registered by core or another extension, use `existing()`:

```php
Extend\ContentType::existing('post')
    ->addCapabilities('commentable')
    ->field('subtitle', 'string', maxLength: 200),
```

Only capabilities and fields can be added this way. When the type comes from another extension, require that extension in your `composer.json`, so it boots first.

##### Fields

Fields hold an item's structured data, next to its title and body. Values are stored in the item's `fields` (JSONB) and checked against the declaration on every save. Read them with `$content->field('price')`.

| Type | Values | Stored as |
|------|--------|-----------|
| `string` | One line of text | text |
| `text` | Longer text | text |
| `int`, `float` | Numbers | numbers (a float stays a float, e.g. `4.0`) |
| `bool` | `true` or `false` | boolean |
| `enum` | One of `choices` | the choice |
| `list` | A list of texts, e.g. tags | array |
| `date` | A date, or a `DateTimeInterface` | `"2026-12-24"` |
| `datetime` | A `DateTimeInterface`, or ISO 8601 text with an offset | UTC text, `"2026-12-24T17:00:00+00:00"` |
| `json` | Anything JSON can hold | as is |
| `relation` | Other content items, or their IDs | the IDs (see [content](content.md#relation-fields)) |

| Option | Description |
|--------|-------------|
| `default` | Set on new items that don't provide a value |
| `required` | New items need a value, and it can't be removed |
| `choices` | The allowed values, for `enum` |
| `min`, `max` | The allowed range, for `int` and `float` |
| `maxLength` | Most characters, for `string`, `text` and each item of a `list` |
| `sortable`, `filterable` | Listings can sort or filter by the field, and it gets a database index. Not available for `text` and `json`; `list` fields can be filtered but not sorted. Indexed `string` fields have a `maxLength` of 255 unless you set one, and at most 1000. |
| `label`, `help`, `group`, `widget`, `order` | How admin forms present the field. Without a `widget`, the type's own is used. |
| `options` | The type's own options: `types`, `multiple`, `limit` and `inverse` for `relation` (see [content](content.md#relation-fields)), and those of extensions' types (see [below](#extendfieldtypes)) |

Field names are lowercase letters, digits and underscores, and unique per type: if two extensions declare the same field for a type, Quire refuses to start and names both.

Only the values being saved are checked. Values an item already has stay as they are, even when no enabled extension declares them any more, so disabling an extension never makes content unsaveable. Purging an extension (`quire:extension:disable --purge`) deletes the values of the fields it declares, also from revisions and pending revisions.

Quire creates and drops the database indexes of sortable and filterable fields itself: when the container starts and after an extension is enabled. In development, run `bin/console quire:content:sync-indexes` after changing a declaration. Indexes are built without blocking writes.

Read the registered types by injecting `Quire\Content\Type\ContentTypeRegistry`:

```php
foreach ($registry->withCapability('commentable') as $id => $type) {
    // ...
}
```

#### `Extend\FieldTypes`

Adds kinds of values fields can hold, next to core's. A type decides how values are checked and stored, how queries compare them, whether they can be indexed, and which admin widget edits them:

```php
new Extend\FieldTypes()
    ->type('acme-maps.location', Maps\LocationField::class),

Extend\ContentType::existing('post')
    ->field('venue', 'acme-maps.location', filterable: true, options: ['precision' => 6]),
```

A type extends `Quire\Content\Field\AbstractFieldType` and overrides what differs:

```php
final class ColorField extends AbstractFieldType
{
    public function options(): array
    {
        return ['palette'];                     // its own options, passed as field(options: [...])
    }

    public function normalize(mixed $value, FieldDefinition $field): mixed
    {
        return \is_string($value) ? strtolower($value) : $value;   // the stored form: "#FF8800" → "#ff8800"
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        return \is_string($value) && 1 === preg_match('/^#[0-9a-f]{6}$/', $value) ? null : 'Expected a color such as "#ff8800".';
    }

    public function widget(FieldDefinition $field): string
    {
        return 'color';
    }
}
```

| Method | Decides |
|--------|---------|
| `options()` | Which options of `field()` apply: any of `choices`, `min`, `max` and `maxLength`, and its own. Others are refused. |
| `definitionError()` | Whether a field's declaration suits the type, e.g. that an option is valid |
| `storage()` | How values are stored and compared (`FieldStorage::Text`, `Number`, `Boolean`, `TextList` or `Unindexed`), and so whether the field can be sorted, filtered and indexed. `Text` by default. |
| `normalize()`, `validate()` | The stored form of a value, and why a value isn't valid |
| `queryValue()`, `expected()` | What a query compares the field with: by default the normalized value, so `where('fields.accent', '=', '#FF8800')` finds `#ff8800` |
| `widget()` | The admin widget, unless the field names one |

- **Names** start with your extension's ID and a dot (the site's own with `custom.`). Declare a type before the fields that use it. An extension that uses another extension's type requires that extension.
- **Types are plain classes**, created with `new` and used while the application is built too (to check declared defaults). So they have no constructor arguments and keep no state: what they need comes from the field's definition. Checks that need services, e.g. that a referenced record exists, belong in listeners of the [content events](#reading-and-writing-content).

#### `Extend\Formatter`

Extends how text becomes HTML. The pipeline is: source → pre-processors → format (Markdown, text, HTML, …) → post-processors → sanitiser. Each class you register becomes an autowired service.

```php
new Extend\Formatter()
    ->shortcode('gallery', GalleryShortcode::class)
    ->shortcode('video', VideoShortcode::class, trusted: true)
    ->commonMark(FootnoteExtension::class, profiles: ['content'])
    ->preProcessor(Emoji::class)
    ->postProcessor(LazyImages::class)
    ->format('asciidoc', AsciidocFormat::class, profiles: ['content'])
    ->profile('review', formats: ['markdown'], sanitizer: 'basic', linkRel: ['nofollow', 'ugc']),
```

| Method | Registers |
|--------|-----------|
| `shortcode($name, $class, $trusted)` | A `ShortcodeInterface` that renders `[[name …]]`. Its output is sanitised unless `trusted: true`, which only suits output that never contains unescaped user input (e.g. an embed's iframe). Shortcodes aren't cached: they run each time the text is shown. |
| `commonMark($class, $profiles)` | A [league/commonmark extension](https://commonmark.thephpleague.com/2.x/customization/overview/): new syntax, nodes, renderers or document changes |
| `preProcessor($class, $profiles)` | A `SourceProcessorInterface` that changes the source before parsing |
| `postProcessor($class, $profiles)` | An `HtmlProcessorInterface` that changes the HTML before it's sanitised |
| `format($name, $class, $profiles)` | A `FormatInterface` for another markup language, allowed in the given profiles |
| `profile($name, ...)` | A set of rules for one kind of text (see below) |

`$profiles` limits a registration to some profiles; by default it applies to all.

```php
use Quire\Formatter\FormatContext;
use Quire\Formatter\Shortcode;
use Quire\Formatter\ShortcodeInterface;

final readonly class GalleryShortcode implements ShortcodeInterface
{
    public function __construct(private Environment $twig) {}

    public function render(Shortcode $shortcode, FormatContext $context): string
    {
        // Attribute values are written by the author: treat them as user input.
        return $this->twig->render('@acme-gallery/gallery.html.twig', ['id' => (int) $shortcode->attribute('id')]);
    }
}
```

**Profiles** let different text get different features. Core has two:

| Profile | For | Formats | Allows |
|---------|-----|---------|--------|
| `content` | Content written by editors | markdown, text, html | Everything safe: headings (with anchors), tables, images, all shortcodes |
| `comment` | Text written by visitors | markdown, text | Paragraphs, emphasis, links (marked `rel="nofollow ugc"`), lists, quotes, code. No images or shortcodes. |

Profile options: `formats`, `shortcodes` (names, or `['*']`), `sanitizer` (`rich` or `basic`), `headingAnchors`, `images`, `rawHtml` (let Markdown contain HTML, still sanitised) and `linkRel`.

Render text with `Quire\Formatter\Formatter`, and content with `Quire\Content\ContentRenderer`, which caches:

```php
$html = $formatter->render($comment->body, 'markdown', 'comment');

$html = $renderer->body($post);         // the whole body
$teaser = $renderer->teaser($post);     // the part before <!--more-->, or null
$summary = $renderer->excerpt($post);   // plain text, at most 200 characters
```

`Formatter::compile()` and `expand()` split rendering in two, for text you cache yourself: compiled text keeps placeholders where its shortcodes go, and `expand()` renders them.

#### `Extend\ContentWorkflow`

Adds statuses and transitions to the content workflow (see [Statuses](content.md#statuses)), e.g. an editorial review step:

```php
new Extend\ContentWorkflow()
    ->status('in_review')
    ->transition('submit', from: ['draft'], to: 'in_review')
    ->transition('reject', from: ['in_review'], to: 'draft')
    ->transition('publish', from: ['in_review'], to: 'published'),
```

Status and transition names are lowercase letters, digits and underscores, up to 32 characters. A transition can reuse an existing name (like `publish` above) from statuses that don't have it yet. Statuses that extensions add are never public: visitors only see published items.

The workflow is a Symfony [state machine](https://symfony.com/doc/current/workflow.html) named `content`, so you can make transitions conditional with guard listeners:

```php
use Quire\Content\Workflow\ContentSubject;
use Symfony\Component\Workflow\Event\GuardEvent;

#[AsEventListener('workflow.content.guard.publish')]
final class RequireAnExcerpt
{
    public function __invoke(GuardEvent $event): void
    {
        $subject = $event->getSubject();

        if ($subject instanceof ContentSubject && null === $subject->content->excerpt) {
            $event->setBlocked(true, 'Write an excerpt first.');
        }
    }
}
```

#### `Extend\Query`

Extends content queries (see [Querying content](content.md#querying-content)) with named scopes, visibility rules and relations:

```php
new Extend\Query()
    ->scope('top_rated', Reviews\TopRatedScope::class, types: ['product'])
    ->visibility(Reviews\HideUnapproved::class, types: ['review'])
    ->relation('reviews', Reviews\ReviewsLoader::class, types: ['product']),
```

A **scope** is a reusable filter that code calls with `->scope('top_rated')` and templates with `.scope('top_rated')`. It's an invokable class that takes the query, plus any arguments, and returns it refined:

```php
final readonly class TopRatedScope
{
    public function __invoke(ContentQuery $query, int $minimum = 4): ContentQuery
    {
        return $query
            ->join('LEFT JOIN quire.acme_reviews_stats rs ON rs.content_id = c.id')
            ->select('rating_avg', 'rs.average')
            ->whereSql('rs.average >= ?', [$minimum])
            ->orderBy('rating_avg', 'desc');
    }
}
```

Scopes may use SQL, which templates can't: `whereSql()` adds a condition, `join()` joins a table that has at most one row per item, and `select()` adds a value to each item, which templates read as `{{ product.rating_avg }}` and PHP as `$product->attribute('rating_avg')`. The content table is aliased `c`, your own tables are written with the schema (`quire.acme_reviews_stats`), and values are passed as `?` placeholders.

A **visibility rule** is a condition that every query applies automatically, for example to hide reviews nobody has approved. It's an invokable class like a scope, without arguments, and it may only add conditions. A rule for some types leaves other types in the same query alone. Only code that calls `withoutVisibilityScope()`, such as an admin interface, sees what it hides.

A **relation** loads related data for many items at once, so a listing doesn't query the database once per item. Implement `Quire\Content\Query\RelationLoaderInterface` and return a value for each item ID:

```php
final readonly class ReviewsLoader implements RelationLoaderInterface
{
    public function __construct(private Connection $connection) {}

    public function load(array $items, ContentQuery $query): array
    {
        $reviews = array_fill_keys(array_map(fn (Content $item) => $item->id, $items), []);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM quire.acme_reviews_review WHERE content_id IN (?) ORDER BY created_at',
            [array_keys($reviews)],
            [ArrayParameterType::STRING],
        );

        foreach ($rows as $row) {
            $reviews[$row['content_id']][] = $row;
        }

        return $reviews;
    }
}
```

`quire_content('product').with('reviews')` then calls the loader once for the whole page of products. For relations to other content items, query them with `$query->related('product')`, which applies the same visibility rules as the query that asked for the relation. Relation names can't be the names of content properties such as `title`.

#### `Extend\Auth`

Adds ways to sign in, as Symfony authenticators (services; autowired when not registered otherwise). The firewall that lists `Quire\User\Security\ExtensionAuthenticators` uses them, so extensions don't edit `security.yaml`. Each request goes to the first one whose `supports()` accepts it.

```php
new Extend\Auth()
    ->authenticator(Sso\OidcAuthenticator::class),
```

Link the accounts they sign in to users with `UserWriter::linkIdentity()`, and show a link on the sign-in page with the `auth.login_options` slot. See [users](users.md#other-ways-to-sign-in).

#### `Extend\Permissions`

Declares roles, abilities and rules that decide what users may do. Check them with Symfony's `is_granted()`.

```php
new Extend\Permissions()
    ->role('moderator', 'Moderator')
    ->ability('acme-reviews.moderate', 'Moderate reviews', roles: ['moderator', 'editor'])
    ->rule(Reviews\BannedReviewersRule::class),
```

See [permissions](permissions.md#declaring-them).

#### `Extend\Storage`

Declares the disks your extension keeps files on. They work without configuration, as directories under the site's `storage/`, and the site can move each one, e.g. to S3, without your code changing.

```php
new Extend\Storage()
    ->disk('acme-gallery', public: true, label: 'Gallery photos')
    ->disk('acme-gallery-originals', public: false, label: 'Full-size originals'),
```

Start disk names with your extension's ID. Store files with `Quire\Storage\ObjectStore`, and refer to them from your tables with a foreign key to `quire.storage_object (id) ON DELETE RESTRICT`, so they're kept while you use them and cleaned up afterwards. See [storage](storage.md).

#### `Extend\Jobs`

Registers handlers of [background jobs](jobs.md), and schedules jobs:

```php
new Extend\Jobs()
    ->handler(GenerateVariantsHandler::class)
    ->schedule('acme-feeds.fetch', 'every 15 minutes', FetchFeeds::class)
    ->schedule('acme-reports.weekly', '0 6 * * 1', SendWeeklyReport::class, timezone: 'Europe/Berlin'),
```

A job is a message implementing `Quire\Jobs\Job`; dispatch it on the message bus, and a worker handles it. Handlers that `Extend\Services` loads need no `handler()` call when they're marked `#[AsMessageHandler]`.

A schedule's frequency is a cron expression (`30 3 * * *`, `@hourly`) or an interval (`every 15 minutes`, `every 2 hours`, `every 1 day`). Intervals count from midnight UTC, so they fall at the same times on every server; cron times are in the given time zone, or PHP's (usually UTC). The message is created without arguments. Start task names with your extension's ID. Each occurrence runs once, however many workers there are. See [background jobs](jobs.md#scheduled-tasks).

#### Admin pages

Pages of the admin interface are routes in the group `admin`. Their paths are relative to the admin's (`/reviews` is `/admin/reviews`), and Quire lets only users with the ability `quire.admin.access` open them, asking guests to sign in. Extend the admin's layout, so the page has its menu and look:

```php
new Extend\Routes(group: 'admin')
    ->get('/reviews', 'acme_reviews_admin', ReviewsAdminController::class),
```

```twig
{% extends '@quire_admin/layout.html.twig' %}

{% block title %}Reviews{% endblock %}

{% block content %}
    …
{% endblock %}
```

The admin's own extender, `Quire\Admin\Extend\Admin`, adds the page to its menu, and widgets to its dashboard:

```php
use Quire\Admin\Extend\Admin;

new Admin()
    ->menu('acme-reviews', 'Reviews', 'acme_reviews_admin', ability: 'acme-reviews.moderate')
    ->widget(PendingReviewsWidget::class, ability: 'acme-reviews.moderate'),
```

| Method | Purpose |
|--------|---------|
| `menu($id, $label, $route, $parameters, $ability, $section, $order)` | A menu entry, shown to users with `$ability` (if given). `$section` is `content`, `site`, `users` or `extensions` (the default). The label is translated in your extension's domain. |
| `widget($class, $ability, $order)` | A dashboard widget: a service implementing `Quire\Admin\Dashboard\WidgetInterface`, with a `title()` and the HTML it `render()`s. An empty string hides it. |
| `contentColumn($id, $class, $types, $order)` | A column of the [content lists](admin.md#content), after the built-in ones: a service implementing `Quire\Admin\Content\ContentColumnInterface`. `$types` limits it to content types, e.g. `['product']`. |
| `contentFilter($id, $class, $types, $order)` | A filter of the content lists: a service implementing `ContentFilterInterface`, with a label, the `choices()` and how a choice narrows the query. |
| `contentAction($id, $class, $types, $order)` | An action of the content lists, for an item or the selected ones: a service implementing `ContentActionInterface`. |

Content types, fields and abilities are all the lists need: an extension that only declares a content type gets a list of it in the admin, with no admin code. Columns, filters and actions are for more:

```php
final readonly class RatingColumn implements ContentColumnInterface
{
    public function label(): string { return 'Rating'; }

    // Prepare the list's query once for all its rows, e.g. with select() or with().
    public function query(ContentQuery $query): ContentQuery
    {
        return $query->select('review_count', 'SELECT count(*) FROM acme_review r WHERE r.content_id = c.id');
    }

    // A string is escaped; return a \Twig\Markup for HTML.
    public function value(Content $item): string { return (string) $item->attribute('review_count'); }

    // What choosing the column's heading sorts by; null when it can't sort.
    public function sortBy(): ?string { return 'review_count'; }
}
```

An action decides who may take it, in `isAvailable($item)` (e.g. with `Security::isGranted()`): the list offers it only there. When `apply($item)` throws a `ContentActionException`, its message is shown for that item; other exceptions are logged, and the editor sees that something went wrong.

When the admin isn't enabled, `Admin` does nothing, so you don't need to require it. If it may not be installed at all, wrap it in `Extend\Conditional`. Stylesheets and scripts for admin pages go in the group `admin` of [`Extend\Assets`](#extendassets). Scripts can register [Stimulus](https://stimulus.hotwired.dev) controllers on `window.QuireAdmin`, whether they load before or after the admin's: give a function that receives Stimulus's `Controller` class and returns your controller.

```js
(window.QuireAdmin ??= { controllers: [] }).controllers.push(
    ['acme-chart', (Controller) => class extends Controller {
        connect() { this.element.textContent = 'Chart'; }
    }],
);
```

Then `<div data-controller="acme-chart">` on an admin page uses it.

#### `Extend\Migrations`

Registers database migrations ([Doctrine Migrations](https://www.doctrine-project.org/projects/doctrine-migrations/en/stable/)) that create and change your extension's tables:

```php
new Extend\Migrations('Acme\\Reviews\\Migrations', __DIR__.'/migrations'),
```

```php
<?php // migrations/Version20261101120000.php

declare(strict_types=1);

namespace Acme\Reviews\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101120000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quire.acme_reviews_review (id SERIAL PRIMARY KEY, content_id UUID NOT NULL REFERENCES quire.content (id) ON DELETE CASCADE, rating SMALLINT NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.acme_reviews_review');
    }
}
```

- Migrations run when the extension is enabled, and when it's updated and the container starts (or when you run `bin/console quire:migrations:migrate`).
- `down()` must undo `up()`: it runs when the extension is disabled with `--purge`.
- Use your own namespace, and keep the directory out of your Composer autoload paths. Doctrine loads migration files itself.
- Create your tables in Quire's `quire` schema and prefix their names with your extension's ID, e.g. `quire.acme_reviews_review`, so they can't clash with other extensions or with the application's tables. Always write the schema in your SQL. See [docs/database.md](database.md).
- Migrations run in dependency order: Quire core first, then extensions in boot order. Your migrations can rely on the tables of the extensions you require. They're kept apart from the application's own migrations (`doctrine:migrations:*`).

#### `Extend\Conditional`

Applies extenders only when a condition holds, for example to integrate with an optional extension:

```php
new Extend\Conditional()
    ->whenExtensionEnabled('acme-seo', fn () => [
        new Extend\Events()->listen(SeoTagsCollected::class, Reviews\AddRatingToSeoTags::class),
    ]),
```

List such extensions under `optional` in your manifest, so they load before yours.

### Reading and writing content

Inject `Quire\Content\ContentWriter` to create and change content. The admin interface, the command line and APIs all use it, so the same rules and events apply everywhere:

```php
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Status;

$post = $writer->create(new CreateContent('post', 'Hello world', body: 'My *first* post.'));
$post = $writer->transition($post->id, $post->version, Status::PUBLISH);
$post = $writer->update(new UpdateContent($post->id, $post->version)->title('Hello again')->field('rating', 5));
$writer->trash($post->id, $post->version);
```

Methods that change an item take the version the change is based on, and throw `ContentConflictException` when someone saved the item since. Invalid changes throw `InvalidContentException`, whose `violations` property maps each property (`title`, `slug`, …) to a message. `Quire\Content\ContentRepository` loads items by ID and lists them with `query()` (see [Querying content](content.md#querying-content)), and `Quire\Content\Revision\RevisionRepository` loads their revisions.

#### Pending revisions

To change published content without publishing at once, e.g. to autosave in an editor, write a pending revision with `Quire\Content\Revision\PendingRevisionWriter` and publish it with the content writer (see [pending revisions](content.md#pending-revisions)):

```php
use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\Revision\Verdict;

$pending = $pendingWriter->create($post->id, authorId: $user->id, message: 'Fix the opening hours');
$pending = $pendingWriter->update(new UpdatePendingRevision($pending->id, $pending->version)->body($markdown)->field('rating', 5));

$pendingWriter->submit($pending->id, $pending->version);                                   // ask for a review
$pendingWriter->review($pending->id, $pending->version, Verdict::Approve, $reviewer->id);   // or RequestChanges, Comment

$post = $writer->publishPendingRevision($pending->id, $pending->version, actorId: $reviewer->id);
```

Saving accepts work in progress, such as an empty title; the item's rules are checked when the changes are published. Like items, pending revisions have a version: saving, reviewing and publishing take the version the user saw, and throw `PendingRevisionConflictException` if it was saved since, e.g. in another tab. So nobody approves or publishes changes they haven't seen.

`Quire\Content\Revision\PendingRevisionRepository` lists an item's pending revisions (`forContent()`, optionally only one author's), their `reviews()`, and shows what publishing would give: `merge()` returns the merged text and fields and any conflicts, and `preview()` the item with the changes, ready to render in its templates. When the changes conflict with what was published meanwhile, publishing throws `MergeConflictException`. Its `conflicts` are keyed by property like `violations`. Set the resolved values and rebase onto the live version, `->rebase($post->version)`, then publish again.

`$pending->diff()` says what a pending revision changes, and `Quire\Content\Revision\Diff::between(Snapshot::of($old), Snapshot::of($new))` compares any two versions, e.g. two revisions. A diff lists the changed properties, and the body line by line (`bodyLines`) or as a unified diff (`unified()`).

Each change dispatches events from `Quire\Content\Event`:

| Before the change (can cancel or modify it) | After the change |
|---------------------------------------------|------------------|
| `ContentCreating` | `ContentCreated` |
| `ContentUpdating` | `ContentUpdated` |
| `ContentPublishing` | `ContentPublished`, `ContentUnpublished`, `ContentStatusChanged` |
| `ContentDeleting` (to the trash) | `ContentDeleted`, `ContentRestored` |
| `ContentPurging` (permanently) | `ContentPurged` |
| | `PendingRevisionSaved`, `PendingRevisionSubmitted`, `PendingRevisionReviewed`, `PendingRevisionDiscarded` |
| `ContentUpdating` (publishing a pending revision) | `ContentUpdated`, `PendingRevisionPublished` |

`ContentViewing` is dispatched before an item's page is rendered. Its listeners can add template variables with `$event->set('related', $items)`, or call `$event->deny()` to respond with "404 Not Found".

The "before" events are dispatched inside the change's database transaction, so what their listeners write is saved or rolled back together with it. Call `$event->cancel('Reason')` to stop the change. The "after" events are dispatched once the transaction has committed, so their listeners never act on a change that was rolled back. That makes them the place for side effects such as clearing caches or sending notifications.

```php
#[AsEventListener]
final class RejectSpam
{
    public function __invoke(ContentCreating $event): void
    {
        if (str_contains($event->command->body, 'casino')) {
            $event->cancel('That looks like spam.');
        }
    }
}
```

### Testing your extension

`quire/testing` runs your extension in a small Quire application, so you can test it in its own repository, without a site around it:

```bash
composer require --dev quire/testing
```

```php
<?php // tests/ReviewsTest.php

declare(strict_types=1);

namespace Acme\Reviews\Tests;

use Quire\Testing\ExtensionTestCase;

final class ReviewsTest extends ExtensionTestCase
{
    // Your extension, plus any extensions it requires.
    protected static array $extensions = ['acme-comments', 'acme-reviews'];

    public function testListsReviews(): void
    {
        $this->setSetting('acme-reviews.per_page', 5);

        $this->client->request('GET', '/reviews');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Reviews');
    }
}
```

What `ExtensionTestCase` does for you:

- **Enables exactly the listed extensions** in a minimal application that doesn't use the host site's configuration, so tests behave the same everywhere. The package you're developing counts as installed, even though Composer never lists a project inside itself.
- **Prepares the database:** before the first test it creates the test database (your `DATABASE_URL` with `_test` added to the name) and runs Quire's migrations: core's and those of the enabled extensions.
- **Isolates tests:** each test runs in a database transaction that's rolled back afterwards, so tests never see each other's data. The transaction spans all requests a test makes with `$this->client`.

It extends Symfony's `WebTestCase`, so all its assertions work (`assertResponseIsSuccessful()`, `assertSelectorTextContains()`, …). It adds:

| Method | Purpose |
|--------|---------|
| `$this->client` | A browser for the test application |
| `setSetting($key, $value)` / `settings()` | Change or read settings, for this test only |
| `connection()` | The database connection, e.g. to insert test data |
| `service($class)` | Any service from the container |
| `runJobs()` | Runs the [background jobs](jobs.md) waiting in the queue, as a worker would, and returns how many ran |
| `assertExtensionRunning($id)` | Fails with the reason if an extension can't run, e.g. a missing requirement |
| `assertQueryCount($n, $callback)` | Runs the callback and fails if it didn't make exactly `$n` database queries, listing them. Use it to check that a listing doesn't make a query per item. |

Set `DATABASE_URL` for your tests, for example in `phpunit.dist.xml`:

```xml
<php>
    <env name="DATABASE_URL" value="postgresql://app:password@127.0.0.1:5432/app?serverVersion=18" />
</php>
```

### Customising a site without an extension

The `extend.php` in the project root works exactly like an extension's, and runs after all of them. Use it for site-specific changes that don't deserve their own package.

### After enabling or disabling extensions

Extensions are compiled into the application, so it must be rebuilt after the enabled extensions change. Quire does this for you:

- **On the machine where you run `quire:extension:enable` or `disable`,** the command rebuilds the application, runs migrations and gracefully restarts the PHP workers. The change is live when the command finishes.
- **On other machines** (when several servers share the database), each PHP worker checks every few seconds whether the enabled extensions changed. When they did, the machine rebuilds in the background and restarts its workers, usually within 5–10 seconds. Requests keep being served meanwhile.

You can run the same refresh by hand with `bin/console quire:app:refresh`. Its output from automatic runs is logged to `var/log/quire-refresh.log`.

`/_quire/health` reports a failing `extensions` check while the running application doesn't match the enabled extensions.

When extensions are pinned in configuration, nothing changes at runtime, so none of this applies.
