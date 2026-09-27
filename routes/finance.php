<?php

use App\Http\Controllers\Finance\AccountController;
use App\Http\Controllers\Finance\CategoryController;
use App\Http\Controllers\Finance\CreditCardController;
use App\Http\Controllers\Finance\CreditCardInvoiceController;
use App\Http\Controllers\Finance\InstitutionController;
use App\Http\Controllers\Finance\OwedController;
use App\Http\Controllers\Finance\PersonController;
use App\Http\Controllers\Finance\RecurringTransactionController;
use App\Http\Controllers\Finance\ReportController;
use App\Http\Controllers\Finance\StatementImportController;
use App\Http\Controllers\Finance\TransactionClassificationController;
use App\Http\Controllers\Finance\TransactionController;
use App\Http\Controllers\Finance\TransferController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('finance')->name('finance.')->group(function () {
    Route::resource('people', PersonController::class)->except(['show', 'create', 'edit'])->names('people');
    Route::resource('institutions', InstitutionController::class)->except(['show', 'create', 'edit'])->names('institutions');
    Route::resource('categories', CategoryController::class)->except(['show', 'create', 'edit'])->names('categories');

    Route::resource('accounts', AccountController::class)->except(['create', 'edit'])->names('accounts');

    Route::get('transactions/quick', [TransactionController::class, 'quick'])->name('transactions.quick');
    Route::get('transactions/recent-by-bank', [TransactionController::class, 'recentByBank'])->name('transactions.recent-by-bank');
    Route::resource('transactions', TransactionController::class)->except(['show', 'create', 'edit'])->names('transactions');

    Route::get('transactions-classification', [TransactionClassificationController::class, 'index'])->name('transaction-classification.index');
    Route::post('transactions-classification', [TransactionClassificationController::class, 'confirm'])->name('transaction-classification.confirm');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

    Route::get('owed', [OwedController::class, 'index'])->name('owed.index');
    Route::get('owed/receipt', [OwedController::class, 'receipt'])->name('owed.receipt');

    Route::resource('transfers', TransferController::class)->only(['index', 'store', 'update', 'destroy'])->names('transfers');

    Route::resource('recurring', RecurringTransactionController::class)->except(['show', 'create', 'edit'])->names('recurring');

    Route::get('credit-cards', [CreditCardController::class, 'index'])->name('credit-cards.index');
    Route::post('credit-cards', [CreditCardController::class, 'store'])->name('credit-cards.store');
    Route::put('credit-cards/{creditCard}', [CreditCardController::class, 'update'])->name('credit-cards.update');

    Route::get('invoices/{invoice}', [CreditCardInvoiceController::class, 'show'])->name('invoices.show');
    Route::post('invoices/{invoice}/pay', [CreditCardInvoiceController::class, 'pay'])->name('invoices.pay');
    Route::post('credit-cards/{creditCard}/purchases', [CreditCardInvoiceController::class, 'storePurchase'])->name('purchases.store');
    Route::put('installments/{transaction}', [CreditCardInvoiceController::class, 'updateInstallment'])->name('installments.update');
    Route::post('installments/{transaction}/reverse', [CreditCardInvoiceController::class, 'toggleReversed'])->name('installments.reverse');
    Route::delete('installments/{transaction}', [CreditCardInvoiceController::class, 'destroyInstallment'])->name('installments.destroy');
    Route::post('installments/{transaction}/split', [CreditCardInvoiceController::class, 'splitInstallment'])->name('installments.split');

    Route::get('statement-imports/create', [StatementImportController::class, 'create'])->name('statement-imports.create');
    Route::post('statement-imports', [StatementImportController::class, 'upload'])->name('statement-imports.upload');
    Route::get('statement-imports/{statementImport}', [StatementImportController::class, 'show'])->name('statement-imports.show');
    Route::post('statement-imports/{statementImport}/reextract', [StatementImportController::class, 'reextract'])->name('statement-imports.reextract');
    Route::post('statement-imports/{statementImport}/confirm', [StatementImportController::class, 'confirm'])->name('statement-imports.confirm');
});
