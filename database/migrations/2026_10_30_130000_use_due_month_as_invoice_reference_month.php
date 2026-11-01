<?php

use App\Models\CreditCardInvoice;
use Illuminate\Database\Migrations\Migration;

/**
 * Invoices used to be keyed by the month they close in; they are now keyed
 * by the month they are due. Only cards whose due date falls in the month
 * after closing (e.g. closes 27th, due 8th) actually change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Latest first, so a shifted month never collides with one not yet moved.
        CreditCardInvoice::query()
            ->orderByDesc('reference_month')
            ->get()
            ->each(function (CreditCardInvoice $invoice) {
                $dueMonth = $invoice->due_date->copy()->startOfMonth();

                if (! $invoice->reference_month->isSameMonth($dueMonth)) {
                    $invoice->update(['reference_month' => $dueMonth->toDateString()]);
                }
            });
    }

    public function down(): void
    {
        CreditCardInvoice::query()
            ->orderBy('reference_month')
            ->get()
            ->each(function (CreditCardInvoice $invoice) {
                $closingMonth = $invoice->closing_date->copy()->startOfMonth();

                if (! $invoice->reference_month->isSameMonth($closingMonth)) {
                    $invoice->update(['reference_month' => $closingMonth->toDateString()]);
                }
            });
    }
};
