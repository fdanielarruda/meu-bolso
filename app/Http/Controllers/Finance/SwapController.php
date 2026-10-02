<?php

namespace App\Http\Controllers\Finance;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreSwapRequest;
use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SwapController extends Controller
{
    /**
     * "Trocas": lets the user settle a debt in one bank against an
     * opposite debt in another bank/person without any real money moving,
     * by reusing the transfer mechanism (see Transfer::createBetween).
     */
    public function index(Request $request): Response
    {
        $accounts = Account::with(['institution', 'person'])
            ->where('user_id', $request->user()->id)
            ->whereNotIn('type', [AccountType::CreditCard, AccountType::Investment])
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'institution' => $account->institution,
                'person' => $account->person,
                'balance' => $account->balance(),
            ]);

        return Inertia::render('Finance/Swaps/Index', [
            'accounts' => $accounts,
        ]);
    }

    /**
     * A "troca" settles a debt between two people across two different
     * banks in one shot: person A pays person B in one bank, and person B
     * pays person A the same amount in the other bank. Each leg is just a
     * regular Transfer::createBetween, so it behaves like any other
     * transaction for balances, listings and reports.
     */
    public function store(StoreSwapRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $userId = $request->user()->id;

        $accountFor = function (int $personId, ?int $institutionId) use ($userId) {
            $account = Account::where('user_id', $userId)
                ->where('person_id', $personId)
                ->where('institution_id', $institutionId)
                ->whereNotIn('type', [AccountType::CreditCard, AccountType::Investment])
                ->whereNull('archived_at')
                ->first();

            if (! $account) {
                throw ValidationException::withMessages([
                    'amount' => 'Não foi encontrada uma conta para uma das combinações de pessoa e banco selecionadas.',
                ]);
            }

            return $account;
        };

        $accountAInOne = $accountFor((int) $data['person_a_id'], $data['institution_one_id'] ?? null);
        $accountBInOne = $accountFor((int) $data['person_b_id'], $data['institution_one_id'] ?? null);
        $accountBInTwo = $accountFor((int) $data['person_b_id'], $data['institution_two_id'] ?? null);
        $accountAInTwo = $accountFor((int) $data['person_a_id'], $data['institution_two_id'] ?? null);

        $shared = [
            'user_id' => $userId,
            'amount' => $data['amount'],
            'date' => $data['date'],
            'description' => $data['description'] ?: 'Troca',
            'is_movement_only' => (bool) ($data['is_movement_only'] ?? true),
        ];

        Transfer::createBetween([
            ...$shared,
            'from_account_id' => $accountAInOne->id,
            'to_account_id' => $accountBInOne->id,
        ]);

        Transfer::createBetween([
            ...$shared,
            'from_account_id' => $accountBInTwo->id,
            'to_account_id' => $accountAInTwo->id,
        ]);

        return Redirect::back();
    }
}
