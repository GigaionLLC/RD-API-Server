<?php

use App\Http\Controllers\Admin\AddressBookController;
use App\Http\Controllers\Admin\AdminRoleController;
use App\Http\Controllers\Admin\AlarmController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ClientConfigController;
use App\Http\Controllers\Admin\ConsoleAuditController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeployTokenController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\DeviceGroupController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\LdapController;
use App\Http\Controllers\Admin\OauthProviderController;
use App\Http\Controllers\Admin\RecordingController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\StrategyController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

/*
 * Admin console (dark dashboard).
 */
Route::get('/admin/login', [AuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AuthController::class, 'login']);

// Post-password TOTP challenge — gated by a session marker, NOT `auth` (the user is logged
// out between supplying their password and their second factor). See TwoFactorController.
Route::get('/admin/2fa/challenge', [TwoFactorController::class, 'challenge'])->name('admin.2fa.challenge');
Route::post('/admin/2fa/challenge', [TwoFactorController::class, 'verifyChallenge'])->name('admin.2fa.challenge.verify');

Route::middleware(['auth', 'admin', 'console.audit'])->group(function () {
    // Logout is available to any signed-in console user (no permission gate).
    Route::post('/admin/logout', [AuthController::class, 'logout'])->name('admin.logout');

    // Personal two-factor management (any signed-in console user manages their own — no gate).
    Route::get('/admin/2fa', [TwoFactorController::class, 'show'])->name('admin.2fa.show');
    Route::post('/admin/2fa/enable', [TwoFactorController::class, 'enable'])->name('admin.2fa.enable');
    Route::post('/admin/2fa/confirm', [TwoFactorController::class, 'confirm'])->name('admin.2fa.confirm');
    Route::delete('/admin/2fa', [TwoFactorController::class, 'disable'])->name('admin.2fa.disable');

    // Dashboard — real stats from the DashboardController.
    Route::get('/admin', [DashboardController::class, 'index'])->middleware('permission:dashboard.view')->name('admin.dashboard');

    // Devices
    Route::get('/admin/devices', [DeviceController::class, 'index'])->middleware('permission:devices.view')->name('admin.devices.index');
    Route::get('/admin/devices/search', [DeviceController::class, 'search'])->middleware('permission:devices.view')->name('admin.devices.search');
    Route::post('/admin/devices/bulk', [DeviceController::class, 'bulkUpdate'])->middleware('permission:devices.edit')->name('admin.devices.bulk');
    Route::get('/admin/devices/{device}/edit', [DeviceController::class, 'edit'])->middleware('permission:devices.view')->name('admin.devices.edit');
    Route::put('/admin/devices/{device}', [DeviceController::class, 'update'])->middleware('permission:devices.edit')->name('admin.devices.update');
    Route::delete('/admin/devices/{device}', [DeviceController::class, 'destroy'])->middleware('permission:devices.edit')->name('admin.devices.destroy');

    // Users
    Route::get('/admin/users', [UserController::class, 'index'])->middleware('permission:users.view')->name('admin.users.index');
    Route::get('/admin/users/search', [UserController::class, 'search'])->middleware('permission:users.view')->name('admin.users.search');
    Route::get('/admin/users/create', [UserController::class, 'create'])->middleware('permission:users.edit')->name('admin.users.create');
    Route::post('/admin/users', [UserController::class, 'store'])->middleware('permission:users.edit')->name('admin.users.store');
    Route::get('/admin/users/{user}/edit', [UserController::class, 'edit'])->middleware('permission:users.view')->name('admin.users.edit');
    Route::put('/admin/users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit')->name('admin.users.update');
    Route::put('/admin/users/{user}/password', [UserController::class, 'resetPassword'])->middleware('permission:users.edit')->name('admin.users.password');
    Route::delete('/admin/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.edit')->name('admin.users.destroy');

    // Groups (user groups)
    Route::get('/admin/groups', [GroupController::class, 'index'])->middleware('permission:groups.view')->name('admin.groups.index');
    Route::get('/admin/groups/create', [GroupController::class, 'create'])->middleware('permission:groups.edit')->name('admin.groups.create');
    Route::post('/admin/groups', [GroupController::class, 'store'])->middleware('permission:groups.edit')->name('admin.groups.store');
    Route::get('/admin/groups/{group}/edit', [GroupController::class, 'edit'])->middleware('permission:groups.view')->name('admin.groups.edit');
    Route::put('/admin/groups/{group}', [GroupController::class, 'update'])->middleware('permission:groups.edit')->name('admin.groups.update');
    Route::delete('/admin/groups/{group}', [GroupController::class, 'destroy'])->middleware('permission:groups.edit')->name('admin.groups.destroy');

    // Device Groups
    Route::get('/admin/device-groups', [DeviceGroupController::class, 'index'])->middleware('permission:device_groups.view')->name('admin.device-groups.index');
    Route::get('/admin/device-groups/create', [DeviceGroupController::class, 'create'])->middleware('permission:device_groups.edit')->name('admin.device-groups.create');
    Route::post('/admin/device-groups', [DeviceGroupController::class, 'store'])->middleware('permission:device_groups.edit')->name('admin.device-groups.store');
    Route::get('/admin/device-groups/{deviceGroup}/edit', [DeviceGroupController::class, 'edit'])->middleware('permission:device_groups.view')->name('admin.device-groups.edit');
    Route::put('/admin/device-groups/{deviceGroup}', [DeviceGroupController::class, 'update'])->middleware('permission:device_groups.edit')->name('admin.device-groups.update');
    Route::post('/admin/device-groups/{deviceGroup}/default', [DeviceGroupController::class, 'setDefault'])->middleware('permission:device_groups.edit')->name('admin.device-groups.default');
    Route::delete('/admin/device-groups/{deviceGroup}', [DeviceGroupController::class, 'destroy'])->middleware('permission:device_groups.edit')->name('admin.device-groups.destroy');

    // Address Books
    Route::get('/admin/address-books', [AddressBookController::class, 'index'])->middleware('permission:address_books.view')->name('admin.address-books.index');
    Route::get('/admin/address-books/{addressBook}', [AddressBookController::class, 'show'])->middleware('permission:address_books.view')->name('admin.address-books.show');
    Route::delete('/admin/address-books/{addressBook}', [AddressBookController::class, 'destroy'])->middleware('permission:address_books.edit')->name('admin.address-books.destroy');
    // Peer add/edit/delete (RustDesk-client-style manager).
    Route::post('/admin/address-books/{addressBook}/peers', [AddressBookController::class, 'storePeer'])->middleware('permission:address_books.edit')->name('admin.address-books.peers.store');
    Route::put('/admin/address-books/peers/{peer}', [AddressBookController::class, 'updatePeer'])->middleware('permission:address_books.edit')->name('admin.address-books.peers.update');
    Route::delete('/admin/address-books/peers/{peer}', [AddressBookController::class, 'destroyPeer'])->middleware('permission:address_books.edit')->name('admin.address-books.peers.destroy');
    // Tag add/edit/delete.
    Route::post('/admin/address-books/{addressBook}/tags', [AddressBookController::class, 'storeTag'])->middleware('permission:address_books.edit')->name('admin.address-books.tags.store');
    Route::put('/admin/address-books/tags/{tag}', [AddressBookController::class, 'updateTag'])->middleware('permission:address_books.edit')->name('admin.address-books.tags.update');
    Route::delete('/admin/address-books/tags/{tag}', [AddressBookController::class, 'destroyTag'])->middleware('permission:address_books.edit')->name('admin.address-books.tags.destroy');

    // Strategies
    Route::get('/admin/strategies', [StrategyController::class, 'index'])->middleware('permission:strategies.view')->name('admin.strategies.index');
    Route::get('/admin/strategies/create', [StrategyController::class, 'create'])->middleware('permission:strategies.edit')->name('admin.strategies.create');
    Route::post('/admin/strategies', [StrategyController::class, 'store'])->middleware('permission:strategies.edit')->name('admin.strategies.store');
    Route::get('/admin/strategies/{strategy}/edit', [StrategyController::class, 'edit'])->middleware('permission:strategies.view')->name('admin.strategies.edit');
    Route::put('/admin/strategies/{strategy}', [StrategyController::class, 'update'])->middleware('permission:strategies.edit')->name('admin.strategies.update');
    Route::post('/admin/strategies/{strategy}/assignments', [StrategyController::class, 'storeAssignment'])->middleware('permission:strategies.edit')->name('admin.strategies.assignments.store');
    Route::delete('/admin/strategies/assignments/{assignment}', [StrategyController::class, 'destroyAssignment'])->middleware('permission:strategies.edit')->name('admin.strategies.assignments.destroy');
    Route::delete('/admin/strategies/{strategy}', [StrategyController::class, 'destroy'])->middleware('permission:strategies.edit')->name('admin.strategies.destroy');

    // Live sessions (active connections) + force-disconnect
    Route::get('/admin/sessions', [SessionController::class, 'index'])->middleware('permission:sessions.view')->name('admin.sessions.index');
    Route::post('/admin/sessions/disconnect', [SessionController::class, 'disconnect'])->middleware('permission:sessions.edit')->name('admin.sessions.disconnect');

    // Audit logs (read-only)
    Route::get('/admin/audit/connections', [AuditController::class, 'connections'])->middleware('permission:audit.view')->name('admin.audit.connections');
    Route::get('/admin/audit/files', [AuditController::class, 'files'])->middleware('permission:audit.view')->name('admin.audit.files');
    Route::get('/admin/audit/logins', [AuditController::class, 'logins'])->middleware('permission:audit.view')->name('admin.audit.logins');
    Route::get('/admin/console-audit', [ConsoleAuditController::class, 'index'])->middleware('permission:audit.view')->name('admin.console-audit.index');

    // Settings
    Route::get('/admin/settings', [SettingController::class, 'index'])->middleware('permission:settings.view')->name('admin.settings.index');
    Route::put('/admin/settings', [SettingController::class, 'update'])->middleware('permission:settings.edit')->name('admin.settings.update');
    Route::put('/admin/settings/smtp', [SettingController::class, 'updateSmtp'])->middleware('permission:settings.edit')->name('admin.settings.smtp');

    // Recordings
    Route::get('/admin/recordings', [RecordingController::class, 'index'])->middleware('permission:recordings.view')->name('admin.recordings.index');
    Route::get('/admin/recordings/{recording}/download', [RecordingController::class, 'download'])->middleware('permission:recordings.view')->name('admin.recordings.download');
    Route::delete('/admin/recordings/{recording}', [RecordingController::class, 'destroy'])->middleware('permission:recordings.view')->name('admin.recordings.destroy');

    // Alarms (read-only log)
    Route::get('/admin/alarms', [AlarmController::class, 'index'])->middleware('permission:alarms.view')->name('admin.alarms.index');
    Route::delete('/admin/alarms/{alarm}', [AlarmController::class, 'destroy'])->middleware('permission:alarms.view')->name('admin.alarms.destroy');

    // OAuth / OIDC providers (client SSO login configuration)
    Route::get('/admin/oauth-providers', [OauthProviderController::class, 'index'])->middleware('permission:oauth.view')->name('admin.oauth-providers.index');
    Route::get('/admin/oauth-providers/create', [OauthProviderController::class, 'create'])->middleware('permission:oauth.edit')->name('admin.oauth-providers.create');
    Route::post('/admin/oauth-providers', [OauthProviderController::class, 'store'])->middleware('permission:oauth.edit')->name('admin.oauth-providers.store');
    Route::get('/admin/oauth-providers/{oauthProvider}/edit', [OauthProviderController::class, 'edit'])->middleware('permission:oauth.view')->name('admin.oauth-providers.edit');
    Route::put('/admin/oauth-providers/{oauthProvider}', [OauthProviderController::class, 'update'])->middleware('permission:oauth.edit')->name('admin.oauth-providers.update');
    Route::delete('/admin/oauth-providers/{oauthProvider}', [OauthProviderController::class, 'destroy'])->middleware('permission:oauth.edit')->name('admin.oauth-providers.destroy');

    // LDAP / Active Directory (read-only status + connection test; configured via env)
    Route::get('/admin/ldap', [LdapController::class, 'index'])->middleware('permission:ldap.view')->name('admin.ldap.index');
    Route::post('/admin/ldap/test', [LdapController::class, 'test'])->middleware('permission:ldap.view')->name('admin.ldap.test');

    // Deploy tokens + device approval
    Route::get('/admin/deploy-tokens', [DeployTokenController::class, 'index'])->middleware('permission:deploy.view')->name('admin.deploy-tokens.index');
    Route::post('/admin/deploy-tokens', [DeployTokenController::class, 'store'])->middleware('permission:deploy.edit')->name('admin.deploy-tokens.store');
    Route::delete('/admin/deploy-tokens/{deployToken}', [DeployTokenController::class, 'destroy'])->middleware('permission:deploy.edit')->name('admin.deploy-tokens.destroy');
    Route::get('/admin/client-config', [ClientConfigController::class, 'index'])->middleware('permission:deploy.view')->name('admin.client-config.index');
    Route::get('/admin/devices/pending', [DeployTokenController::class, 'pending'])->middleware('permission:deploy.view')->name('admin.devices.pending');
    Route::put('/admin/devices/{device}/approve', [DeployTokenController::class, 'approve'])->middleware('permission:deploy.edit')->name('admin.devices.approve');
    Route::delete('/admin/devices/{device}/reject', [DeployTokenController::class, 'reject'])->middleware('permission:deploy.edit')->name('admin.devices.reject');

    // Admin Roles (delegated console permissions, Admin Role Layer 3)
    Route::get('/admin/roles', [AdminRoleController::class, 'index'])->middleware('permission:roles.view')->name('admin.roles.index');
    Route::get('/admin/roles/create', [AdminRoleController::class, 'create'])->middleware('permission:roles.edit')->name('admin.roles.create');
    Route::post('/admin/roles', [AdminRoleController::class, 'store'])->middleware('permission:roles.edit')->name('admin.roles.store');
    Route::get('/admin/roles/{role}/edit', [AdminRoleController::class, 'edit'])->middleware('permission:roles.view')->name('admin.roles.edit');
    Route::put('/admin/roles/{role}', [AdminRoleController::class, 'update'])->middleware('permission:roles.edit')->name('admin.roles.update');
    Route::delete('/admin/roles/{role}', [AdminRoleController::class, 'destroy'])->middleware('permission:roles.edit')->name('admin.roles.destroy');
});
