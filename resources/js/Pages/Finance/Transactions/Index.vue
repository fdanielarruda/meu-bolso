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
import { categoryTreeOptions, formatDate, formatMoney } from '@/finance';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    transactions: Object,
    accounts: Array,
    institutions: Array,
    people: Array,
    categories: Array,
    filters: Object,
});

const categoryOptions = computed(() => categoryTreeOptions(props.categories));

function toIsoDate(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function firstDayOfMonth(base = new Date()) {
    return toIsoDate(new Date(base.getFullYear(), base.getMonth(), 1));
}

function lastDayOfMonth(base = new Date()) {
    return toIsoDate(new Date(base.getFullYear(), base.getMonth() + 1, 0));
}

const filters = reactive({
    q: props.filters.q ?? '',
    institution_id: props.filters.institution_id ?? '',
    account_id: props.filters.account_id ?? '',
    person_id: props.filters.person_id ?? '',
    category_id: props.filters.category_id ?? '',
    from: props.filters.from ?? firstDayOfMonth(),
    to: props.filters.to ?? lastDayOfMonth(),
    kind: props.filters.kind ?? '',
    min_amount: props.filters.min_amount ?? '',
    max_amount: props.filters.max_amount ?? '',
});

const kindOptions = [
    { value: '', label: 'Tudo' },
    { value: 'credit_card', label: 'Cartão' },
    { value: 'account', label: 'Conta' },
    { value: 'transfers', label: 'Transferências e trocas' },
];

function setKind(value) {
    filters.kind = value;
    applyFilters();
}

/* ---------- Período ---------- */

/* The month being shown, when the range is exactly one full month. */
const selectedMonth = computed(() => {
    if (!filters.from) return null;
    const start = new Date(filters.from + 'T00:00:00');
    return filters.from === firstDayOfMonth(start) && filters.to === lastDayOfMonth(start) ? start : null;
});

const showCustomPeriod = ref(!selectedMonth.value);

const periodLabel = computed(() => {
    if (selectedMonth.value) {
        const label = selectedMonth.value.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
        return label.charAt(0).toUpperCase() + label.slice(1);
    }
    if (filters.from && filters.to) return `${formatDate(filters.from)} até ${formatDate(filters.to)}`;
    if (filters.from) return `A partir de ${formatDate(filters.from)}`;
    if (filters.to) return `Até ${formatDate(filters.to)}`;
    return 'Todo o período';
});

function goToMonth(offset) {
    const base = selectedMonth.value ?? (filters.from ? new Date(filters.from + 'T00:00:00') : new Date());
    const target = new Date(base.getFullYear(), base.getMonth() + offset, 1);
    filters.from = firstDayOfMonth(target);
    filters.to = lastDayOfMonth(target);
    showCustomPeriod.value = false;
    applyFilters();
}

function goToCurrentMonth() {
    filters.from = firstDayOfMonth();
    filters.to = lastDayOfMonth();
    showCustomPeriod.value = false;
    applyFilters();
}

function accountOptionLabel(account) {
    const extra = accountExtra(account);
    return `${personLabel(account)} · ${bankLabel(account)}${extra ? ` (${extra})` : ''}`;
}

/* ---------- Busca e limpeza ---------- */

let searchTimeout = null;

watch(
    () => filters.q,
    () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(applyFilters, 350);
    }
);

const resettableFilters = ['q', 'institution_id', 'account_id', 'person_id', 'category_id', 'kind', 'min_amount', 'max_amount'];

const activeFilterCount = computed(() => resettableFilters.filter((key) => filters[key] !== '' && filters[key] !== null).length);

function clearFilters() {
    for (const key of resettableFilters) filters[key] = '';
    applyFilters();
}

const accountsForFilterBank = computed(() =>
    props.accounts.filter(
        (a) =>
            (filters.institution_id === '' || a.institution?.id === Number(filters.institution_id)) &&
            (filters.person_id === '' || a.person?.id === Number(filters.person_id))
    )
);

