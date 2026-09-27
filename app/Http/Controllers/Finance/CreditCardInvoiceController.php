<?php

namespace App\Http\Controllers\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CreditCardInvoiceController extends Controller
{
    public function show(Request $request, CreditCardInvoice $invoice): Response
    {
        $this->authorizeInvoice($request, $invoice);

        abort_if($invoice->reference_month->gt(Transaction::recurringCapMonth()), HttpResponse::HTTP_FORBIDDEN);

        $invoice->load(['creditCard.institution', 'creditCard.paymentAccount']);

        $creditCard = $invoice->creditCard;
        abort_if($creditCard->isBeforeStartMonth($invoice->reference_month), HttpResponse::HTTP_FORBIDDEN);

        $prevMonth = $invoice->reference_month->copy()->subMonthNoOverflow();
        $prevInvoice = $creditCard->isBeforeStartMonth($prevMonth) ? null : $creditCard->invoiceForMonth($prevMonth);
        $nextMonth = $invoice->reference_month->copy()->addMonthNoOverflow();
        $nextInvoice = $nextMonth->lte(Transaction::recurringCapMonth()) ? $creditCard->invoiceForMonth($nextMonth) : null;

        return Inertia::render('Finance/CreditCards/Invoice', [
            'invoice' => [
                ...$invoice->toArray(),
                'total' => $invoice->total(),
            ],
            'prevInvoiceId' => $prevInvoice?->id,
            'nextInvoiceId' => $nextInvoice?->id,
            'transactions' => $invoice->transactions()
                ->with(['account.institution', 'account.person', 'category'])
                ->orderBy('date')
                ->orderBy('installment_number')
                ->orderBy('id')
                ->get(),
            'accounts' => Account::with(['institution', 'person'])
                ->whereNotIn('type', [AccountType::CreditCard, AccountType::Investment])
                ->where('institution_id', $creditCard->institution_id)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get()
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'type' => $account->type->value,
                    'transient' => $account->transient,
                    'person' => $account->person,
                    'institution' => $account->institution,
                ]),
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function pay(Request $request, CreditCardInvoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($request, $invoice);

        $data = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $invoice->pay(isset($data['date']) ? Carbon::parse($data['date']) : null);

        return Redirect::back();
    }

    public function storePurchase(Request $request, CreditCard $creditCard): RedirectResponse
    {
        abort_if($creditCard->user_id !== $request->user()->id, HttpResponse::HTTP_FORBIDDEN);

        $data = $request->validate([
            'reference_invoice_id' => ['required', Rule::exists('credit_card_invoices', 'id')],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)],
            'is_unknown' => ['nullable', 'boolean'],
            'description' => [Rule::requiredIf(! $request->boolean('is_unknown')), 'nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'mode' => ['required', Rule::in(['single', 'installments', 'recurring'])],
            'installment_number' => ['required_if:mode,installments', 'integer', 'min:1'],
            'installment_total' => ['required_if:mode,installments', 'integer', 'min:1', 'gte:installment_number'],
            'split' => ['nullable', 'array', 'min:2'],
            'split.*.account_id' => ['required_with:split', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'split.*.amount' => ['required_with:split', 'numeric', 'min:0.01'],
        ]);

        $anchorInvoice = CreditCardInvoice::findOrFail($data['reference_invoice_id']);
        abort_if($anchorInvoice->credit_card_id !== $creditCard->id, HttpResponse::HTTP_FORBIDDEN);

        $isUnknown = (bool) ($data['is_unknown'] ?? false);

        $attributes = [
            'user_id' => $request->user()->id,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'] ?? null,
            'type' => TransactionType::Expense,
            'description' => $data['description'] ?: 'Desconhecido',
            'is_unknown' => $isUnknown,
            'amount' => $data['amount'],
            'date' => $data['date'],
        ];

        if (! empty($data['split'])) {
            $splitTotal = array_reduce($data['split'], fn ($carry, $share) => Money::add($carry, (string) $share['amount']), '0.00');

            abort_if(Money::compare($splitTotal, (string) $data['amount']) !== 0, HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'A soma da divisão precisa ser igual ao valor total.');

            $shares = array_map(fn ($share) => [
                'account_id' => $share['account_id'],
                'amount' => (string) $share['amount'],
            ], $data['split']);

            if ($data['mode'] === 'recurring') {
                Transaction::createSplitRecurringForInvoice($shares, $attributes, $anchorInvoice);
            } else {
                [$number, $total] = $data['mode'] === 'installments'
                    ? [$data['installment_number'], $data['installment_total']]
                    : [1, 1];

                Transaction::createSplitInstallmentsForInvoice($shares, $attributes, $anchorInvoice, $number, $total);
            }

            return Redirect::route('finance.invoices.show', $anchorInvoice->id);
        }

        if ($data['mode'] === 'recurring') {
            Transaction::createRecurringForInvoice($attributes, $anchorInvoice);
        } else {
            [$number, $total] = $data['mode'] === 'installments'
                ? [$data['installment_number'], $data['installment_total']]
                : [1, 1];

            Transaction::createInstallmentsForInvoice($attributes, $anchorInvoice, $number, $total);
        }

        return Redirect::route('finance.invoices.show', $anchorInvoice->id);
    }

    /**
     * Split an already-saved purchase into shares across several people's
     * accounts. The submitted split's percentages (relative to $transaction's
     * own amount) are reapplied to every installment in scope, so each one
     * is replaced by its own group of shares, sized proportionally to that
     * installment's amount and linked by its own split_group_id. Each share
     * keeps its installment_number/installment_total/is_recurring so it
     * still shows and edits like a normal installment — only
     * installment_group_id is dropped, since the shares no longer belong to
     * a single chain (scope for further edits is resolved via
     * split_group_id instead, see scopedInstallments()).
     */
    public function splitInstallment(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_if($transaction->user_id !== $request->user()->id, HttpResponse::HTTP_FORBIDDEN);
        abort_if($transaction->split_group_id, HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'Este lançamento não pode ser dividido.');

        $data = $request->validate([
            'split' => ['required', 'array', 'min:2'],
            'split.*.account_id' => ['required', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'split.*.amount' => ['required', 'numeric', 'min:0.01'],
            'scope' => ['nullable', Rule::in(['this', 'future', 'all'])],
        ]);

        $shareAmounts = array_map(fn ($share) => (string) $share['amount'], $data['split']);
        $splitTotal = array_reduce($shareAmounts, fn ($carry, $amount) => Money::add($carry, $amount), '0.00');

        abort_if(Money::compare($splitTotal, (string) $transaction->amount) !== 0, HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'A soma da divisão precisa ser igual ao valor total.');

        $scope = $transaction->installment_group_id ? ($data['scope'] ?? 'this') : 'this';
        $targets = $this->scopedInstallments($transaction, $scope);
        $invoiceId = $transaction->credit_card_invoice_id;

        // Each person's share needs its own chain id, reused across every
        // month/target so "future"/"all" edits and deletes can later walk
        // that one person's shares across months (see scopedInstallments()).
        // Without this, every share created here would be a same-month-only
        // split with no cross-month link, so "future"/"all" would silently
        // collapse into "this" for a purchase that was split after the fact.
        $groupIds = $transaction->installment_group_id
            ? array_map(fn () => (string) Str::ulid(), $data['split'])
            : array_fill(0, count($data['split']), null);

        foreach ($targets as $target) {
            $targetShares = Money::scaleShares($shareAmounts, (string) $transaction->amount, (string) $target->amount);
            $splitGroupId = (string) Str::ulid();

            foreach ($data['split'] as $index => $share) {
                Transaction::create([
                    'user_id' => $target->user_id,
                    'account_id' => $share['account_id'],
                    'category_id' => $target->category_id,
                    'credit_card_invoice_id' => $target->credit_card_invoice_id,
                    'type' => $target->type,
                    'description' => $target->description,
                    'is_unknown' => $target->is_unknown,
                    'amount' => $targetShares[$index],
                    'date' => $target->date,
                    'installment_group_id' => $groupIds[$index],
                    'installment_number' => $target->installment_number,
                    'installment_total' => $target->installment_total,
                    'is_recurring' => $target->is_recurring,
                    'split_group_id' => $splitGroupId,
                ]);
            }

            $target->delete();
        }

        return Redirect::route('finance.invoices.show', $invoiceId);
    }

    public function updateInstallment(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_if($transaction->user_id !== $request->user()->id, HttpResponse::HTTP_FORBIDDEN);

        $data = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'is_unknown' => ['nullable', 'boolean'],
            'description' => [Rule::requiredIf(! $request->boolean('is_unknown')), 'nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)],
            'date' => ['required', 'date'],
            'mode' => ['required', Rule::in(['single', 'installments', 'recurring'])],
            'installment_number' => ['required_if:mode,installments', 'integer', 'min:1'],
            'installment_total' => ['required_if:mode,installments', 'integer', 'min:1', 'gte:installment_number'],
            'scope' => ['required', Rule::in(['this', 'future', 'all'])],
            'split' => ['nullable', 'array', 'min:2'],
            'split.*.account_id' => ['required_with:split', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'split.*.amount' => ['required_with:split', 'numeric', 'min:0.01'],
        ]);

        $isUnknown = (bool) ($data['is_unknown'] ?? false);

        if (! empty($data['split'])) {
            $splitTotal = array_reduce($data['split'], fn ($carry, $share) => Money::add($carry, (string) $share['amount']), '0.00');

            abort_if(Money::compare($splitTotal, (string) $data['amount']) !== 0, HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'A soma da divisão precisa ser igual ao valor total.');
        }

        $currentMode = $transaction->installment_total
            ? 'installments'
            : ($transaction->is_recurring ? 'recurring' : 'single');

        $typeChanged = $data['mode'] !== $currentMode
            || ($data['mode'] === 'installments' && (
                (int) $data['installment_number'] !== $transaction->installment_number
                || (int) $data['installment_total'] !== $transaction->installment_total
            ));

        // A divided purchase — or one being divided/redivided right now via
        // $data['split'] — is treated as one thing, exactly like creating it:
        // whatever's in scope gets torn down and rebuilt with the submitted
        // shares (or, if the split itself isn't changing, each sibling's own
        // existing account/amount, with the form's new account/amount only
        // for the transaction being edited).
        if ($transaction->split_group_id || ! empty($data['split'])) {
            $invoiceId = $transaction->credit_card_invoice_id;
            $transaction->loadMissing('creditCardInvoice');
            $anchorInvoice = $transaction->creditCardInvoice;

            if (! empty($data['split'])) {
                $shares = array_map(fn ($share) => [
                    'account_id' => $share['account_id'],
                    'amount' => (string) $share['amount'],
                ], $data['split']);
            } else {
                $shares = Transaction::where('split_group_id', $transaction->split_group_id)->get()
                    ->map(fn ($sibling) => [
                        'account_id' => $sibling->is($transaction) ? $data['account_id'] : $sibling->account_id,
                        'amount' => (string) ($sibling->is($transaction) ? $data['amount'] : $sibling->amount),
                    ])->all();
            }

            if ($transaction->split_group_id && $transaction->installment_group_id && $data['scope'] !== 'this') {
                $chain = Transaction::where('installment_group_id', $transaction->installment_group_id)
                    ->when($data['scope'] === 'future', fn ($query) => $query->where('installment_number', '>=', $transaction->installment_number))
                    ->get();

                $splitGroupIds = $chain->pluck('split_group_id')->filter()->unique()->values();

                Transaction::where(
                    fn ($query) => $query->whereIn('split_group_id', $splitGroupIds)->orWhereIn('id', $chain->pluck('id'))
                )->delete();
            } elseif ($transaction->split_group_id) {
                Transaction::where('split_group_id', $transaction->split_group_id)->delete();
            } else {
                $transaction->delete();
            }

            $baseAttributes = [
                'user_id' => $request->user()->id,
                'category_id' => $data['category_id'] ?? null,
                'type' => TransactionType::Expense,
                'description' => $data['description'] ?: 'Desconhecido',
                'is_unknown' => $isUnknown,
                'date' => $data['date'],
            ];

            if ($data['mode'] === 'recurring') {
                Transaction::createSplitRecurringForInvoice($shares, $baseAttributes, $anchorInvoice);
            } else {
                [$number, $total] = $data['mode'] === 'installments'
                    ? [$data['installment_number'], $data['installment_total']]
                    : [1, 1];

                Transaction::createSplitInstallmentsForInvoice($shares, $baseAttributes, $anchorInvoice, $number, $total);
            }

            return Redirect::route('finance.invoices.show', $invoiceId);
        }

        if ($typeChanged) {
            $invoiceId = $transaction->credit_card_invoice_id;

            Transaction::changeType($transaction, $data['mode'], [
                'user_id' => $request->user()->id,
                'account_id' => $data['account_id'],
                'category_id' => $data['category_id'] ?? null,
                'type' => TransactionType::Expense,
                'description' => $data['description'] ?: 'Desconhecido',
                'is_unknown' => $isUnknown,
                'amount' => $data['amount'],
                'date' => $data['date'],
            ], $data['installment_number'] ?? null, $data['installment_total'] ?? null);

            return Redirect::route('finance.invoices.show', $invoiceId);
        }

        $targets = $this->scopedInstallments($transaction, $data['scope']);

        $targets->toQuery()->update([
            'account_id' => $data['account_id'],
            'description' => $data['description'] ?: 'Desconhecido',
            'is_unknown' => $isUnknown,
            'amount' => $data['amount'],
            'category_id' => $data['category_id'] ?? null,
        ]);

        if ($data['date'] !== $transaction->date->toDateString()) {
            $transaction->update(['date' => $data['date']]);
        }

        return Redirect::back();
    }

    public function toggleReversed(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['this', 'future', 'all'])],
        ]);

        $this->scopedInstallments($transaction, $data['scope'])->toQuery()->update([
            'reversed' => ! $transaction->reversed,
        ]);

        return Redirect::back();
    }

    public function destroyInstallment(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_if($transaction->user_id !== $request->user()->id, HttpResponse::HTTP_FORBIDDEN);

        $data = $request->validate([
            'scope' => ['required', Rule::in(['this', 'future', 'all'])],
        ]);

        $this->scopedInstallments($transaction, $data['scope'])->toQuery()->delete();

        return Redirect::back();
    }

    /**
     * Resolve which installments a "this / this and future / all" edit or
     * reversal scope applies to. A transaction without siblings (a single
     * purchase, not an installment plan) always targets just itself.
     *
     * Split shares have no chronological order between them (they're all
     * dated the same day), so for a split group "future" and "all" both mean
     * every sibling share.
     */
    private function scopedInstallments(Transaction $transaction, string $scope): Collection
    {
        // A share created together with an installment/recurring split (see
        // createSplitInstallmentsForInvoice) carries both ids — its own
        // chain across months AND this month's split group across people.
        // "this" only touches this month's split (every person); "future"/
        // "all" walk this person's chain across months and pull in every
        // month's split siblings along the way, so the whole multi-month
        // divided purchase moves together.
        if ($transaction->installment_group_id && $transaction->split_group_id) {
            if ($scope === 'this') {
                return Transaction::where('split_group_id', $transaction->split_group_id)->get();
            }

            $chain = Transaction::where('installment_group_id', $transaction->installment_group_id)
                ->when($scope === 'future', fn ($query) => $query->where('installment_number', '>=', $transaction->installment_number))
                ->get();

            $splitGroupIds = $chain->pluck('split_group_id')->filter()->unique()->values();

            return Transaction::where(
                fn ($query) => $query->whereIn('split_group_id', $splitGroupIds)->orWhereIn('id', $chain->pluck('id'))
            )->get();
        }

        // A divided purchase is one item — every person's share always moves
        // together, no matter which scope was picked. Splitting only matters
        // for reports (who spent what), never for edit/reverse/delete.
        if ($transaction->split_group_id) {
            return Transaction::where('split_group_id', $transaction->split_group_id)->get();
        }

        if ($transaction->installment_group_id) {
            return match ($scope) {
                'this' => new Collection([$transaction]),
                'future' => Transaction::where('installment_group_id', $transaction->installment_group_id)
                    ->where('installment_number', '>=', $transaction->installment_number)
                    ->get(),
                'all' => Transaction::where('installment_group_id', $transaction->installment_group_id)->get(),
            };
        }

        return new Collection([$transaction]);
    }

    private function authorizeInvoice(Request $request, CreditCardInvoice $invoice): void
    {
        abort_if(
            $invoice->creditCard->user_id !== $request->user()->id,
            HttpResponse::HTTP_FORBIDDEN
        );
    }
}
