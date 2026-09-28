<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DrawerController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProfilePinController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

/*
 * Every route here is behind auth except the login form itself. There is no
 * public surface: this is a shop's till, reachable from the internet only
 * because the shop's own machine is too old to run anything locally.
 *
 * Authorisation is by Gate (see AuthServiceProvider), applied with the `can`
 * middleware so an unauthorised link 404s rather than rendering a screen that
 * then refuses to work. The services enforce the same rules again at their own
 * boundary - the middleware decides what is reachable, the service decides what
 * actually happens.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {

    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    // ---------------------------------------------------------------------
    // The till. A view that mounts the Livewire component, rather than the
    // component routed directly: see resources/views/pos/index.blade.php for
    // why routing it straight at this application's layout drops its markup.
    // ---------------------------------------------------------------------
    Route::view('sell', 'pos.index')->middleware('can:sell')->name('pos.index');

    // ---------------------------------------------------------------------
    // Inventory. Staff unpack the deliveries, so this is staff-level.
    //
    // Every quantity on these screens is derived from the ledger. There is no
    // route that writes a quantity directly - receiving and adjusting both go
    // through StockService, which is the only thing that appends to it.
    // ---------------------------------------------------------------------
    Route::middleware('can:manage-inventory')->group(function () {
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory/alerts', [InventoryController::class, 'alerts'])->name('inventory.alerts');
        Route::get('inventory/new', [InventoryController::class, 'create'])->name('inventory.create');
        Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');
        Route::get('inventory/{item}', [InventoryController::class, 'show'])->name('inventory.show');
        Route::get('inventory/{item}/edit', [InventoryController::class, 'edit'])->name('inventory.edit');
        Route::put('inventory/{item}', [InventoryController::class, 'update'])->name('inventory.update');

        Route::get('inventory/{item}/receive', [InventoryController::class, 'receiveForm'])->name('inventory.receive.form');
        Route::post('inventory/{item}/receive', [InventoryController::class, 'receive'])->name('inventory.receive');

        Route::get('inventory/{item}/adjust', [InventoryController::class, 'adjustForm'])->name('inventory.adjust.form');
        Route::post('inventory/{item}/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
    });

    // ---------------------------------------------------------------------
    // Sales history. Read-only: a sale is never edited, only returned
    // against, so there is deliberately no edit route to find.
    // ---------------------------------------------------------------------
    Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
    Route::get('sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');

    // ---------------------------------------------------------------------
    // The cash drawer
    // ---------------------------------------------------------------------
    Route::get('drawer', [DrawerController::class, 'show'])->name('drawer.show');

    Route::post('drawer/open', [DrawerController::class, 'open'])
        ->middleware('can:open-drawer')
        ->name('drawer.open');

    // Closing is manager-and-above: the close computes the variance, and the
    // person who might be short must not be the only one to record it.
    Route::post('drawer/close', [DrawerController::class, 'close'])
        ->middleware('can:close-drawer')
        ->name('drawer.close');

    // ---------------------------------------------------------------------
    // My own PIN - available to everyone, for their own account only
    // ---------------------------------------------------------------------
    Route::get('my-pin', [ProfilePinController::class, 'edit'])->name('profile.pin.edit');
    Route::put('my-pin', [ProfilePinController::class, 'update'])->name('profile.pin.update');

    // ---------------------------------------------------------------------
    // Staff accounts - owner only
    // ---------------------------------------------------------------------
    Route::middleware('can:manage-staff')->group(function () {
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::get('staff/new', [StaffController::class, 'create'])->name('staff.create');
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::get('staff/{user}', [StaffController::class, 'edit'])->name('staff.edit');
        Route::put('staff/{user}', [StaffController::class, 'update'])->name('staff.update');
        Route::put('staff/{user}/pin', [StaffController::class, 'resetPin'])->name('staff.pin.reset');
        Route::delete('staff/{user}', [StaffController::class, 'deactivate'])->name('staff.deactivate');
        Route::post('staff/{user}/reactivate', [StaffController::class, 'reactivate'])->name('staff.reactivate');
    });

    // ---------------------------------------------------------------------
    // Shop settings - owner only
    // ---------------------------------------------------------------------
    Route::middleware('can:manage-settings')->group(function () {
        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    });
});
