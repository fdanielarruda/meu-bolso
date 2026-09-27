<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { categoryTreeOptions, formatDate, formatMoney, frequencyLabels } from '@/finance';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ref } from 'vue';

const props = defineProps({
    recurringTransactions: Array,
    accounts: Array,
    people: Array,
    categories: Array,
});

const categoryOptions = computed(() => categoryTreeOptions(props.categories));

const showModal = ref(false);
const editing = ref(null);
const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    account_id: props.accounts[0]?.id ?? '',
    person_id: props.people[0]?.id ?? '',
    category_id: '',
    type: 'expense',
    description: '',
    amount: '',
    frequency: 'monthly',
    interval: 1,
    start_date: today,
    end_date: '',
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.account_id = props.accounts[0]?.id ?? '';
    form.person_id = props.people[0]?.id ?? '';
    form.clearErrors();
    showModal.value = true;
}

function openEdit(recurring) {
    editing.value = recurring;
    form.account_id = recurring.account.id;
    form.person_id = recurring.person.id;
    form.category_id = recurring.category?.id ?? '';
    form.type = recurring.type;
    form.description = recurring.description;
    form.amount = recurring.amount;
    form.frequency = recurring.frequency;
    form.interval = recurring.interval;
    form.start_date = recurring.start_date;
    form.end_date = recurring.end_date ?? '';
    form.clearErrors();
    showModal.value = true;
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (showModal.value = false) };

    if (editing.value) {
        form.put(route('finance.recurring.update', editing.value.id), options);
    } else {
        form.post(route('finance.recurring.store'), options);
    }
}

function destroy(recurring) {
    if (confirm(`Remover a recorrência "${recurring.description}"?`)) {
        useForm({}).delete(route('finance.recurring.destroy', recurring.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Transações recorrentes" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Transações recorrentes</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div class="flex justify-end">
                    <PrimaryButton @click="openCreate">Nova recorrência</PrimaryButton>
                </div>

                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <ul class="divide-y divide-gray-200">
                        <li
                            v-for="recurring in recurringTransactions"
                            :key="recurring.id"
                            class="flex items-center justify-between px-4 py-3 sm:px-6"
                        >
                            <div>
                                <p class="text-gray-900">
                                    {{ recurring.description }}
                                    <span v-if="!recurring.is_active" class="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500">
                                        encerrada
                                    </span>
                                </p>
                                <p class="text-xs text-gray-500">
                                    {{ recurring.account.name }} · {{ recurring.person.name }} · {{ frequencyLabels[recurring.frequency] }}
                                    · próxima em {{ formatDate(recurring.next_run_date) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span :class="recurring.type === 'income' ? 'text-green-600' : 'text-red-600'">
                                    {{ recurring.type === 'income' ? '+' : '-' }}{{ formatMoney(recurring.amount) }}
                                </span>
                                <button class="text-sm text-indigo-600 hover:text-indigo-900" @click="openEdit(recurring)">Editar</button>
                                <button class="text-sm text-red-600 hover:text-red-900" @click="destroy(recurring)">Remover</button>
                            </div>
                        </li>
                        <li v-if="recurringTransactions.length === 0" class="px-4 py-6 text-sm text-gray-500">
                            Nenhuma recorrência cadastrada ainda.
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <Modal :show="showModal" max-width="lg" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="text-lg font-medium text-gray-900">
                    {{ editing ? 'Editar recorrência' : 'Nova recorrência' }}
                </h2>

                <div class="mt-6 grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <InputLabel for="description" value="Descrição" />
                        <TextInput id="description" v-model="form.description" class="mt-1 block w-full" required autofocus />
                        <InputError class="mt-2" :message="form.errors.description" />
                    </div>

                    <div>
                        <InputLabel for="amount" value="Valor" />
                        <TextInput id="amount" v-model="form.amount" type="number" step="0.01" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.amount" />
                    </div>

                    <div>
                        <InputLabel for="type" value="Tipo" />
                        <SelectInput id="type" v-model="form.type" class="mt-1 block w-full">
                            <option value="expense">Despesa</option>
                            <option value="income">Receita</option>
                        </SelectInput>
                    </div>

                    <div>
                        <InputLabel for="account_id" value="Conta" />
                        <SelectInput id="account_id" v-model="form.account_id" class="mt-1 block w-full">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </SelectInput>
                        <InputError class="mt-2" :message="form.errors.account_id" />
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

                    <div>
                        <InputLabel for="frequency" value="Frequência" />
                        <SelectInput id="frequency" v-model="form.frequency" class="mt-1 block w-full">
                            <option value="weekly">Semanal</option>
                            <option value="monthly">Mensal</option>
                            <option value="yearly">Anual</option>
                        </SelectInput>
                    </div>

                    <div>
                        <InputLabel for="interval" value="A cada" />
                        <TextInput id="interval" v-model="form.interval" type="number" min="1" class="mt-1 block w-full" />
                    </div>

                    <div>
                        <InputLabel for="start_date" value="Início" />
                        <TextInput id="start_date" v-model="form.start_date" type="date" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.start_date" />
                    </div>

                    <div>
                        <InputLabel for="end_date" value="Fim (opcional)" />
                        <TextInput id="end_date" v-model="form.end_date" type="date" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.end_date" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="showModal = false">Cancelar</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">Salvar</PrimaryButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
