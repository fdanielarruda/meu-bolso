<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { categoryTreeOptions, formatDate, formatMoney, invoiceStatusLabels } from '@/finance';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    invoice: Object,
    prevInvoiceId: Number,
    nextInvoiceId: Number,
    transactions: Array,
    accounts: Array,
    categories: Array,
});

const categoryOptions = computed(() => categoryTreeOptions(props.categories));

const today = new Date().toISOString().slice(0, 10);

const showTransient = ref(false);

const visibleAccounts = computed(() => props.accounts.filter((a) => showTransient.value || !a.transient));

/* ---------- Filtro por pessoa ---------- */

const selectedPersonId = ref('');

const people = computed(() => {
    const seen = new Map();

    for (const a of props.accounts) {
        if (a.person) seen.set(a.person.id, a.person);
    }

    return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name));
});

const filteredTransactions = computed(() =>
    selectedPersonId.value
        ? props.transactions.filter((t) => t.account.person?.id === Number(selectedPersonId.value))
        : props.transactions
);

const filteredTotal = computed(() =>
    filteredTransactions.value.reduce((sum, t) => sum + (t.reversed ? 0 : Number(t.amount)), 0)
);

function addMonths(dateString, months) {
    const [year, month, day] = dateString.slice(0, 10).split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1 + months, day));

    return date.toISOString().slice(0, 10);
}

function monthLabel(dateString) {
    return new Date(dateString.slice(0, 10) + 'T00:00:00').toLocaleDateString('pt-BR', {
        month: 'long',
        year: 'numeric',
    });
}

/* ---------- Pagar fatura ---------- */

const payForm = useForm({});

function payInvoice() {
    if (!confirm('Pagar esta fatura? Será lançada uma transação de "Pagamento de Fatura" em cada conta usada nas compras.')) {
        return;
    }

    payForm.post(route('finance.invoices.pay', props.invoice.id), { preserveScroll: true });
}

/* ---------- Nova compra ---------- */

const form = useForm({
    account_id: props.accounts[0]?.id ?? '',
    category_id: '',
    description: '',
    is_unknown: false,
    amount: '',
    date: today,
    mode: 'single',
    installment_number: 1,
    installment_total: 1,
});

/* ---------- Divisão entre pessoas ---------- */

const splitEnabled = ref(false);
const splitPersonIds = ref([]);
const splitShares = ref([]);

function accountsForPerson(personId) {
    return props.accounts.filter((a) => a.person?.id === personId);
}

function defaultAccountForPerson(personId) {
    return accountsForPerson(personId)[0]?.id ?? '';
}

function applyEqualSplit() {
    const parts = splitPersonIds.value.length;

    if (parts < 2) {
        splitShares.value = [];
        return;
    }

    const totalCents = Math.round((Number(form.amount) || 0) * 100);
    const baseCents = Math.floor(totalCents / parts);
    const remainderCents = totalCents - baseCents * parts;

    splitShares.value = splitPersonIds.value.map((personId, index) => {
        const existing = splitShares.value.find((s) => s.person_id === personId);
        const shareCents = baseCents + (index === parts - 1 ? remainderCents : 0);

        return {
            person_id: personId,
            account_id: existing?.account_id || defaultAccountForPerson(personId),
            amount: (shareCents / 100).toFixed(2),
        };
    });
}

watch(splitPersonIds, applyEqualSplit, { deep: true });

watch(splitEnabled, (enabled) => {
    if (!enabled) {
        splitPersonIds.value = [];
        splitShares.value = [];
    }
});

function togglePersonSplit(personId) {
    splitPersonIds.value = splitPersonIds.value.includes(personId)
        ? splitPersonIds.value.filter((id) => id !== personId)
        : [...splitPersonIds.value, personId];
}

const splitTotal = computed(() => splitShares.value.reduce((sum, s) => sum + (Number(s.amount) || 0), 0));

const splitTotalMatches = computed(
    () => splitShares.value.length >= 2 && Math.round(splitTotal.value * 100) === Math.round((Number(form.amount) || 0) * 100)
);

const purchasePreview = computed(() => {
    if (splitEnabled.value && !splitTotalMatches.value) {
        return `A soma das partes (${formatMoney(splitTotal.value)}) precisa ser igual ao valor total (${formatMoney(form.amount || 0)}).`;
    }

    const splitPrefix = splitEnabled.value ? `Dividida entre ${splitShares.value.length} pessoas. ` : '';

    if (form.mode === 'single') {
        return `${splitPrefix}Será lançada${splitEnabled.value ? '' : ' 1 compra'} na fatura de ${monthLabel(props.invoice.reference_month)}.`;
    }

    if (form.mode === 'recurring') {
        return `${splitPrefix}Será lançada uma parcela de ${formatMoney(form.amount || 0)} por mês, a partir da fatura de `
            + `${monthLabel(props.invoice.reference_month)}, renovando automaticamente até sempre estar 2 anos à frente.`;
    }

    const number = Number(form.installment_number) || 1;
    const total = Number(form.installment_total) || 1;

    const firstMonth = addMonths(props.invoice.reference_month, 1 - number);
    const lastMonth = addMonths(props.invoice.reference_month, total - number);

    return `${splitPrefix}Serão criadas ${total} parcelas de ${formatMoney(form.amount || 0)}, de ${monthLabel(firstMonth)} a ${monthLabel(lastMonth)} `
        + `(esta compra aparece como ${number}/${total} na fatura de ${monthLabel(props.invoice.reference_month)}).`;
});

