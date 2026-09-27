<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import Modal from '@/Components/Modal.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { categoryTreeOptions, formatDate, formatMoney } from '@/finance';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    totals: Object,
    monthly: Array,
    transactionsByMonth: Object,
    categoryBreakdown: Array,
    accountsSummary: Array,
    people: Array,
    categories: Array,
    institutions: Array,
    filters: Object,
});

const categoryOptions = computed(() => categoryTreeOptions(props.categories));

const filters = reactive({
    person_id: props.filters.person_id ?? '',
    category_id: props.filters.category_id ?? '',
    institution_id: props.filters.institution_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    view: props.filters.view ?? 'overview',
});

const views = [
    { key: 'overview', label: 'Visão geral' },
    { key: 'credit_card', label: 'Cartão' },
    { key: 'investments', label: 'Investimentos' },
];

function applyFilters() {
    router.get(route('finance.reports.index'), filters, { preserveState: true, replace: true });
}

function setView(view) {
    filters.view = view;
    applyFilters();
}

const balance = computed(() => Number(props.totals.income) - Number(props.totals.expense));

function monthLabel(month) {
    const [year, m] = month.split('-');
    return new Date(Number(year), Number(m) - 1, 1).toLocaleDateString('pt-BR', { month: 'short', year: '2-digit' });
}

const maxMonthlyValue = computed(() =>
    Math.max(1, ...props.monthly.flatMap((m) => [Number(m.income), Number(m.expense)]))
);

const maxCategoryValue = computed(() => Math.max(1, ...props.categoryBreakdown.map((c) => Number(c.amount))));

const categoryColors = ['#6366f1', '#ef4444', '#10b981', '#f59e0b', '#3b82f6', '#ec4899', '#14b8a6', '#a855f7', '#84cc16', '#f97316'];

function categoryColor(row, index) {
    return row.category?.color || categoryColors[index % categoryColors.length];
}

/* ---------- Transações do mês ---------- */

const showMonthModal = ref(false);
const selectedMonth = ref(null);
const showAllMonths = ref(false);
const showIncome = ref(true);
const showExpense = ref(true);
const monthSort = ref('date');

const modalSourceTransactions = computed(() =>
    showAllMonths.value ? Object.values(props.transactionsByMonth).flat() : props.transactionsByMonth[selectedMonth.value] ?? []
);

const selectedMonthTransactions = computed(() => {
    const transactions = modalSourceTransactions.value.filter(
        (t) => (t.type === 'income' && showIncome.value) || (t.type === 'expense' && showExpense.value)
    );

    return [...transactions].sort((a, b) => {
        if (monthSort.value === 'description') {
            return a.description.localeCompare(b.description);
        }

        if (monthSort.value === 'amount') {
            return Number(b.amount) - Number(a.amount);
        }

        return b.date.localeCompare(a.date);
    });
});

function openMonth(month) {
    selectedMonth.value = month;
    showAllMonths.value = false;
    showIncome.value = true;
    showExpense.value = true;
    monthSort.value = 'date';
    showMonthModal.value = true;
}

function openTotals(type) {
    selectedMonth.value = null;
    showAllMonths.value = true;
    showIncome.value = type === 'income';
    showExpense.value = type === 'expense';
    monthSort.value = 'date';
    showMonthModal.value = true;
}

/* ---------- Gastos por categoria ---------- */

const showCategoryModal = ref(false);
const selectedCategory = ref(null);
const hasSelectedCategory = ref(false);

const allTransactions = computed(() => Object.values(props.transactionsByMonth).flat());

const selectedCategoryTransactions = computed(() => {
    const transactions = allTransactions.value.filter((t) => {
        if (t.type !== 'expense') {
            return false;
        }

        return hasSelectedCategory.value ? t.category_id === selectedCategory.value?.id : !t.category_id;
    });

    return [...transactions].sort((a, b) => b.date.localeCompare(a.date));
});

function openCategory(row) {
    selectedCategory.value = row.category;
    hasSelectedCategory.value = row.category !== null;
    showCategoryModal.value = true;
}
</script>