function applyFilters() {
    router.get(route('finance.transactions.index'), filters, { preserveState: true, replace: true });
}

if (!props.filters.from && !props.filters.to) {
    applyFilters();
}

watch(
    () => [filters.institution_id, filters.person_id],
    () => {
        if (!accountsForFilterBank.value.some((a) => a.id === Number(filters.account_id))) {
            filters.account_id = '';
        }
    }
);

function destroyTransaction(transaction) {
    const message = transaction.transfer_id
        ? 'Remover esta transferência? Os dois lados (saída e entrada) serão removidos.'
        : 'Remover este lançamento?';

    if (confirm(message)) {
        useForm({}).delete(route('finance.transactions.destroy', transaction.id), { preserveScroll: true });
    }
}

const showEditModal = ref(false);
const editing = ref(null);

const editForm = useForm({
    account_id: '',
    category_id: '',
    type: 'expense',
    description: '',
    is_unknown: false,
    exclude_from_reports: false,
    amount: '',
    date: '',
});

function accountBankKey(account) {
    return account.institution?.id ?? 'none';
}

function accountBankLabel(account) {
    return account.institution?.name ?? 'Dinheiro';
}

const editBanks = computed(() => {
    const seen = new Map();

    for (const a of props.accounts) {
        seen.set(accountBankKey(a), accountBankLabel(a));
    }

    return [...seen.entries()]
        .map(([key, label]) => ({ key, label }))
        .sort((a, b) => a.label.localeCompare(b.label));
});

const editSelectedBank = ref('');

const editAccountsForBank = computed(() =>
    props.accounts.filter((a) => accountBankKey(a) === editSelectedBank.value)
);

watch(editSelectedBank, () => {
    if (!editAccountsForBank.value.some((a) => a.id === Number(editForm.account_id))) {
        editForm.account_id = editAccountsForBank.value[0]?.id ?? '';
    }
});

function openEdit(transaction) {
    if (transaction.transfer_id) {
        openEditTransfer(transaction);
        return;
    }

    editing.value = transaction;
    editSelectedBank.value = accountBankKey(transaction.account);
    editForm.account_id = transaction.account.id;
    editForm.category_id = transaction.category?.id ?? '';
    editForm.type = transaction.type;
    editForm.description = transaction.description;
    editForm.is_unknown = transaction.is_unknown;
    editForm.exclude_from_reports = transaction.exclude_from_reports;
    editForm.amount = transaction.amount;
    editForm.date = transaction.date.slice(0, 10);
    editForm.clearErrors();
    showEditModal.value = true;
}

function submitEdit() {
    editForm.put(route('finance.transactions.update', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => (showEditModal.value = false),
    });
}

const showEditTransferModal = ref(false);
const editingTransfer = ref(null);

const editTransferForm = useForm({
    from_account_id: '',
    to_account_id: '',
    amount: '',
    date: '',
    description: '',
    is_movement_only: true,
});

const editTransferFromBank = ref('');
const editTransferToBank = ref('');

const editTransferAccountsForFromBank = computed(() =>
    props.accounts.filter((a) => accountBankKey(a) === editTransferFromBank.value)
);
const editTransferAccountsForToBank = computed(() =>
    props.accounts.filter((a) => accountBankKey(a) === editTransferToBank.value)
);

watch(editTransferFromBank, () => {
    if (!editTransferAccountsForFromBank.value.some((a) => a.id === Number(editTransferForm.from_account_id))) {
        editTransferForm.from_account_id = editTransferAccountsForFromBank.value[0]?.id ?? '';
    }
});

watch(editTransferToBank, () => {
    if (!editTransferAccountsForToBank.value.some((a) => a.id === Number(editTransferForm.to_account_id))) {
        editTransferForm.to_account_id = editTransferAccountsForToBank.value[0]?.id ?? '';
    }
});

