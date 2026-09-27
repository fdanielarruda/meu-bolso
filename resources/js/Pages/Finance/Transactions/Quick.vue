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

const today = new Date().toISOString().slice(0, 10);

function bankKey(a) {
    return a.institution?.id ?? 'none';
}

function bankLabel(a) {
    return a.institution?.name ?? 'Dinheiro / Sem instituição';
}

const showTransient = ref(false);

const visibleAccounts = computed(() =>
    props.accounts.filter((a) => showTransient.value || !a.transient)
);

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
    form.account_id = accountsForBank.value[0]?.id ?? '';
});

watch(showTransient, () => {
    selectedBank.value = banks.value[0]?.key ?? '';
});

const isTransfer = ref(false);

const transferForm = useForm({
    from_account_id: '',
    to_account_id: '',
    amount: '',
    description: '',
    date: today,
    is_movement_only: true,
});

watch(isTransfer, (value) => {
    if (value) {
        if (!form.description) {
            form.description = 'Transferência';
        }

        transferForm.from_account_id = form.account_id;
        transferForm.amount = form.amount;
        transferForm.description = form.description;
        transferForm.date = form.date;
    }
});

watch(
    () => form.account_id,
    (accountId) => {
        transferForm.from_account_id = accountId;
    }
);

const transferTargetOptions = computed(() =>
    visibleAccounts.value
        .filter((a) => a.id !== Number(transferForm.from_account_id))
        .sort((a, b) => (a.transient ?? false) - (b.transient ?? false))
);

function submitTransfer() {
    transferForm.from_account_id = form.account_id;
    transferForm.amount = form.amount;
    transferForm.description = form.description;
    transferForm.date = form.date;

    transferForm.post(route('finance.transfers.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount', 'description');
            transferForm.reset('amount', 'description', 'to_account_id');
        },
    });
}

const cardOrder = reactive(JSON.parse(localStorage.getItem('quickCardOrder') ?? '[]'));

function saveCardOrder() {
    localStorage.setItem('quickCardOrder', JSON.stringify(cardOrder));
}

function cardOrderIndex(key) {
    const index = cardOrder.indexOf(key);
    return index === -1 ? Number.MAX_SAFE_INTEGER : index;
}

function moveCard(key, direction) {
    const list = bankCards.value;
    const index = list.findIndex((c) => c.key === key);
    const targetIndex = index + direction;

    if (index === -1 || targetIndex < 0 || targetIndex >= list.length) {
        return;
    }

    const currentOrder = list.map((c) => c.key);
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
            cards.push({ key: `${group.bank}::normal`, kind: 'normal', group });
        }

        if (group.investmentAccounts.length > 0) {
            cards.push({ key: `${group.bank}::invest`, kind: 'invest', group });
        }
    }

    return cards.sort((a, b) => {
        const diff = cardOrderIndex(a.key) - cardOrderIndex(b.key);
        return diff !== 0 ? diff : a.key.localeCompare(b.key);
    });
});

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

const collapsedGroups = reactive(new Set(JSON.parse(localStorage.getItem('quickCollapsedGroups') ?? '[]')));

function toggleGroup(key) {
    if (collapsedGroups.has(key)) {
        collapsedGroups.delete(key);
    } else {
        collapsedGroups.add(key);
    }

    localStorage.setItem('quickCollapsedGroups', JSON.stringify([...collapsedGroups]));
}

function submit(type) {
    form.type = type;
    form.post(route('finance.transactions.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount', 'description', 'is_unknown');
        },
    });
}
</script>

