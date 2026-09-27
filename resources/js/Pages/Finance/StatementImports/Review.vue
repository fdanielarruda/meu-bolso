<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { categoryTreeOptions, formatMoney } from '@/finance';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

const props = defineProps({
    statementImport: Object,
    account: Object,
    items: Array,
    categories: Array,
});

const categoryOptions = computed(() => categoryTreeOptions(props.categories));

function buildRows() {
    return props.items.map((item) => ({
        include: !item.possible_duplicate,
        description: item.description,
        date: item.purchase_date,
        amount: item.total_amount,
        per_installment_amount: item.amount,
        type: item.type,
        category_id: item.category_id ?? '',
        installment_number: item.installment_number,
        installment_total: item.installment_total,
        possible_duplicate: item.possible_duplicate,
    }));
}

const rows = reactive(buildRows());

function restoreOriginal() {
    if (confirm('Descartar os ajustes feitos e voltar à extração original?')) {
        rows.splice(0, rows.length, ...buildRows());
    }
}

const isImported = computed(() => props.statementImport.status === 'imported');
const isFailed = computed(() => props.statementImport.status === 'failed');

const includedCount = computed(() => rows.filter((r) => r.include).length);
const includedTotal = computed(() =>
    rows
        .filter((r) => r.include)
        .reduce((sum, r) => sum + (r.type === 'income' ? -1 : 1) * Number(r.amount || 0), 0)
        .toFixed(2),
);

const confirmForm = useForm({ items: [] });

function submit() {
    confirmForm.items = rows
        .filter((r) => r.include)
        .map((r) => ({
            description: r.description,
            date: r.date,
            amount: r.amount,
            type: r.type,
            category_id: r.category_id || null,
            installment_total: r.installment_total,
        }));

    confirmForm.post(route('finance.statement-imports.confirm', props.statementImport.id), { preserveScroll: true });
}

const reextractForm = useForm({});

function reextract() {
    if (confirm('Descartar esta leitura e pedir para a IA ler o PDF novamente?')) {
        reextractForm.post(route('finance.statement-imports.reextract', props.statementImport.id));
    }
}
</script>

<template>
    <Head title="Revisar importação" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Revisar lançamentos — {{ account.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between rounded-lg bg-white p-4 shadow">
                    <div class="text-sm text-gray-600">
                        <span class="font-medium text-gray-900">{{ statementImport.original_filename }}</span>
                        ·
                        <span
                            :class="{
                                'text-amber-600': !isImported && !isFailed,
                                'text-green-600': isImported,
                                'text-red-600': isFailed,
                            }"
                        >
                            {{ statementImport.status_label }}
                        </span>
                        <span v-if="isImported && statementImport.imported_at">
                            em {{ new Date(statementImport.imported_at).toLocaleString('pt-BR') }}
                        </span>
                    </div>
                    <div class="flex gap-3">
                        <Link :href="route('finance.statement-imports.create')">
                            <SecondaryButton type="button">Nova importação</SecondaryButton>
                        </Link>
                        <SecondaryButton v-if="!isImported" type="button" :disabled="reextractForm.processing" @click="reextract">
                            {{ reextractForm.processing ? 'Lendo novamente...' : 'Pedir para a IA ler novamente' }}
                        </SecondaryButton>
                    </div>
                </div>

                <div v-if="isFailed" class="rounded-lg bg-red-50 p-6 shadow">
                    <p class="font-medium text-red-800">Não foi possível ler esta fatura.</p>
                    <p class="mt-1 text-sm text-red-700">{{ statementImport.error_message }}</p>
                    <div class="mt-4">
                        <DangerButton :disabled="reextractForm.processing" @click="reextract">
                            {{ reextractForm.processing ? 'Tentando novamente...' : 'Tentar novamente' }}
                        </DangerButton>
                    </div>
                </div>

                <template v-else>
                    <p class="text-sm text-gray-600">
                        Confira os lançamentos extraídos da fatura. Compras parceladas já incluem todas as parcelas
                        futuras, lançadas automaticamente nas faturas seguintes. Desmarque ou ajuste o que não
                        estiver correto antes de confirmar.
                    </p>

                    <InputError :message="confirmForm.errors.items" />

                    <div v-if="rows.length === 0" class="rounded-lg bg-white p-6 text-sm text-gray-500 shadow">
                        Nenhum lançamento foi identificado neste PDF.
                    </div>

                    <div v-else class="overflow-x-auto rounded-lg bg-white shadow">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                                <tr>
                                    <th class="px-3 py-3"></th>
                                    <th class="px-3 py-3">Descrição</th>
                                    <th class="px-3 py-3">Data da compra</th>
                                    <th class="px-3 py-3">Valor total</th>
                                    <th class="px-3 py-3">Tipo</th>
                                    <th class="px-3 py-3">Categoria</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr
                                    v-for="(row, index) in rows"
                                    :key="index"
                                    :class="row.possible_duplicate ? 'bg-amber-50' : ''"
                                >
                                    <td class="px-3 py-2 align-top">
                                        <input
                                            v-model="row.include"
                                            type="checkbox"
                                            class="mt-2 rounded border-gray-300 text-indigo-600"
                                            :disabled="isImported"
                                        />
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <TextInput v-model="row.description" class="block w-56" :disabled="!row.include || isImported" />
                                        <p v-if="row.installment_total" class="mt-1 text-xs text-gray-500">
                                            Parcela {{ row.installment_number }}/{{ row.installment_total }} ·
                                            {{ formatMoney(row.per_installment_amount) }} por mês
                                        </p>
                                        <p v-if="row.possible_duplicate" class="mt-1 text-xs font-medium text-amber-700">
                                            Possível duplicata de um lançamento já existente
                                        </p>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <TextInput v-model="row.date" type="date" class="block" :disabled="!row.include || isImported" />
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <TextInput
                                            v-model="row.amount"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            class="block w-28"
                                            :disabled="!row.include || isImported"
                                        />
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <SelectInput v-model="row.type" class="block" :disabled="!row.include || isImported">
                                            <option value="expense">Despesa</option>
                                            <option value="income">Receita</option>
                                        </SelectInput>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <SelectInput v-model="row.category_id" class="block" :disabled="!row.include || isImported">
                                            <option value="">Sem categoria</option>
                                            <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
                                        </SelectInput>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="!isImported" class="flex items-center justify-between rounded-lg bg-white p-4 shadow">
                        <div class="text-sm text-gray-600">
                            {{ includedCount }} de {{ rows.length }} selecionado(s) · total líquido
                            {{ formatMoney(includedTotal) }}
                        </div>
                        <div class="flex gap-3">
                            <SecondaryButton type="button" @click="restoreOriginal">Restaurar extração original</SecondaryButton>
                            <PrimaryButton :disabled="confirmForm.processing || includedCount === 0" @click="submit">
                                Importar selecionados ({{ includedCount }})
                            </PrimaryButton>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
