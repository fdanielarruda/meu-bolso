<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { categoryTreeOptions, formatDate, formatMoney } from '@/finance';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    transactions: Array,
    classifiedTransactions: Array,
    classifiedCategories: Array,
    categories: Array,
    people: Array,
    institutions: Array,
    filters: Object,
    error: String,
});

const expenseCategoryOptions = computed(() => categoryTreeOptions(props.categories.filter((c) => c.type === 'expense')));
const incomeCategoryOptions = computed(() => categoryTreeOptions(props.categories.filter((c) => c.type === 'income')));

function categoryOptionsForType(type) {
    return type === 'income' ? incomeCategoryOptions.value : expenseCategoryOptions.value;
}

const filters = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    person_id: props.filters.person_id ?? '',
    institution_id: props.filters.institution_id ?? '',
    kind: props.filters.kind ?? 'transactions',
    hide_classified: props.filters.hide_classified ?? true,
    tab: props.filters.tab ?? 'classify',
    category_ids: props.filters.category_ids ?? [],
});

function toggleCategoryFilter(categoryId) {
    const index = filters.category_ids.indexOf(categoryId);
    if (index === -1) {
        filters.category_ids.push(categoryId);
    } else {
        filters.category_ids.splice(index, 1);
    }
    applyFilters();
}

function isolateCategoryFilter(categoryId) {
    filters.category_ids.splice(0, filters.category_ids.length, categoryId);
    applyFilters();
}

const classifiedByCategory = computed(() => {
    const groups = new Map();
    for (const category of props.classifiedCategories) {
        if (filters.category_ids.length === 0 || filters.category_ids.includes(category.id)) {
            groups.set(category.id, { category, rows: [] });
        }
    }
    for (const t of props.classifiedTransactions) {
        const group = groups.get(t.category_id);
        if (group) {
            group.rows.push(t);
        }
    }
    return Array.from(groups.values()).filter((g) => g.rows.length > 0);
});

function updateClassifiedCategory(row, categoryId) {
    router.post(
        route('finance.transaction-classification.confirm'),
        { items: [{ transaction_id: row.id, category_id: categoryId || null }] },
        { preserveScroll: true }
    );
}

function applyFilters() {
    router.get(route('finance.transaction-classification.index'), filters, { preserveState: true, replace: true });
}

const classifying = ref(false);

function classifyWithAi() {
    classifying.value = true;
    router.get(
        route('finance.transaction-classification.index'),
        { ...filters, classify: 1 },
        { preserveState: true, replace: true, onFinish: () => (classifying.value = false) }
    );
}

function buildRows() {
    return props.transactions.map((t) => ({
        transaction_id: t.id,
        date: t.date,
        description: t.description,
        type: t.type,
        amount: t.amount,
        account: t.account,
        category_id: t.category_id ?? t.suggested_category_id ?? '',
        already_classified: t.category_id ?? null,
        suggested: t.suggested_category_id ?? null,
        recurring: t.recurring ?? false,
        include: true,
    }));
}

const rows = reactive(buildRows());

watch(
    () => props.transactions,
    () => {
        rows.splice(0, rows.length, ...buildRows());
    }
);

const suggestedCount = computed(() => rows.filter((r) => r.suggested).length);
const includedCount = computed(() => rows.filter((r) => r.include).length);

const form = useForm({ items: [] });