function submitPurchase() {
    if (splitEnabled.value && !splitTotalMatches.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        reference_invoice_id: props.invoice.id,
        ...(splitEnabled.value
            ? { split: splitShares.value.map((s) => ({ account_id: s.account_id, amount: s.amount })) }
            : {}),
    })).post(route('finance.purchases.store', props.invoice.credit_card.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('description', 'amount', 'is_unknown', 'installment_number', 'installment_total');
            splitEnabled.value = false;
        },
    });
}

/* ---------- Editar parcela ---------- */

const showEditModal = ref(false);
const editing = ref(null);

const editForm = useForm({
    account_id: '',
    description: '',
    is_unknown: false,
    amount: '',
    category_id: '',
    date: '',
    mode: 'single',
    installment_number: 1,
    installment_total: 1,
    scope: 'this',
});

const editSplitEnabled = ref(false);
const editSplitPersonIds = ref([]);
const editSplitShares = ref([]);

function openEdit(transaction) {
    const primary = transaction.isSplitGroup ? transaction.shares[0] : transaction;

    editing.value = primary;
    editForm.account_id = primary.account.id;
    if (props.accounts.find((a) => a.id === primary.account.id)?.transient) {
        showTransient.value = true;
    }
    editForm.description = transaction.description;
    editForm.is_unknown = transaction.is_unknown;
    editForm.amount = transaction.amount;
    editForm.category_id = transaction.category?.id ?? primary.category?.id ?? '';
    editForm.date = primary.date.slice(0, 10);
    editForm.mode = primary.installment_total ? 'installments' : primary.is_recurring ? 'recurring' : 'single';
    editForm.installment_number = editForm.mode === 'installments' ? primary.installment_number ?? 1 : 1;
    editForm.installment_total = editForm.mode === 'installments' ? primary.installment_total ?? 1 : 1;
    editForm.scope = primary.installment_total || primary.is_recurring ? 'future' : 'this';
    editForm.clearErrors();

    if (transaction.isSplitGroup) {
        editSplitEnabled.value = true;
        editSplitPersonIds.value = transaction.shares.map((s) => s.account.person?.id).filter(Boolean);
        editSplitShares.value = transaction.shares.map((s) => ({
            person_id: s.account.person?.id,
            account_id: s.account.id,
            amount: s.amount,
        }));
    } else {
        editSplitEnabled.value = false;
        editSplitPersonIds.value = [];
        editSplitShares.value = [];
    }

    showEditModal.value = true;
}

function applyEqualSplitEdit() {
    const parts = editSplitPersonIds.value.length;

    if (parts < 2) {
        editSplitShares.value = [];
        return;
    }

    const totalCents = Math.round((Number(editForm.amount) || 0) * 100);
    const baseCents = Math.floor(totalCents / parts);
    const remainderCents = totalCents - baseCents * parts;

    editSplitShares.value = editSplitPersonIds.value.map((personId, index) => {
        const existing = editSplitShares.value.find((s) => s.person_id === personId);
        const shareCents = baseCents + (index === parts - 1 ? remainderCents : 0);

        return {
            person_id: personId,
            account_id: existing?.account_id || defaultAccountForPerson(personId),
            amount: (shareCents / 100).toFixed(2),
        };
    });
}

watch(editSplitPersonIds, applyEqualSplitEdit, { deep: true });

watch(editSplitEnabled, (enabled) => {
    if (!enabled) {
        editSplitPersonIds.value = [];
        editSplitShares.value = [];
    }
});

function toggleEditPersonSplit(personId) {
    editSplitPersonIds.value = editSplitPersonIds.value.includes(personId)
        ? editSplitPersonIds.value.filter((id) => id !== personId)
        : [...editSplitPersonIds.value, personId];
}

const editSplitTotal = computed(() => editSplitShares.value.reduce((sum, s) => sum + (Number(s.amount) || 0), 0));

const editSplitTotalMatches = computed(
    () => editSplitShares.value.length >= 2 && Math.round(editSplitTotal.value * 100) === Math.round((Number(editForm.amount) || 0) * 100)
);

function submitEdit() {
    if (editSplitEnabled.value && !editSplitTotalMatches.value) {
        return;
    }

    editForm
        .transform((data) => ({
            ...data,
            ...(editSplitEnabled.value
                ? { split: editSplitShares.value.map((s) => ({ account_id: s.account_id, amount: s.amount })) }
                : {}),
        }))
        .put(route('finance.installments.update', editing.value.id), {
            preserveScroll: true,
            onSuccess: () => (showEditModal.value = false),
        });
}

/* ---------- Dividir lançamento já salvo ---------- */

function canSplit(transaction) {
    return !transaction.split_group_id;
}

const showSplitModal = ref(false);
const splitting = ref(null);
const splitModalPersonIds = ref([]);
const splitModalShares = ref([]);
const splitModalForm = useForm({ scope: 'this' });

function openSplit(transaction) {
    splitting.value = transaction;
    splitModalPersonIds.value = [];
    splitModalShares.value = [];
    splitModalForm.clearErrors();
    splitModalForm.scope = 'this';
    showSplitModal.value = true;
}

