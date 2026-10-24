# Quire Admin

The administration panel of [Quire](https://quirecms.com): manage content, settings, extensions, themes and users in the browser, at `/admin`.

```bash
composer require quire/admin
bin/console quire:extension:enable quire-admin
```

New sites set up with `bin/console quire:install` have it enabled. Users with the ability `quire.admin.access` (editors and admins) can use it.

It's built only on what Quire offers every extension, so extensions can add to it the same way: pages in the route group `admin`; menu entries, dashboard widgets, and columns, filters and actions of content lists with `Quire\Admin\Extend\Admin`. Pages are rendered on the server and work without JavaScript; the site downloads Turbo and Stimulus when the package is installed, so no Node.js is needed.

See the [admin documentation](https://github.com/quirecms/framework/blob/main/docs/admin.md).

License: MIT.
