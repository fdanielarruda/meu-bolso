<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Support\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'account_id', 'category_id', 'credit_card_invoice_id', 'recurring_transaction_id',
    'invoice_payment_id', 'transfer_id', 'type', 'description', 'is_unknown', 'exclude_from_reports', 'reversed', 'amount', 'date',
    'installment_group_id', 'installment_number', 'installment_total', 'is_recurring', 'split_group_id',
])]
class Transaction extends Model
{
    use BelongsToUser, HasFactory;

    /**
     * A transaction has no person of its own — it's always whoever owns
     * its account. Appended so the frontend can keep reading
     * `transaction.person` without every caller reaching into `account`.
     */
    protected $appends = ['person'];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'decimal:2',
            'date' => 'date',
            'is_unknown' => 'boolean',
            'exclude_from_reports' => 'boolean',
            'reversed' => 'boolean',
            'is_recurring' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Requires 'account.person' to be eager-loaded to avoid N+1 queries.
     */
    protected function person(): Attribute
    {
        return Attribute::get(fn () => $this->account?->person);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creditCardInvoice(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoice::class);
    }

    public function recurringTransaction(): BelongsTo
    {
        return $this->belongsTo(RecurringTransaction::class);
    }

    public function invoicePayment(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoice::class, 'invoice_payment_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    /**
     * Create a plain account transaction (checking/savings/wallet/investment).
     * Credit-card purchases are created separately via
     * createInstallmentsForInvoice(), since cards are no longer accounts.
     *
     * @return Collection<int, Transaction>
     */
    public static function createPurchase(array $attributes): Collection
    {
        $account = Account::findOrFail($attributes['account_id']);

        $transaction = $account->transactions()->create($attributes);

        return new Collection([$transaction]);
    }

    /**
     * How far into the future a recurring card purchase is allowed to have
     * generated installments. Invoices beyond this month cannot be viewed.
     */
    public static function recurringCapMonth(): \Illuminate\Support\Carbon
    {
        return now()->startOfMonth()->addMonths(24);
    }

    /**
     * Start a recurring card purchase (e.g. a subscription): creates
     * installments from the anchor invoice's month up to the rolling
     * 2-years-ahead cap, all sharing one installment_group_id so the window
     * can later be extended by extendRecurringGroup().
     *
     * @return Collection<int, Transaction>
     */
    public static function createRecurringForInvoice(array $attributes, CreditCardInvoice $anchorInvoice): Collection
    {
        $account = Account::findOrFail($attributes['account_id']);
        $creditCard = $anchorInvoice->creditCard;
        $groupId = (string) Str::ulid();
        $anchorMonth = $anchorInvoice->reference_month;
        $capMonth = self::recurringCapMonth();

        $months = collect();
        for ($month = $anchorMonth->copy(); $month->lte($capMonth); $month->addMonthNoOverflow()) {
            $months->push($month->copy());
        }

        return $months->values()->map(function ($month, int $index) use ($account, $attributes, $creditCard, $groupId) {
            $invoice = $creditCard->invoiceForMonth($month);

            return $account->transactions()->create([
                ...$attributes,
                'date' => $index === 0 ? $attributes['date'] : $month->copy()->startOfMonth()->toDateString(),
                'credit_card_invoice_id' => $invoice->id,
                'installment_group_id' => $groupId,
                'installment_number' => $index + 1,
                'installment_total' => null,
                'is_recurring' => true,
            ]);
        });
    }

    /**
     * Fill in any missing months for an existing recurring group, from the
     * month after its last generated installment up to the rolling cap.
     * Safe to call repeatedly (e.g. on login) — a group already at the cap
     * simply generates nothing.
     */
    public static function extendRecurringGroup(string $groupId): Collection
    {
        $last = static::where('installment_group_id', $groupId)
            ->where('is_recurring', true)
            ->with('creditCardInvoice')
            ->orderByDesc('installment_number')
            ->first();

        if (! $last) {
            return new Collection;
        }

        $creditCard = $last->creditCardInvoice->creditCard;
        $capMonth = self::recurringCapMonth();
        $nextMonth = $last->creditCardInvoice->reference_month->copy()->addMonthNoOverflow();
        $nextNumber = $last->installment_number + 1;

        $created = new Collection;

        for ($month = $nextMonth; $month->lte($capMonth); $month->addMonthNoOverflow()) {
            $invoice = $creditCard->invoiceForMonth($month);

            $created->push(Transaction::create([
                'user_id' => $last->user_id,
                'account_id' => $last->account_id,
                'category_id' => $last->category_id,
                'credit_card_invoice_id' => $invoice->id,
                'type' => $last->type,
                'description' => $last->description,
                'is_unknown' => $last->is_unknown,
                'amount' => $last->amount,
                'date' => $month->copy()->startOfMonth()->toDateString(),
                'installment_group_id' => $groupId,
                'installment_number' => $nextNumber++,
                'installment_total' => null,
                'is_recurring' => true,
            ]));
        }

        return $created;
    }

    /**
     * Same as createInstallmentsForInvoice(), but fans each installment out
     * across several people's shares instead of a single account. Each
     * share gets its own installment_group_id (so its own future/all edit
     * scope only ever touches that person's installments), while every
     * month gets its own split_group_id linking that month's shares
     * together for display and editing.
     *
     * @param  array<int, array{account_id: int|string, amount: string}>  $shares
     */
    public static function createSplitInstallmentsForInvoice(
        array $shares,
        array $baseAttributes,
        CreditCardInvoice $anchorInvoice,
        int $installmentNumber,
        int $installmentTotal
    ): void {
        $creditCard = $anchorInvoice->creditCard;
        $anchorMonth = $anchorInvoice->reference_month;
        $groupIds = $installmentTotal > 1
            ? array_map(fn () => (string) Str::ulid(), $shares)
            : array_fill(0, count($shares), null);

        for ($number = 1; $number <= $installmentTotal; $number++) {
            $invoiceMonth = $anchorMonth->copy()->addMonthsNoOverflow($number - $installmentNumber);
            $invoice = $creditCard->invoiceForMonth($invoiceMonth);
            $splitGroupId = (string) Str::ulid();

            foreach ($shares as $index => $share) {
                $account = Account::findOrFail($share['account_id']);

                $account->transactions()->create([
                    ...$baseAttributes,
                    'account_id' => $share['account_id'],
                    'amount' => $share['amount'],
                    'credit_card_invoice_id' => $invoice->id,
                    'installment_group_id' => $groupIds[$index],
                    'installment_number' => $installmentTotal > 1 ? $number : null,
                    'installment_total' => $installmentTotal > 1 ? $installmentTotal : null,
                    'split_group_id' => $splitGroupId,
                ]);
            }
        }
    }

    /**
     * Same as createRecurringForInvoice(), but fans each month out across
     * several people's shares instead of a single account. See
     * createSplitInstallmentsForInvoice() for the grouping rationale.
     *
     * @param  array<int, array{account_id: int|string, amount: string}>  $shares
     */
    public static function createSplitRecurringForInvoice(array $shares, array $baseAttributes, CreditCardInvoice $anchorInvoice): void
    {
        $creditCard = $anchorInvoice->creditCard;
        $anchorMonth = $anchorInvoice->reference_month;
        $capMonth = self::recurringCapMonth();
        $groupIds = array_map(fn () => (string) Str::ulid(), $shares);

        $index = 0;

        for ($month = $anchorMonth->copy(); $month->lte($capMonth); $month->addMonthNoOverflow()) {
            $invoice = $creditCard->invoiceForMonth($month);
            $splitGroupId = (string) Str::ulid();

            foreach ($shares as $shareIndex => $share) {
                $account = Account::findOrFail($share['account_id']);

                $account->transactions()->create([
                    ...$baseAttributes,
                    'account_id' => $share['account_id'],
                    'amount' => $share['amount'],
                    'date' => $index === 0 ? $baseAttributes['date'] : $month->copy()->startOfMonth()->toDateString(),
                    'credit_card_invoice_id' => $invoice->id,
                    'installment_group_id' => $groupIds[$shareIndex],
                    'installment_number' => $index + 1,
                    'installment_total' => null,
                    'is_recurring' => true,
                    'split_group_id' => $splitGroupId,
                ]);
            }

            $index++;
        }
    }

    /**
     * Rebuild a card purchase from a given installment forward as a new
     * plan (single / fixed installments / recurring), replacing that
     * installment and any later siblings in its group. Earlier installments
     * (already past) are left untouched. Reuses the same creation paths as
     * a brand-new purchase, anchored on the invoice the edited installment
     * currently sits on.
     *
     * @return Collection<int, Transaction>
     */
    public static function changeType(
        Transaction $transaction,
        string $mode,
        array $attributes,
        ?int $installmentNumber = null,
        ?int $installmentTotal = null
    ): Collection {
        $transaction->loadMissing('creditCardInvoice');
        $anchorInvoice = $transaction->creditCardInvoice;

        if ($transaction->installment_group_id) {
            static::where('installment_group_id', $transaction->installment_group_id)
                ->where('installment_number', '>=', $transaction->installment_number)
                ->delete();
        } else {
            $transaction->delete();
        }

        return match ($mode) {
            'recurring' => self::createRecurringForInvoice($attributes, $anchorInvoice),
            'installments' => self::createInstallmentsForInvoice($attributes, $anchorInvoice, $installmentNumber, $installmentTotal),
            default => self::createInstallmentsForInvoice($attributes, $anchorInvoice, 1, 1),
        };
    }

    /**
     * Create a credit-card purchase anchored on the installment currently
     * visible on a given invoice (e.g. "I'm looking at 5/10 on March's
     * invoice"): fans out the remaining installments to past/future invoices,
     * creating those invoices as needed. Each installment keeps its own
     * amount (as typed, matching what the bank statement shows).
     *
     * @return Collection<int, Transaction>
     */
    public static function createInstallmentsForInvoice(
        array $attributes,
        CreditCardInvoice $anchorInvoice,
        int $installmentNumber,
        int $installmentTotal
    ): Collection {
        $account = Account::findOrFail($attributes['account_id']);
        $creditCard = $anchorInvoice->creditCard;
        $groupId = $installmentTotal > 1 ? (string) Str::ulid() : null;
        $anchorMonth = $anchorInvoice->reference_month;

        return collect(range(1, $installmentTotal))->map(function (int $number) use (
            $account, $attributes, $creditCard, $anchorMonth, $installmentNumber, $installmentTotal, $groupId
        ) {
            $invoiceMonth = $anchorMonth->copy()->addMonthsNoOverflow($number - $installmentNumber);
            $invoice = $creditCard->invoiceForMonth($invoiceMonth);

            return $account->transactions()->create([
                ...$attributes,
                'credit_card_invoice_id' => $invoice->id,
                'installment_group_id' => $groupId,
                'installment_number' => $installmentTotal > 1 ? $number : null,
                'installment_total' => $installmentTotal > 1 ? $installmentTotal : null,
            ]);
        });
    }
}
