<?php

declare(strict_types=1);

use Quire\Admin\Controller\ContentListController;
use Quire\Admin\Controller\DashboardController;
use Quire\Admin\Dashboard\Widget\ContentWidget;
use Quire\Admin\Dashboard\Widget\RecentlyEditedWidget;
use Quire\Admin\Extend\Admin;
use Quire\Extend;

/*
 * Quire's admin interface, at /admin (see "quire.routing.admin_prefix"), for users with the ability
 * "quire.admin.access". It uses only what core offers every extension: other extensions add pages to it
 * with Extend\Routes(group: 'admin'), and menu entries and dashboard widgets with Admin.
 */
return [
    new Extend\Services()
        ->autoload('Quire\\Admin\\', __DIR__.'/src', exclude: [__DIR__.'/src/Extend', __DIR__.'/src/Navigation/MenuItem.php']),

    new Extend\Routes(group: 'admin')
        ->get('/', 'quire_admin_dashboard', DashboardController::class)
        ->get('/content/{type}', 'quire_admin_content', ContentListController::class)
        ->post('/content/{type}', 'quire_admin_content_action', [ContentListController::class, 'act']),

    new Extend\Views()
        ->namespace('quire_admin', __DIR__.'/templates'),

    new Extend\Assets()
        ->stylesheet('quire-admin/admin.css', group: 'admin')
        ->script('quire-admin/admin.js', group: 'admin'),

    new Admin()
        ->menu('quire-admin.dashboard', 'Dashboard', 'quire_admin_dashboard', section: 'general', order: 0)
        ->widget(ContentWidget::class, order: 10)
        ->widget(RecentlyEditedWidget::class, order: 20),
];