<template>
    <Head title="Relatórios" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Relatórios</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div class="flex flex-wrap gap-2 rounded-lg bg-white p-2 shadow">
                    <button
                        v-for="v in views"
                        :key="v.key"
                        type="button"
                        class="rounded-md px-4 py-2 text-sm font-medium"
                        :class="
                            filters.view === v.key
                                ? 'bg-indigo-600 text-white'
                                : 'text-gray-600 hover:bg-gray-100'
                        "
                        @click="setView(v.key)"
                    >
                        {{ v.label }}
                    </button>
                </div>

                <div class="grid grid-cols-1 gap-4 rounded-lg bg-white p-5 shadow sm:grid-cols-3 lg:grid-cols-5">
                    <div>
                        <label class="text-xs font-medium text-gray-500">Pessoa</label>
                        <SelectInput v-model="filters.person_id" class="mt-1 block w-full" @change="applyFilters">
                            <option value="">Todas as pessoas</option>
                            <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </SelectInput>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500">Categoria</label>
                        <SelectInput v-model="filters.category_id" class="mt-1 block w-full" @change="applyFilters">
                            <option value="">Todas as categorias</option>
                            <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
                        </SelectInput>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500">Banco</label>
                        <SelectInput v-model="filters.institution_id" class="mt-1 block w-full" @change="applyFilters">
                            <option value="">Todos os bancos</option>
                            <option v-for="i in institutions" :key="i.id" :value="i.id">{{ i.name }}</option>
                        </SelectInput>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500">De</label>
                        <TextInput v-model="filters.from" type="date" class="mt-1 block w-full" @change="applyFilters" />
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500">Até</label>
                        <TextInput v-model="filters.to" type="date" class="mt-1 block w-full" @change="applyFilters" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <button
                        type="button"
                        class="rounded-lg bg-white p-5 text-left shadow hover:bg-gray-50"
                        @click="openTotals('income')"
                    >
                        <p class="text-sm text-gray-500">Entradas no período</p>
                        <p class="mt-1 text-2xl font-semibold text-green-600">{{ formatMoney(totals.income) }}</p>
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-white p-5 text-left shadow hover:bg-gray-50"
                        @click="openTotals('expense')"
                    >
                        <p class="text-sm text-gray-500">Saídas no período</p>
                        <p class="mt-1 text-2xl font-semibold text-red-600">{{ formatMoney(totals.expense) }}</p>
                    </button>
                    <div class="rounded-lg bg-white p-5 shadow">
                        <p class="text-sm text-gray-500">Saldo do período</p>
                        <p class="mt-1 text-2xl font-semibold" :class="balance < 0 ? 'text-red-600' : 'text-gray-900'">
                            {{ formatMoney(balance) }}
                        </p>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-5 shadow">
                    <h3 class="font-medium text-gray-900">Entradas x Saídas por mês</h3>

                    <div v-if="monthly.length > 0" class="mt-6 flex items-end gap-4 overflow-x-auto pb-2">
                        <div v-for="m in monthly" :key="m.month" class="flex min-w-[3.5rem] flex-col items-center gap-1">
                            <div class="flex h-40 items-end gap-1">
                                <div
                                    class="w-4 rounded-t bg-green-500"
                                    :style="{ height: `${(Number(m.income) / maxMonthlyValue) * 100}%` }"
                                    :title="`Entradas: ${formatMoney(m.income)}`"
                                ></div>
                                <div
                                    class="w-4 rounded-t bg-red-500"
                                    :style="{ height: `${(Number(m.expense) / maxMonthlyValue) * 100}%` }"
                                    :title="`Saídas: ${formatMoney(m.expense)}`"
                                ></div>
                            </div>
                            <button
                                type="button"
                                class="text-xs capitalize text-indigo-600 underline-offset-2 hover:underline"
                                @click="openMonth(m.month)"
                            >
                                {{ monthLabel(m.month) }}
                            </button>
                        </div>
                    </div>
                    <p v-else class="mt-4 text-sm text-gray-500">Nenhum lançamento no período selecionado.</p>

                    <div class="mt-4 flex items-center gap-4 text-xs text-gray-500">
                        <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-green-500"></span> Entradas</span>
                        <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-red-500"></span> Saídas</span>
                    </div>

                    <div v-if="monthly.length > 0" class="mt-6 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th class="py-2 pr-4">Mês</th>
                                    <th class="py-2 pr-4 text-right">Entradas</th>
                                    <th class="py-2 pr-4 text-right">Saídas</th>
                                    <th class="py-2 text-right">Saldo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="m in monthly" :key="m.month">
                                    <td class="py-2 pr-4">
                                        <button
                                            type="button"
                                            class="capitalize text-indigo-600 hover:underline"
                                            @click="openMonth(m.month)"
                                        >
                                            {{ monthLabel(m.month) }}
                                        </button>
                                    </td>
                                    <td class="py-2 pr-4 text-right text-green-600">{{ formatMoney(m.income) }}</td>
                                    <td class="py-2 pr-4 text-right text-red-600">{{ formatMoney(m.expense) }}</td>
                                    <td
                                        class="py-2 text-right font-medium"
                                        :class="Number(m.income) - Number(m.expense) < 0 ? 'text-red-600' : 'text-gray-900'"
                                    >
                                        {{ formatMoney(Number(m.income) - Number(m.expense)) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-5 shadow">
                    <h3 class="font-medium text-gray-900">Gastos por categoria</h3>

                    <div v-if="categoryBreakdown.length > 0" class="mt-4 space-y-3">
                        <div v-for="(row, index) in categoryBreakdown" :key="row.category?.id ?? 'none'">
                            <div class="flex items-center justify-between text-sm">
                                <button
                                    type="button"
                                    class="text-gray-700 hover:text-indigo-600 hover:underline"
                                    @click="openCategory(row)"
                                >
                                    {{ row.category?.name ?? 'Sem categoria' }}
                                </button>
                                <span class="font-medium text-gray-900">{{ formatMoney(row.amount) }}</span>
                            </div>
                            <div class="mt-1 h-2 w-full rounded-full bg-gray-100">
                                <div
                                    class="h-2 rounded-full"
                                    :style="{
                                        width: `${(Number(row.amount) / maxCategoryValue) * 100}%`,
                                        backgroundColor: categoryColor(row, index),
                                    }"
                                ></div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="mt-4 text-sm text-gray-500">Nenhuma despesa no período selecionado.</p>
                </div>

                <div v-if="filters.view !== 'overview'" class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="border-b px-4 py-3 sm:px-6">
                        <h3 class="font-medium text-gray-900">
                            {{ filters.view === 'credit_card' ? 'Cartões de crédito' : 'Contas de investimento' }}
                        </h3>
                    </div>
                    <ul class="divide-y divide-gray-200">
                        <li
                            v-for="group in accountsSummary"
                            :key="group.account.id"
                            class="flex items-center justify-between px-4 py-4 sm:px-6"
                        >
                            <div>
                                <Link
                                    :href="route('finance.accounts.show', group.account.id)"
                                    class="font-medium text-gray-900 hover:text-indigo-600"
                                >
                                    {{ group.account.name }}
                                </Link>
                                <p class="text-xs text-gray-500">{{ group.account.institution?.name ?? 'Dinheiro' }}</p>
                            </div>
                            <div class="text-right">
                                <p v-if="group.balance !== null" class="font-semibold text-gray-900">
                                    {{ formatMoney(group.balance) }}
                                </p>
                                <p class="text-xs text-gray-500">
                                    <span class="text-green-600">+{{ formatMoney(group.income) }}</span>
                                    ·
                                    <span class="text-red-600">-{{ formatMoney(group.expense) }}</span>
                                </p>
                            </div>
                        </li>
                        <li v-if="accountsSummary.length === 0" class="px-4 py-6 text-sm text-gray-500 sm:px-6">
                            Nenhuma conta com lançamentos no período selecionado.
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <Modal :show="showMonthModal" max-width="4xl" @close="showMonthModal = false">
        <div class="p-6">
            <h2 class="text-lg font-medium capitalize text-gray-900">
                {{ showAllMonths ? 'Lançamentos do período' : `Lançamentos de ${selectedMonth ? monthLabel(selectedMonth) : ''}` }}
            </h2>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <Checkbox v-model:checked="showIncome" />
                        <span class="text-green-600">Entradas</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <Checkbox v-model:checked="showExpense" />
                        <span class="text-red-600">Saídas</span>
                    </label>
                </div>

                <div class="flex items-center gap-2">
                    <label class="text-xs font-medium text-gray-500">Ordenar por</label>
                    <SelectInput v-model="monthSort" class="block w-auto text-sm">
                        <option value="date">Data</option>
                        <option value="description">Nome</option>
                        <option value="amount">Valor</option>
                    </SelectInput>
                </div>
            </div>

            <div class="mt-4 max-h-96 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 bg-white">
                        <tr class="border-b text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pr-4">Tipo</th>
                            <th class="py-2 pr-4">Descrição</th>
                            <th class="py-2 pr-4">Data</th>
                            <th class="py-2 text-right">Valor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="t in selectedMonthTransactions" :key="t.id">
                            <td class="py-2 pr-4">
                                <span
                                    class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                                    :class="
                                        t.source === 'credit_card'
                                            ? 'bg-indigo-100 text-indigo-700'
                                            : 'bg-gray-100 text-gray-600'
                                    "
                                >
                                    {{ t.source === 'credit_card' ? 'Cartão' : 'Transação' }}
                                </span>
                            </td>
                            <td class="py-2 pr-4 text-gray-900">
                                {{ t.description }}
                                <span v-if="t.installment_total" class="text-xs text-gray-500">
                                    ({{ t.installment_number }}/{{ t.installment_total }})
                                </span>
                                <span class="block text-xs text-gray-500">
                                    {{ t.account }}
                                    <template v-if="t.category"> · {{ t.category }}</template>
                                </span>
                            </td>
                            <td class="py-2 pr-4 whitespace-nowrap text-gray-700">{{ formatDate(t.date) }}</td>
                            <td
                                class="py-2 text-right font-medium whitespace-nowrap"
                                :class="t.type === 'income' ? 'text-green-600' : 'text-red-600'"
                            >
                                {{ t.type === 'income' ? '+' : '-' }}{{ formatMoney(t.amount) }}
                            </td>
                        </tr>
                        <tr v-if="selectedMonthTransactions.length === 0">
                            <td colspan="4" class="py-6 text-center text-sm text-gray-500">
                                Nenhum lançamento neste mês.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </Modal>

    <Modal :show="showCategoryModal" max-width="3xl" @close="showCategoryModal = false">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900">
                Gastos em {{ hasSelectedCategory ? selectedCategory?.name : 'Sem categoria' }}
            </h2>

            <div class="mt-4 max-h-96 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 bg-white">
                        <tr class="border-b text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pr-4">Tipo</th>
                            <th class="py-2 pr-4">Descrição</th>
                            <th class="py-2 pr-4">Data</th>
                            <th class="py-2 text-right">Valor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="t in selectedCategoryTransactions" :key="t.id">
                            <td class="py-2 pr-4">
                                <span
                                    class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                                    :class="
                                        t.source === 'credit_card'
                                            ? 'bg-indigo-100 text-indigo-700'
                                            : 'bg-gray-100 text-gray-600'
                                    "
                                >
                                    {{ t.source === 'credit_card' ? 'Cartão' : 'Transação' }}
                                </span>
                            </td>
                            <td class="py-2 pr-4 text-gray-900">
                                {{ t.description }}
                                <span v-if="t.installment_total" class="text-xs text-gray-500">
                                    ({{ t.installment_number }}/{{ t.installment_total }})
                                </span>
                                <span class="block text-xs text-gray-500">{{ t.account }}</span>
                            </td>
                            <td class="py-2 pr-4 whitespace-nowrap text-gray-700">{{ formatDate(t.date) }}</td>
                            <td class="py-2 text-right font-medium whitespace-nowrap text-red-600">
                                -{{ formatMoney(t.amount) }}
                            </td>
                        </tr>
                        <tr v-if="selectedCategoryTransactions.length === 0">
                            <td colspan="4" class="py-6 text-center text-sm text-gray-500">
                                Nenhum lançamento nesta categoria.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </Modal>
</template>
