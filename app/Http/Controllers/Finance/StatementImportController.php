<?php

namespace App\Http\Controllers\Finance;

use App\Enums\AccountType;
use App\Enums\StatementImportStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ExtractStatementRequest;
use App\Http\Requests\Finance\StoreStatementImportRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Institution;
use App\Models\StatementImport;
use App\Models\Transaction;
use App\Services\StatementImport\StatementExtractor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class StatementImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Finance/StatementImports/Create', [
            'institutions' => Institution::orderBy('name')->get(['id', 'name']),
            'creditCards' => CreditCard::orderBy('name')->get(['id', 'name', 'institution_id']),
        ]);
    }

    /**
     * Store the uploaded PDF and run the first extraction. The import row is
     * always persisted, even when the AI read fails, so the user can retry
     * without uploading the file again.
     */
    public function upload(ExtractStatementRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $file = $request->file('file');

        $path = $file->storeAs(
            'statement-imports/'.$request->user()->id,
            Str::ulid().'.pdf',
        );

        $import = StatementImport::create([
            'user_id' => $request->user()->id,
            'kind' => $data['kind'],
            'institution_id' => $data['institution_id'],
            'credit_card_id' => $data['kind'] === 'credit' ? $data['credit_card_id'] : null,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => StatementImportStatus::Processing,
        ]);

        $this->runExtraction($import);

        return Redirect::route('finance.statement-imports.show', $import);
    }

    public function show(StatementImport $statementImport): Response
    {
        $statementImport->load(['institution', 'creditCard']);

        $isReviewable = $statementImport->status === StatementImportStatus::Extracted;
        $owners = $this->ownerAccounts($statementImport);

        return Inertia::render('Finance/StatementImports/Review', [
            'statementImport' => [
                'id' => $statementImport->id,
                'kind' => $statementImport->kind,
                'status' => $statementImport->status->value,
                'status_label' => $statementImport->status->label(),
                'original_filename' => $statementImport->original_filename,
                'error_message' => $statementImport->error_message,
                'imported_at' => $statementImport->imported_at,
                'reference_month' => $statementImport->reference_month?->format('Y-m'),
                'institution' => $statementImport->institution?->only(['id', 'name']),
                'credit_card' => $statementImport->creditCard?->only(['id', 'name', 'payment_account_id']),
            ],
            'items' => $isReviewable
                ? $this->flagDuplicates($statementImport, $statementImport->items ?? [])
                : ($statementImport->items ?? []),
            'owners' => $owners,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    /**
     * Re-run the AI extraction against the PDF already stored for this
     * import, discarding the previous reading.
     */
    public function reextract(StatementImport $statementImport): RedirectResponse
    {
        $this->abortIfAlreadyImported($statementImport);

        $this->runExtraction($statementImport);

        return Redirect::route('finance.statement-imports.show', $statementImport);
    }

    public function confirm(StoreStatementImportRequest $request, StatementImport $statementImport): RedirectResponse
    {
        $this->abortIfAlreadyImported($statementImport);

        $data = $request->validated();
        $userId = $request->user()->id;
        $isCredit = $statementImport->kind === 'credit';

        $invoice = null;

        if ($isCredit) {
            $card = $statementImport->creditCard;
            abort_unless($card, HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'Cartão não encontrado para esta importação.');

            $month = ! empty($data['reference_month'])
                ? Carbon::parse($data['reference_month'].'-01')
                : $card->resolveInvoiceFor(Carbon::parse(collect($data['items'])->max('date')))->reference_month;

            abort_if($card->isBeforeStartMonth($month), HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'Este mês é anterior ao início do cartão.');

            $invoice = $card->invoiceForMonth($month);
        }

        DB::transaction(function () use ($data, $userId, $statementImport, $invoice, $isCredit) {
            foreach ($data['items'] as $item) {
                $attributes = [
                    'user_id' => $userId,
                    'account_id' => $item['account_id'],
                    'category_id' => $item['category_id'] ?? null,
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                    'date' => $item['date'],
                ];

                if ($isCredit) {
                    // Cards only hold expenses; a statement credit is a reversed charge.
                    Transaction::createInstallmentsForInvoice(
                        [
                            ...$attributes,
                            'type' => TransactionType::Expense,
                            'reversed' => $item['type'] === 'income',
                        ],
                        $invoice,
                        $item['installment_number'] ?? 1,
                        $item['installment_total'] ?? 1,
                    );
                } else {
                    Transaction::createPurchase([...$attributes, 'type' => $item['type']]);
                }
            }

            $statementImport->update([
                'status' => StatementImportStatus::Imported,
                'reference_month' => $invoice?->reference_month,
                'imported_at' => now(),
            ]);
        });

        $redirect = $invoice
            ? Redirect::route('finance.invoices.show', $invoice->id)
            : Redirect::route('finance.accounts.index');

        return $redirect->with('success', count($data['items']).' lançamento(s) importado(s).');
    }

    private function runExtraction(StatementImport $import): void
    {
        try {
            $contents = Storage::get($import->file_path);

            $result = (new StatementExtractor)->extract(
                pdfContents: $contents,
                filename: $import->original_filename,
                categories: Category::orderBy('name')->get(),
                kind: $import->kind,
            );

            $import->update([
                'status' => StatementImportStatus::Extracted,
                'items' => $result['items'],
                'reference_month' => $result['reference_month'] ? $result['reference_month'].'-01' : null,
                'error_message' => null,
                'extracted_at' => now(),
            ]);
        } catch (RuntimeException $exception) {
            $import->update([
                'status' => StatementImportStatus::Failed,
                'error_message' => $exception->getMessage(),
            ]);
        }
    }

    private function abortIfAlreadyImported(StatementImport $statementImport): void
    {
        abort_if(
            $statementImport->status === StatementImportStatus::Imported,
            HttpResponse::HTTP_CONFLICT,
            'Esta fatura já foi importada.'
        );
    }

    /**
     * Accounts that can own an imported item: the bank's own accounts,
     * including transient (passerby) ones, so the user can attribute each
     * line to a person.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function ownerAccounts(StatementImport $import)
    {
        return Account::with('person')
            ->whereNotIn('type', [AccountType::CreditCard, AccountType::Investment])
            ->when($import->institution_id, fn ($query, $id) => $query->where('institution_id', $id))
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'transient' => $account->transient,
                'person' => $account->person?->only(['id', 'name']),
            ]);
    }

    /**
     * Mark items that look like they've already been imported (same date,
     * amount and description in the target invoice / bank accounts), so the
     * review screen can warn before double-creating them.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function flagDuplicates(StatementImport $import, array $items): array
    {
        $query = Transaction::query();

        if ($import->kind === 'credit') {
            $invoiceIds = $import->creditCard?->invoices()
                ->when($import->reference_month, fn ($q, $month) => $q->whereDate('reference_month', $month))
                ->pluck('id') ?? collect();

            $query->whereIn('credit_card_invoice_id', $invoiceIds);
        } else {
            $query->whereNull('credit_card_invoice_id')
                ->whereIn('account_id', Account::where('institution_id', $import->institution_id)->select('id'));
        }

        $existing = $query
            ->get(['date', 'amount', 'description'])
            ->map(fn ($t) => mb_strtolower($t->date->toDateString().'|'.$t->amount.'|'.trim($t->description)))
            ->flip();

        return array_map(function (array $item) use ($existing) {
            $key = mb_strtolower($item['statement_date'].'|'.$item['amount'].'|'.$item['description']);
            $item['possible_duplicate'] = $existing->has($key);

            return $item;
        }, $items);
    }
}
