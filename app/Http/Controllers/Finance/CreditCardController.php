<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreCreditCardRequest;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\Institution;
use App\Models\Person;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class CreditCardController extends Controller
{
    public function index(Request $request): Response
    {
        $userCards = CreditCard::with(['institution', 'paymentAccount'])
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get();

        $month = $request->filled('month')
            ? \Illuminate\Support\Carbon::parse($request->string('month').'-01')
            : $this->defaultMonth($userCards);

        $month = $month->min(\App\Models\Transaction::recurringCapMonth());

        $personId = $request->integer('person_id') ?: null;

        $cards = $userCards
            ->map(function (CreditCard $card) use ($month, $personId) {
                $notStarted = $card->isBeforeStartMonth($month);
                $invoice = $notStarted ? null : $card->invoiceForMonth($month);

                return [
                    'id' => $card->id,
                    'name' => $card->name,
                    'institution' => $card->institution,
                    'credit_limit' => $card->credit_limit,
                    'closing_day' => $card->closing_day,
                    'due_day' => $card->due_day,
                    'payment_account_id' => $card->payment_account_id,
                    'starts_at' => $card->starts_at?->format('Y-m'),
                    'not_started' => $notStarted,
                    'open_invoice_total' => $invoice?->total() ?? '0.00',
                    'available_limit' => $card->availableLimit($month),
                    'current_invoice_id' => $invoice?->id,
                    'due_date' => $invoice?->due_date,
                    'invoice_status' => $invoice?->status,
                    'person_total' => $personId && $invoice ? $this->personShare($invoice, $personId) : null,
                ];
            });

        return Inertia::render('Finance/CreditCards/Index', [
            'cards' => $cards,
            'institutions' => Institution::orderBy('name')->get(),
            'people' => Person::whereNull('archived_at')->orderBy('name')->get(),
            'month' => $month->format('Y-m'),
            'personId' => $personId,
        ]);
    }

    /**
     * One person's part of an invoice: their purchases minus their reversals,
     * mirroring CreditCardInvoice::total().
     */
    private function personShare(CreditCardInvoice $invoice, int $personId): string
    {
        $query = fn () => $invoice->transactions()->whereHas('account', fn ($q) => $q->where('person_id', $personId));

        $charges = (string) $query()->where('reversed', false)->sum('amount');
        $credits = (string) $query()->where('reversed', true)->sum('amount');

        return Money::sub($charges, $credits);
    }

    /**
     * Defaults to the current month, unless every card's invoice for the
     * current month is already paid, in which case it advances to next
     * month so the view starts on the invoice the user still needs to track.
     *
     * @param  \Illuminate\Support\Collection<int, CreditCard>  $cards
     */
    private function defaultMonth($cards): \Illuminate\Support\Carbon
    {
        $currentMonth = \Illuminate\Support\Carbon::now()->startOfMonth();

        $startedCards = $cards->reject(fn (CreditCard $card) => $card->isBeforeStartMonth($currentMonth));

        if ($startedCards->isEmpty()) {
            return $currentMonth;
        }

        $allPaid = $startedCards->every(
            fn (CreditCard $card) => $card->invoiceForMonth($currentMonth)->status === \App\Enums\InvoiceStatus::Paid
        );

        return $allPaid ? $currentMonth->copy()->addMonthNoOverflow() : $currentMonth;
    }

    public function store(StoreCreditCardRequest $request): RedirectResponse
    {
        $request->user()->creditCards()->create($request->validated());

        return Redirect::route('finance.credit-cards.index');
    }

    public function update(StoreCreditCardRequest $request, CreditCard $creditCard): RedirectResponse
    {
        $creditCard->update($request->validated());

        return Redirect::route('finance.credit-cards.index');
    }
}