function applyEqualSplitModal() {
    const parts = splitModalPersonIds.value.length;

    if (parts < 2) {
        splitModalShares.value = [];
        return;
    }

    const totalCents = Math.round(Number(splitting.value.amount) * 100);
    const baseCents = Math.floor(totalCents / parts);
    const remainderCents = totalCents - baseCents * parts;

    splitModalShares.value = splitModalPersonIds.value.map((personId, index) => {
        const existing = splitModalShares.value.find((s) => s.person_id === personId);
        const shareCents = baseCents + (index === parts - 1 ? remainderCents : 0);

        return {
            person_id: personId,
            account_id: existing?.account_id || defaultAccountForPerson(personId),
            amount: (shareCents / 100).toFixed(2),
        };
    });
}

watch(splitModalPersonIds, applyEqualSplitModal, { deep: true });

function toggleModalPersonSplit(personId) {
    splitModalPersonIds.value = splitModalPersonIds.value.includes(personId)
        ? splitModalPersonIds.value.filter((id) => id !== personId)
        : [...splitModalPersonIds.value, personId];
}

const splitModalTotal = computed(() => splitModalShares.value.reduce((sum, s) => sum + (Number(s.amount) || 0), 0));

const splitModalTotalMatches = computed(
    () =>
        splitModalShares.value.length >= 2
        && Math.round(splitModalTotal.value * 100) === Math.round(Number(splitting.value?.amount ?? 0) * 100)
);

function submitSplit() {
    if (!splitModalTotalMatches.value) {
        return;
    }

    splitModalForm
        .transform(() => ({
            split: splitModalShares.value.map((s) => ({ account_id: s.account_id, amount: s.amount })),
            scope: splitting.value?.installment_group_id ? splitModalForm.scope : 'this',
        }))
        .post(route('finance.installments.split', splitting.value.id), {
            preserveScroll: true,
            onSuccess: () => (showSplitModal.value = false),
        });
}

function toggleReversed(transaction) {
    const scope = transaction.installment_total
        ? prompt('Estornar apenas esta parcela, esta e as futuras, ou todas? (this / future / all)', 'this')
        : 'this';

    if (!scope) return;

    useForm({ scope }).post(route('finance.installments.reverse', transaction.id), { preserveScroll: true });
}

function toggleReversedGroup(group) {
    useForm({ scope: 'all' }).post(route('finance.installments.reverse', group.shares[0].id), { preserveScroll: true });
}

const showDeleteModal = ref(false);
const deleting = ref(null);
const deleteScope = ref('this');

function destroyInstallment(transaction) {
    deleting.value = transaction;

    if (!transaction.installment_total && !transaction.is_recurring && !transaction.split_group_id) {
        deleteScope.value = 'this';

        if (confirm('Excluir este lançamento da fatura? Esta ação não pode ser desfeita.')) {
            useForm({ scope: 'this' }).delete(route('finance.installments.destroy', transaction.id), { preserveScroll: true });
        }
        return;
    }

    deleteScope.value = 'future';
    showDeleteModal.value = true;
}

function destroyGroup(group) {
    deleting.value = group.shares[0];
    deleteScope.value = 'future';
    showDeleteModal.value = true;
}

const expandedGroups = ref(new Set());

function toggleExpandGroup(groupId) {
    const next = new Set(expandedGroups.value);

    next.has(groupId) ? next.delete(groupId) : next.add(groupId);

    expandedGroups.value = next;
}

function confirmDestroyInstallment() {
    useForm({ scope: deleteScope.value }).delete(route('finance.installments.destroy', deleting.value.id), {
        preserveScroll: true,
        onSuccess: () => (showDeleteModal.value = false),
    });
}

/* ---------- Conferência (checklist local) ---------- */

const checkedStorageKey = `invoice-checked-${props.invoice.id}`;

function loadCheckedIds() {
    try {
        const raw = localStorage.getItem(checkedStorageKey);
        return raw ? new Set(JSON.parse(raw)) : new Set();
    } catch {
        return new Set();
    }
}

const checkedIds = ref(loadCheckedIds());

watch(
    checkedIds,
    (value) => {
        localStorage.setItem(checkedStorageKey, JSON.stringify([...value]));
    },
    { deep: true }
);

function isChecked(transaction) {
    return transaction.isSplitGroup
        ? transaction.shares.every((s) => checkedIds.value.has(s.id))
        : checkedIds.value.has(transaction.id);
}

function toggleChecked(transaction) {
    const next = new Set(checkedIds.value);
    const ids = transaction.isSplitGroup ? transaction.shares.map((s) => s.id) : [transaction.id];
    const shouldCheck = !isChecked(transaction);

    for (const id of ids) {
        shouldCheck ? next.add(id) : next.delete(id);
    }

    checkedIds.value = next;
}

const allChecked = computed(
    () => filteredTransactions.value.length > 0 && filteredTransactions.value.every((t) => checkedIds.value.has(t.id))
);

function toggleCheckAll() {
    checkedIds.value = allChecked.value ? new Set() : new Set(filteredTransactions.value.map((t) => t.id));
}

/**
 * Collapse a split's shares into a single row for display, so a divided
 * purchase reads as one item with the people's names underneath its
 * description instead of one line per share. Only merges shares that are
 * both present in the current filter (a person filter naturally shows just
 * their own share, unmerged).
 */
const mergedTransactions = computed(() => {
    const groups = new Map();
    const result = [];

    for (const transaction of filteredTransactions.value) {
        if (!transaction.split_group_id) {
            result.push(transaction);
            continue;
        }

        const existing = groups.get(transaction.split_group_id);

        if (existing) {
            existing.shares.push(transaction);
            existing.amount += Number(transaction.amount);
        } else {
            const group = {
                isSplitGroup: true,
                id: transaction.split_group_id,
                date: transaction.date,
                description: transaction.description,
                is_unknown: transaction.is_unknown,
                reversed: transaction.reversed,
                installment_number: transaction.installment_number,
                installment_total: transaction.installment_total,
                is_recurring: transaction.is_recurring,
                shares: [transaction],
                amount: Number(transaction.amount),
            };

            groups.set(transaction.split_group_id, group);
            result.push(group);
        }
    }

    return result;
});