function submit() {
    form.items = rows
        .filter((r) => r.include)
        .map((r) => ({
            transaction_id: r.transaction_id,
            category_id: r.category_id || null,
        }));

    form.post(route('finance.transaction-classification.confirm'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Classificar transações" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Classificar transações</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between rounded-lg bg-white p-4 shadow">
                    <div class="flex rounded-md bg-gray-100 p-1 text-sm">
                        <button
                            type="button"
                            class="rounded px-3 py-1 font-medium"
                            :class="filters.tab === 'classify' ? 'bg-white text-gray-900 shadow' : 'text-gray-500'"
                            @click="filters.tab = 'classify'; applyFilters()"
                        >
                            Classificar
                        </button>
                        <button
                            type="button"
                            class="rounded px-3 py-1 font-medium"
                            :class="filters.tab === 'classified' ? 'bg-white text-gray-900 shadow' : 'text-gray-500'"
                            @click="filters.tab = 'classified'; applyFilters()"
                        >
                            Já classificadas
                        </button>
                    </div>
                    <Link :href="route('finance.transactions.index')">
                        <SecondaryButton type="button">Voltar</SecondaryButton>
                    </Link>
                </div>

                <div v-if="filters.tab === 'classify'" class="space-y-4 rounded-lg bg-white p-5 shadow">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="text-xs font-medium text-gray-500">Pessoa</label>
                            <SelectInput v-model="filters.person_id" class="mt-1 block w-full" @change="applyFilters">
                                <option value="">Todas as pessoas</option>
                                <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
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

                    <div class="flex flex-wrap items-center justify-between gap-4 border-t pt-4">
                        <div class="flex flex-wrap items-center gap-4">
                            <div class="flex rounded-md bg-gray-100 p-1 text-sm">
                                <button
                                    type="button"
                                    class="rounded px-3 py-1 font-medium"
                                    :class="filters.kind === 'transactions' ? 'bg-white text-gray-900 shadow' : 'text-gray-500'"
                                    @click="filters.kind = 'transactions'; applyFilters()"
                                >
                                    Transações
                                </button>
                                <button
                                    type="button"
                                    class="rounded px-3 py-1 font-medium"
                                    :class="filters.kind === 'credit_card' ? 'bg-white text-gray-900 shadow' : 'text-gray-500'"
                                    @click="filters.kind = 'credit_card'; applyFilters()"
                                >
                                    Cartão
                                </button>
                            </div>

                            <label class="flex items-center gap-2 text-sm text-gray-600">
                                <input
                                    v-model="filters.hide_classified"
                                    type="checkbox"
                                    class="rounded border-gray-300 text-indigo-600"
                                    @change="applyFilters"
                                />
                                Remover já classificadas
                            </label>
                        </div>

                        <PrimaryButton :disabled="classifying" @click="classifyWithAi">
                            {{ classifying ? 'Classificando...' : 'Classificar com IA' }}
                        </PrimaryButton>
                    </div>
                </div>

                <template v-if="filters.tab === 'classify'">
                    <div v-if="error" class="rounded-lg bg-red-50 p-4 text-sm text-red-700 shadow">
                        {{ error }}
                    </div>

                    <div v-if="rows.length === 0" class="rounded-lg bg-white p-6 text-sm text-gray-500 shadow">
                        Nenhum lançamento encontrado com os filtros selecionados.
                    </div>

                    <template v-else>
                        <div class="overflow-x-auto rounded-lg bg-white shadow">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                                    <tr>
                                        <th class="px-3 py-3"></th>
                                        <th class="px-3 py-3">Data</th>
                                        <th class="px-3 py-3">Descrição</th>
                                        <th class="px-3 py-3">Conta</th>
                                        <th class="px-3 py-3 text-right">Valor</th>
                                        <th class="px-3 py-3">Categoria sugerida</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="row in rows" :key="row.transaction_id">
                                        <td class="px-3 py-2 align-top">
                                            <input v-model="row.include" type="checkbox" class="mt-2 rounded border-gray-300 text-indigo-600" />
                                        </td>
                                        <td class="px-3 py-2 align-top whitespace-nowrap text-gray-700">{{ formatDate(row.date) }}</td>
                                        <td class="px-3 py-2 align-top text-gray-900">{{ row.description }}</td>
                                        <td class="px-3 py-2 align-top text-xs text-gray-500">{{ row.account }}</td>
                                        <td
                                            class="px-3 py-2 align-top text-right font-medium whitespace-nowrap"
                                            :class="row.type === 'income' ? 'text-green-600' : 'text-red-600'"
                                        >
                                            {{ row.type === 'income' ? '+' : '-' }}{{ formatMoney(row.amount) }}
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            <SelectInput v-model="row.category_id" class="block w-48" :disabled="!row.include">
                                                <option value="">Sem categoria</option>
                                                <option v-for="c in categoryOptionsForType(row.type)" :key="c.id" :value="c.id">{{ c.label }}</option>
                                            </SelectInput>
                                            <p v-if="row.suggested" class="mt-1 text-xs text-indigo-600">Sugerido pela IA</p>
                                            <p v-else-if="row.already_classified" class="mt-1 text-xs text-gray-500">Já categorizado</p>
                                            <p v-if="row.recurring" class="mt-1 text-xs text-amber-600">
                                                Recorrente · aplica a categoria em todas as ocorrências
                                            </p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center justify-between rounded-lg bg-white p-4 shadow">
                            <div class="text-sm text-gray-600">
                                {{ suggestedCount }} de {{ rows.length }} com sugestão da IA · {{ includedCount }} selecionado(s)
                            </div>
                            <PrimaryButton :disabled="form.processing || includedCount === 0" @click="submit">
                                Confirmar categorias ({{ includedCount }})
                            </PrimaryButton>
                        </div>
                    </template>
                </template>

                <template v-else>
                    <div class="space-y-4 rounded-lg bg-white p-5 shadow">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label class="text-xs font-medium text-gray-500">Pessoa</label>
                                <SelectInput v-model="filters.person_id" class="mt-1 block w-full" @change="applyFilters">
                                    <option value="">Todas as pessoas</option>
                                    <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
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

                        <div class="flex rounded-md bg-gray-100 p-1 text-sm w-fit">
                            <button
                                type="button"
                                class="rounded px-3 py-1 font-medium"
                                :class="filters.kind === 'transactions' ? 'bg-white text-gray-900 shadow' : 'text-gray-500'"
                                @click="filters.kind = 'transactions'; applyFilters()"
                            >
                                Transações
                            </button>
                            <button
                                type="button"
                                class="rounded px-3 py-1 font-medium"
                                :class="filters.kind === 'credit_card' ? 'bg-white text-gray-900 shadow' : 'text-gray-500'"
                                @click="filters.kind = 'credit_card'; applyFilters()"
                            >
                                Cartão
                            </button>
                        </div>

                        <div class="border-t pt-4">
                            <label class="text-xs font-medium text-gray-500">Categorias exibidas</label>
                            <p class="mb-2 text-xs text-gray-400">
                                Nenhuma selecionada = mostra todas as categorias com lançamentos classificados.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="c in classifiedCategories"
                                    :key="c.id"
                                    type="button"
                                    class="rounded-full border px-3 py-1 text-xs font-medium"
                                    :class="
                                        filters.category_ids.includes(c.id)
                                            ? 'border-indigo-600 bg-indigo-600 text-white'
                                            : 'border-gray-300 text-gray-600 hover:border-indigo-400'
                                    "
                                    @click="toggleCategoryFilter(c.id)"
                                    @contextmenu.prevent="isolateCategoryFilter(c.id)"
                                    title="Clique com o botão direito para mostrar só esta categoria"
                                >
                                    {{ c.name }} ({{ c.count }})
                                </button>
                                <p v-if="classifiedCategories.length === 0" class="text-sm text-gray-400">
                                    Nenhuma categoria classificada encontrada.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-if="classifiedByCategory.length === 0" class="rounded-lg bg-white p-6 text-sm text-gray-500 shadow">
                        Nenhum lançamento classificado encontrado com os filtros selecionados.
                    </div>

                    <div v-for="group in classifiedByCategory" :key="group.category.id" class="overflow-x-auto rounded-lg bg-white shadow">
                        <div class="border-b bg-gray-50 px-4 py-3">
                            <h3 class="text-sm font-semibold text-gray-800">{{ group.category.name }}</h3>
                        </div>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                                <tr>
                                    <th class="px-3 py-3">Data</th>
                                    <th class="px-3 py-3">Descrição</th>
                                    <th class="px-3 py-3">Conta</th>
                                    <th class="px-3 py-3 text-right">Valor</th>
                                    <th class="px-3 py-3">Categoria</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="row in group.rows" :key="row.id">
                                    <td class="px-3 py-2 align-top whitespace-nowrap text-gray-700">{{ formatDate(row.date) }}</td>
                                    <td class="px-3 py-2 align-top text-gray-900">
                                        {{ row.description }}
                                        <span v-if="row.recurring" class="ml-1 text-xs text-amber-600">(recorrente)</span>
                                    </td>
                                    <td class="px-3 py-2 align-top text-xs text-gray-500">{{ row.account }}</td>
                                    <td
                                        class="px-3 py-2 align-top text-right font-medium whitespace-nowrap"
                                        :class="row.type === 'income' ? 'text-green-600' : 'text-red-600'"
                                    >
                                        {{ row.type === 'income' ? '+' : '-' }}{{ formatMoney(row.amount) }}
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <SelectInput
                                            :model-value="row.category_id"
                                            class="block w-48"
                                            @update:model-value="(value) => updateClassifiedCategory(row, value)"
                                        >
                                            <option value="">Sem categoria</option>
                                            <option v-for="c in categoryOptionsForType(row.type)" :key="c.id" :value="c.id">{{ c.label }}</option>
                                        </SelectInput>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