function openEditTransfer(transaction) {
    editingTransfer.value = transaction.transfer_id;
    editTransferFromBank.value = accountBankKey(transaction.transfer.from_account);
    editTransferToBank.value = accountBankKey(transaction.transfer.to_account);
    editTransferForm.from_account_id = transaction.transfer.from_account.id;
    editTransferForm.to_account_id = transaction.transfer.to_account.id;
    editTransferForm.amount = transaction.transfer.amount;
    editTransferForm.date = transaction.transfer.date.slice(0, 10);
    editTransferForm.description = transaction.transfer.description ?? '';
    editTransferForm.is_movement_only = transaction.transfer.is_movement_only;
    editTransferForm.clearErrors();
    showEditTransferModal.value = true;
}

function submitEditTransfer() {
    editTransferForm.put(route('finance.transfers.update', editingTransfer.value), {
        preserveScroll: true,
        onSuccess: () => (showEditTransferModal.value = false),
    });
}

function bankLabel(account) {
    return account?.institution?.name ?? 'Dinheiro';
}

function personLabel(account) {
    return account?.person?.name ?? account?.name ?? '?';
}

/* Account name only when it says something beyond the person (e.g. "Poupança"). */
function accountExtra(account) {
    return account && account.name !== account.person?.name ? account.name : null;
}

function cardLabel(invoice) {
    const card = invoice?.credit_card;
    return card ? `Cartão ${card.institution?.name ?? card.name}` : 'Cartão';
}

function invoiceMonthLabel(invoice) {
    if (!invoice?.reference_month) return '';
    const date = new Date(invoice.reference_month.slice(0, 10) + 'T00:00:00');
    return date.toLocaleDateString('pt-BR', { month: 'short', year: 'numeric' }).replace('. de ', '/').replace(' de ', '/');
}

const genericDescriptions = ['troca', 'transferência', 'transação', 'pagamento de fatura'];

function hasCustomDescription(transaction) {
    return !genericDescriptions.includes((transaction.description ?? '').trim().toLowerCase());
}

/*
 * What kind of line this is, so the list can explain itself:
 * swap/transfer (money moving between accounts, shown once per transfer),
 * card (purchase on a credit card invoice), invoice_payment, or a plain
 * account movement.
 */
function rowKind(transaction) {
    if (transaction.transfer_id) {
        return (transaction.description ?? '').trim().toLowerCase().startsWith('troca') ? 'swap' : 'transfer';
    }
    if (transaction.credit_card_invoice_id) return 'card';
    if (transaction.invoice_payment_id) return 'invoice_payment';
    return 'account';
}

const kindBadges = {
    swap: { label: 'Troca', class: 'bg-amber-100 text-amber-800' },
    transfer: { label: 'Transferência', class: 'bg-indigo-100 text-indigo-700' },
    card: { label: 'Cartão', class: 'bg-purple-100 text-purple-700' },
    invoice_payment: { label: 'Pagamento de fatura', class: 'bg-gray-100 text-gray-700' },
    account: null,
};

function excludedFromReports(transaction) {
    return (
        transaction.exclude_from_reports ||
        !!transaction.invoice_payment_id ||
        (!!transaction.transfer_id && transaction.transfer?.is_movement_only)
    );
}

/* ---------- Conferência (checklist local) ---------- */

const checkedStorageKey = 'transactions-index-checked';

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
    return checkedIds.value.has(transaction.id);
}

function toggleChecked(transaction) {
    const next = new Set(checkedIds.value);

    isChecked(transaction) ? next.delete(transaction.id) : next.add(transaction.id);

    checkedIds.value = next;
}

/*
 * A transfer has two legs (the "out" on one account and the "in" on the
 * other); show it once, keeping the outgoing leg when both are on the page.
 */
const visibleTransactions = computed(() => {
    const seenTransfers = new Map();
    const result = [];

    for (const transaction of props.transactions.data) {
        if (!transaction.transfer_id) {
            result.push(transaction);
            continue;
        }

        const index = seenTransfers.get(transaction.transfer_id);

        if (index === undefined) {
            seenTransfers.set(transaction.transfer_id, result.length);
            result.push(transaction);
        } else if (transaction.type === 'expense') {
            result[index] = transaction;
        }
    }

    return result;
});

