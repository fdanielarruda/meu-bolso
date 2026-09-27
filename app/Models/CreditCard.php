<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Support\BelongsToUser;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'institution_id', 'name', 'payment_account_id', 'credit_limit', 'closing_day', 'due_day', 'starts_at'])]
class CreditCard extends Model
{
    use BelongsToUser, HasFactory;

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'starts_at' => 'date',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CreditCardInvoice::class);
    }

    /**
     * Total of the currently open invoice.
     */
    public function openInvoiceTotal(): string
    {
        return $this->currentInvoice()?->total() ?? '0.00';
    }

    public function availableLimit(): string
    {
        $unpaidTotal = $this->invoices()
            ->where('status', '!=', InvoiceStatus::Paid)
            ->get()
            ->reduce(fn ($carry, $invoice) => Money::add($carry, $invoice->total()), '0.00');

        return Money::sub($this->credit_limit, $unpaidTotal);
    }

    /**
     * Find (or create) the invoice a purchase made on the given date belongs to.
     * Purchases made after the closing day roll over to next month's invoice.
     */
    public function resolveInvoiceFor(CarbonInterface $date): CreditCardInvoice
    {
        $referenceMonth = $date->day > $this->closing_day
            ? $date->copy()->startOfMonth()->addMonthNoOverflow()
            : $date->copy()->startOfMonth();

        return $this->invoiceForMonth($referenceMonth);
    }

    /**
     * Find (or create) the invoice for a given reference month directly,
     * used when navigating the invoice month-by-month or fanning out
     * installments to specific past/future invoices.
     */
    public function invoiceForMonth(CarbonInterface $month): CreditCardInvoice
    {
        $referenceMonth = $month->copy()->startOfMonth();

        return $this->invoices()->firstOrCreate(
            ['reference_month' => $referenceMonth->toDateString()],
            [
                'closing_date' => $this->dayInMonth($referenceMonth, $this->closing_day),
                'due_date' => $this->due_day > $this->closing_day
                    ? $this->dayInMonth($referenceMonth, $this->due_day)
                    : $this->dayInMonth($referenceMonth->copy()->addMonthNoOverflow(), $this->due_day),
                'status' => InvoiceStatus::Open,
            ]
        );
    }

    public function currentInvoice(): ?CreditCardInvoice
    {
        return $this->resolveInvoiceFor(Carbon::now());
    }

    /**
     * Whether the given month is before this card's configured starting
     * month, i.e. a month whose invoice shouldn't be generated/navigable.
     */
    public function isBeforeStartMonth(CarbonInterface $month): bool
    {
        return $this->starts_at && $month->copy()->startOfMonth()->lt($this->starts_at->copy()->startOfMonth());
    }

    private function dayInMonth(CarbonInterface $month, int $day): string
    {
        return $month->copy()->startOfMonth()->addDays(min($day, $month->daysInMonth) - 1)->toDateString();
    }
}