<template>
    <Head title="Lançamento rápido" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Lançamento rápido</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto grid max-w-4xl grid-cols-1 gap-6 sm:px-6 lg:px-8 lg:grid-cols-3">
                <form class="rounded-lg bg-white p-6 shadow lg:col-span-2" @submit.prevent>
                    <div>
                        <InputLabel for="amount" value="Valor" />
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

                    <div class="mt-4">
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
                            :placeholder="form.is_unknown ? 'Desconhecido (opcional preencher)' : 'Ex: Mercado, Salário...'"
                            :required="!form.is_unknown"
                        />
                        <InputError class="mt-2" :message="form.errors.description" />
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="date" value="Data" />
                            <TextInput id="date" v-model="form.date" type="date" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.date" />
                        </div>

                        <div v-if="!isTransfer">
                            <InputLabel for="category_id" value="Categoria (opcional)" />
                            <SelectInput id="category_id" v-model="form.category_id" class="mt-1 block w-full">
                                <option value="">Sem categoria</option>
                                <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
                            </SelectInput>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <Checkbox v-model:checked="showTransient" />
                            Exibir transeuntes
                        </label>
                    </div>

                    <div class="mt-4">
                        <InputLabel value="Banco" />
                        <div
                            class="mt-1 grid gap-2"
                            :style="{ gridTemplateColumns: `repeat(${Math.min(banks.length || 1, 4)}, minmax(0, 1fr))` }"
                        >
                            <button
                                v-for="b in banks"
                                :key="b.key"
                                type="button"
                                class="w-full truncate rounded-md border px-3 py-2 text-sm font-medium"
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

                    <div class="mt-4">
                        <InputLabel value="Conta" />
                        <div
                            class="mt-1 grid gap-2"
                            :style="{ gridTemplateColumns: `repeat(${Math.min(accountsForBank.length || 1, 4)}, minmax(0, 1fr))` }"
                        >
                            <button
                                v-for="a in accountsForBank"
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

                    <div class="mt-4">
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <Checkbox v-model:checked="isTransfer" />
                            É uma transferência para outra conta?
                        </label>
                    </div>

                    <div v-if="isTransfer" class="mt-4">
                        <InputLabel for="to_account_id" value="Conta destino" />
                        <SelectInput id="to_account_id" v-model="transferForm.to_account_id" class="mt-1 block w-full">
                            <option value="" disabled>Selecione a conta destino</option>
                            <option v-for="a in transferTargetOptions" :key="a.id" :value="a.id">
                                {{ a.name }} · {{ bankLabel(a) }}
                            </option>
                        </SelectInput>
                        <InputError class="mt-2" :message="transferForm.errors.to_account_id" />
                        <InputError class="mt-2" :message="transferForm.errors.from_account_id" />

                        <label class="mt-3 flex items-center gap-2 text-sm text-gray-600">
                            <Checkbox v-model:checked="transferForm.is_movement_only" />
                            Apenas movimentação (não contar no relatório)
                        </label>
                    </div>

                    <div v-if="!isTransfer" class="mt-6 grid grid-cols-2 gap-4">
                        <button
                            type="button"
                            class="flex items-center justify-center gap-2 rounded-md bg-green-600 px-4 py-3 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50"
                            :disabled="form.processing"
                            @click="submit('income')"
                        >
                            ↓ Receita
                        </button>
                        <button
                            type="button"
                            class="flex items-center justify-center gap-2 rounded-md bg-red-600 px-4 py-3 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50"
                            :disabled="form.processing"
                            @click="submit('expense')"
                        >
                            ↑ Despesa
                        </button>
                    </div>

                    <div v-else class="mt-6">
                        <button
                            type="button"
                            class="flex w-full items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                            :disabled="transferForm.processing || !transferForm.to_account_id"
                            @click="submitTransfer"
                        >
                            ⇄ Transferir
                        </button>
                    </div>
                </form>

                <div class="rounded-lg bg-white p-5 shadow">
                    <h3 class="text-sm font-semibold text-gray-700">Saldos</h3>
                    <div
                        v-for="(card, cardIndex) in bankCards"
                        :key="card.key"
                        class="mt-4 space-y-3 first:mt-3"
                    >
                        <div
                            class="rounded-md border p-3"
                            :class="card.kind === 'invest' ? 'border-gray-200' : 'border-gray-200'"
                        >
                            <div
                                class="flex items-center gap-1 text-xs font-semibold uppercase tracking-wide"
                                :class="card.kind === 'invest' ? 'text-indigo-500' : 'text-gray-500'"
                            >
                                {{ card.group.bank }}{{ card.kind === 'invest' ? ' · Investimentos' : '' }}
                                <button
                                    type="button"
                                    title="Ver últimas transações"
                                    class="flex h-4 w-4 items-center justify-center rounded-full border text-[10px] font-bold normal-case"
                                    :class="
                                        card.kind === 'invest'
                                            ? 'border-indigo-400 text-indigo-500 hover:border-indigo-600 hover:text-indigo-700'
                                            : 'border-gray-400 text-gray-500 hover:border-indigo-600 hover:text-indigo-600'
                                    "
                                    @click="openRecent(card.group)"
                                >
                                    i
                                </button>
                                <button
                                    type="button"
                                    :title="collapsedGroups.has(card.key) ? 'Mostrar conta' : 'Esconder conta'"
                                    class="flex h-4 w-4 items-center justify-center rounded-full border text-[10px] font-bold normal-case"
                                    :class="
                                        card.kind === 'invest'
                                            ? 'border-indigo-400 text-indigo-500 hover:border-indigo-600 hover:text-indigo-700'
                                            : 'border-gray-400 text-gray-500 hover:border-indigo-600 hover:text-indigo-600'
                                    "
                                    @click="toggleGroup(card.key)"
                                >
                                    {{ collapsedGroups.has(card.key) ? '+' : '−' }}
                                </button>
                                <span class="ml-auto flex items-center gap-0.5">
                                    <button
                                        type="button"
                                        title="Mover para cima"
                                        class="flex h-4 w-4 items-center justify-center rounded-full border border-gray-400 text-[10px] font-bold normal-case text-gray-500 hover:border-indigo-600 hover:text-indigo-600 disabled:opacity-30"
                                        :disabled="cardIndex === 0"
                                        @click="moveCard(card.key, -1)"
                                    >
                                        ▲
                                    </button>
                                    <button
                                        type="button"
                                        title="Mover para baixo"
                                        class="flex h-4 w-4 items-center justify-center rounded-full border border-gray-400 text-[10px] font-bold normal-case text-gray-500 hover:border-indigo-600 hover:text-indigo-600 disabled:opacity-30"
                                        :disabled="cardIndex === bankCards.length - 1"
                                        @click="moveCard(card.key, 1)"
                                    >
                                        ▼
                                    </button>
                                </span>
                            </div>
                            <ul v-if="!collapsedGroups.has(card.key)" class="mt-1 divide-y divide-gray-100">
                                <li
                                    v-for="a in card.kind === 'invest' ? card.group.investmentAccounts : card.group.accounts"
                                    :key="a.id"
                                    class="flex items-center justify-between py-2 text-sm"
                                >
                                    <span class="text-gray-600">
                                        {{ a.name }}
                                    </span>
                                    <span
                                        v-if="a.balance !== null"
                                        class="font-medium"
                                        :class="Number(a.balance) < 0 ? 'text-red-600' : 'text-gray-900'"
                                    >
                                        {{ formatMoney(a.balance) }}
                                    </span>
                                </li>
                                <li class="flex items-center justify-between py-2 text-sm">
                                    <span
                                        class="font-semibold"
                                        :class="card.kind === 'invest' ? 'text-indigo-700' : 'text-gray-700'"
                                    >
                                        Total
                                    </span>
                                    <span
                                        class="font-semibold"
                                        :class="
                                            (card.kind === 'invest' ? card.group.investmentTotal : card.group.total) < 0
                                                ? 'text-red-600'
                                                : 'text-gray-900'
                                        "
                                    >
                                        {{ formatMoney(card.kind === 'invest' ? card.group.investmentTotal : card.group.total) }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <p v-if="accounts.length === 0" class="mt-3 py-2 text-sm text-gray-500">
                        Nenhuma conta cadastrada.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <Modal :show="showRecentModal" max-width="md" @close="showRecentModal = false">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900">Últimas transações · {{ recentBankLabel }}</h2>

            <p v-if="loadingRecent" class="mt-4 text-sm text-gray-500">Carregando...</p>

            <ul v-else class="mt-4 divide-y divide-gray-100">
                <li
                    v-for="transaction in recentTransactions"
                    :key="transaction.id"
                    class="flex items-center justify-between py-2 text-sm"
                >
                    <div>
                        <p class="text-gray-900">{{ transaction.description }}</p>
                        <p class="text-xs text-gray-500">
                            {{ formatDate(transaction.date.slice(0, 10)) }} · {{ transaction.account.name }}
                        </p>
                    </div>
                    <span :class="transaction.type === 'income' ? 'text-green-600' : 'text-red-600'">
                        {{ transaction.type === 'income' ? '+' : '-' }}{{ formatMoney(transaction.amount) }}
                    </span>
                </li>
                <li v-if="recentTransactions.length === 0" class="py-4 text-sm text-gray-500">
                    Nenhuma transação encontrada.
                </li>
            </ul>
        </div>
    </Modal>
</template>