const allChecked = computed(
    () => visibleTransactions.value.length > 0 && visibleTransactions.value.every((t) => checkedIds.value.has(t.id))
);

function toggleCheckAll() {
    checkedIds.value = allChecked.value ? new Set() : new Set(visibleTransactions.value.map((t) => t.id));
}

const groupedByDay = computed(() => {
    const groups = new Map();

    for (const transaction of visibleTransactions.value) {
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
    <Head title="Transações" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Transações</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div class="space-y-4 rounded-lg bg-white p-5 shadow">
                    <!-- Período -->
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-1">
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1.5 text-lg leading-none text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                                title="Mês anterior"
                                @click="goToMonth(-1)"
                            >
                                ‹
                            </button>
                            <span class="min-w-[11rem] text-center text-base font-semibold text-gray-800">{{ periodLabel }}</span>
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1.5 text-lg leading-none text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                                title="Próximo mês"
                                @click="goToMonth(1)"
                            >
                                ›
                            </button>
                        </div>
                        <div class="flex items-center gap-3 text-sm">
                            <button type="button" class="text-indigo-600 hover:text-indigo-800" @click="goToCurrentMonth">
                                Mês atual
                            </button>
                            <button type="button" class="text-gray-500 hover:text-gray-800" @click="showCustomPeriod = !showCustomPeriod">
                                {{ showCustomPeriod ? 'Fechar período' : 'Escolher datas' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="showCustomPeriod" class="grid grid-cols-2 gap-4 sm:max-w-md">
                        <div>
                            <InputLabel value="De" />
                            <TextInput v-model="filters.from" type="date" class="mt-1 block w-full" @change="applyFilters" />
                        </div>
                        <div>
                            <InputLabel value="Até" />
                            <TextInput v-model="filters.to" type="date" class="mt-1 block w-full" @change="applyFilters" />
                        </div>
                    </div>

                    <!-- Busca + tipo -->
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <TextInput v-model="filters.q" type="search" placeholder="Buscar pela descrição…" class="block w-full sm:max-w-xs" />
                        <div class="flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1">
                            <button
                                v-for="option in kindOptions"
                                :key="option.value"
                                type="button"
                                class="rounded-md px-3 py-1.5 text-sm transition"
                                :class="
                                    filters.kind === option.value
                                        ? 'bg-white font-medium text-gray-900 shadow-sm'
                                        : 'text-gray-600 hover:text-gray-900'
                                "
                                @click="setKind(option.value)"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Quem / onde / o quê -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        <div>
                            <InputLabel value="Pessoa" />
                            <SelectInput v-model="filters.person_id" class="mt-1 block w-full" @change="applyFilters">
                                <option value="">Todas</option>
                                <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel value="Banco" />
                            <SelectInput v-model="filters.institution_id" class="mt-1 block w-full" @change="applyFilters">
                                <option value="">Todos</option>
                                <option v-for="i in institutions" :key="i.id" :value="i.id">{{ i.name }}</option>
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel value="Conta" />
                            <SelectInput v-model="filters.account_id" class="mt-1 block w-full" @change="applyFilters">
                                <option value="">Todas</option>
                                <option v-for="a in accountsForFilterBank" :key="a.id" :value="a.id">
                                    {{ accountOptionLabel(a) }}
                                </option>
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel value="Categoria" />
                            <SelectInput v-model="filters.category_id" class="mt-1 block w-full" @change="applyFilters">
                                <option value="">Todas</option>
                                <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel value="Valor (R$)" />
                            <div class="mt-1 flex items-center gap-2">
                                <TextInput
                                    v-model="filters.min_amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    placeholder="mín."
                                    class="block w-full"
                                    @change="applyFilters"
                                />
                                <span class="text-gray-400">–</span>
                                <TextInput
                                    v-model="filters.max_amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    placeholder="máx."
                                    class="block w-full"
                                    @change="applyFilters"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t pt-3 text-sm text-gray-500">
                        <span>
                            {{ transactions.total }} {{ transactions.total === 1 ? 'lançamento' : 'lançamentos' }}
                            <template v-if="activeFilterCount">
                                · {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'filtro ativo' : 'filtros ativos' }}
                            </template>
                        </span>
                        <button v-if="activeFilterCount" type="button" class="text-red-600 hover:text-red-800" @click="clearFilters">
                            Limpar filtros
                        </button>
                    </div>

                    <details class="text-sm text-gray-600">
                        <summary class="cursor-pointer select-none text-gray-500 hover:text-gray-700">
                            O que significa cada tipo?
                        </summary>
                        <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                            <div>
                                <dt><span class="rounded bg-purple-100 px-1.5 py-0.5 text-[11px] font-semibold text-purple-700">Cartão</span></dt>
                                <dd class="mt-1">Compra feita no cartão de crédito. Entra na fatura do mês indicado, não sai da conta na hora.</dd>
                            </div>
                            <div>
                                <dt><span class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold text-gray-700">Pagamento de fatura</span></dt>
                                <dd class="mt-1">Saída da conta para pagar a fatura do cartão. Fica fora dos relatórios, porque as compras já foram contadas.</dd>
                            </div>
                            <div>
                                <dt><span class="rounded bg-indigo-100 px-1.5 py-0.5 text-[11px] font-semibold text-indigo-700">Transferência</span></dt>
                                <dd class="mt-1">Dinheiro que passou de uma conta para outra (de quem → para quem). Não é gasto nem receita.</dd>
                            </div>
                            <div>
                                <dt><span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800">Troca</span></dt>
                                <dd class="mt-1">Acerto de dívida entre duas pessoas em dois bancos: A paga B em um banco e B paga A no outro. Aparecem duas linhas, uma para cada lado, que se anulam.</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="font-medium text-gray-700">Sem selo</dt>
                                <dd class="mt-1">Entrada ou saída normal da conta (Pix, débito, salário…).</dd>
                            </div>
                        </dl>
                    </details>
                </div>

                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <div v-if="transactions.data.length > 0" class="flex items-center gap-2 border-b bg-gray-50 px-4 py-2 sm:px-6">
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
                                class="flex items-center justify-between px-4 py-3 sm:px-6"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <Checkbox :checked="isChecked(transaction)" @update:checked="toggleChecked(transaction)" />
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-gray-900">
                                            <span
                                                v-if="kindBadges[rowKind(transaction)]"
                                                class="rounded px-1.5 py-0.5 text-[11px] font-semibold"
                                                :class="kindBadges[rowKind(transaction)].class"
                                            >
                                                {{ kindBadges[rowKind(transaction)].label }}
                                            </span>

                                            <!-- Transfers/swaps: who paid whom -->
                                            <template v-if="transaction.transfer">
                                                <span>
                                                    {{ personLabel(transaction.transfer.from_account) }}
                                                    <span class="text-gray-400">→</span>
                                                    {{ personLabel(transaction.transfer.to_account) }}
                                                </span>
                                                <span v-if="hasCustomDescription(transaction)" class="text-gray-500">
                                                    · {{ transaction.description }}
                                                </span>
                                            </template>
                                            <span v-else>{{ transaction.description }}</span>

                                            <span v-if="transaction.installment_total" class="text-xs text-gray-500">
                                                parcela {{ transaction.installment_number }}/{{ transaction.installment_total }}
                                            </span>
                                            <span
                                                v-if="transaction.is_unknown"
                                                class="rounded bg-yellow-100 px-1.5 py-0.5 text-[11px] font-semibold text-yellow-700"
                                            >
                                                Desconhecida
                                            </span>
                                        </p>

                                        <p class="mt-0.5 text-xs text-gray-500">
                                            <template v-if="transaction.transfer">
                                                {{ bankLabel(transaction.transfer.from_account) }}
                                                <span class="text-gray-400">→</span>
                                                {{ bankLabel(transaction.transfer.to_account) }}
                                            </template>
                                            <template v-else-if="rowKind(transaction) === 'card'">
                                                {{ personLabel(transaction.account) }}
                                                · {{ cardLabel(transaction.credit_card_invoice) }}
                                                · fatura de {{ invoiceMonthLabel(transaction.credit_card_invoice) }}
                                            </template>
                                            <template v-else>
                                                {{ personLabel(transaction.account) }} · {{ bankLabel(transaction.account) }}
                                                <template v-if="accountExtra(transaction.account)">
                                                    ({{ accountExtra(transaction.account) }})
                                                </template>
                                                <template v-if="transaction.invoice_payment">
                                                    · paga a fatura do {{ cardLabel(transaction.invoice_payment) }}
                                                </template>
                                            </template>
                                            <template v-if="transaction.category"> · {{ transaction.category.name }}</template>
                                            <span v-if="excludedFromReports(transaction)" class="italic text-gray-400">
                                                · fora dos relatórios
                                            </span>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span v-if="transaction.transfer" class="text-gray-600">
                                        {{ formatMoney(transaction.amount) }}
                                    </span>
                                    <span v-else :class="transaction.type === 'income' ? 'text-green-600' : 'text-red-600'">
                                        {{ transaction.type === 'income' ? '+' : '-' }}{{ formatMoney(transaction.amount) }}
                                    </span>
                                    <button class="text-sm text-indigo-600 hover:text-indigo-900" @click="openEdit(transaction)">
                                        Editar
                                    </button>
                                    <button class="text-sm text-red-600 hover:text-red-900" @click="destroyTransaction(transaction)">
                                        Remover
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <p v-if="transactions.data.length === 0" class="px-4 py-6 text-sm text-gray-500 sm:px-6">
                        Nenhum lançamento encontrado.
                    </p>

                    <div v-if="transactions.links.length > 3" class="flex flex-wrap gap-2 border-t px-4 py-3 sm:px-6">
                        <Link
                            v-for="link in transactions.links"
                            :key="link.label"
                            :href="link.url ?? '#'"
                            v-html="link.label"
                            class="rounded px-3 py-1 text-sm"
                            :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <Modal :show="showEditModal" max-width="lg" @close="showEditModal = false">
        <form class="p-6" @submit.prevent="submitEdit">
            <h2 class="text-lg font-medium text-gray-900">Editar lançamento</h2>

            <div class="mt-6 grid grid-cols-2 gap-4">
                <div class="col-span-2">
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
                        :placeholder="editForm.is_unknown ? 'Desconhecido (opcional preencher)' : ''"
                        :required="!editForm.is_unknown"
                    />
                    <InputError class="mt-2" :message="editForm.errors.description" />
                </div>

                <div>
                    <InputLabel for="edit_amount" value="Valor" />
                    <TextInput id="edit_amount" v-model="editForm.amount" type="number" step="0.01" min="0.01" class="mt-1 block w-full" required />
                    <InputError class="mt-2" :message="editForm.errors.amount" />
                </div>

                <div>
                    <InputLabel for="edit_type" value="Tipo" />
                    <SelectInput id="edit_type" v-model="editForm.type" class="mt-1 block w-full">
                        <option value="income">Receita</option>
                        <option value="expense">Despesa</option>
                    </SelectInput>
                    <InputError class="mt-2" :message="editForm.errors.type" />
                </div>

                <div>
                    <InputLabel for="edit_bank" value="Banco" />
                    <SelectInput id="edit_bank" v-model="editSelectedBank" class="mt-1 block w-full">
                        <option v-for="b in editBanks" :key="b.key" :value="b.key">{{ b.label }}</option>
                    </SelectInput>
                </div>

                <div>
                    <InputLabel for="edit_account_id" value="Conta" />
                    <SelectInput id="edit_account_id" v-model="editForm.account_id" class="mt-1 block w-full">
                        <option v-for="a in editAccountsForBank" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </SelectInput>
                    <InputError class="mt-2" :message="editForm.errors.account_id" />
                </div>

                <div>
                    <InputLabel for="edit_date" value="Data" />
                    <TextInput id="edit_date" v-model="editForm.date" type="date" class="mt-1 block w-full" required />
                    <InputError class="mt-2" :message="editForm.errors.date" />
                </div>

                <div>
                    <InputLabel for="edit_category_id" value="Categoria" />
                    <SelectInput id="edit_category_id" v-model="editForm.category_id" class="mt-1 block w-full">
                        <option value="">Sem categoria</option>
                        <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
                    </SelectInput>
                    <InputError class="mt-2" :message="editForm.errors.category_id" />
                </div>

                <div class="col-span-2">
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <Checkbox v-model:checked="editForm.exclude_from_reports" />
                        Desconsiderar dos relatórios
                    </label>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="showEditModal = false">Cancelar</SecondaryButton>
                <PrimaryButton :disabled="editForm.processing">Salvar</PrimaryButton>
            </div>
        </form>
    </Modal>

    <Modal :show="showEditTransferModal" max-width="lg" @close="showEditTransferModal = false">
        <form class="p-6" @submit.prevent="submitEditTransfer">
            <h2 class="text-lg font-medium text-gray-900">Editar transferência</h2>

            <div class="mt-6 space-y-4">
                <div>
                    <InputLabel value="De" />
                    <div class="mt-1 grid grid-cols-2 gap-4">
                        <SelectInput v-model="editTransferFromBank" class="block w-full">
                            <option v-for="b in editBanks" :key="b.key" :value="b.key">{{ b.label }}</option>
                        </SelectInput>
                        <div>
                            <SelectInput id="edit_transfer_from" v-model="editTransferForm.from_account_id" class="block w-full">
                                <option v-for="a in editTransferAccountsForFromBank" :key="a.id" :value="a.id">
                                    {{ a.name }}
                                </option>
                            </SelectInput>
                            <InputError class="mt-2" :message="editTransferForm.errors.from_account_id" />
                        </div>
                    </div>
                </div>

                <div>
                    <InputLabel value="Para" />
                    <div class="mt-1 grid grid-cols-2 gap-4">
                        <SelectInput v-model="editTransferToBank" class="block w-full">
                            <option v-for="b in editBanks" :key="b.key" :value="b.key">{{ b.label }}</option>
                        </SelectInput>
                        <div>
                            <SelectInput id="edit_transfer_to" v-model="editTransferForm.to_account_id" class="block w-full">
                                <option v-for="a in editTransferAccountsForToBank" :key="a.id" :value="a.id">
                                    {{ a.name }}
                                </option>
                            </SelectInput>
                            <InputError class="mt-2" :message="editTransferForm.errors.to_account_id" />
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="edit_transfer_amount" value="Valor" />
                        <TextInput
                            id="edit_transfer_amount"
                            v-model="editTransferForm.amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            class="mt-1 block w-full"
                            required
                        />
                        <InputError class="mt-2" :message="editTransferForm.errors.amount" />
                    </div>

                    <div>
                        <InputLabel for="edit_transfer_date" value="Data" />
                        <TextInput id="edit_transfer_date" v-model="editTransferForm.date" type="date" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="editTransferForm.errors.date" />
                    </div>
                </div>

                <div>
                    <InputLabel for="edit_transfer_description" value="Descrição (opcional)" />
                    <TextInput
                        id="edit_transfer_description"
                        v-model="editTransferForm.description"
                        class="mt-1 block w-full"
                    />
                    <InputError class="mt-2" :message="editTransferForm.errors.description" />
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <Checkbox v-model:checked="editTransferForm.is_movement_only" />
                    Apenas movimentação (não contar no relatório)
                </label>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="showEditTransferModal = false">Cancelar</SecondaryButton>
                <PrimaryButton :disabled="editTransferForm.processing">Salvar</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
