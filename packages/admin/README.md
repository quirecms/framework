# Quire Admin

The administration panel of [Quire](https://quirecms.com), at `/admin`, in the browser and on phones: write, review and publish content (several people at once, without losing each other's changes), manage people and roles, settings, themes and extensions, and see who did what in the activity log, with the console command that does the same. One bar finds or does anything ("Find or do anything"), and changes to who may do what ask you to confirm it's you.

```bash
composer require quire/admin
bin/console quire:extension:enable quire-admin
```

New sites set up with `bin/console quire:install` have it enabled. Users with the ability `quire.admin.access` (editors and admins) can use it.

It's built only on what Quire offers every extension, so extensions can add to it the same way: pages in the route group `admin`; menu entries, dashboard widgets, and columns, filters and actions of content lists with `Quire\Admin\Extend\Admin`. Pages are rendered on the server and work without JavaScript; the site downloads Turbo and Stimulus when the package is installed, so no Node.js is needed.

See the [admin documentation](https://github.com/quirecms/framework/blob/main/docs/admin.md).

License: MIT.
