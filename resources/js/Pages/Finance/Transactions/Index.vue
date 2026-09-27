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
    categories: Array,
    filters: Object,
});

const categoryOptions = computed(() => categoryTreeOptions(props.categories));

function firstDayOfMonth() {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10);
}

function lastDayOfMonth() {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().slice(0, 10);
}

const filters = reactive({
    institution_id: props.filters.institution_id ?? '',
    account_id: props.filters.account_id ?? '',
    category_id: props.filters.category_id ?? '',
    from: props.filters.from ?? firstDayOfMonth(),
    to: props.filters.to ?? lastDayOfMonth(),
    kind: props.filters.kind ?? '',
    min_amount: props.filters.min_amount ?? '',
    max_amount: props.filters.max_amount ?? '',
});

const accountsForFilterBank = computed(() =>
    filters.institution_id === ''
        ? props.accounts
        : props.accounts.filter((a) => a.institution?.id === Number(filters.institution_id))
);

function applyFilters() {
    router.get(route('finance.transactions.index'), filters, { preserveState: true, replace: true });
}

if (!props.filters.from && !props.filters.to) {
    applyFilters();
}

watch(
    () => filters.institution_id,
    () => {
        if (!accountsForFilterBank.value.some((a) => a.id === Number(filters.account_id))) {
            filters.account_id = '';
        }
    }
);

function destroyTransaction(transaction) {
    if (confirm('Remover este lançamento?')) {
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

function bankLabel(transaction) {
    return transaction.account.institution?.name ?? 'Dinheiro';
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

const allChecked = computed(
    () => props.transactions.data.length > 0 && props.transactions.data.every((t) => checkedIds.value.has(t.id))
);

function toggleCheckAll() {
    checkedIds.value = allChecked.value ? new Set() : new Set(props.transactions.data.map((t) => t.id));
}

const groupedByDay = computed(() => {
    const groups = new Map();

    for (const transaction of props.transactions.data) {
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
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Banco" />
                            <SelectInput v-model="filters.institution_id" class="mt-1 block w-full" @change="applyFilters">
                                <option value="">Todos os bancos</option>
                                <option v-for="i in institutions" :key="i.id" :value="i.id">{{ i.name }}</option>
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel value="Conta" />
                            <SelectInput
                                v-model="filters.account_id"
                                class="mt-1 block w-full disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400"
                                :disabled="!filters.institution_id"
                                @change="applyFilters"
                            >
                                <option value="">
                                    {{ filters.institution_id ? 'Todas as contas' : 'Selecione um banco' }}
                                </option>
                                <option v-for="a in accountsForFilterBank" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel value="Categoria" />
                            <SelectInput v-model="filters.category_id" class="mt-1 block w-full" @change="applyFilters">
                                <option value="">Todas as categorias</option>
                                <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel value="Tipo" />
                            <SelectInput v-model="filters.kind" class="mt-1 block w-full" @change="applyFilters">
                                <option value="">Todos</option>
                                <option value="credit_card">Cartão de crédito</option>
                                <option value="transactions">Transações</option>
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel value="De" />
                            <TextInput v-model="filters.from" type="date" class="mt-1 block w-full" @change="applyFilters" />
                        </div>

                        <div>
                            <InputLabel value="Até" />
                            <TextInput v-model="filters.to" type="date" class="mt-1 block w-full" @change="applyFilters" />
                        </div>

                        <div>
                            <InputLabel value="Valor mínimo" />
                            <TextInput
                                v-model="filters.min_amount"
                                type="number"
                                step="0.01"
                                min="0"
                                class="mt-1 block w-full"
                                @change="applyFilters"
                            />
                        </div>

                        <div>
                            <InputLabel value="Valor máximo" />
                            <TextInput
                                v-model="filters.max_amount"
                                type="number"
                                step="0.01"
                                min="0"
                                class="mt-1 block w-full"
                                @change="applyFilters"
                            />
                        </div>
                    </div>
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
                                <div class="flex items-center gap-3">
                                    <Checkbox :checked="isChecked(transaction)" @update:checked="toggleChecked(transaction)" />
                                    <div>
                                    <p class="text-gray-900">
                                        {{ transaction.description }}
                                        <span v-if="transaction.installment_total" class="text-xs text-gray-500">
                                            ({{ transaction.installment_number }}/{{ transaction.installment_total }})
                                        </span>
                                        <span
                                            v-if="transaction.transfer_id"
                                            class="ml-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-indigo-700"
                                        >
                                            Transferência
                                        </span>
                                        <span
                                            v-if="transaction.transfer_id && transaction.transfer?.is_movement_only"
                                            class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-600"
                                        >
                                            Não conta no relatório
                                        </span>
                                        <span
                                            v-if="transaction.exclude_from_reports"
                                            class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-600"
                                        >
                                            Não conta no relatório
                                        </span>
                                        <span
                                            v-if="transaction.invoice_payment_id"
                                            class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-600"
                                        >
                                            Pagamento de fatura · não conta no relatório
                                        </span>
                                        <span
                                            v-if="transaction.is_unknown"
                                            class="ml-1 rounded bg-yellow-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-yellow-700"
                                        >
                                            Desconhecida
                                        </span>
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        {{ bankLabel(transaction) }} / {{ transaction.account.name }}
                                        <template v-if="transaction.category"> · {{ transaction.category.name }}</template>
                                    </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span :class="transaction.type === 'income' ? 'text-green-600' : 'text-red-600'">
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
