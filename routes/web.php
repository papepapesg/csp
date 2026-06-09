<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Backoffice screens (data loaded client-side from each owning module's API).
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/customers', fn () => Inertia::render('Ilm/Customers/Index'))->name('customers.index');
    Route::get('/tickets', fn () => Inertia::render('Tickets/Index'))->name('tickets.index');
    Route::get('/reports', fn () => Inertia::render('Reports/Dashboard'))->name('reports.index');
    Route::get('/workflow/studio', fn () => Inertia::render('Workflow/Studio'))->name('workflow.studio');
    Route::get('/rules/studio', fn () => Inertia::render('Rules/Studio'))->name('rules.studio');
    Route::get('/templates/studio', fn () => Inertia::render('Notification/Studio'))->name('templates.studio');
    Route::get('/admin/rbac', fn () => Inertia::render('Rbac/Admin'))->name('rbac.admin');
    Route::get('/workflow/ops', fn () => Inertia::render('Workflow/Operations'))->name('workflow.ops');
    Route::get('/itops', fn () => Inertia::render('ItOps/Console'))->name('itops.console');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// SOPHIX Field — installable mobile PWA (FE-APP-02/03). Auth handled in-app via tokens.
Route::get('/m', fn () => view('mobile'))->name('mobile');

// SOPHIX Care — customer self-care PWA (FE-APP-04). Token auth in-app.
Route::get('/care', fn () => view('care'))->name('care');

require __DIR__.'/auth.php';
