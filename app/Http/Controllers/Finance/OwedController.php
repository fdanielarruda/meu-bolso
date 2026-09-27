<?php

namespace App\Http\Controllers\Finance;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Person;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class OwedController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $request->filled('month') ? $request->input('month') : now()->format('Y-m');
        $monthStart = Carbon::parse($month.'-01')->startOfMonth();
        $personId = $request->integer('person_id') ?: null;

        $accounts = Account::with(['institution', 'person'])
            ->where('type', '!=', AccountType::CreditCard)
            ->whereNull('archived_at')
            ->when($personId, fn ($q) => $q->where('person_id', $personId))
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'institution' => $account->institution,
                'person' => $account->person,
                'balance' => $account->balance(),
            ]);

        $cards = CreditCard::with('institution')
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get()
            ->map(function (CreditCard $card) use ($monthStart, $personId) {
                $invoice = $card->invoices()->where('reference_month', $monthStart->toDateString())->first();

                $total = '0.00';
                if ($invoice) {
                    $items = $invoice->transactions()->when(
                        $personId,
                        fn ($q) => $q->whereHas('account', fn ($accountQuery) => $accountQuery->where('person_id', $personId))
                    );
                    $charges = (string) (clone $items)->where('reversed', false)->sum('amount');
                    $credits = (string) (clone $items)->where('reversed', true)->sum('amount');
                    $total = Money::sub($charges, $credits);
                }

                return [
                    'id' => $card->id,
                    'name' => $card->name,
                    'institution' => $card->institution,
                    'invoice_id' => $invoice?->id,
                    'due_date' => $invoice?->due_date,
                    'total' => $total,
                ];
            });

        return Inertia::render('Finance/Owed/Index', [
            'accounts' => $accounts,
            'cards' => $cards,
            'people' => Person::orderBy('name')->get(),
            'filters' => [
                'person_id' => $personId,
                'month' => $monthStart->format('Y-m'),
            ],
        ]);
    }

    /**
     * General "notinha" (receipt) for a person/month: a flat list combining
     * every card's invoice items (description, installment x/y, amount)
     * with every account's balance as its own line, plus the grand total —
     * no grouping by bank/card, just the whole picture in one note.
     */
    public function receipt(Request $request): \Illuminate\Http\JsonResponse
    {
        $month = $request->filled('month') ? $request->input('month') : now()->format('Y-m');
        $monthStart = Carbon::parse($month.'-01')->startOfMonth();
        $personId = $request->integer('person_id') ?: null;

        $items = CreditCard::where('user_id', $request->user()->id)
            ->get()
            ->flatMap(function (CreditCard $card) use ($monthStart, $personId) {
                $invoice = $card->invoices()->where('reference_month', $monthStart->toDateString())->first();

                if (! $invoice) {
                    return collect();
                }

                return $invoice->transactions()
                    ->when($personId, fn ($q) => $q->whereHas('account', fn ($accountQuery) => $accountQuery->where('person_id', $personId)))
                    ->where('reversed', false)
                    ->orderBy('date')
                    ->orderBy('installment_number')
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($transaction) => [
                        'description' => $transaction->description,
                        'installment_number' => $transaction->installment_number,
                        'installment_total' => $transaction->installment_total,
                        'amount' => $transaction->amount,
                    ]);
            })
            ->values();

        $accountsBalance = Account::where('type', '!=', AccountType::CreditCard)
            ->whereNull('archived_at')
            ->when($personId, fn ($q) => $q->where('person_id', $personId))
            ->get()
            ->reduce(fn ($carry, Account $account) => Money::add($carry, $account->balance()), '0.00');

        // A negative account balance means the person owes money (they
        // spent from an account funded/owned by the user), so it flips to a
        // positive "owed to me" amount here — the inverse of how the
        // balance itself is displayed everywhere else in the app.
        $accountsOwed = Money::sub('0.00', $accountsBalance);

        $accountItems = $accountsOwed !== '0.00'
            ? collect([[
                'description' => 'Contas correntes',
                'installment_number' => null,
                'installment_total' => null,
                'amount' => $accountsOwed,
            ]])
            : collect();

        $items = $items->concat($accountItems)->values();

        $total = $items->reduce(fn ($carry, $item) => Money::add($carry, (string) $item['amount']), '0.00');

        return response()->json(['items' => $items, 'total' => $total]);
    }
}
