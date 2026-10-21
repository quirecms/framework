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

A route with the same name as an existing one replaces it. Extensions can override core routes, and the site's own `extend.php` can override extension routes.

#### `Extend\Views`

| Method | Purpose |
|--------|---------|
| `namespace($name, $directory)` | Makes the Twig templates in `$directory` available as `@name/...`, e.g. `{% include '@reviews/stars.html.twig' %}` |
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

#### `Extend\Conditional`

Applies extenders only when a condition holds, for example to integrate with an optional extension:

```php
new Extend\Conditional()
    ->whenExtensionEnabled('acme-seo', fn () => [
        new Extend\Events()->listen(SeoTagsCollected::class, Reviews\AddRatingToSeoTags::class),
    ]),
```

List such extensions under `optional` in your manifest, so they load before yours.

### Customising a site without an extension

The `extend.php` in the project root works exactly like an extension's, and runs after all of them. Use it for site-specific changes that don't deserve their own package.

### After enabling or disabling extensions

Extensions are compiled into the application, so it must be rebuilt after the enabled extensions change:

- **Development:** console commands rebuild automatically. Running PHP workers keep the previous build until they restart: `docker compose restart php`.
- **Production:** the container rebuilds when it starts. After enabling or disabling an extension at runtime, restart it.

`/_quire/health` reports a failing `extensions` check while the running application doesn't match the enabled extensions.
