<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatDate, formatMoney } from '@/finance';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    cards: Array,
    institutions: Array,
    month: String,
});

const visibleCards = computed(() => props.cards.filter((card) => !card.not_started));

function addMonths(monthString, delta) {
    const [year, month] = monthString.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1 + delta, 1));

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

function monthLabel(monthString) {
    const [year, month] = monthString.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
}

function goToMonth(monthString) {
    router.get(route('finance.credit-cards.index'), { month: monthString }, { preserveState: true, preserveScroll: true });
}

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    institution_id: props.institutions[0]?.id ?? '',
    name: '',
    credit_limit: '',
    closing_day: '',
    due_day: '',
    starts_at: '',
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.institution_id = props.institutions[0]?.id ?? '';
    form.clearErrors();
    showModal.value = true;
}

function openEdit(card) {
    editing.value = card;
    form.institution_id = card.institution.id;
    form.name = card.name;
    form.credit_limit = card.credit_limit;
    form.closing_day = String(card.closing_day);
    form.due_day = String(card.due_day);
    form.starts_at = card.starts_at ?? '';
    form.clearErrors();
    showModal.value = true;
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (showModal.value = false) };

    form.transform((data) => ({ ...data, starts_at: data.starts_at ? `${data.starts_at}-01` : null }));

    if (editing.value) {
        form.put(route('finance.credit-cards.update', editing.value.id), options);
    } else {
        form.post(route('finance.credit-cards.store'), options);
    }
}
</script>

<template>
    <Head title="Cartões de crédito" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Cartões de crédito</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-gray-300 px-2 py-1 text-sm text-gray-600 hover:bg-gray-50"
                            @click="goToMonth(addMonths(month, -1))"
                        >
                            ←
                        </button>
                        <span class="min-w-[9rem] text-center text-sm font-medium capitalize text-gray-700">
                            {{ monthLabel(month) }}
                        </span>
                        <button
                            type="button"
                            class="rounded-md border border-gray-300 px-2 py-1 text-sm text-gray-600 hover:bg-gray-50"
                            @click="goToMonth(addMonths(month, 1))"
                        >
                            →
                        </button>
                    </div>
                    <PrimaryButton @click="openCreate">Novo cartão</PrimaryButton>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="card in visibleCards" :key="card.id" class="rounded-lg bg-white p-5 shadow">
                        <div class="flex items-start justify-between">
                            <Link :href="route('finance.invoices.show', card.current_invoice_id)" class="hover:text-indigo-600">
                                <p class="flex items-center gap-2 text-sm text-gray-500">
                                    {{ card.institution?.name ?? 'Dinheiro' }} / {{ card.name }}
                                    <span
                                        v-if="card.invoice_status === 'paid'"
                                        class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700"
                                    >
                                        Paga
                                    </span>
                                </p>
                                <p class="mt-2 text-2xl font-semibold text-gray-900">{{ formatMoney(card.open_invoice_total) }}</p>
                            </Link>
                            <button class="text-sm text-indigo-600 hover:text-indigo-900" @click="openEdit(card)">Editar</button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            <template v-if="card.due_date">Vencimento {{ formatDate(card.due_date) }}<br /></template>
                            Limite disponível: {{ formatMoney(card.available_limit) }}
                        </p>
                    </div>

                    <p v-if="visibleCards.length === 0" class="text-sm text-gray-500">
                        Nenhum cartão de crédito cadastrado ainda.
                    </p>
                </div>
            </div>
        </div>

        <Modal :show="showModal" max-width="lg" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="text-lg font-medium text-gray-900">
                    {{ editing ? 'Editar cartão' : 'Novo cartão' }}
                </h2>

                <div class="mt-6 grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="institution_id" value="Banco" />
                        <SelectInput id="institution_id" v-model="form.institution_id" class="mt-1 block w-full">
                            <option v-for="i in institutions" :key="i.id" :value="i.id">{{ i.name }}</option>
                        </SelectInput>
                        <InputError class="mt-2" :message="form.errors.institution_id" />
                    </div>

                    <div>
                        <InputLabel for="name" value="Nome do cartão" />
                        <TextInput id="name" v-model="form.name" class="mt-1 block w-full" placeholder="Ex: Roxinho" required />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <InputLabel for="credit_limit" value="Limite" />
                        <TextInput
                            id="credit_limit"
                            v-model="form.credit_limit"
                            type="number"
                            step="0.01"
                            class="mt-1 block w-full"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.credit_limit" />
                    </div>

                    <div>
                        <InputLabel for="closing_day" value="Dia de fechamento" />
                        <TextInput
                            id="closing_day"
                            v-model="form.closing_day"
                            type="number"
                            min="1"
                            max="31"
                            class="mt-1 block w-full"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.closing_day" />
                    </div>

                    <div>
                        <InputLabel for="due_day" value="Dia de vencimento" />
                        <TextInput
                            id="due_day"
                            v-model="form.due_day"
                            type="number"
                            min="1"
                            max="31"
                            class="mt-1 block w-full"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.due_day" />
                    </div>

                    <div>
                        <InputLabel for="starts_at" value="Começa a contar em" />
                        <TextInput id="starts_at" v-model="form.starts_at" type="month" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.starts_at" />
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
