<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Institution;
use App\Models\Person;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoSeeder extends Seeder
{
    /**
     * Seed a fully populated demo user for presentations: accounts, credit
     * card with invoices, categories, people and several months of
     * realistic transaction history.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Usuário Demo', 'password' => bcrypt('password')]
        );

        if ($user->accounts()->exists()) {
            $this->command?->warn('Usuário demo já possui dados, pulando.');

            return;
        }

        $this->call([InstitutionSeeder::class]);

        $person = $user->people()->create(['name' => 'Eu', 'color' => '#2563eb']);
        $spouse = $user->people()->create(['name' => 'Cônjuge', 'color' => '#db2777']);

        $nubank = Institution::withoutGlobalScopes()->where('name', 'Nubank')->first();
        $bradesco = Institution::withoutGlobalScopes()->where('name', 'Bradesco')->first();
        $inter = Institution::withoutGlobalScopes()->where('name', 'Inter')->first();

        $checking = $user->accounts()->create([
            'person_id' => $person->id,
            'institution_id' => $bradesco->id,
            'name' => 'Conta Corrente',
            'type' => AccountType::Checking,
            'initial_balance' => '3500.00',
        ]);

        $savings = $user->accounts()->create([
            'person_id' => $person->id,
            'institution_id' => $inter->id,
            'name' => 'Poupança',
            'type' => AccountType::Savings,
            'initial_balance' => '12000.00',
        ]);

        $wallet = $user->accounts()->create([
            'person_id' => $person->id,
            'institution_id' => null,
            'name' => 'Carteira',
            'type' => AccountType::Wallet,
            'initial_balance' => '400.00',
        ]);

        $investment = $user->accounts()->create([
            'person_id' => $person->id,
            'institution_id' => $inter->id,
            'name' => 'Reserva de Emergência',
            'type' => AccountType::Investment,
            'initial_balance' => '25000.00',
        ]);

        $creditCard = $user->creditCards()->create([
            'institution_id' => $nubank->id,
            'name' => 'Nubank',
            'payment_account_id' => $checking->id,
            'credit_limit' => '8000.00',
            'closing_day' => 20,
            'due_day' => 27,
            'starts_at' => now()->subMonths(6)->startOfMonth(),
        ]);

        CategorySeeder::tree(TransactionType::Expense);
        $this->createCategoryTree($user, CategorySeeder::tree(TransactionType::Expense), TransactionType::Expense);
        $this->createCategoryTree($user, CategorySeeder::tree(TransactionType::Income), TransactionType::Income);

        $categories = $user->categories()->get()->keyBy('name');

        $salary = $categories['Salário'];
        $freelance = $categories['Freelance'];
        $market = $categories['Supermercado'];
        $restaurants = $categories['Restaurantes'];
        $fuel = $categories['Combustível'];
        $bills = $categories['Contas de Casa'];
        $internet = $categories['Internet e Telefone'];
        $pharmacy = $categories['Farmácia'];
        $gym = $categories['Academia'];
        $leisure = $categories['Lazer'];
        $streaming = $categories['Streaming'];
        $clothing = $categories['Vestuário'];

        // Salário mensal recorrente, retroativo a 6 meses.
        $recurringSalary = $user->recurringTransactions()->create([
            'account_id' => $checking->id,
            'person_id' => $person->id,
            'category_id' => $salary->id,
            'type' => TransactionType::Income,
            'description' => 'Salário',
            'amount' => '6500.00',
            'frequency' => 'monthly',
            'interval' => 1,
            'day_of_month' => 5,
            'start_date' => now()->subMonths(6)->startOfMonth()->addDays(4),
            'next_run_date' => now()->startOfMonth()->addDays(4),
            'is_active' => true,
        ]);

        for ($i = 6; $i >= 0; $i--) {
            $month = now()->subMonths($i)->startOfMonth();

            if ($month->gt(now())) {
                continue;
            }

            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $checking->id,
                'category_id' => $salary->id,
                'recurring_transaction_id' => $recurringSalary->id,
                'type' => TransactionType::Income,
                'description' => 'Salário',
                'amount' => '6500.00',
                'date' => $month->copy()->addDays(4),
            ]);

            // Bills (checking account).
            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $checking->id,
                'category_id' => $bills->id,
                'type' => TransactionType::Expense,
                'description' => 'Condomínio',
                'amount' => '650.00',
                'date' => $month->copy()->addDays(9),
            ]);

            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $checking->id,
                'category_id' => $internet->id,
                'type' => TransactionType::Expense,
                'description' => 'Internet + Celular',
                'amount' => '189.90',
                'date' => $month->copy()->addDays(10),
            ]);

            // Freelance income, a few months.
            if ($i % 2 === 0) {
                Transaction::create([
                    'user_id' => $user->id,
                    'account_id' => $checking->id,
                    'category_id' => $freelance->id,
                    'type' => TransactionType::Income,
                    'description' => 'Projeto freelance',
                    'amount' => '1200.00',
                    'date' => $month->copy()->addDays(15),
                ]);
            }

            // Supermarket (checking).
            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $checking->id,
                'category_id' => $market->id,
                'type' => TransactionType::Expense,
                'description' => 'Supermercado',
                'amount' => '540.30',
                'date' => $month->copy()->addDays(6),
            ]);

            // Gym (checking).
            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $checking->id,
                'category_id' => $gym->id,
                'type' => TransactionType::Expense,
                'description' => 'Academia',
                'amount' => '129.90',
                'date' => $month->copy()->addDays(8),
            ]);

            // Wallet cash spending.
            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $wallet->id,
                'category_id' => $restaurants->id,
                'type' => TransactionType::Expense,
                'description' => 'Almoço',
                'amount' => '45.00',
                'date' => $month->copy()->addDays(12),
            ]);

            // Credit card purchases for the invoice closing in this month.
            $invoice = $creditCard->invoiceForMonth($month);

            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $checking->id,
                'category_id' => $restaurants->id,
                'credit_card_invoice_id' => $invoice->id,
                'type' => TransactionType::Expense,
                'description' => 'Restaurante',
                'amount' => '180.50',
                'date' => $month->copy()->addDays(14),
            ]);

            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $checking->id,
                'category_id' => $fuel->id,
                'credit_card_invoice_id' => $invoice->id,
                'type' => TransactionType::Expense,
                'description' => 'Combustível',
                'amount' => '280.00',
                'date' => $month->copy()->addDays(5),
            ]);

            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $checking->id,
                'category_id' => $streaming->id,
                'credit_card_invoice_id' => $invoice->id,
                'type' => TransactionType::Expense,
                'description' => 'Streaming (Netflix + Spotify)',
                'amount' => '59.80',
                'date' => $month->copy()->addDays(2),
            ]);

            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $checking->id,
                'category_id' => $pharmacy->id,
                'credit_card_invoice_id' => $invoice->id,
                'type' => TransactionType::Expense,
                'description' => 'Farmácia',
                'amount' => '95.40',
                'date' => $month->copy()->addDays(18),
            ]);

            // Pay off older invoices so only the last couple remain open.
            if ($i >= 2) {
                $invoice->pay($month->copy()->addMonthNoOverflow()->addDays(6));
            }
        }

        // An installment purchase: a new TV in 10x, still running.
        $tvInvoice = $creditCard->invoiceForMonth(now());
        Transaction::createInstallmentsForInvoice([
            'user_id' => $user->id,
            'account_id' => $checking->id,
            'category_id' => $clothing->id,
            'type' => TransactionType::Expense,
            'description' => 'Smart TV 55"',
            'amount' => '320.00',
            'date' => now(),
        ], $tvInvoice, 1, 10);

        // Transfer: moving savings into the emergency fund.
        Transfer::createBetween([
            'user_id' => $user->id,
            'from_account_id' => $savings->id,
            'to_account_id' => $investment->id,
            'amount' => '2000.00',
            'date' => now()->subMonth()->addDays(20),
            'description' => 'Aporte reserva de emergência',
        ]);

        // A couple of shared expenses with the spouse person.
        $user->accounts()->create([
            'person_id' => $spouse->id,
            'institution_id' => $bradesco->id,
            'name' => 'Conta do Cônjuge',
            'type' => AccountType::Checking,
            'initial_balance' => '1800.00',
        ])->transactions()->create([
            'user_id' => $user->id,
            'category_id' => $leisure->id,
            'type' => TransactionType::Expense,
            'description' => 'Viagem de fim de semana',
            'amount' => '890.00',
            'date' => now()->subDays(10),
        ]);

        $this->command?->info('Usuário demo criado: demo@example.com / password');
    }

    private function createCategoryTree(User $user, array $nodes, TransactionType $type, ?int $parentId = null): void
    {
        foreach ($nodes as $node) {
            $category = $user->categories()->firstOrCreate(
                ['name' => $node['name'], 'type' => $type, 'parent_id' => $parentId],
                ['color' => $node['color']]
            );

            if (! empty($node['children'])) {
                $this->createCategoryTree($user, $node['children'], $type, $category->id);
            }
        }
    }
}
