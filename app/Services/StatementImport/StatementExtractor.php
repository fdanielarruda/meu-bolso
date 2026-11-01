<?php

namespace App\Services\StatementImport;

use App\Enums\TransactionType;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

/**
 * Reads a credit-card statement or a bank (debit) account statement PDF with
 * OpenAI and returns a normalized list of line items ready for review.
 */
class StatementExtractor
{
    /**
     * @param  'credit'|'debit'  $kind
     * @return array{reference_month: ?string, items: array<int, array<string, mixed>>}
     */
    public function extract(string $pdfContents, string $filename, Collection $categories, string $kind = 'credit'): array
    {
        if (! config('openai.api_key')) {
            throw new RuntimeException('OPENAI_API_KEY não configurada.');
        }

        try {
            $response = OpenAI::responses()->create([
                'model' => config('services.openai.model'),
                'instructions' => $this->systemPrompt($kind),
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_file',
                            'filename' => $filename,
                            'file_data' => 'data:application/pdf;base64,'.base64_encode($pdfContents),
                        ],
                        [
                            'type' => 'input_text',
                            'text' => $this->userPrompt($categories, $kind),
                        ],
                    ],
                ]],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'statement_items',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ],
                ],
            ]);
        } catch (ErrorException|RateLimitException|TransporterException $exception) {
            throw new RuntimeException('Não foi possível ler a fatura com a OpenAI API: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->status !== 'completed' || ! $response->outputText) {
            throw new RuntimeException('A OpenAI não conseguiu processar este PDF (a leitura ficou incompleta ou foi recusada).');
        }

        $payload = json_decode($response->outputText, associative: true);

        if (! is_array($payload) || ! isset($payload['items']) || ! is_array($payload['items'])) {
            throw new RuntimeException('A resposta da OpenAI não veio no formato esperado.');
        }

        $categoriesByName = $categories->keyBy(fn ($category) => mb_strtolower($category->name));

        try {
            $items = collect($payload['items'])
                ->map(fn (array $item) => $this->normalize($item, $categoriesByName, $kind))
                ->values()
                ->all();

            $referenceMonth = $kind === 'credit' && preg_match('/^\d{4}-\d{2}/', (string) ($payload['reference_month'] ?? ''))
                ? substr($payload['reference_month'], 0, 7)
                : null;

            return ['reference_month' => $referenceMonth, 'items' => $items];
        } catch (\Throwable $exception) {
            throw new RuntimeException('A resposta da OpenAI veio com dados inválidos: '.$exception->getMessage(), previous: $exception);
        }
    }

    private function normalize(array $item, Collection $categoriesByName, string $kind): array
    {
        $amount = number_format((float) ($item['amount'] ?? 0), 2, '.', '');
        $date = Carbon::parse($item['date']);
        $type = ($item['type'] ?? null) === TransactionType::Income->value
            ? TransactionType::Income->value
            : TransactionType::Expense->value;

        $installmentTotal = isset($item['installment_total']) ? max(1, min(48, (int) $item['installment_total'])) : null;
        $installmentNumber = isset($item['installment_number']) ? max(1, (int) $item['installment_number']) : null;

        $hasInstallments = $kind === 'credit' && $installmentTotal && $installmentTotal > 1 && $installmentNumber;

        $purchaseDate = $hasInstallments
            ? $date->copy()->subMonthsNoOverflow($installmentNumber - 1)
            : $date->copy();

        $totalAmount = $hasInstallments ? Money::mul($amount, $installmentTotal) : $amount;

        $categoryName = $item['category_name'] ?? null;
        $category = $categoryName ? $categoriesByName->get(mb_strtolower($categoryName)) : null;

        return [
            'description' => trim((string) ($item['description'] ?? 'Sem descrição')),
            'statement_date' => $date->toDateString(),
            'purchase_date' => $purchaseDate->toDateString(),
            'amount' => $amount,
            'total_amount' => $totalAmount,
            'type' => $type,
            'installment_number' => $hasInstallments ? $installmentNumber : null,
            'installment_total' => $hasInstallments ? $installmentTotal : null,
            'category_id' => $category?->id,
        ];
    }

    private function systemPrompt(string $kind): string
    {
        $document = $kind === 'debit'
            ? 'extratos de conta corrente/débito'
            : 'faturas de cartão de crédito';

        return <<<PROMPT
            Você é um assistente que extrai lançamentos de {$document}
            brasileiros a partir de arquivos PDF, para importação em um sistema de
            controle financeiro pessoal. Seja preciso e completo: não invente dados que
            não estejam no documento e não deixe de fora nenhum lançamento individual.
            PROMPT;
    }

    private function userPrompt(Collection $categories, string $kind): string
    {
        $categoryList = $categories->isEmpty()
            ? 'nenhuma categoria cadastrada'
            : $categories->map(fn ($c) => "\"{$c->name}\" ({$c->type->label()})")->implode(', ');

        $categoryRule = <<<PROMPT
            - "category_name" deve ser exatamente um dos nomes desta lista, apenas quando
              houver correspondência clara pelo nome do estabelecimento; caso contrário,
              null. Categorias disponíveis: {$categoryList}.
            PROMPT;

        if ($kind === 'debit') {
            return <<<PROMPT
                Leia o extrato de conta bancária (débito) em anexo e liste TODAS as
                movimentações individuais (Pix, TED/DOC, débitos, compras no débito, tarifas,
                depósitos, rendimentos, etc.).

                Regras:
                - NÃO inclua linhas de resumo, como saldo anterior, saldo do dia, saldo final
                  ou totais do período.
                - "amount" é sempre um número positivo.
                - "type" é "income" para entradas (dinheiro recebido na conta) e "expense"
                  para saídas.
                - "date" é a data da movimentação no formato AAAA-MM-DD (use o período do
                  extrato para inferir o ano quando a linha só tiver dia/mês).
                - "installment_number" e "installment_total" devem ser sempre null.
                - "reference_month" deve ser sempre null.
                {$categoryRule}
                PROMPT;
        }

        return <<<PROMPT
            Leia a fatura de cartão de crédito em anexo e liste TODOS os lançamentos
            individuais (compras, assinaturas, estornos/créditos) que aparecem na fatura.

            Regras:
            - NÃO inclua linhas de resumo, como total da fatura, saldo anterior, pagamento
              mínimo, pagamento recebido/efetuado ou limite disponível.
            - Para cada lançamento, "amount" é sempre um número positivo: o valor cobrado
              NESTA fatura para aquele item (a parcela do mês, se for parcelado).
            - "type" é "income" apenas para estornos/créditos recebidos; caso contrário
              "expense".
            - Se a descrição do lançamento indicar parcelamento (ex.: "3/12", "03/12",
              "Parc 3/12"), preencha "installment_number" e "installment_total" com esses
              números e remova esse sufixo de "description". Caso contrário, ambos devem
              ser null.
            - "date" é a data do lançamento como aparece na fatura, no formato AAAA-MM-DD
              (use o mês/ano de referência da fatura para inferir o ano quando a linha só
              tiver dia/mês).
            - "reference_month" é o mês (AAAA-MM) do VENCIMENTO desta fatura; se não
              for possível determinar, null.
            {$categoryRule}
            PROMPT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reference_month' => ['type' => ['string', 'null'], 'description' => 'Formato AAAA-MM'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => ['type' => 'string'],
                            'date' => ['type' => 'string', 'description' => 'Formato AAAA-MM-DD'],
                            'amount' => ['type' => 'number'],
                            'type' => ['type' => 'string', 'enum' => ['expense', 'income']],
                            'installment_number' => ['type' => ['integer', 'null']],
                            'installment_total' => ['type' => ['integer', 'null']],
                            'category_name' => ['type' => ['string', 'null']],
                        ],
                        'required' => [
                            'description', 'date', 'amount', 'type',
                            'installment_number', 'installment_total', 'category_name',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['reference_month', 'items'],
            'additionalProperties' => false,
        ];
    }
}