const groupedByDay = computed(() => {
    const groups = new Map();

    for (const transaction of mergedTransactions.value) {
        const key = transaction.date.slice(0, 10);

        if (!groups.has(key)) {
            groups.set(key, { date: key, transactions: [] });
        }

        groups.get(key).transactions.push(transaction);
    }

    return [...groups.values()].sort((a, b) => b.date.localeCompare(a.date));
});
</script>

<template>
    <Head :title="`Fatura ${formatDate(invoice.reference_month)}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        {{ invoice.credit_card.institution.name }} / {{ invoice.credit_card.name }}
                    </h2>
                    <p class="mt-1 text-xs text-gray-500">
                        Fechamento {{ formatDate(invoice.closing_date) }} · Vencimento {{ formatDate(invoice.due_date) }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="route('finance.invoices.show', prevInvoiceId)"
                        class="flex h-9 w-9 items-center justify-center rounded-md border border-gray-300 text-gray-600 hover:bg-gray-50"
                        title="Fatura anterior"
                    >
                        ←
                    </Link>
                    <span class="min-w-[9rem] text-center text-sm font-semibold capitalize text-gray-700">
                        {{ monthLabel(invoice.reference_month) }}
                    </span>
                    <Link
                        v-if="nextInvoiceId"
                        :href="route('finance.invoices.show', nextInvoiceId)"
                        class="flex h-9 w-9 items-center justify-center rounded-md border border-gray-300 text-gray-600 hover:bg-gray-50"
                        title="Próxima fatura"
                    >
                        →
                    </Link>
                    <span
                        v-else
                        class="flex h-9 w-9 items-center justify-center rounded-md border border-gray-200 text-gray-300"
                        title="Limite de 2 anos à frente"
                    >
                        →
                    </span>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="people.length > 0" class="rounded-lg bg-white p-5 shadow">
                    <label class="text-xs font-medium text-gray-500">Pessoa</label>
                    <SelectInput v-model="selectedPersonId" class="mt-1 block w-full sm:w-64">
                        <option value="">Todas as pessoas</option>
                        <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </SelectInput>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-white p-5 shadow">
                        <p class="text-sm text-gray-500">
                            {{ selectedPersonId ? 'Total filtrado' : 'Total da fatura' }}
                        </p>
                        <p class="mt-1 text-3xl font-semibold text-gray-900">
                            {{ formatMoney(selectedPersonId ? filteredTotal : invoice.total) }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Status</p>
                                <p class="mt-1 text-3xl font-semibold text-gray-900">{{ invoiceStatusLabels[invoice.status] }}</p>
                            </div>
                            <PrimaryButton v-if="invoice.status !== 'paid'" :disabled="payForm.processing" @click="payInvoice">
                                Pagar fatura
                            </PrimaryButton>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-6 shadow">
                    <h3 class="font-medium text-gray-900">Nova compra</h3>

                    <form class="mt-4" @submit.prevent="submitPurchase">
                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <InputLabel for="amount" value="Valor da parcela" />
                                <TextInput
                                    id="amount"
                                    v-model="form.amount"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.01"
                                    min="0.01"
                                    placeholder="0,00"
                                    class="mt-1 block w-full text-3xl font-semibold"
                                    autofocus
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.amount" />
                            </div>

                            <div>
                                <div class="flex items-center justify-between">
                                    <InputLabel for="description" value="Descrição" />
                                    <label class="flex items-center gap-2 text-sm text-gray-600">
                                        <Checkbox v-model:checked="form.is_unknown" />
                                        Transação desconhecida
                                    </label>
                                </div>
                                <TextInput
                                    id="description"
                                    v-model="form.description"
                                    class="mt-1 block w-full"
                                    :placeholder="form.is_unknown ? 'Desconhecido (opcional preencher)' : 'Ex: PAG*Estabelecimento'"
                                    :required="!form.is_unknown"
                                />
                                <InputError class="mt-2" :message="form.errors.description" />
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="date" value="Data da compra" />
                                    <TextInput id="date" v-model="form.date" type="date" class="mt-1 block w-full" required />
                                    <InputError class="mt-2" :message="form.errors.date" />
                                </div>

                                <div>
                                    <InputLabel for="category_id" value="Categoria (opcional)" />
                                    <SelectInput id="category_id" v-model="form.category_id" class="mt-1 block w-full">
                                        <option value="">Sem categoria</option>
                                        <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
                                    </SelectInput>
                                </div>
                            </div>

                            <div v-if="!splitEnabled">
                                <div class="flex items-center justify-between">
                                    <InputLabel value="Conta" />
                                    <label class="flex items-center gap-2 text-sm text-gray-600">
                                        <Checkbox v-model:checked="showTransient" />
                                        Exibir transeuntes
                                    </label>
                                </div>
                                <div
                                    class="mt-1 grid gap-2"
                                    :style="{ gridTemplateColumns: `repeat(${Math.min(visibleAccounts.length || 1, 4)}, minmax(0, 1fr))` }"
                                >
                                    <button
                                        v-for="a in visibleAccounts"
                                        :key="a.id"
                                        type="button"
                                        class="w-full truncate rounded-md border px-3 py-2 text-sm font-medium"
                                        :class="
                                            Number(form.account_id) === a.id
                                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                                : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                                        "
                                        @click="form.account_id = a.id"
                                    >
                                        {{ a.name }}
                                    </button>
                                </div>
                                <InputError class="mt-2" :message="form.errors.account_id" />
                            </div>

                            <div v-if="people.length > 1">
                                <label class="flex items-center gap-2 text-sm text-gray-600">
                                    <Checkbox v-model:checked="splitEnabled" />
                                    Dividir esta compra entre pessoas
                                </label>

                                <div v-if="splitEnabled" class="mt-3 space-y-3 rounded-md border border-gray-200 p-3">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="p in people"
                                            :key="p.id"
                                            type="button"
                                            class="rounded-md border px-3 py-1.5 text-sm font-medium"
                                            :class="
                                                splitPersonIds.includes(p.id)
                                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                                    : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                                            "
                                            @click="togglePersonSplit(p.id)"
                                        >
                                            {{ p.name }}
                                        </button>
                                    </div>

                                    <p v-if="splitPersonIds.length < 2" class="text-xs text-gray-500">
                                        Selecione pelo menos 2 pessoas para dividir a compra.
                                    </p>

                                    <div v-for="share in splitShares" :key="share.person_id" class="grid grid-cols-2 gap-2">
                                        <SelectInput v-model="share.account_id" class="block w-full text-sm">
                                            <option v-for="a in accountsForPerson(share.person_id)" :key="a.id" :value="a.id">
                                                {{ people.find((p) => p.id === share.person_id)?.name }} · {{ a.name }}
                                            </option>
                                        </SelectInput>
                                        <TextInput
                                            v-model="share.amount"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            class="block w-full text-sm"
                                        />
                                    </div>

                                    <button
                                        v-if="splitShares.length >= 2"
                                        type="button"
                                        class="text-xs font-medium text-indigo-600 hover:underline"
                                        @click="applyEqualSplit"
                                    >
                                        Redistribuir igualmente
                                    </button>
                                </div>
                            </div>

                            <div>
                                <InputLabel value="Tipo de lançamento" />
                                <div class="mt-1 grid grid-cols-3 gap-2">
                                    <button
                                        type="button"
                                        class="rounded-md border px-3 py-2 text-sm font-medium"
                                        :class="
                                            form.mode === 'single'
                                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                                : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                                        "
                                        @click="form.mode = 'single'"
                                    >
                                        Parcela única
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md border px-3 py-2 text-sm font-medium"
                                        :class="
                                            form.mode === 'installments'
                                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                                : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                                        "
                                        @click="form.mode = 'installments'"
                                    >
                                        Parcelado
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md border px-3 py-2 text-sm font-medium"
                                        :class="
                                            form.mode === 'recurring'
                                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                                : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                                        "
                                        @click="form.mode = 'recurring'"
                                    >
                                        Recorrente
                                    </button>
                                </div>
                            </div>

                            <div v-if="form.mode === 'installments'" class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="installment_number" value="Parcela neste mês" />
                                    <TextInput
                                        id="installment_number"
                                        v-model="form.installment_number"
                                        type="number"
                                        min="1"
                                        :max="form.installment_total"
                                        class="mt-1 block w-full"
                                        required
                                    />
                                    <InputError class="mt-2" :message="form.errors.installment_number" />
                                </div>

                                <div>
                                    <InputLabel for="installment_total" value="Total de parcelas" />
                                    <TextInput
                                        id="installment_total"
                                        v-model="form.installment_total"
                                        type="number"
                                        min="1"
                                        class="mt-1 block w-full"
                                        required
                                    />
                                    <InputError class="mt-2" :message="form.errors.installment_total" />
                                </div>
                            </div>

                            <div class="rounded-md bg-blue-50 p-3 text-sm text-blue-800">
                                {{ purchasePreview }}
                            </div>

                            <div>
                                <PrimaryButton :disabled="form.processing || (splitEnabled && !splitTotalMatches)">
                                    Lançar compra
                                </PrimaryButton>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <div v-if="filteredTransactions.length > 0" class="flex items-center gap-2 border-b bg-gray-50 px-4 py-2 sm:px-6">
                        <Checkbox :checked="allChecked" @update:checked="toggleCheckAll" />
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Marcar todas</span>
                    </div>
                    <div v-for="group in groupedByDay" :key="group.date">
                        <div class="bg-gray-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">
                            {{ formatDate(group.date) }}
                        </div>
                        <ul class="divide-y divide-gray-200">
                            <li
                                v-for="transaction in group.transactions"
                                :key="transaction.id"
                                class="px-4 py-3 sm:px-6"
                            >
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <Checkbox :checked="isChecked(transaction)" @update:checked="toggleChecked(transaction)" />
                                        <div>
                                        <p class="text-gray-900" :class="transaction.reversed ? 'line-through text-gray-400' : ''">
                                            {{ transaction.description }}
                                            <span v-if="transaction.installment_total" class="text-xs text-gray-500">
                                                ({{ transaction.installment_number }}/{{ transaction.installment_total }})
                                            </span>
                                            <span
                                                v-if="transaction.installment_total && transaction.installment_number === transaction.installment_total"
                                                class="ml-1 rounded bg-green-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-green-700"
                                            >
                                                Última
                                            </span>
                                            <span
                                                v-if="!transaction.installment_total && !transaction.is_recurring"
                                                class="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-blue-700"
                                            >
                                                Única
                                            </span>
                                            <span
                                                v-if="transaction.is_recurring"
                                                class="ml-1 rounded bg-purple-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-purple-700"
                                            >
                                                Recorrente
                                            </span>
                                            <span
                                                v-if="transaction.is_unknown"
                                                class="ml-1 rounded bg-yellow-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-yellow-700"
                                            >
                                                Desconhecida
                                            </span>
                                            <span
                                                v-if="transaction.reversed"
                                                class="ml-1 rounded bg-green-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-green-700"
                                            >
                                                Estornada
                                            </span>
                                            <span
                                                v-if="transaction.isSplitGroup || transaction.split_group_id"
                                                class="ml-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-indigo-700"
                                            >
                                                Dividida
                                            </span>
                                            <button
                                                v-if="transaction.isSplitGroup"
                                                type="button"
                                                class="ml-1 align-middle text-gray-400 hover:text-gray-600"
                                                :aria-label="expandedGroups.has(transaction.id) ? 'Recolher divisão' : 'Expandir divisão'"
                                                @click="toggleExpandGroup(transaction.id)"
                                            >
                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                    class="inline h-4 w-4 transition-transform"
                                                    :class="expandedGroups.has(transaction.id) ? 'rotate-90' : ''"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </p>
                                        <p v-if="!transaction.isSplitGroup" class="text-xs text-gray-500">
                                            {{ transaction.account.name }}
                                        </p>
                                        <p v-else class="text-xs text-gray-500">
                                            {{ transaction.shares.map((s) => s.account.name).join(' · ') }}
                                        </p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span :class="transaction.reversed ? 'text-green-600' : 'text-gray-900'">
                                            {{ transaction.reversed ? '-' : '' }}{{ formatMoney(transaction.amount) }}
                                        </span>
                                        <template v-if="!transaction.isSplitGroup">
                                            <button class="text-sm text-indigo-600 hover:text-indigo-900" @click="openEdit(transaction)">
                                                Editar
                                            </button>
                                            <button
                                                class="text-sm text-indigo-600 hover:text-indigo-900"
                                                @click="openSplit(transaction)"
                                            >
                                                Dividir
                                            </button>
                                            <button class="text-sm text-amber-600 hover:text-amber-900" @click="toggleReversed(transaction)">
                                                {{ transaction.reversed ? 'Desfazer estorno' : 'Estornar' }}
                                            </button>
                                            <button class="text-sm text-red-600 hover:text-red-900" @click="destroyInstallment(transaction)">
                                                Excluir
                                            </button>
                                        </template>
                                        <template v-else>
                                            <button class="text-sm text-indigo-600 hover:text-indigo-900" @click="openEdit(transaction)">
                                                Editar
                                            </button>
                                            <button class="text-sm text-amber-600 hover:text-amber-900" @click="toggleReversedGroup(transaction)">
                                                {{ transaction.reversed ? 'Desfazer estorno' : 'Estornar' }}
                                            </button>
                                            <button class="text-sm text-red-600 hover:text-red-900" @click="destroyGroup(transaction)">
                                                Excluir
                                            </button>
                                        </template>
                                    </div>
                                </div>

                                <ul v-if="transaction.isSplitGroup && expandedGroups.has(transaction.id)" class="mt-2 space-y-1 pl-8">
                                    <li
                                        v-for="share in transaction.shares"
                                        :key="share.id"
                                        class="text-xs text-gray-500"
                                    >
                                        {{ share.account.name }} — {{ formatMoney(share.amount) }}
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </div>
                    <p v-if="filteredTransactions.length === 0" class="px-4 py-6 text-sm text-gray-500 sm:px-6">
                        {{ selectedPersonId ? 'Nenhum lançamento desta pessoa na fatura.' : 'Nenhum lançamento nesta fatura.' }}
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <Modal :show="showEditModal" max-width="lg" @close="showEditModal = false">
        <form class="p-6" @submit.prevent="submitEdit">
            <h2 class="text-lg font-medium text-gray-900">Editar parcela</h2>

            <div class="mt-6 space-y-4">
                <div>
                    <div class="flex items-center justify-between">
                        <InputLabel for="edit_description" value="Descrição" />
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <Checkbox v-model:checked="editForm.is_unknown" />
                            Transação desconhecida
                        </label>
                    </div>
                    <TextInput
                        id="edit_description"
                        v-model="editForm.description"
                        class="mt-1 block w-full"
                        :required="!editForm.is_unknown"
                    />
                    <InputError class="mt-2" :message="editForm.errors.description" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="edit_amount" value="Valor" />
                        <TextInput
                            id="edit_amount"
                            v-model="editForm.amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            class="mt-1 block w-full"
                            required
                        />
                        <InputError class="mt-2" :message="editForm.errors.amount" />
                    </div>

                    <div>
                        <InputLabel for="edit_category_id" value="Categoria" />
                        <SelectInput id="edit_category_id" v-model="editForm.category_id" class="mt-1 block w-full">
                            <option value="">Sem categoria</option>
                            <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
                        </SelectInput>
                    </div>
                </div>

                <div v-if="!editSplitEnabled">
                    <div class="flex items-center justify-between">
                        <InputLabel for="edit_account_id" value="Conta" />
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <Checkbox v-model:checked="showTransient" />
                            Exibir transeuntes
                        </label>
                    </div>
                    <SelectInput id="edit_account_id" v-model="editForm.account_id" class="mt-1 block w-full">
                        <option v-for="a in visibleAccounts" :key="a.id" :value="a.id">
                            {{ a.name }}
                        </option>
                    </SelectInput>
                    <InputError class="mt-2" :message="editForm.errors.account_id" />
                </div>

                <div v-if="people.length > 1">
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <Checkbox v-model:checked="editSplitEnabled" />
                        Dividir esta compra entre pessoas
                    </label>

                    <div v-if="editSplitEnabled" class="mt-3 space-y-3 rounded-md border border-gray-200 p-3">
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="p in people"
                                :key="p.id"
                                type="button"
                                class="rounded-md border px-3 py-1.5 text-sm font-medium"
                                :class="
                                    editSplitPersonIds.includes(p.id)
                                        ? 'border-indigo-600 bg-indigo-600 text-white'
                                        : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                                "
                                @click="toggleEditPersonSplit(p.id)"
                            >
                                {{ p.name }}
                            </button>
                        </div>

                        <p v-if="editSplitPersonIds.length < 2" class="text-xs text-gray-500">
                            Selecione pelo menos 2 pessoas para dividir a compra.
                        </p>

                        <div v-for="share in editSplitShares" :key="share.person_id" class="grid grid-cols-2 gap-2">
                            <SelectInput v-model="share.account_id" class="block w-full text-sm">
                                <option v-for="a in accountsForPerson(share.person_id)" :key="a.id" :value="a.id">
                                    {{ people.find((p) => p.id === share.person_id)?.name }} · {{ a.name }}
                                </option>
                            </SelectInput>
                            <TextInput
                                v-model="share.amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                class="block w-full text-sm"
                            />
                        </div>

                        <button
                            v-if="editSplitShares.length >= 2"
                            type="button"
                            class="text-xs font-medium text-indigo-600 hover:underline"
                            @click="applyEqualSplitEdit"
                        >
                            Redistribuir igualmente
                        </button>

                        <p v-if="editSplitShares.length >= 2 && !editSplitTotalMatches" class="text-xs text-red-600">
                            A soma das partes ({{ formatMoney(editSplitTotal) }}) precisa ser igual ao valor total
                            ({{ formatMoney(editForm.amount || 0) }}).
                        </p>
                        <InputError :message="editForm.errors.split" />
                    </div>
                </div>

                <div>
                    <InputLabel for="edit_date" value="Data da compra" />
                    <TextInput id="edit_date" v-model="editForm.date" type="date" class="mt-1 block w-full" required />
                    <InputError class="mt-2" :message="editForm.errors.date" />
                </div>

                <div>
                    <InputLabel value="Tipo de lançamento" />
                    <div class="mt-1 grid grid-cols-3 gap-2">
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm font-medium"
                            :class="
                                editForm.mode === 'single'
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                            "
                            @click="editForm.mode = 'single'"
                        >
                            Parcela única
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm font-medium"
                            :class="
                                editForm.mode === 'installments'
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                            "
                            @click="editForm.mode = 'installments'"
                        >
                            Parcelado
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm font-medium"
                            :class="
                                editForm.mode === 'recurring'
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                            "
                            @click="editForm.mode = 'recurring'"
                        >
                            Recorrente
                        </button>
                    </div>
                    <p v-if="editForm.mode !== (editing?.installment_total ? 'installments' : editing?.is_recurring ? 'recurring' : 'single')"
                       class="mt-2 text-xs text-amber-600">
                        Trocar o tipo recria esta parcela e todas as futuras do mesmo grupo a partir de hoje{{ editing?.split_group_id ? ', tirando-a da divisão' : '' }}.
                    </p>
                </div>

                <div v-if="editForm.mode === 'installments'" class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="edit_installment_number" value="Parcela neste mês" />
                        <TextInput
                            id="edit_installment_number"
                            v-model="editForm.installment_number"
                            type="number"
                            min="1"
                            :max="editForm.installment_total"
                            class="mt-1 block w-full"
                            required
                        />
                        <InputError class="mt-2" :message="editForm.errors.installment_number" />
                    </div>

                    <div>
                        <InputLabel for="edit_installment_total" value="Total de parcelas" />
                        <TextInput
                            id="edit_installment_total"
                            v-model="editForm.installment_total"
                            type="number"
                            min="1"
                            class="mt-1 block w-full"
                            required
                        />
                        <InputError class="mt-2" :message="editForm.errors.installment_total" />
                    </div>
                </div>

                <div v-if="editing?.installment_total || editing?.split_group_id">
                    <InputLabel value="Aplicar em" />
                    <p v-if="editSplitEnabled" class="mt-1 text-xs text-gray-500">
                        A divisão acima será recriada nas parcelas escolhidas abaixo.
                    </p>
                    <p v-else-if="editing?.split_group_id" class="mt-1 text-xs text-gray-500">
                        Isso remove a divisão — o valor total passa a ser só desta conta.
                    </p>
                    <div class="mt-1 grid grid-cols-3 gap-2">
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm font-medium"
                            :class="
                                editForm.scope === 'this'
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                            "
                            @click="editForm.scope = 'this'"
                        >
                            Só esta
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm font-medium"
                            :class="
                                editForm.scope === 'future'
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-indigo-300 text-indigo-700 hover:bg-indigo-50'
                            "
                            @click="editForm.scope = 'future'"
                        >
                            Esta e as próximas
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm font-medium"
                            :class="
                                editForm.scope === 'all'
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                            "
                            @click="editForm.scope = 'all'"
                        >
                            Todas
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="showEditModal = false">Cancelar</SecondaryButton>
                <PrimaryButton :disabled="editForm.processing">Salvar</PrimaryButton>
            </div>
        </form>
    </Modal>

    <Modal :show="showSplitModal" max-width="lg" @close="showSplitModal = false">
        <form class="p-6" @submit.prevent="submitSplit">
            <h2 class="text-lg font-medium text-gray-900">Dividir lançamento</h2>
            <p class="mt-1 text-sm text-gray-600">
                {{ splitting?.description }} · {{ formatMoney(splitting?.amount ?? 0) }}
            </p>

            <div class="mt-4 space-y-3">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="p in people"
                        :key="p.id"
                        type="button"
                        class="rounded-md border px-3 py-1.5 text-sm font-medium"
                        :class="
                            splitModalPersonIds.includes(p.id)
                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                        "
                        @click="toggleModalPersonSplit(p.id)"
                    >
                        {{ p.name }}
                    </button>
                </div>

                <p v-if="splitModalPersonIds.length < 2" class="text-xs text-gray-500">
                    Selecione pelo menos 2 pessoas para dividir este lançamento.
                </p>

                <div v-for="share in splitModalShares" :key="share.person_id" class="grid grid-cols-2 gap-2">
                    <SelectInput v-model="share.account_id" class="block w-full text-sm">
                        <option v-for="a in accountsForPerson(share.person_id)" :key="a.id" :value="a.id">
                            {{ people.find((p) => p.id === share.person_id)?.name }} · {{ a.name }}
                        </option>
                    </SelectInput>
                    <TextInput v-model="share.amount" type="number" step="0.01" min="0.01" class="block w-full text-sm" />
                </div>

                <button
                    v-if="splitModalShares.length >= 2"
                    type="button"
                    class="text-xs font-medium text-indigo-600 hover:underline"
                    @click="applyEqualSplitModal"
                >
                    Redistribuir igualmente
                </button>

                <p v-if="splitModalShares.length >= 2 && !splitModalTotalMatches" class="text-xs text-red-600">
                    A soma das partes ({{ formatMoney(splitModalTotal) }}) precisa ser igual ao valor total
                    ({{ formatMoney(splitting?.amount ?? 0) }}).
                </p>

                <div v-if="splitting?.installment_group_id">
                    <InputLabel value="Aplicar em" />
                    <p class="mt-1 text-xs text-gray-500">
                        A mesma divisão (mesmas pessoas e percentuais) será aplicada, proporcionalmente ao valor de
                        cada parcela. As parcelas divididas deixam de fazer parte do parcelamento/recorrência.
                    </p>
                    <div class="mt-1 grid grid-cols-3 gap-2">
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm font-medium"
                            :class="
                                splitModalForm.scope === 'this'
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                            "
                            @click="splitModalForm.scope = 'this'"
                        >
                            Só esta
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm font-medium"
                            :class="
                                splitModalForm.scope === 'future'
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                            "
                            @click="splitModalForm.scope = 'future'"
                        >
                            Esta e as próximas
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm font-medium"
                            :class="
                                splitModalForm.scope === 'all'
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                            "
                            @click="splitModalForm.scope = 'all'"
                        >
                            Todas
                        </button>
                    </div>
                </div>
                <InputError :message="splitModalForm.errors.split" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="showSplitModal = false">Cancelar</SecondaryButton>
                <PrimaryButton :disabled="splitModalForm.processing || !splitModalTotalMatches">Dividir</PrimaryButton>
            </div>
        </form>
    </Modal>

    <Modal :show="showDeleteModal" max-width="md" @close="showDeleteModal = false">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900">Excluir parcela</h2>
            <p v-if="deleting?.split_group_id" class="mt-2 text-sm text-gray-600">
                Esta compra é dividida entre pessoas. Excluir apenas esta parte, ou todas as partes desta divisão?
                "Esta e as próximas" e "Todas" têm o mesmo efeito aqui.
            </p>
            <p v-else class="mt-2 text-sm text-gray-600">
                Esta compra é parcelada. Excluir apenas esta parcela, esta e as futuras, ou todas?
            </p>

            <div class="mt-4">
                <InputLabel value="Aplicar em" />
                <div class="mt-1 grid grid-cols-3 gap-2">
                    <button
                        type="button"
                        class="rounded-md border px-3 py-2 text-sm font-medium"
                        :class="
                            deleteScope === 'this'
                                ? 'border-red-600 bg-red-600 text-white'
                                : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                        "
                        @click="deleteScope = 'this'"
                    >
                        Só esta
                    </button>
                    <button
                        type="button"
                        class="rounded-md border px-3 py-2 text-sm font-medium"
                        :class="
                            deleteScope === 'future'
                                ? 'border-red-600 bg-red-600 text-white'
                                : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                        "
                        @click="deleteScope = 'future'"
                    >
                        Esta e as próximas
                    </button>
                    <button
                        type="button"
                        class="rounded-md border px-3 py-2 text-sm font-medium"
                        :class="
                            deleteScope === 'all'
                                ? 'border-red-600 bg-red-600 text-white'
                                : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                        "
                        @click="deleteScope = 'all'"
                    >
                        Todas
                    </button>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="showDeleteModal = false">Cancelar</SecondaryButton>
                <button
                    type="button"
                    class="rounded-md border border-transparent bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-50"
                    @click="confirmDestroyInstallment"
                >
                    Excluir
                </button>
            </div>
        </div>
    </Modal>
</template>
