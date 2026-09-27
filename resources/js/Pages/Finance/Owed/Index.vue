<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatDate, formatMoney } from '@/finance';
import { Head, Link, router } from '@inertiajs/vue3';
import html2canvas from 'html2canvas';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    accounts: Array,
    cards: Array,
    people: Array,
    filters: Object,
});

const filters = reactive({
    person_id: props.filters.person_id ?? '',
    month: props.filters.month ?? '',
});

function applyFilters() {
    router.get(route('finance.owed.index'), filters, { preserveState: true, replace: true });
}

function bankLabel(entity) {
    return entity.institution?.name ?? 'Dinheiro';
}

function monthLabel(monthString) {
    const [year, month] = monthString.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, 1))
        .toLocaleDateString('pt-BR', { month: 'short', year: '2-digit', timeZone: 'UTC' })
        .replace('.', '')
        .replace(/^\w/, (c) => c.toUpperCase());
}

const accountsTotal = computed(() =>
    props.accounts.reduce((sum, a) => sum + Number(a.balance), 0)
);

const cardsTotal = computed(() =>
    props.cards.reduce((sum, c) => sum + Number(c.total), 0)
);

const showReceiptModal = ref(false);
const receiptItems = ref([]);
const receiptTotal = ref('0.00');
const receiptLoading = ref(false);
const receiptContent = ref(null);
const copyState = ref('idle');

function openReceipt() {
    receiptItems.value = [];
    receiptTotal.value = '0.00';
    receiptLoading.value = true;
    showReceiptModal.value = true;

    window.axios
        .get(route('finance.owed.receipt'), { params: { person_id: filters.person_id || null, month: filters.month || null } })
        .then((response) => {
            receiptItems.value = response.data.items;
            receiptTotal.value = response.data.total;
        })
        .finally(() => (receiptLoading.value = false));
}

async function copyAsImage() {
    if (!receiptContent.value) return;

    copyState.value = 'copying';

    try {
        const canvas = await html2canvas(receiptContent.value, { backgroundColor: '#ffffff', scale: 2 });
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));

        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        copyState.value = 'copied';
    } catch (error) {
        console.error(error);
        copyState.value = 'error';
    } finally {
        setTimeout(() => (copyState.value = 'idle'), 2000);
    }
}
</script>

<template>
    <Head title="Quanto me devem" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Quanto me devem</h2>
                <SecondaryButton @click="openReceipt">Resumo</SecondaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-4 rounded-lg bg-white p-5 shadow sm:grid-cols-2">
                    <div>
                        <SelectInput v-model="filters.person_id" class="block w-full" @change="applyFilters">
                            <option value="">Todas as pessoas</option>
                            <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </SelectInput>
                    </div>

                    <div>
                        <TextInput v-model="filters.month" type="month" class="block w-full" @change="applyFilters" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-white p-5 shadow">
                        <p class="text-sm text-gray-500">Total nas faturas do mês</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatMoney(cardsTotal) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow">
                        <p class="text-sm text-gray-500">Saldo atual nas contas</p>
                        <p
                            class="mt-1 text-2xl font-semibold"
                            :class="accountsTotal < 0 ? 'text-red-600' : 'text-gray-900'"
                        >
                            {{ formatMoney(accountsTotal) }}
                        </p>
                    </div>
                </div>

                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <h3 class="border-b px-4 py-3 font-medium text-gray-900 sm:px-6">Cartões (fatura do mês)</h3>
                    <ul class="divide-y divide-gray-200">
                        <li
                            v-for="card in cards"
                            :key="card.id"
                            class="flex items-center justify-between px-4 py-3 sm:px-6"
                        >
                            <div>
                                <Link
                                    v-if="card.invoice_id"
                                    :href="route('finance.invoices.show', card.invoice_id)"
                                    class="font-medium text-gray-900 hover:text-indigo-600"
                                >
                                    {{ bankLabel(card) }} / {{ card.name }}
                                </Link>
                                <p v-else class="font-medium text-gray-900">{{ bankLabel(card) }} / {{ card.name }}</p>
                                <p v-if="card.due_date" class="text-xs text-gray-500">
                                    Vencimento {{ formatDate(card.due_date) }}
                                </p>
                            </div>
                            <span class="font-semibold" :class="Number(card.total) < 0 ? 'text-green-600' : 'text-gray-900'">
                                {{ formatMoney(card.total) }}
                            </span>
                        </li>
                        <li v-if="cards.length === 0" class="px-4 py-6 text-sm text-gray-500">
                            Nenhum cartão cadastrado.
                        </li>
                    </ul>
                </div>

                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <h3 class="border-b px-4 py-3 font-medium text-gray-900 sm:px-6">Contas (saldo atual)</h3>
                    <ul class="divide-y divide-gray-200">
                        <li
                            v-for="account in accounts"
                            :key="account.id"
                            class="flex items-center justify-between px-4 py-3 sm:px-6"
                        >
                            <div>
                                <Link
                                    :href="route('finance.accounts.show', account.id)"
                                    class="font-medium text-gray-900 hover:text-indigo-600"
                                >
                                    {{ bankLabel(account) }} / {{ account.name }}
                                </Link>
                                <p class="text-xs text-gray-500">{{ account.person.name }}</p>
                            </div>
                            <span class="font-semibold" :class="Number(account.balance) < 0 ? 'text-red-600' : 'text-gray-900'">
                                {{ formatMoney(account.balance) }}
                            </span>
                        </li>
                        <li v-if="accounts.length === 0" class="px-4 py-6 text-sm text-gray-500">
                            Nenhuma conta encontrada.
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <Modal :show="showReceiptModal" max-width="lg" @close="showReceiptModal = false">
            <div ref="receiptContent" class="p-6">
                <div class="bg-white p-2">
                    <h2 class="text-lg font-medium text-gray-900">Resumo · {{ monthLabel(filters.month) }}</h2>

                    <p v-if="receiptLoading" class="mt-4 text-sm text-gray-500">Carregando...</p>

                    <div v-else class="mt-4 space-y-4">
                        <ul class="divide-y divide-gray-100">
                            <li
                                v-for="(item, index) in receiptItems"
                                :key="index"
                                class="flex items-center justify-between py-1.5 text-sm"
                            >
                                <span class="text-gray-700">
                                    {{ item.description }}
                                    <span v-if="item.installment_total" class="text-xs text-gray-500">
                                        ({{ item.installment_number }}/{{ item.installment_total }})
                                    </span>
                                </span>
                                <span class="font-medium" :class="Number(item.amount) < 0 ? 'text-red-600' : 'text-gray-900'">
                                    {{ formatMoney(item.amount) }}
                                </span>
                            </li>
                            <li v-if="receiptItems.length === 0" class="py-4 text-sm text-gray-500">
                                Nada a mostrar para este filtro.
                            </li>
                        </ul>

                        <div class="flex items-center justify-between border-t pt-3">
                            <span class="text-sm font-semibold text-gray-900">Total geral</span>
                            <span
                                class="text-lg font-semibold"
                                :class="Number(receiptTotal) < 0 ? 'text-red-600' : 'text-gray-900'"
                            >
                                {{ formatMoney(receiptTotal) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 p-6 pt-0">
                <SecondaryButton @click="showReceiptModal = false">Fechar</SecondaryButton>
                <SecondaryButton :disabled="receiptLoading || copyState === 'copying'" @click="copyAsImage">
                    {{
                        copyState === 'copied'
                            ? 'Copiado!'
                            : copyState === 'error'
                              ? 'Erro ao copiar'
                              : 'Copiar como imagem'
                    }}
                </SecondaryButton>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
