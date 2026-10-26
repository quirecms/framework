<?php

declare(strict_types=1);

use Quire\Admin\Controller\AuditController;
use Quire\Admin\Controller\ContentChangesController;
use Quire\Admin\Controller\ContentEditController;
use Quire\Admin\Controller\ContentListController;
use Quire\Admin\Controller\DashboardController;
use Quire\Admin\Controller\ExtensionsController;
use Quire\Admin\Controller\IntentController;
use Quire\Admin\Controller\PreferencesController;
use Quire\Admin\Controller\RolesController;
use Quire\Admin\Controller\SettingsController;
use Quire\Admin\Controller\ThemesController;
use Quire\Admin\Controller\UiController;
use Quire\Admin\Controller\UsersController;
use Quire\Admin\Dashboard\Inbox\ChangesInbox;
use Quire\Admin\Dashboard\Inbox\ScheduledChecksInbox;
use Quire\Admin\Extend\Admin;
use Quire\Admin\Preferences\AdminPreferences;
use Quire\Extend;
use Symfony\Component\Routing\Requirement\Requirement;

/*
 * Quire's admin interface, at /admin (see "quire.routing.admin_prefix"), for users with the ability
 * "quire.admin.access". It uses only what core offers every extension: other extensions add pages to it
 * with Extend\Routes(group: 'admin'), and menu entries and dashboard widgets with Admin.
 */
