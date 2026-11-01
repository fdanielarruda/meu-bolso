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

    /**
     * Limit minus what is still owed from the given month onward: that
     * month's unpaid invoice plus the remaining installments of later ones.
     * Recurring charges only count in the given month itself, since they're
     * pre-generated 24 months ahead and would otherwise eat the limit many
     * times over. Months before the card's "starts counting at" (starts_at)
     * and earlier than the given month are ignored.
     */
    public function availableLimit(?CarbonInterface $month = null): string
    {
        $from = ($month ?? Carbon::now())->copy()->startOfMonth();

        if ($this->starts_at && $this->starts_at->copy()->startOfMonth()->gt($from)) {
            $from = $this->starts_at->copy()->startOfMonth();
        }

        $used = $this->invoices()
            ->where('status', '!=', InvoiceStatus::Paid)
            ->whereDate('reference_month', '>=', $from->toDateString())
            ->with('transactions')
            ->get()
            ->flatMap(fn ($invoice) => $invoice->transactions->filter(
                fn ($transaction) => ! $transaction->is_recurring || $invoice->reference_month->isSameMonth($from)
            ))
            ->reduce(fn (string $carry, $transaction) => $transaction->reversed
                ? Money::sub($carry, $transaction->amount)
                : Money::add($carry, $transaction->amount), '0.00');

        return Money::sub($this->credit_limit, $used);
    }

    /**
     * Find (or create) the invoice a purchase made on the given date belongs to.
     * Purchases made after the closing day roll over to the next closing cycle.
     * An invoice is identified by the month it is due (see invoiceForMonth()).
     */
    public function resolveInvoiceFor(CarbonInterface $date): CreditCardInvoice
    {
        $closingMonth = $date->day > $this->closing_day
            ? $date->copy()->startOfMonth()->addMonthNoOverflow()
            : $date->copy()->startOfMonth();

        $referenceMonth = $this->due_day > $this->closing_day
            ? $closingMonth
            : $closingMonth->copy()->addMonthNoOverflow();

        return $this->invoiceForMonth($referenceMonth);
    }

    /**
     * Find (or create) the invoice for a given reference month directly,
     * used when navigating the invoice month-by-month or fanning out
     * installments to specific past/future invoices. The reference month is
     * the month the invoice is due, so it matches the "vencimento" shown
     * on the card; the closing date falls the same month or the one before.
     */
    public function invoiceForMonth(CarbonInterface $month): CreditCardInvoice
    {
        $referenceMonth = $month->copy()->startOfMonth();

        return $this->invoices()->firstOrCreate(
            ['reference_month' => $referenceMonth->toDateString()],
            [
                'closing_date' => $this->due_day > $this->closing_day
                    ? $this->dayInMonth($referenceMonth, $this->closing_day)
                    : $this->dayInMonth($referenceMonth->copy()->subMonthNoOverflow(), $this->closing_day),
                'due_date' => $this->dayInMonth($referenceMonth, $this->due_day),
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
