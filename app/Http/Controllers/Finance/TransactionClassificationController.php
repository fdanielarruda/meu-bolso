<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Transaction;
use App\Services\TransactionClassification\TransactionClassifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class TransactionClassificationController extends Controller
{
    /**
     * List transactions without a category and ask OpenAI to suggest one
     * for each, so the user can review and confirm before anything is saved.
     */
    public function index(Request $request): Response
    {
        $kind = $request->input('kind') === 'credit_card' ? 'credit_card' : 'transactions';
        $hideClassified = $request->boolean('hide_classified', true);
        $tab = $request->input('tab') === 'classified' ? 'classified' : 'classify';
        $categoryIds = collect($request->input('category_ids', []))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $baseFilters = fn ($query) => $query
            ->where('user_id', Auth::id())
            ->where(
                fn ($transferQuery) => $transferQuery
                    ->whereNull('transfer_id')
                    ->orWhereHas('transfer', fn ($tq) => $tq->where('is_movement_only', false))
            )
            ->when($kind === 'credit_card', fn ($q) => $q->whereNotNull('credit_card_invoice_id'))
            ->when($kind === 'transactions', fn ($q) => $q->whereNull('credit_card_invoice_id'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->date('to')))
            ->when(
                $request->filled('person_id'),
                fn ($q) => $q->whereHas('account', fn ($accountQuery) => $accountQuery->where('person_id', $request->integer('person_id')))
            )
            ->when(
                $request->filled('institution_id'),
                fn ($q) => $q->whereHas('account', fn ($accountQuery) => $accountQuery->where('institution_id', $request->integer('institution_id')))
            );

        $categories = Category::orderBy('name')->get();

        $transactions = collect();
        $error = null;
        $suggestions = collect();
        $classifiedTransactions = collect();
        $classifiedCategories = collect();

        if ($tab === 'classified') {
            $classifiedCategories = Category::query()
                ->withCount(['transactions' => fn ($q) => $baseFilters($q)->whereNotNull('category_id')])
                ->orderBy('name')
                ->get()
                ->filter(fn (Category $category) => $category->transactions_count > 0)
                ->values();

            $classifiedTransactions = $this->dedupeByRecurrence(
                $baseFilters(Transaction::query())
                    ->whereNotNull('category_id')
                    ->when(! empty($categoryIds), fn ($q) => $q->whereIn('category_id', $categoryIds))
                    ->with('account')
                    ->orderByDesc('date')
                    ->limit(2000)
                    ->get()
            )->take(500);
        } else {
            $transactions = $this->dedupeByRecurrence(
                $baseFilters(Transaction::query())
                    ->when($hideClassified, fn ($q) => $q->whereNull('category_id'))
                    ->with('account')
                    ->orderByDesc('date')
                    ->limit(300)
                    ->get()
            )->take(50);

            if ($request->boolean('classify')) {
                try {
                    $suggestions = collect((new TransactionClassifier)->classify($transactions->whereNull('category_id'), $categories))
                        ->keyBy('transaction_id');
                } catch (RuntimeException $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        return Inertia::render('Finance/Transactions/Classify', [
            'transactions' => $transactions->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'description' => $transaction->description,
                'type' => $transaction->type->value,
                'amount' => (string) $transaction->amount,
                'account' => $transaction->account->name,
                'category_id' => $transaction->category_id,
                'suggested_category_id' => $suggestions[$transaction->id]['category_id'] ?? null,
                'recurring' => $transaction->recurring_transaction_id !== null,
            ]),
            'classifiedTransactions' => $classifiedTransactions->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'description' => $transaction->description,
                'type' => $transaction->type->value,
                'amount' => (string) $transaction->amount,
                'account' => $transaction->account->name,
                'category_id' => $transaction->category_id,
                'recurring' => $transaction->recurring_transaction_id !== null,
            ]),
            'classifiedCategories' => $classifiedCategories->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'count' => $category->transactions_count,
            ]),
            'categories' => $categories,
            'people' => Person::orderBy('name')->get(),
            'institutions' => Institution::orderBy('name')->get(),
            'filters' => [
                'from' => $request->input('from', ''),
                'to' => $request->input('to', ''),
                'person_id' => $request->input('person_id', ''),
                'institution_id' => $request->input('institution_id', ''),
                'kind' => $kind,
                'hide_classified' => $hideClassified,
                'tab' => $tab,
                'category_ids' => $categoryIds,
            ],
            'error' => $error,
        ]);
    }

    /**
     * Keep only the most recent transaction for each recurring rule, since
     * they all share (or will share) the same category once classified.
     */
    private function dedupeByRecurrence(Collection $transactions): Collection
    {
        return $transactions
            ->unique(fn (Transaction $transaction) => $transaction->recurring_transaction_id ?? 'single-'.$transaction->id)
            ->values();
    }

    /**
     * Apply the categories confirmed by the user (which may differ from the
     * AI suggestion) to each transaction.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.transaction_id' => ['required', 'integer'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $transactions = Transaction::query()
            ->where('user_id', Auth::id())
            ->whereIn('id', collect($data['items'])->pluck('transaction_id'))
            ->get()
            ->keyBy('id');

        foreach ($data['items'] as $item) {
            $transaction = $transactions->get($item['transaction_id']);

            if (! $transaction) {
                continue;
            }

            $categoryId = $item['category_id'] ?? null;
            $transaction->update(['category_id' => $categoryId]);

            if ($transaction->recurring_transaction_id) {
                Transaction::query()
                    ->where('user_id', Auth::id())
                    ->where('recurring_transaction_id', $transaction->recurring_transaction_id)
                    ->update(['category_id' => $categoryId]);
            }
        }

        return Redirect::back()
            ->with('success', count($data['items']).' lançamento(s) categorizado(s).');
    }
}
