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
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    statementImport: Object,
    owners: Array,
    items: Array,
    categories: Array,
});

const categoryOptions = computed(() => categoryTreeOptions(props.categories));

function buildRows() {
    const defaultOwner = props.statementImport.credit_card?.payment_account_id;
    const defaultAccountId = props.owners.some((o) => o.id === defaultOwner) ? defaultOwner : '';

    return props.items.map((item) => ({
        include: !item.possible_duplicate,
        account_id: defaultAccountId,
        description: item.description,
        date: item.statement_date,
        amount: item.amount,
        type: item.type,
        category_id: item.category_id ?? '',
        installment_number: item.installment_number,
        installment_total: item.installment_total,
        possible_duplicate: item.possible_duplicate,
    }));
}

const rows = reactive(buildRows());
const referenceMonth = ref(props.statementImport.reference_month ?? '');
const bulkOwner = ref('');
const showTransient = ref(true);

const isCredit = computed(() => props.statementImport.kind === 'credit');

const visibleOwners = computed(() => props.owners.filter((o) => showTransient.value || !o.transient));

function ownerLabel(owner) {
    return `${owner.person?.name ?? 'Sem pessoa'} — ${owner.name}${owner.transient ? ' (transeunte)' : ''}`;
}

function applyBulkOwner(onlyEmpty) {
    if (!bulkOwner.value) return;

    for (const row of rows) {
        if (row.include && (!onlyEmpty || !row.account_id)) row.account_id = bulkOwner.value;
    }
}

function restoreOriginal() {
    if (confirm('Descartar os ajustes feitos e voltar à extração original?')) {
        rows.splice(0, rows.length, ...buildRows());
    }
}

const isImported = computed(() => props.statementImport.status === 'imported');
const isFailed = computed(() => props.statementImport.status === 'failed');

const missingOwnerCount = computed(() => rows.filter((r) => r.include && !r.account_id).length);

const includedCount = computed(() => rows.filter((r) => r.include).length);
const includedTotal = computed(() =>
    rows
        .filter((r) => r.include)
        .reduce((sum, r) => sum + (r.type === 'income' ? -1 : 1) * Number(r.amount || 0), 0)
        .toFixed(2),
);

const confirmForm = useForm({ reference_month: '', items: [] });

function submit() {
    confirmForm.reference_month = isCredit.value ? referenceMonth.value : '';
    confirmForm.items = rows
        .filter((r) => r.include)
        .map((r) => ({
            account_id: r.account_id,
            description: r.description,
            date: r.date,
            amount: r.amount,
            type: r.type,
            category_id: r.category_id || null,
            installment_number: r.installment_number,
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
                Revisar lançamentos — {{ statementImport.institution?.name }}
                <span v-if="statementImport.credit_card">· {{ statementImport.credit_card.name }}</span>
                <span v-else-if="!isCredit">· Débito</span>
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
                        Confira tudo o que foi extraído do PDF e escolha o dono de cada lançamento. Nada é gravado
                        até você confirmar.
                        <template v-if="isCredit">
                            Compras parceladas têm as parcelas seguintes criadas automaticamente nas próximas faturas.
                        </template>
                        Desmarque ou ajuste o que não estiver correto.
                    </p>

                    <div v-if="!isImported" class="flex flex-wrap items-end gap-4 rounded-lg bg-white p-4 shadow">
                        <div v-if="isCredit">
                            <label class="block text-xs font-medium uppercase text-gray-500">Mês da fatura</label>
                            <TextInput v-model="referenceMonth" type="month" class="mt-1 block" />
                            <InputError class="mt-1" :message="confirmForm.errors.reference_month" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium uppercase text-gray-500">Definir dono em lote</label>
                            <div class="mt-1 flex gap-2">
                                <SelectInput v-model="bulkOwner" class="block">
                                    <option value="">Selecione o dono</option>
                                    <option v-for="o in visibleOwners" :key="o.id" :value="o.id">{{ ownerLabel(o) }}</option>
                                </SelectInput>
                                <SecondaryButton type="button" :disabled="!bulkOwner" @click="applyBulkOwner(true)">
                                    Preencher vazios
                                </SecondaryButton>
                                <SecondaryButton type="button" :disabled="!bulkOwner" @click="applyBulkOwner(false)">
                                    Aplicar a todos
                                </SecondaryButton>
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input v-model="showTransient" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            Mostrar transeuntes
                        </label>
                    </div>

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
                                    <th class="px-3 py-3">Dono</th>
                                    <th class="px-3 py-3">Data</th>
                                    <th class="px-3 py-3">Valor</th>
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
                                            parcela do mês
                                        </p>
                                        <p v-if="row.possible_duplicate" class="mt-1 text-xs font-medium text-amber-700">
                                            Possível duplicata de um lançamento já existente
                                        </p>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <SelectInput
                                            v-model="row.account_id"
                                            class="block w-52"
                                            :class="row.include && !row.account_id ? 'border-red-400' : ''"
                                            :disabled="!row.include || isImported"
                                        >
                                            <option value="" disabled>Selecione o dono</option>
                                            <option
                                                v-for="o in owners.filter((o) => showTransient || !o.transient || o.id === row.account_id)"
                                                :key="o.id"
                                                :value="o.id"
                                            >
                                                {{ ownerLabel(o) }}
                                            </option>
                                        </SelectInput>
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
                                            <option value="income">{{ isCredit ? 'Estorno' : 'Receita' }}</option>
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
                            <span v-if="missingOwnerCount > 0" class="ml-2 font-medium text-red-600">
                                · {{ missingOwnerCount }} sem dono
                            </span>
                        </div>
                        <div class="flex gap-3">
                            <SecondaryButton type="button" @click="restoreOriginal">Restaurar extração original</SecondaryButton>
                            <PrimaryButton :disabled="confirmForm.processing || includedCount === 0 || missingOwnerCount > 0" @click="submit">
                                Importar selecionados ({{ includedCount }})
                            </PrimaryButton>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