return [
    new Extend\Services()
        ->autoload('Quire\\Admin\\', __DIR__.'/src', exclude: [__DIR__.'/src/Extend', __DIR__.'/src/Navigation/MenuItem.php', __DIR__.'/src/Content/{ListState,NextStep}.php', __DIR__.'/src/Content/Job/ApplyToMatching.php']),

    new Extend\Routes(group: 'admin')
        ->get('/', 'quire_admin_dashboard', DashboardController::class)
        ->get('/content/{type}', 'quire_admin_content', ContentListController::class)
        ->post('/content/{type}', 'quire_admin_content_action', [ContentListController::class, 'act'])
        ->get('/content/{type}/new', 'quire_admin_content_new', [ContentEditController::class, 'new'])
        ->post('/content/{type}/new', 'quire_admin_content_create', [ContentEditController::class, 'create'])
        ->post('/content/{type}/preview', 'quire_admin_content_preview', [ContentEditController::class, 'previewFragment'])
        ->post('/content/{type}/check', 'quire_admin_content_check', [ContentEditController::class, 'checksFragment'])
        ->post('/content/{type}/placement', 'quire_admin_content_placement', [ContentEditController::class, 'placementFragment'])
        ->get('/content/{type}/{id}', 'quire_admin_content_edit', [ContentEditController::class, 'edit'], requirements: ['id' => Requirement::UUID])
        ->post('/content/{type}/{id}', 'quire_admin_content_save', [ContentEditController::class, 'save'], requirements: ['id' => Requirement::UUID])
        ->get('/content/{type}/{id}/changes/{pending}', 'quire_admin_content_changes', [ContentChangesController::class, 'changes'], requirements: ['id' => Requirement::UUID, 'pending' => Requirement::UUID])
        ->post('/content/{type}/{id}/changes/{pending}', 'quire_admin_content_changes_action', [ContentChangesController::class, 'act'], requirements: ['id' => Requirement::UUID, 'pending' => Requirement::UUID])
        ->post('/content/{type}/{id}/changes/{pending}/comments', 'quire_admin_content_comment', [ContentChangesController::class, 'comment'], requirements: ['id' => Requirement::UUID, 'pending' => Requirement::UUID])
        ->get('/content/{type}/{id}/history', 'quire_admin_content_history', [ContentChangesController::class, 'history'], requirements: ['id' => Requirement::UUID])
        ->post('/content/{type}/{id}/restore', 'quire_admin_content_restore', [ContentChangesController::class, 'restore'], requirements: ['id' => Requirement::UUID])
        ->post('/content/{type}/{id}/place', 'quire_admin_content_place', [ContentChangesController::class, 'place'], requirements: ['id' => Requirement::UUID])
        ->get('/content-search', 'quire_admin_content_search', [ContentEditController::class, 'search'])
        ->get('/settings/export', 'quire_admin_settings_export', [SettingsController::class, 'export'])
        ->post('/settings/import', 'quire_admin_settings_import', [SettingsController::class, 'import'])
        ->post('/settings/defaults', 'quire_admin_settings_defaults', [SettingsController::class, 'restoreDefaults'])
        ->get('/settings/{group}', 'quire_admin_settings', SettingsController::class, requirements: ['group' => '[a-z0-9_.-]+'], defaults: ['group' => null])
        ->post('/settings/{group}', 'quire_admin_settings_save', [SettingsController::class, 'save'], requirements: ['group' => '[a-z0-9_.-]+'])
        ->get('/themes', 'quire_admin_themes', ThemesController::class)
        ->get('/themes/{id}/screenshot', 'quire_admin_theme_screenshot', [ThemesController::class, 'screenshot'], requirements: ['id' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
        ->post('/themes', 'quire_admin_themes_use', [ThemesController::class, 'use'])
        ->get('/users', 'quire_admin_users', UsersController::class)
        ->add('/users/new', 'quire_admin_user_new', [UsersController::class, 'create'], ['GET', 'HEAD', 'POST'])
        ->post('/users/invite', 'quire_admin_users_invite', [UsersController::class, 'inviteMany'])
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
        ->get('/audit', 'quire_admin_audit', AuditController::class)
        ->get('/audit/export', 'quire_admin_audit_export', [AuditController::class, 'export'])
        ->post('/audit/undo', 'quire_admin_audit_undo', [AuditController::class, 'undo'])
        ->add('/preferences', 'quire_admin_preferences', PreferencesController::class, ['GET', 'HEAD', 'POST'])
        ->post('/preferences/hood', 'quire_admin_hood', [PreferencesController::class, 'hood'])
        ->post('/preferences/density', 'quire_admin_density', [PreferencesController::class, 'density'])
        ->get('/find', 'quire_admin_find', IntentController::class)
        ->post('/find', 'quire_admin_intent', [IntentController::class, 'do'])
        ->get('/ui', 'quire_admin_ui', UiController::class),

    // How each person likes the admin (see Preferences\AdminPreferences).
    new Extend\Preferences()
        ->define(AdminPreferences::THEME, 'enum', 'system', choices: AdminPreferences::CHOICES[AdminPreferences::THEME])
        ->define(AdminPreferences::CONTRAST, 'enum', 'standard', choices: AdminPreferences::CHOICES[AdminPreferences::CONTRAST])
        ->define(AdminPreferences::TEXT, 'enum', 'md', choices: AdminPreferences::CHOICES[AdminPreferences::TEXT])
        ->define(AdminPreferences::DENSITY, 'enum', 'comfortable', choices: AdminPreferences::CHOICES[AdminPreferences::DENSITY])
        ->define(AdminPreferences::MOTION, 'enum', 'system', choices: AdminPreferences::CHOICES[AdminPreferences::MOTION])
        ->define(AdminPreferences::HOOD, 'bool', null, nullable: true),

    new Extend\Views()
        ->namespace('quire_admin', __DIR__.'/templates'),

    new Extend\Assets()
        // Quire UI, the admin's design system, then the admin's own pages.
        ->stylesheet('quire-admin/quire-ui.css', group: 'admin')
        ->stylesheet('quire-admin/admin.css', group: 'admin')
        ->script('quire-admin/admin.js', group: 'admin'),

    new Admin()
        ->menu('quire-admin.dashboard', 'Home', 'quire_admin_dashboard', section: 'general', order: 0)
        ->menu('quire-admin.settings', 'Settings', 'quire_admin_settings', ability: SettingsController::ABILITY, section: 'site', order: 10)
        ->menu('quire-admin.themes', 'Themes', 'quire_admin_themes', ability: ThemesController::ABILITY, section: 'site', order: 20)
        ->menu('quire-admin.extensions', 'Extensions', 'quire_admin_extensions', ability: ExtensionsController::ABILITY, section: 'site', order: 30)
        ->menu('quire-admin.audit', 'Activity', 'quire_admin_audit', ability: AuditController::ABILITY, section: 'activity', order: 10)
        ->menu('quire-admin.users', 'Users', 'quire_admin_users', ability: UsersController::ABILITY, section: 'users', order: 10)
        ->menu('quire-admin.roles', 'Roles', 'quire_admin_roles', ability: RolesController::ABILITY, section: 'users', order: 20)
        ->menu('quire-admin.access', 'Check access', 'quire_admin_access', ability: RolesController::ABILITY, section: 'users', order: 30)
        ->inbox(ChangesInbox::class)
        ->inbox(ScheduledChecksInbox::class),
];
