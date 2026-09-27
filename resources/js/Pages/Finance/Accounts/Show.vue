<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { categoryTreeOptions, formatDate, formatMoney } from '@/finance';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    account: Object,
    transactions: Object,
    categories: Array,
    people: Array,
});

const categoryOptions = computed(() => categoryTreeOptions(props.categories));

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    account_id: props.account.id,
    person_id: props.account.person.id,
    category_id: '',
    type: 'expense',
    description: '',
    amount: '',
    date: today,
});

function submit() {
    form.post(route('finance.transactions.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('description', 'amount'),
    });
}

function destroyTransaction(transaction) {
    if (confirm('Remover este lançamento?')) {
        useForm({}).delete(route('finance.transactions.destroy', transaction.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="account.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ account.name }}</h2>
                <Link :href="route('finance.accounts.index')" class="text-sm text-indigo-600 hover:text-indigo-900">
                    Voltar para contas
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-white p-5 shadow">
                        <p class="text-sm text-gray-500">Saldo atual</p>
                        <p class="text-2xl font-semibold text-gray-900">{{ formatMoney(account.balance) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow">
                        <p class="text-sm text-gray-500">Dono</p>
                        <p class="text-2xl font-semibold text-gray-900">{{ account.person.name }}</p>
                    </div>
                </div>

                <div class="overflow-hidden bg-white p-5 shadow sm:rounded-lg">
                    <h3 class="font-medium text-gray-900">Novo lançamento</h3>
                    <form class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3" @submit.prevent="submit">
                        <div class="col-span-2 sm:col-span-1">
                            <InputLabel for="description" value="Descrição" />
                            <TextInput id="description" v-model="form.description" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.description" />
                        </div>

                        <div>
                            <InputLabel for="amount" value="Valor" />
                            <TextInput id="amount" v-model="form.amount" type="number" step="0.01" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.amount" />
                        </div>

                        <div>
                            <InputLabel for="date" value="Data" />
                            <TextInput id="date" v-model="form.date" type="date" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.date" />
                        </div>

                        <div>
                            <InputLabel for="type" value="Tipo" />
                            <SelectInput id="type" v-model="form.type" class="mt-1 block w-full">
                                <option value="expense">Despesa</option>
                                <option value="income">Receita</option>
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel for="person_id" value="Pessoa" />
                            <SelectInput id="person_id" v-model="form.person_id" class="mt-1 block w-full">
                                <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel for="category_id" value="Categoria" />
                            <SelectInput id="category_id" v-model="form.category_id" class="mt-1 block w-full">
                                <option value="">Sem categoria</option>
                                <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
                            </SelectInput>
                        </div>

                        <div class="col-span-2 flex items-end sm:col-span-1">
                            <PrimaryButton :disabled="form.processing">Adicionar</PrimaryButton>
                        </div>
                    </form>
                </div>

                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <h3 class="border-b px-4 py-3 font-medium text-gray-900 sm:px-6">Lançamentos</h3>
                    <ul class="divide-y divide-gray-200">
                        <li
                            v-for="transaction in transactions.data"
                            :key="transaction.id"
                            class="flex items-center justify-between px-4 py-3 sm:px-6"
                        >
                            <div>
                                <p class="text-gray-900">
                                    {{ transaction.description }}
                                    <span v-if="transaction.installment_total" class="text-xs text-gray-500">
                                        ({{ transaction.installment_number }}/{{ transaction.installment_total }})
                                    </span>
                                </p>
                                <p class="text-xs text-gray-500">
                                    {{ formatDate(transaction.date) }} · {{ transaction.person.name }}
                                    <template v-if="transaction.category"> · {{ transaction.category.name }}</template>
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span :class="transaction.type === 'income' ? 'text-green-600' : 'text-red-600'">
                                    {{ transaction.type === 'income' ? '+' : '-' }}{{ formatMoney(transaction.amount) }}
                                </span>
                                <button class="text-sm text-red-600 hover:text-red-900" @click="destroyTransaction(transaction)">
                                    Remover
                                </button>
                            </div>
                        </li>
                        <li v-if="transactions.data.length === 0" class="px-4 py-6 text-sm text-gray-500">
                            Nenhum lançamento ainda.
                        </li>
                    </ul>

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
</template>
