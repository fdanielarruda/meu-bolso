<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreTransactionRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Institution;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function quick(): Response
    {
        return Inertia::render('Finance/Transactions/Quick', [
            'accounts' => Account::with(['person', 'institution'])
                ->whereNull('archived_at')
                ->where('type', '!=', \App\Enums\AccountType::CreditCard)
                ->orderBy('name')
                ->get()
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'type' => $account->type->value,
                    'transient' => $account->transient,
                    'person' => $account->person,
                    'institution' => $account->institution,
                    'balance' => $account->type !== \App\Enums\AccountType::CreditCard ? $account->balance() : null,
                ]),
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function recentByBank(Request $request): \Illuminate\Http\JsonResponse
    {
        $institutionId = $request->integer('institution_id') ?: null;

        $transactions = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('credit_card_invoice_id')
            ->whereHas('account', function ($q) use ($institutionId) {
                $q->where('type', '!=', \App\Enums\AccountType::CreditCard);

                $institutionId
                    ? $q->where('institution_id', $institutionId)
                    : $q->whereNull('institution_id');
            })
            ->with(['account', 'category', 'transfer.fromAccount', 'transfer.toAccount'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->reject(fn (Transaction $transaction) => $transaction->transfer
                && $transaction->transfer->fromAccount?->institution_id === $transaction->transfer->toAccount?->institution_id)
            ->take(10)
            ->values();

        return response()->json(['transactions' => $transactions]);
    }

    public function index(Request $request): Response
    {
        $transactions = Transaction::query()
            ->with([
                'account.institution', 'account.person', 'category',
                'transfer.fromAccount.institution', 'transfer.toAccount.institution',
            ])
            ->when($request->filled('account_id'), fn ($q) => $q->where('account_id', $request->integer('account_id')))
            ->when($request->filled('institution_id'), fn ($q) => $q->whereHas(
                'account',
                fn ($accountQuery) => $accountQuery->where('institution_id', $request->integer('institution_id'))
            ))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->date('to')))
            ->when($request->filled('min_amount'), fn ($q) => $q->where('amount', '>=', $request->input('min_amount')))
            ->when($request->filled('max_amount'), fn ($q) => $q->where('amount', '<=', $request->input('max_amount')))
            ->when($request->input('kind') === 'credit_card', fn ($q) => $q->whereNotNull('credit_card_invoice_id'))
            ->when($request->input('kind') === 'transactions', fn ($q) => $q->whereNull('credit_card_invoice_id'))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Finance/Transactions/Index', [
            'transactions' => $transactions,
            'accounts' => Account::with(['institution', 'person'])->whereNull('archived_at')->orderBy('name')->get(),
            'institutions' => Institution::orderBy('name')->get(),
            'categories' => Category::orderBy('name')->get(),
            'filters' => $request->only(['account_id', 'institution_id', 'category_id', 'from', 'to', 'kind', 'min_amount', 'max_amount']),
        ]);
    }

    public function store(StoreTransactionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $isUnknown = (bool) ($data['is_unknown'] ?? false);

        Transaction::createPurchase([
            'user_id' => $request->user()->id,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'] ?? null,
            'type' => $data['type'],
            'description' => $data['description'] ?: 'Desconhecido',
            'is_unknown' => $isUnknown,
            'exclude_from_reports' => (bool) ($data['exclude_from_reports'] ?? false),
            'amount' => $data['amount'],
            'date' => $data['date'],
        ]);

        return Redirect::back();
    }

    public function update(StoreTransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        if ($transaction->transfer_id) {
            return Redirect::back()->withErrors([
                'description' => 'Transações de transferência não podem ser editadas. Remova a transferência e crie novamente.',
            ]);
        }

        $data = $request->validated();
        $isUnknown = (bool) ($data['is_unknown'] ?? false);

        $transaction->update([
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'] ?? null,
            'type' => $data['type'],
            'description' => $data['description'] ?: 'Desconhecido',
            'is_unknown' => $isUnknown,
            'exclude_from_reports' => (bool) ($data['exclude_from_reports'] ?? false),
            'amount' => $data['amount'],
            'date' => $data['date'],
        ]);

        return Redirect::back();
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        if ($transaction->transfer_id) {
            $transaction->transfer()->delete();
        } else {
            $transaction->delete();
        }

        return Redirect::back();
    }
}
