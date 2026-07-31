<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\IssuedInvoiceController;
use App\Http\Controllers\NumberSeriesController;
use App\Http\Controllers\OrganizationSelectController;
use App\Http\Controllers\OrganizationSettingsController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReceivedInvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/organizace', [OrganizationSelectController::class, 'index'])->name('organizations.select');
    Route::post('/organizace/{organization}', [OrganizationSelectController::class, 'select'])->name('organizations.choose');
});

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('kontakty', ContactController::class)
        ->parameters(['kontakty' => 'contact'])
        ->except(['show'])
        ->names('contacts');

    Route::resource('projekty', ProjectController::class)
        ->parameters(['projekty' => 'project'])
        ->except(['show'])
        ->names('projects');

    Route::resource('faktury', IssuedInvoiceController::class)
        ->parameters(['faktury' => 'invoice'])
        ->names('invoices');
    Route::post('faktury/{invoice}/vystavit', [IssuedInvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('faktury/{invoice}/platba', [IssuedInvoiceController::class, 'registerPayment'])->name('invoices.payment');
    Route::post('faktury/{invoice}/uhrazeno', [IssuedInvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
    Route::post('faktury/{invoice}/storno', [IssuedInvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::get('faktury/{invoice}/pdf', InvoicePdfController::class)->name('invoices.pdf');

    Route::resource('prijate-faktury', ReceivedInvoiceController::class)
        ->parameters(['prijate-faktury' => 'received'])
        ->names('received');
    Route::post('prijate-faktury/{received}/schvalit', [ReceivedInvoiceController::class, 'approve'])->name('received.approve');
    Route::post('prijate-faktury/{received}/zamitnout', [ReceivedInvoiceController::class, 'reject'])->name('received.reject');
    Route::post('prijate-faktury/{received}/uhrazeno', [ReceivedInvoiceController::class, 'markPaid'])->name('received.mark-paid');
    Route::post('prijate-faktury/{received}/prilohy', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('prilohy/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('prilohy/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    Route::get('nastaveni', [OrganizationSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('nastaveni/profil', [OrganizationSettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::put('nastaveni/fakturace', [OrganizationSettingsController::class, 'updateInvoicing'])->name('settings.invoicing');
    Route::post('nastaveni/logo', [OrganizationSettingsController::class, 'updateLogo'])->name('settings.logo');
    Route::get('nastaveni/logo', [OrganizationSettingsController::class, 'showLogo'])->name('settings.logo.show');

    Route::post('nastaveni/ucty', [BankAccountController::class, 'store'])->name('bank-accounts.store');
    Route::put('nastaveni/ucty/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
    Route::delete('nastaveni/ucty/{bankAccount}', [BankAccountController::class, 'destroy'])->name('bank-accounts.destroy');

    Route::post('nastaveni/rady', [NumberSeriesController::class, 'store'])->name('number-series.store');
    Route::put('nastaveni/rady/{series}', [NumberSeriesController::class, 'update'])->name('number-series.update');
    Route::delete('nastaveni/rady/{series}', [NumberSeriesController::class, 'destroy'])->name('number-series.destroy');
});
