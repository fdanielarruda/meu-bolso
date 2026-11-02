<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { categoryTreeOptions, formatDate, formatMoney } from '@/finance';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    accounts: Array,
    categories: Array,
});

const categoryOptions = computed(() => categoryTreeOptions(props.categories));

function toIsoDate(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function daysAgo(days) {
    const date = new Date();
    date.setDate(date.getDate() - days);
    return toIsoDate(date);
}

const today = daysAgo(0);
const yesterday = daysAgo(1);

function bankKey(a) {
    return a.institution?.id ?? 'none';
}

function bankLabel(a) {
    return a.institution?.name ?? 'Dinheiro';
}

function personLabel(a) {
    return a?.person?.name ?? a?.name ?? '';
}

/* Account name only when it says something beyond the person (e.g. "Poupança"). */
function accountExtra(a) {
    return a && a.name !== a.person?.name ? a.name : null;
}

function accountLabel(a) {
    return accountExtra(a) ?? personLabel(a);
}

/* ---------- Tipo do lançamento ---------- */

const modes = {
    expense: { label: 'Despesa', icon: '↑', active: 'bg-red-600 text-white', button: 'bg-red-600 hover:bg-red-700', amount: 'text-red-600' },
    income: { label: 'Receita', icon: '↓', active: 'bg-green-600 text-white', button: 'bg-green-600 hover:bg-green-700', amount: 'text-green-600' },
    transfer: { label: 'Transferência', icon: '⇄', active: 'bg-indigo-600 text-white', button: 'bg-indigo-600 hover:bg-indigo-700', amount: 'text-indigo-600' },
};

const mode = ref('expense');

/* ---------- Conta de origem ---------- */

const showTransient = ref(false);

const visibleAccounts = computed(() => props.accounts.filter((a) => showTransient.value || !a.transient));

const banks = computed(() => {
    const seen = new Map();

    for (const a of visibleAccounts.value) {
        seen.set(bankKey(a), bankLabel(a));
    }

    return [...seen.entries()]
        .map(([key, label]) => ({ key, label }))
        .sort((a, b) => a.label.localeCompare(b.label));
});

const selectedBank = ref(banks.value[0]?.key ?? '');

const accountsForBank = computed(() =>
    visibleAccounts.value
        .filter((a) => bankKey(a) === selectedBank.value)
        .sort((a, b) => (a.type === 'investment') - (b.type === 'investment') || (a.transient ?? false) - (b.transient ?? false))
);

const form = useForm({
    amount: '',
    description: '',
    is_unknown: false,
    account_id: accountsForBank.value[0]?.id ?? '',
    category_id: '',
    date: today,
    type: 'expense',
});

watch(selectedBank, () => {
    if (!accountsForBank.value.some((a) => a.id === Number(form.account_id))) {
        form.account_id = accountsForBank.value[0]?.id ?? '';
    }
});

watch(showTransient, () => {
    if (!banks.value.some((b) => b.key === selectedBank.value)) {
        selectedBank.value = banks.value[0]?.key ?? '';
    }
});

const selectedAccount = computed(() => props.accounts.find((a) => a.id === Number(form.account_id)) ?? null);

/* Clicking an account in the balances panel picks it as the source. */
function pickAccount(a) {
    if (a.transient) showTransient.value = true;
    selectedBank.value = bankKey(a);
    form.account_id = a.id;
}

/* ---------- Transferência ---------- */

const transferForm = useForm({
    from_account_id: '',
    to_account_id: '',
    amount: '',
    description: '',
    date: today,
    is_movement_only: true,
});

const transferTargetGroups = computed(() => {
    const groups = new Map();

    for (const a of visibleAccounts.value) {
        if (a.id === Number(form.account_id)) continue;
        const label = bankLabel(a);
        if (!groups.has(label)) groups.set(label, []);
        groups.get(label).push(a);
    }

    return [...groups.entries()]
        .map(([bank, accounts]) => ({ bank, accounts: accounts.sort((x, y) => accountLabel(x).localeCompare(accountLabel(y))) }))
        .sort((a, b) => a.bank.localeCompare(b.bank));
});

watch(
    () => form.account_id,
    (accountId) => {
        if (Number(transferForm.to_account_id) === Number(accountId)) {
            transferForm.to_account_id = '';
        }
    }
);

const transferTarget = computed(() => props.accounts.find((a) => a.id === Number(transferForm.to_account_id)) ?? null);

/* ---------- Envio ---------- */

const lastSaved = ref(null);
let lastSavedTimeout = null;

function flashSaved(message) {
    lastSaved.value = message;
    clearTimeout(lastSavedTimeout);
    lastSavedTimeout = setTimeout(() => (lastSaved.value = null), 4000);
}

const processing = computed(() => form.processing || transferForm.processing);

const canSubmit = computed(() => {
    if (processing.value || !form.amount || !form.account_id) return false;
    if (mode.value === 'transfer') return !!transferForm.to_account_id;
    return !!form.description || form.is_unknown;
});

const submitLabel = computed(() => {
    const amount = form.amount ? formatMoney(form.amount) : '';
    const from = selectedAccount.value ? `${accountLabel(selectedAccount.value)} · ${bankLabel(selectedAccount.value)}` : '';

    if (mode.value === 'transfer') {
        const to = transferTarget.value ? `${accountLabel(transferTarget.value)} · ${bankLabel(transferTarget.value)}` : '…';
        return `Transferir ${amount} de ${from} para ${to}`;
    }

    return `Lançar ${modes[mode.value].label.toLowerCase()} ${amount} em ${from}`;
});

function submit() {
    if (!canSubmit.value) return;

    if (mode.value === 'transfer') {
        transferForm.from_account_id = form.account_id;
        transferForm.amount = form.amount;
        transferForm.description = form.description || 'Transferência';
        transferForm.date = form.date;

        const label = submitLabel.value.replace('Transferir', 'Transferido');

        transferForm.post(route('finance.transfers.store'), {
            preserveScroll: true,
            onSuccess: () => {
                flashSaved(label);
                form.reset('amount', 'description');
                transferForm.reset('amount', 'description', 'to_account_id');
            },
        });

        return;
    }

    form.type = mode.value;
    const label = `${modes[mode.value].label} de ${formatMoney(form.amount)} lançada · ${form.description || 'Desconhecido'}`;

    form.post(route('finance.transactions.store'), {
        preserveScroll: true,
        onSuccess: () => {
            flashSaved(label);
            form.reset('amount', 'description', 'is_unknown');
        },
    });
}

/* ---------- Painel de saldos ---------- */

const cardOrder = reactive(JSON.parse(localStorage.getItem('quickCardOrder') ?? '[]'));

function saveCardOrder() {
    localStorage.setItem('quickCardOrder', JSON.stringify(cardOrder));
}

function cardOrderIndex(key) {
    const index = cardOrder.indexOf(key);
    return index === -1 ? Number.MAX_SAFE_INTEGER : index;
}

function moveCard(key, direction) {
    // Swap with the neighbour actually on screen (hidden cards are skipped).
    const visible = visibleBankCards.value;
    const visibleIndex = visible.findIndex((c) => c.key === key);
    const neighbour = visible[visibleIndex + direction];

    if (visibleIndex === -1 || !neighbour) {
        return;
    }

    const currentOrder = bankCards.value.map((c) => c.key);
    const index = currentOrder.indexOf(key);
    const targetIndex = currentOrder.indexOf(neighbour.key);
    [currentOrder[index], currentOrder[targetIndex]] = [currentOrder[targetIndex], currentOrder[index]];

    cardOrder.splice(0, cardOrder.length, ...currentOrder);
    saveCardOrder();
}

const bankGroups = computed(() => {
    const groups = new Map();

    for (const a of props.accounts) {
        const key = bankLabel(a);

        if (!groups.has(key)) {
            groups.set(key, {
                bank: key,
                bankId: a.institution?.id ?? null,
                accounts: [],
                investmentAccounts: [],
                total: 0,
                investmentTotal: 0,
            });
        }

        const group = groups.get(key);
        const balance = a.balance !== null ? Number(a.balance) : 0;

        if (a.type === 'investment') {
            group.investmentAccounts.push(a);
            group.investmentTotal += balance;
        } else {
            group.accounts.push(a);
            group.total += balance;
        }
    }

    return [...groups.values()].sort((a, b) => a.bank.localeCompare(b.bank));
});

const bankCards = computed(() => {
    const cards = [];

    for (const group of bankGroups.value) {
        if (group.accounts.length > 0) {
            cards.push({ key: `${group.bank}::normal`, kind: 'normal', group, accounts: group.accounts, total: group.total });
        }

        if (group.investmentAccounts.length > 0) {
            cards.push({
                key: `${group.bank}::invest`,
                kind: 'invest',
                group,
                accounts: group.investmentAccounts,
                total: group.investmentTotal,
            });
        }
    }

    return cards.sort((a, b) => {
        const diff = cardOrderIndex(a.key) - cardOrderIndex(b.key);
        return diff !== 0 ? diff : a.key.localeCompare(b.key);
    });
});

const reorderingCards = ref(false);

const hideZero = ref(localStorage.getItem('quickHideZero') !== 'false');

watch(hideZero, (value) => localStorage.setItem('quickHideZero', String(value)));

function isZeroBalance(a) {
    return a.balance === null || Math.abs(Number(a.balance)) < 0.005;
}

/* Zero-balance accounts are noise in the panel, except the one currently picked. */
function showsInPanel(a) {
    return !hideZero.value || !isZeroBalance(a) || a.id === Number(form.account_id);
}

function byBalance(a, b) {
    return Number(b.balance ?? 0) - Number(a.balance ?? 0);
}

const visibleBankCards = computed(() =>
    bankCards.value
        .map((card) => ({ ...card, visibleAccounts: card.accounts.filter(showsInPanel).sort(byBalance) }))
        .filter((card) => card.visibleAccounts.length > 0)
);

const hiddenZeroCount = computed(() => props.accounts.filter((a) => !showsInPanel(a)).length);

const showRecentModal = ref(false);
const recentBankLabel = ref('');
const recentTransactions = ref([]);
const loadingRecent = ref(false);

function openRecent(group) {
    recentBankLabel.value = group.bank;
    showRecentModal.value = true;
    loadingRecent.value = true;
    recentTransactions.value = [];

    window.axios
        .get(route('finance.transactions.recent-by-bank'), { params: { institution_id: group.bankId } })
        .then((response) => {
            recentTransactions.value = response.data.transactions;
        })
        .finally(() => {
            loadingRecent.value = false;
        });
}

/* ---------- Histórico de uma conta ---------- */

const historyKinds = [
    { value: 'account', label: 'Conta', badge: null },
    { value: 'card', label: 'Cartão', badge: 'bg-purple-100 text-purple-700' },
    { value: 'invoice_payment', label: 'Pagamento de fatura', badge: 'bg-gray-100 text-gray-700' },
    { value: 'transfer', label: 'Transferência', badge: 'bg-indigo-100 text-indigo-700' },
    { value: 'swap', label: 'Troca', badge: 'bg-amber-100 text-amber-800' },
];

const historyAccount = ref(null);
const historySelectedKinds = ref(historyKinds.map((k) => k.value));
const historyIncludeFuture = ref(false);
const historyPage = ref(1);
const historyData = ref(null);
const loadingHistory = ref(false);
let historyRequestId = 0;

function historyRowKind(transaction) {
    if (transaction.transfer_id) {
        return (transaction.description ?? '').trim().toLowerCase().startsWith('troca') ? 'swap' : 'transfer';
    }
    if (transaction.credit_card_invoice_id) return 'card';
    if (transaction.invoice_payment_id) return 'invoice_payment';
    return 'account';
}

function historyKindInfo(transaction) {
    return historyKinds.find((k) => k.value === historyRowKind(transaction));
}

/* The other side of a transfer, seen from the account whose history is open. */
function transferCounterpart(transaction) {
    const transfer = transaction.transfer;
    if (!transfer) return null;
    const outgoing = transfer.from_account?.id === transaction.account_id;
    const other = outgoing ? transfer.to_account : transfer.from_account;
    return `${outgoing ? 'para' : 'de'} ${accountLabel(other)} · ${bankLabel(other)}`;
}

function loadHistory() {
    if (!historyAccount.value) return;

    if (historySelectedKinds.value.length === 0) {
        historyData.value = { data: [], total: 0, current_page: 1, last_page: 1 };
        return;
    }

    const requestId = ++historyRequestId;
    const allKinds = historySelectedKinds.value.length === historyKinds.length;
    loadingHistory.value = true;

    window.axios
        .get(route('finance.transactions.account-history'), {
            params: {
                account_id: historyAccount.value.id,
                kinds: allKinds ? undefined : historySelectedKinds.value,
                include_future: historyIncludeFuture.value ? 1 : 0,
                page: historyPage.value,
                per_page: 20,
            },
        })
        .then((response) => {
            if (requestId === historyRequestId) historyData.value = response.data;
        })
        .finally(() => {
            if (requestId === historyRequestId) loadingHistory.value = false;
        });
}

function openHistory(a) {
    historyAccount.value = a;
    historyPage.value = 1;
    historyData.value = null;
    loadHistory();
}

function closeHistory() {
    historyAccount.value = null;
}

function toggleHistoryKind(value) {
    const selected = historySelectedKinds.value;
    historySelectedKinds.value = selected.includes(value) ? selected.filter((k) => k !== value) : [...selected, value];
    historyPage.value = 1;
    loadHistory();
}

function toggleHistoryFuture(value) {
    historyIncludeFuture.value = value;
    historyPage.value = 1;
    loadHistory();
}

function goToHistoryPage(page) {
    historyPage.value = page;
    loadHistory();
}

function useHistoryAccount() {
    pickAccount(historyAccount.value);
    closeHistory();
}

const collapsedGroups = reactive(new Set(JSON.parse(localStorage.getItem('quickCollapsedGroups') ?? '[]')));

function toggleGroup(key) {
    if (collapsedGroups.has(key)) {
        collapsedGroups.delete(key);
    } else {
        collapsedGroups.add(key);
    }

    localStorage.setItem('quickCollapsedGroups', JSON.stringify([...collapsedGroups]));
}
</script>

<template>
    <Head title="Lançamento rápido" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Lançamento rápido</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto grid max-w-5xl grid-cols-1 gap-6 sm:px-6 lg:grid-cols-3 lg:px-8">
                <form class="space-y-6 rounded-lg bg-white p-6 shadow lg:col-span-2" @submit.prevent="submit">
                    <!-- 1. O que é -->
                    <div class="grid grid-cols-3 gap-1 rounded-lg bg-gray-100 p-1">
                        <button
                            v-for="(m, key) in modes"
                            :key="key"
                            type="button"
                            class="rounded-md px-3 py-2 text-sm font-semibold transition"
                            :class="mode === key ? m.active + ' shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                            @click="mode = key"
                        >
                            {{ m.icon }} {{ m.label }}
                        </button>
                    </div>

                    <!-- 2. Quanto e o quê -->
                    <div>
                        <InputLabel for="amount" value="Valor" />
                        <div class="relative mt-1">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-xl text-gray-400">R$</span>
                            <TextInput
                                id="amount"
                                v-model="form.amount"
                                type="number"
                                inputmode="decimal"
                                step="0.01"
                                min="0.01"
                                placeholder="0,00"
                                class="block w-full pl-12 text-3xl font-semibold"
                                :class="modes[mode].amount"
                                autofocus
                                required
                            />
                        </div>
                        <InputError class="mt-2" :message="form.errors.amount || transferForm.errors.amount" />
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <InputLabel for="description" :value="mode === 'transfer' ? 'Descrição (opcional)' : 'Descrição'" />
                            <label v-if="mode !== 'transfer'" class="flex items-center gap-2 text-sm text-gray-600">
                                <Checkbox v-model:checked="form.is_unknown" />
                                Não sei o que foi
                            </label>
                        </div>
                        <TextInput
                            id="description"
                            v-model="form.description"
                            class="mt-1 block w-full"
                            :placeholder="
                                mode === 'transfer'
                                    ? 'Transferência'
                                    : form.is_unknown
                                      ? 'Desconhecido (opcional preencher)'
                                      : mode === 'income'
                                        ? 'Ex: Salário, Pix recebido…'
                                        : 'Ex: Mercado, Uber…'
                            "
                        />
                        <InputError class="mt-2" :message="form.errors.description" />
                    </div>

                    <div class="space-y-4">
                        <div>
                            <InputLabel for="date" value="Data" />
                            <div class="mt-1 flex gap-2">
                                <button
                                    v-for="option in [
                                        { value: today, label: 'Hoje' },
                                        { value: yesterday, label: 'Ontem' },
                                    ]"
                                    :key="option.label"
                                    type="button"
                                    class="rounded-md border px-3 text-sm"
                                    :class="
                                        form.date === option.value
                                            ? 'border-indigo-600 bg-indigo-50 font-medium text-indigo-700'
                                            : 'border-gray-300 text-gray-600 hover:bg-gray-50'
                                    "
                                    @click="form.date = option.value"
                                >
                                    {{ option.label }}
                                </button>
                                <TextInput id="date" v-model="form.date" type="date" class="block w-full" required />
                            </div>
                            <InputError class="mt-2" :message="form.errors.date" />
                        </div>

                        <div v-if="mode !== 'transfer'">
                            <InputLabel for="category_id" value="Categoria (opcional)" />
                            <SelectInput id="category_id" v-model="form.category_id" class="mt-1 block w-full">
                                <option value="">Sem categoria</option>
                                <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
                            </SelectInput>
                        </div>
                    </div>

                    <!-- 3. De qual conta -->
                    <div class="space-y-3 rounded-lg border border-gray-200 p-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-700">
                                {{ mode === 'income' ? 'Entra em' : 'Sai de' }}
                            </h3>
                            <label class="flex items-center gap-2 text-xs text-gray-500" title="Pessoas eventuais (transeuntes)">
                                <Checkbox v-model:checked="showTransient" />
                                Mostrar pessoas eventuais
                            </label>
                        </div>

                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Banco</p>
                            <div class="mt-1 flex flex-wrap gap-2">
                                <button
                                    v-for="b in banks"
                                    :key="b.key"
                                    type="button"
                                    class="rounded-md border px-3 py-1.5 text-sm font-medium"
                                    :class="
                                        selectedBank === b.key
                                            ? 'border-indigo-600 bg-indigo-600 text-white'
                                            : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                                    "
                                    @click="selectedBank = b.key"
                                >
                                    {{ b.label }}
                                </button>
                            </div>
                        </div>

                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">De quem é a conta</p>
                            <div class="mt-1 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <button
                                    v-for="a in accountsForBank"
                                    :key="a.id"
                                    type="button"
                                    class="rounded-md border px-3 py-2 text-left text-sm"
                                    :class="
                                        Number(form.account_id) === a.id
                                            ? 'border-indigo-600 bg-indigo-50 ring-1 ring-indigo-600'
                                            : 'border-gray-300 hover:bg-gray-50'
                                    "
                                    @click="form.account_id = a.id"
                                >
                                    <span class="block truncate font-medium text-gray-900">{{ accountLabel(a) }}</span>
                                    <span
                                        v-if="a.balance !== null"
                                        class="block text-xs"
                                        :class="Number(a.balance) < 0 ? 'text-red-600' : 'text-gray-500'"
                                    >
                                        saldo {{ formatMoney(a.balance) }}
                                    </span>
                                </button>
                            </div>
                            <InputError class="mt-2" :message="form.errors.account_id || transferForm.errors.from_account_id" />
                        </div>
                    </div>

                    <!-- 4. Para onde (transferência) -->
                    <div v-if="mode === 'transfer'" class="space-y-3 rounded-lg border border-indigo-200 bg-indigo-50/40 p-4">
                        <h3 class="text-sm font-semibold text-gray-700">Vai para</h3>
                        <SelectInput id="to_account_id" v-model="transferForm.to_account_id" class="block w-full">
                            <option value="" disabled>Escolha a conta de destino</option>
                            <optgroup v-for="g in transferTargetGroups" :key="g.bank" :label="g.bank">
                                <option v-for="a in g.accounts" :key="a.id" :value="a.id">{{ accountLabel(a) }}</option>
                            </optgroup>
                        </SelectInput>
                        <InputError :message="transferForm.errors.to_account_id" />

                        <label class="flex items-start gap-2 text-sm text-gray-600">
                            <Checkbox v-model:checked="transferForm.is_movement_only" class="mt-0.5" />
                            <span>
                                Só mover dinheiro (fora dos relatórios)
                                <span class="block text-xs text-gray-400">
                                    Desmarque se a transferência for um pagamento de verdade que deve aparecer como gasto/receita.
                                </span>
                            </span>
                        </label>
                    </div>

                    <!-- 5. Confirmar -->
                    <div class="space-y-3">
                        <button
                            type="submit"
                            class="flex w-full items-center justify-center rounded-md px-4 py-3 text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-40"
                            :class="modes[mode].button"
                            :disabled="!canSubmit"
                        >
                            <span class="truncate">{{ form.amount ? submitLabel : `${modes[mode].icon} Lançar ${modes[mode].label.toLowerCase()}` }}</span>
                        </button>

                        <p
                            v-if="lastSaved"
                            class="rounded-md bg-green-50 px-3 py-2 text-center text-sm text-green-700"
                        >
                            ✓ {{ lastSaved }}
                        </p>
                    </div>
                </form>

                <!-- Saldos -->
                <div
                    class="flex max-h-[70vh] flex-col self-start rounded-lg bg-white p-5 shadow lg:sticky lg:top-6 lg:max-h-[calc(100vh-3rem)]"
                >
                    <div class="flex shrink-0 items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-700">Saldos</h3>
                        <div class="flex items-center gap-3 text-xs">
                            <button type="button" class="text-gray-400 hover:text-gray-700" @click="hideZero = !hideZero">
                                {{ hideZero ? 'Mostrar zerados' : 'Ocultar zerados' }}
                            </button>
                            <button
                                type="button"
                                class="text-gray-400 hover:text-gray-700"
                                @click="reorderingCards = !reorderingCards"
                            >
                                {{ reorderingCards ? 'Pronto' : 'Reordenar' }}
                            </button>
                        </div>
                    </div>

                    <div class="thin-scrollbar -mr-2 mt-4 min-h-0 flex-1 space-y-5 overflow-y-auto pr-2">
                        <div v-for="(card, cardIndex) in visibleBankCards" :key="card.key" class="group">
                            <div class="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    class="flex min-w-0 flex-1 items-baseline gap-1.5 text-left"
                                    :title="collapsedGroups.has(card.key) ? 'Mostrar contas' : 'Esconder contas'"
                                    @click="toggleGroup(card.key)"
                                >
                                    <span class="truncate text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        {{ card.group.bank }}
                                    </span>
                                    <span v-if="card.kind === 'invest'" class="shrink-0 text-[10px] font-medium text-indigo-500">
                                        investimentos
                                    </span>
                                    <span v-if="collapsedGroups.has(card.key)" class="shrink-0 text-xs text-gray-300">
                                        · {{ card.visibleAccounts.length }}
                                    </span>
                                    <span
                                        class="ml-auto shrink-0 text-xs font-semibold"
                                        :class="card.total < 0 ? 'text-red-600' : 'text-gray-500'"
                                    >
                                        {{ formatMoney(card.total) }}
                                    </span>
                                </button>

                                <button
                                    v-if="!reorderingCards"
                                    type="button"
                                    title="Ver últimos lançamentos"
                                    class="shrink-0 rounded p-0.5 text-gray-300 hover:bg-gray-100 hover:text-indigo-600"
                                    @click="openRecent(card.group)"
                                >
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path
                                            fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-12.25a.75.75 0 00-1.5 0V10c0 .2.08.39.22.53l2.5 2.5a.75.75 0 101.06-1.06l-2.28-2.28V5.75z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>

                                <span v-if="reorderingCards" class="flex shrink-0 items-center gap-0.5">
                                    <button
                                        type="button"
                                        title="Mover para cima"
                                        class="rounded px-1 text-xs text-gray-500 hover:bg-gray-100 disabled:opacity-30"
                                        :disabled="cardIndex === 0"
                                        @click="moveCard(card.key, -1)"
                                    >
                                        ▲
                                    </button>
                                    <button
                                        type="button"
                                        title="Mover para baixo"
                                        class="rounded px-1 text-xs text-gray-500 hover:bg-gray-100 disabled:opacity-30"
                                        :disabled="cardIndex === visibleBankCards.length - 1"
                                        @click="moveCard(card.key, 1)"
                                    >
                                        ▼
                                    </button>
                                </span>
                            </div>

                            <ul v-if="!collapsedGroups.has(card.key)" class="mt-1">
                                <li v-for="a in card.visibleAccounts" :key="a.id">
                                    <button
                                        type="button"
                                        title="Ver histórico de lançamentos"
                                        class="-mx-2 flex w-[calc(100%+1rem)] items-center justify-between rounded px-2 py-1 text-left text-sm hover:bg-gray-50"
                                        :class="Number(form.account_id) === a.id ? 'bg-indigo-50 hover:bg-indigo-50' : ''"
                                        @click="openHistory(a)"
                                    >
                                        <span class="truncate" :class="a.transient ? 'text-gray-400' : 'text-gray-700'">
                                            {{ accountLabel(a) }}
                                        </span>
                                        <span
                                            v-if="a.balance !== null"
                                            class="shrink-0 tabular-nums"
                                            :class="Number(a.balance) < 0 ? 'text-red-600' : 'text-gray-900'"
                                        >
                                            {{ formatMoney(a.balance) }}
                                        </span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <p v-if="hideZero && hiddenZeroCount" class="mt-3 shrink-0 border-t pt-2 text-xs text-gray-400">
                        {{ hiddenZeroCount }} {{ hiddenZeroCount === 1 ? 'conta zerada oculta' : 'contas zeradas ocultas' }}
                    </p>

                    <p v-if="accounts.length === 0" class="mt-3 py-2 text-sm text-gray-500">Nenhuma conta cadastrada.</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <Modal :show="!!historyAccount" max-width="2xl" @close="closeHistory">
        <div v-if="historyAccount" class="flex max-h-[85vh] flex-col p-6">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="truncate text-lg font-medium text-gray-900">
                        {{ accountLabel(historyAccount) }} · {{ bankLabel(historyAccount) }}
                    </h2>
                    <p class="text-sm text-gray-500">
                        Saldo atual
                        <span
                            class="font-semibold"
                            :class="Number(historyAccount.balance) < 0 ? 'text-red-600' : 'text-gray-900'"
                        >
                            {{ formatMoney(historyAccount.balance) }}
                        </span>
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <button
                        type="button"
                        class="rounded-md border border-indigo-600 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50"
                        @click="useHistoryAccount"
                    >
                        Usar no lançamento
                    </button>
                    <button type="button" class="text-xl leading-none text-gray-400 hover:text-gray-700" title="Fechar" @click="closeHistory">
                        ×
                    </button>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 border-b pb-3">
                <label v-for="k in historyKinds" :key="k.value" class="flex items-center gap-1.5 text-sm text-gray-700">
                    <Checkbox
                        :checked="historySelectedKinds.includes(k.value)"
                        @update:checked="toggleHistoryKind(k.value)"
                    />
                    {{ k.label }}
                </label>
                <label class="ml-auto flex items-center gap-1.5 text-sm text-gray-500">
                    <Checkbox :checked="historyIncludeFuture" @update:checked="toggleHistoryFuture" />
                    Incluir futuros
                </label>
            </div>

            <div class="thin-scrollbar -mr-2 min-h-0 flex-1 overflow-y-auto pr-2" :class="loadingHistory ? 'opacity-50' : ''">
                <p v-if="!historyData && loadingHistory" class="py-6 text-sm text-gray-500">Carregando...</p>

                <ul v-else-if="historyData" class="divide-y divide-gray-100">
                    <li v-for="transaction in historyData.data" :key="transaction.id" class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-gray-900">
                                <span
                                    v-if="historyKindInfo(transaction).badge"
                                    class="rounded px-1.5 py-0.5 text-[11px] font-semibold"
                                    :class="historyKindInfo(transaction).badge"
                                >
                                    {{ historyKindInfo(transaction).label }}
                                </span>
                                <span class="truncate">{{ transaction.description }}</span>
                                <span v-if="transaction.installment_total" class="text-xs text-gray-500">
                                    {{ transaction.installment_number }}/{{ transaction.installment_total }}
                                </span>
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ formatDate(transaction.date.slice(0, 10)) }}
                                <template v-if="transaction.transfer"> · {{ transferCounterpart(transaction) }}</template>
                                <template v-else-if="transaction.credit_card_invoice">
                                    · Cartão {{ transaction.credit_card_invoice.credit_card?.institution?.name }}
                                </template>
                                <template v-if="transaction.category"> · {{ transaction.category.name }}</template>
                            </p>
                        </div>
                        <span class="shrink-0 tabular-nums" :class="transaction.type === 'income' ? 'text-green-600' : 'text-red-600'">
                            {{ transaction.type === 'income' ? '+' : '-' }}{{ formatMoney(transaction.amount) }}
                        </span>
                    </li>
                    <li v-if="historyData.data.length === 0" class="py-6 text-sm text-gray-500">
                        {{ historySelectedKinds.length === 0 ? 'Marque pelo menos um tipo.' : 'Nenhum lançamento encontrado.' }}
                    </li>
                </ul>
            </div>

            <div v-if="historyData && historyData.total > 0" class="mt-3 flex items-center justify-between border-t pt-3 text-sm">
                <span class="text-gray-500">
                    Página {{ historyData.current_page }} de {{ historyData.last_page }} · {{ historyData.total }} lançamentos
                </span>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded px-3 py-1 text-gray-600 hover:bg-gray-100 disabled:opacity-30"
                        :disabled="loadingHistory || historyData.current_page <= 1"
                        @click="goToHistoryPage(historyData.current_page - 1)"
                    >
                        ‹ Anterior
                    </button>
                    <button
                        type="button"
                        class="rounded px-3 py-1 text-gray-600 hover:bg-gray-100 disabled:opacity-30"
                        :disabled="loadingHistory || historyData.current_page >= historyData.last_page"
                        @click="goToHistoryPage(historyData.current_page + 1)"
                    >
                        Próxima ›
                    </button>
                </div>
            </div>
        </div>
    </Modal>

    <Modal :show="showRecentModal" max-width="md" @close="showRecentModal = false">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900">Últimos lançamentos · {{ recentBankLabel }}</h2>

            <p v-if="loadingRecent" class="mt-4 text-sm text-gray-500">Carregando...</p>

            <ul v-else class="mt-4 divide-y divide-gray-100">
                <li v-for="transaction in recentTransactions" :key="transaction.id" class="flex items-center justify-between py-2 text-sm">
                    <div>
                        <p class="text-gray-900">
                            {{ transaction.description }}
                            <span
                                v-if="transaction.transfer_id"
                                class="ml-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[11px] font-semibold text-indigo-700"
                            >
                                Transferência
                            </span>
                        </p>
                        <p class="text-xs text-gray-500">
                            {{ formatDate(transaction.date.slice(0, 10)) }} · {{ accountLabel(transaction.account) }}
                        </p>
                    </div>
                    <span :class="transaction.type === 'income' ? 'text-green-600' : 'text-red-600'">
                        {{ transaction.type === 'income' ? '+' : '-' }}{{ formatMoney(transaction.amount) }}
                    </span>
                </li>
                <li v-if="recentTransactions.length === 0" class="py-4 text-sm text-gray-500">Nenhum lançamento encontrado.</li>
            </ul>
        </div>
    </Modal>
</template>

<style scoped>
.thin-scrollbar {
    scrollbar-width: thin;
    scrollbar-color: rgb(209 213 219) transparent;
}

.thin-scrollbar::-webkit-scrollbar {
    width: 4px;
}

.thin-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}

.thin-scrollbar::-webkit-scrollbar-thumb {
    background-color: rgb(209 213 219);
    border-radius: 9999px;
}

.thin-scrollbar::-webkit-scrollbar-thumb:hover {
    background-color: rgb(156 163 175);
}
</style>
