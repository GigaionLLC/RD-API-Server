<?php

use App\Http\Controllers\Api\AddressBookController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\DevicesController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\IndexController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\OauthController;
use App\Http\Controllers\Api\RecordController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\StrategyController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
 * Client-facing RustDesk API. These routes are auto-prefixed with /api.
 * Build strictly to docs/modernization/02-client-api-contract.md — the JSON keys and
 * paths here are the wire protocol the RustDesk client speaks; do not rename them.
 *
 * Model-dependent endpoints (heartbeat+strategy, sysinfo+presets, login+2FA, address
 * book, audit, record, devices/deploy) are added in the next wave once the data model
 * lands. Routing is enabled now so /api is live.
 */

Route::get('/', [IndexController::class, 'index']);
Route::get('/version', [IndexController::class, 'version']);

// Device telemetry + Strategy push + preset auto-registration (the differentiators).
Route::post('/heartbeat', [SystemController::class, 'heartbeat']);
Route::post('/sysinfo', [SystemController::class, 'sysinfo']);
Route::post('/sysinfo_ver', [SystemController::class, 'sysinfoVer']);

// Account login & 2FA negotiation (contract §3-§4). Unauthenticated except where noted.
// Login is brute-force throttled (per account+IP and per IP) — see AppServiceProvider.
Route::get('/login-options', [LoginController::class, 'loginOptions']);
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:api-login');

// OIDC / OAuth device-login flow (contract §3a). All unauthenticated.
Route::post('/oidc/auth', [OauthController::class, 'auth']);
Route::get('/oidc/auth-query', [OauthController::class, 'authQuery']);
Route::get('/oauth/callback', [OauthController::class, 'callback']);
Route::get('/oidc/callback', [OauthController::class, 'callback']);
Route::get('/oauth/msg', [OauthController::class, 'msg']);
Route::get('/oidc/msg', [OauthController::class, 'msg']);

// Audit ingestion from hbbs/clients (contract §8). Unauthenticated.
Route::post('/audit/conn', [AuditController::class, 'conn']);
Route::post('/audit/file', [AuditController::class, 'file']);
Route::post('/audit/alarm', [AuditController::class, 'alarm']);

// Session recording chunked upload (contract §5).
Route::post('/record', [RecordController::class, 'store']);

// Device deployment / CLI enrollment (contract §7). Deploy-token authenticated internally.
Route::post('/devices/deploy', [DevicesController::class, 'deploy']);
// `rustdesk --assign --token …`: register + apply strategy/address-book/group/owner presets.
Route::post('/devices/cli', [DevicesController::class, 'cli']);

/*
 * Admin REST API (v1) — programmatic access authenticated by a scoped API key
 * (Authorization: Bearer <key> or X-API-Key). Manage keys + scopes in the admin console
 * (API Keys). Documented in docs/api/README.md.
 */
Route::prefix('v1')->group(function (): void {
    Route::get('/devices', [DeviceController::class, 'index'])->middleware('apikey:devices.read');
    Route::put('/devices/{device}', [DeviceController::class, 'update'])->middleware('apikey:devices.write');

    Route::get('/users', [UserController::class, 'index'])->middleware('apikey:users.read');
    Route::post('/users', [UserController::class, 'store'])->middleware('apikey:users.write');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('apikey:users.write');

    Route::get('/strategies', [StrategyController::class, 'index'])->middleware('apikey:strategies.read');
    Route::post('/strategies', [StrategyController::class, 'store'])->middleware('apikey:strategies.write');
    Route::put('/strategies/{strategy}', [StrategyController::class, 'update'])->middleware('apikey:strategies.write');

    Route::get('/audit/connections', [App\Http\Controllers\Api\V1\AuditController::class, 'connections'])->middleware('apikey:audit.read');

    Route::get('/address-books', [App\Http\Controllers\Api\V1\AddressBookController::class, 'index'])->middleware('apikey:address_book.read');
    Route::post('/address-books', [App\Http\Controllers\Api\V1\AddressBookController::class, 'store'])->middleware('apikey:address_book.write');
    Route::delete('/address-books/{addressBook}', [App\Http\Controllers\Api\V1\AddressBookController::class, 'destroy'])->middleware('apikey:address_book.write');
    Route::get('/address-books/{addressBook}/peers', [App\Http\Controllers\Api\V1\AddressBookController::class, 'peers'])->middleware('apikey:address_book.read');
    Route::post('/address-books/{addressBook}/peers', [App\Http\Controllers\Api\V1\AddressBookController::class, 'storePeer'])->middleware('apikey:address_book.write');
    Route::delete('/address-books/{addressBook}/peers/{peer}', [App\Http\Controllers\Api\V1\AddressBookController::class, 'destroyPeer'])->middleware('apikey:address_book.write');
});

// Bearer-token (account) authenticated client API.
Route::middleware('rustauth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::post('/currentUser', [LoginController::class, 'currentUser']);
    Route::get('/user/info', [LoginController::class, 'currentUser']);

    // Accessible users / peers / device groups (Access Control Layer 1, contract §10).
    Route::get('/users', [GroupController::class, 'users']);
    Route::get('/peers', [GroupController::class, 'peers']);
    Route::get('/device-group/accessible', [GroupController::class, 'deviceGroupAccessible']);

    // Operator end-of-connection notes (contract §8). The controlling client fetches the
    // live session's audit guid, then attaches a note — both carry the operator's account
    // bearer. See docs/modernization/16-response-contract.md rows 28 / §0.5.
    Route::get('/audit/conn/active', [AuditController::class, 'active']);
    Route::put('/audit', [AuditController::class, 'note']);

    // Address book — legacy blob transport (older Sciter clients).
    Route::post('/ab/get', [AddressBookController::class, 'getLegacy']);
    Route::post('/ab', [AddressBookController::class, 'updateLegacy']);

    // Address book — granular per-collection transport (Flutter clients).
    Route::post('/ab/personal', [AddressBookController::class, 'personal']);
    Route::post('/ab/settings', [AddressBookController::class, 'settings']);
    Route::post('/ab/shared/profiles', [AddressBookController::class, 'sharedProfiles']);
    Route::post('/ab/peers', [AddressBookController::class, 'peers']);
    Route::post('/ab/tags/{guid}', [AddressBookController::class, 'tags']);

    // Peer mutations.
    Route::post('/ab/peer/add/{guid}', [AddressBookController::class, 'peerAdd']);
    Route::put('/ab/peer/update/{guid}', [AddressBookController::class, 'peerUpdate']);
    Route::delete('/ab/peer/{guid}', [AddressBookController::class, 'peerDelete']);

    // Tag mutations.
    Route::post('/ab/tag/add/{guid}', [AddressBookController::class, 'tagAdd']);
    Route::put('/ab/tag/update/{guid}', [AddressBookController::class, 'tagUpdate']);
    Route::put('/ab/tag/rename/{guid}', [AddressBookController::class, 'tagRename']);
    Route::delete('/ab/tag/{guid}', [AddressBookController::class, 'tagDelete']);
});
