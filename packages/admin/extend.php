<?php

declare(strict_types=1);

use Quire\Admin\Controller\AuditController;
use Quire\Admin\Controller\ContentChangesController;
use Quire\Admin\Controller\ContentEditController;
use Quire\Admin\Controller\ContentListController;
use Quire\Admin\Controller\DashboardController;
use Quire\Admin\Controller\ExtensionsController;
use Quire\Admin\Controller\RolesController;
use Quire\Admin\Controller\SettingsController;
use Quire\Admin\Controller\ThemesController;
use Quire\Admin\Controller\UsersController;
use Quire\Admin\Dashboard\Widget\ActivityWidget;
use Quire\Admin\Dashboard\Widget\ChangesWidget;
use Quire\Admin\Dashboard\Widget\ContentWidget;
use Quire\Admin\Dashboard\Widget\RecentlyEditedWidget;
use Quire\Admin\Extend\Admin;
use Quire\Extend;
use Symfony\Component\Routing\Requirement\Requirement;

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
        ->post('/content/{type}', 'quire_admin_content_action', [ContentListController::class, 'act'])
        ->get('/content/{type}/new', 'quire_admin_content_new', [ContentEditController::class, 'new'])
        ->post('/content/{type}/new', 'quire_admin_content_create', [ContentEditController::class, 'create'])
        ->post('/content/{type}/preview', 'quire_admin_content_preview', [ContentEditController::class, 'previewFragment'])
        ->post('/content/{type}/check', 'quire_admin_content_check', [ContentEditController::class, 'checksFragment'])
        ->get('/content/{type}/{id}', 'quire_admin_content_edit', [ContentEditController::class, 'edit'], requirements: ['id' => Requirement::UUID])
        ->post('/content/{type}/{id}', 'quire_admin_content_save', [ContentEditController::class, 'save'], requirements: ['id' => Requirement::UUID])
        ->get('/content/{type}/{id}/changes/{pending}', 'quire_admin_content_changes', [ContentChangesController::class, 'changes'], requirements: ['id' => Requirement::UUID, 'pending' => Requirement::UUID])
        ->post('/content/{type}/{id}/changes/{pending}', 'quire_admin_content_changes_action', [ContentChangesController::class, 'act'], requirements: ['id' => Requirement::UUID, 'pending' => Requirement::UUID])
        ->get('/content/{type}/{id}/history', 'quire_admin_content_history', [ContentChangesController::class, 'history'], requirements: ['id' => Requirement::UUID])
        ->post('/content/{type}/{id}/restore', 'quire_admin_content_restore', [ContentChangesController::class, 'restore'], requirements: ['id' => Requirement::UUID])
        ->post('/content/{type}/{id}/place', 'quire_admin_content_place', [ContentChangesController::class, 'place'], requirements: ['id' => Requirement::UUID])
        ->get('/content-search', 'quire_admin_content_search', [ContentEditController::class, 'search'])
        ->get('/settings/{group}', 'quire_admin_settings', SettingsController::class, requirements: ['group' => '[a-z0-9_.-]+'], defaults: ['group' => null])
        ->post('/settings/{group}', 'quire_admin_settings_save', [SettingsController::class, 'save'], requirements: ['group' => '[a-z0-9_.-]+'])
        ->get('/themes', 'quire_admin_themes', ThemesController::class)
        ->post('/themes', 'quire_admin_themes_use', [ThemesController::class, 'use'])
        ->get('/users', 'quire_admin_users', UsersController::class)
        ->add('/users/new', 'quire_admin_user_new', [UsersController::class, 'create'], ['GET', 'HEAD', 'POST'])
        ->add('/users/{id}', 'quire_admin_user', [UsersController::class, 'edit'], ['GET', 'HEAD', 'POST'], ['id' => Requirement::UUID])
        ->post('/users/{id}/{action}', 'quire_admin_user_act', [UsersController::class, 'act'], requirements: ['id' => Requirement::UUID, 'action' => 'suspend|activate|invite'])
        ->add('/users/{id}/delete', 'quire_admin_user_delete', [UsersController::class, 'delete'], ['GET', 'HEAD', 'POST'], ['id' => Requirement::UUID])
        ->get('/roles', 'quire_admin_roles', RolesController::class)
        ->post('/roles', 'quire_admin_roles_create', [RolesController::class, 'create'])
        ->get('/access', 'quire_admin_access', [RolesController::class, 'check'])
        ->get('/roles/{role}', 'quire_admin_role', [RolesController::class, 'show'], requirements: ['role' => '[a-z][a-z0-9_-]*'])
        ->post('/roles/{role}', 'quire_admin_role_save', [RolesController::class, 'save'], requirements: ['role' => '[a-z][a-z0-9_-]*'])
        ->post('/roles/{role}/review', 'quire_admin_role_review', [RolesController::class, 'review'], requirements: ['role' => '[a-z][a-z0-9_-]*'])
        ->post('/roles/{role}/rename', 'quire_admin_role_rename', [RolesController::class, 'rename'], requirements: ['role' => '[a-z][a-z0-9_-]*'])
        ->add('/roles/{role}/delete', 'quire_admin_role_delete', [RolesController::class, 'delete'], ['GET', 'HEAD', 'POST'], ['role' => '[a-z][a-z0-9_-]*'])
        ->get('/extensions', 'quire_admin_extensions', ExtensionsController::class)
        ->get('/extensions/{id}/{action}', 'quire_admin_extension_confirm', [ExtensionsController::class, 'confirm'], requirements: ['id' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'action' => 'enable|disable|purge'])
        ->post('/extensions/{id}/{action}', 'quire_admin_extension_act', [ExtensionsController::class, 'act'], requirements: ['id' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'action' => 'enable|disable|purge'])
        ->get('/audit', 'quire_admin_audit', AuditController::class),

    new Extend\Views()
        ->namespace('quire_admin', __DIR__.'/templates'),

    new Extend\Assets()
        ->stylesheet('quire-admin/admin.css', group: 'admin')
        ->script('quire-admin/admin.js', group: 'admin'),

    new Admin()
        ->menu('quire-admin.dashboard', 'Dashboard', 'quire_admin_dashboard', section: 'general', order: 0)
        ->menu('quire-admin.settings', 'Settings', 'quire_admin_settings', ability: SettingsController::ABILITY, section: 'site', order: 10)
        ->menu('quire-admin.themes', 'Themes', 'quire_admin_themes', ability: ThemesController::ABILITY, section: 'site', order: 20)
        ->menu('quire-admin.extensions', 'Extensions', 'quire_admin_extensions', ability: ExtensionsController::ABILITY, section: 'site', order: 30)
        ->menu('quire-admin.audit', 'Audit log', 'quire_admin_audit', ability: AuditController::ABILITY, section: 'site', order: 40)
        ->menu('quire-admin.users', 'Users', 'quire_admin_users', ability: UsersController::ABILITY, section: 'users', order: 10)
        ->menu('quire-admin.roles', 'Roles', 'quire_admin_roles', ability: RolesController::ABILITY, section: 'users', order: 20)
        ->menu('quire-admin.access', 'Check access', 'quire_admin_access', ability: RolesController::ABILITY, section: 'users', order: 30)
        ->widget(ContentWidget::class, order: 10)
        ->widget(ChangesWidget::class, order: 15)
        ->widget(RecentlyEditedWidget::class, order: 20)
        ->widget(ActivityWidget::class, order: 30),
];
