<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatMoney } from '@/finance';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    accounts: Array,
});

const today = new Date().toISOString().slice(0, 10);

function bankLabel(account) {
    return account.institution?.name ?? 'Dinheiro';
}

function bankColor(account) {
    return account.institution?.color ?? '#94a3b8';
}

const banks = computed(() => {
    const groups = new Map();

    for (const account of props.accounts) {
        const key = account.institution?.id ?? 'none';

        if (!groups.has(key)) {
            groups.set(key, { key, label: bankLabel(account), color: bankColor(account), accounts: [] });
        }

        groups.get(key).accounts.push(account);
    }

    for (const bank of groups.values()) {
        bank.total = bank.accounts.reduce((sum, account) => sum + Number(account.balance), 0);

        bank.accounts.sort((a, b) => {
            const balanceA = Number(a.balance);
            const balanceB = Number(b.balance);

            if (balanceA === 0 && balanceB === 0) return 0;
            if (balanceA === 0) return 1;
            if (balanceB === 0) return -1;

            return balanceB - balanceA;
        });
    }

    return [...groups.values()].sort((a, b) => a.label.localeCompare(b.label));
});

function bankKey(account) {
    return account.institution?.id ?? 'none';
}

function accountFor(personId, bankId) {
    return (
        props.accounts.find(
            (a) => a.person?.id === Number(personId) && String(bankKey(a)) === String(bankId)
        ) ?? null
    );
}

const people = computed(() => {
    const seen = new Map();

    for (const account of props.accounts) {
        if (account.person && !seen.has(account.person.id)) {
            seen.set(account.person.id, account.person);
        }
    }

    return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name));
});

const form = useForm({
    person_a_id: '',
    person_b_id: '',
    institution_one_id: '',
    institution_two_id: '',
    amount: '',
    date: today,
    description: 'Troca',
    is_movement_only: true,
});

// Banks where the given person actually has an account, so the user can
// only pick a leg that resolves to a real account on both sides.
function banksForPerson(personId) {
    if (!personId) return banks.value;

    return banks.value.filter((bank) => accountFor(personId, bank.key));
}

const banksForBoth = computed(() => {
    const a = banksForPerson(form.person_a_id);
    const b = banksForPerson(form.person_b_id);
    const bKeys = new Set(b.map((bank) => String(bank.key)));

    return a.filter((bank) => bKeys.has(String(bank.key)));
});

const banksForOne = computed(() => banksForBoth.value);
const banksForTwo = computed(() =>
    banksForBoth.value.filter((bank) => String(bank.key) !== String(form.institution_one_id))
);

watch([() => form.person_a_id, () => form.person_b_id], () => {
    if (!banksForOne.value.some((bank) => String(bank.key) === String(form.institution_one_id))) {
        form.institution_one_id = '';
    }
    if (!banksForTwo.value.some((bank) => String(bank.key) === String(form.institution_two_id))) {
        form.institution_two_id = '';
    }
});

watch(
    () => form.institution_one_id,
    () => {
        if (String(form.institution_two_id) === String(form.institution_one_id)) {
            form.institution_two_id = '';
        }
    }
);

const accountAInOne = computed(() => accountFor(form.person_a_id, form.institution_one_id));
const accountBInOne = computed(() => accountFor(form.person_b_id, form.institution_one_id));
const accountBInTwo = computed(() => accountFor(form.person_b_id, form.institution_two_id));
const accountAInTwo = computed(() => accountFor(form.person_a_id, form.institution_two_id));

const previewAmount = computed(() => Number(form.amount || 0));

function balanceAfter(account, direction) {
    if (!account) return null;

    const delta = direction === 'out' ? -previewAmount.value : previewAmount.value;

    return Number(account.balance) + delta;
}

function personName(personId) {
    return people.value.find((p) => p.id === Number(personId))?.name ?? '';
}

function bankLabelFor(bankId) {
    return banks.value.find((bank) => String(bank.key) === String(bankId))?.label ?? '';
}

function submit() {
    form.transform((data) => ({
        ...data,
        institution_one_id: data.institution_one_id === 'none' ? null : data.institution_one_id,
        institution_two_id: data.institution_two_id === 'none' ? null : data.institution_two_id,
    })).post(route('finance.swaps.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount');
            router.reload({ only: ['accounts'] });
        },
    });
}
</script>

<template>
    <Head title="Trocas" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Trocas</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="bank in banks"
                        :key="bank.label"
                        class="overflow-hidden rounded-lg bg-white shadow"
                    >
                        <div
                            class="flex items-center gap-2 px-4 py-1"
                            :style="{ backgroundColor: bank.color + '1a' }"
                        >
                            <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: bank.color }" />
                            <span class="text-sm font-semibold text-gray-900">{{ bank.label }}</span>
                            <span
                                class="ml-auto text-sm font-semibold"
                                :class="bank.total < 0 ? 'text-red-600' : bank.total === 0 ? 'text-gray-400' : 'text-emerald-600'"
                            >
                                {{ formatMoney(bank.total) }}
                            </span>
                        </div>
                        <ul class="divide-y divide-gray-100">
                            <li
                                v-for="account in bank.accounts"
                                :key="account.id"
                                class="flex items-center justify-between px-4 py-1"
                            >
                                <p class="text-sm font-medium text-gray-900">{{ account.name }}</p>
                                <span
                                    class="text-sm font-semibold"
                                    :class="Number(account.balance) < 0 ? 'text-red-600' : Number(account.balance) === 0 ? 'text-gray-400' : 'text-emerald-600'"
                                >
                                    {{ formatMoney(account.balance) }}
                                </span>
                            </li>
                        </ul>
                    </div>

                    <p v-if="banks.length === 0" class="col-span-full text-sm text-gray-500">
                        Nenhuma conta encontrada.
                    </p>
                </div>

                <div aria-hidden="true" style="height: 32px"></div>

                <div class="overflow-hidden rounded-lg bg-white shadow">
                    <h3 class="border-b px-4 py-3 font-medium text-gray-900 sm:px-6">Fazer uma troca</h3>

                    <form class="p-4 sm:p-6" @submit.prevent="submit">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Pessoa A" />
                                <SelectInput v-model="form.person_a_id" class="mt-1 block w-full">
                                    <option value="" disabled>Selecione</option>
                                    <option v-for="person in people" :key="person.id" :value="person.id">
                                        {{ person.name }}
                                    </option>
                                </SelectInput>
                                <InputError :message="form.errors.person_a_id" class="mt-1" />
                            </div>

                            <div>
                                <InputLabel value="Pessoa B" />
                                <SelectInput v-model="form.person_b_id" class="mt-1 block w-full">
                                    <option value="" disabled>Selecione</option>
                                    <option v-for="person in people" :key="person.id" :value="person.id">
                                        {{ person.name }}
                                    </option>
                                </SelectInput>
                                <InputError :message="form.errors.person_b_id" class="mt-1" />
                            </div>
                        </div>

                        <div class="mt-6 grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
                            <!-- Perna 1 -->
                            <div>
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Banco 1 · A paga B
                                </p>
                                <SelectInput v-model="form.institution_one_id" class="block w-full">
                                    <option value="" disabled>Banco</option>
                                    <option v-for="bank in banksForOne" :key="bank.key" :value="bank.key">
                                        {{ bank.label }}
                                    </option>
                                </SelectInput>
                                <InputError :message="form.errors.institution_one_id" class="mt-1" />

                                <div v-if="accountAInOne" class="mt-3 flex items-center justify-between text-sm">
                                    <span class="text-gray-700">{{ personName(form.person_a_id) }}</span>
                                    <span class="text-gray-500">
                                        {{ formatMoney(accountAInOne.balance) }}
                                        <template v-if="previewAmount > 0">
                                            → <span class="font-semibold text-red-600">{{ formatMoney(balanceAfter(accountAInOne, 'out')) }}</span>
                                        </template>
                                    </span>
                                </div>
                                <div v-if="accountBInOne" class="mt-1 flex items-center justify-between text-sm">
                                    <span class="text-gray-700">{{ personName(form.person_b_id) }}</span>
                                    <span class="text-gray-500">
                                        {{ formatMoney(accountBInOne.balance) }}
                                        <template v-if="previewAmount > 0">
                                            → <span class="font-semibold text-emerald-600">{{ formatMoney(balanceAfter(accountBInOne, 'in')) }}</span>
                                        </template>
                                    </span>
                                </div>
                            </div>

                            <!-- Perna 2 -->
                            <div>
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Banco 2 · B paga A
                                </p>
                                <SelectInput v-model="form.institution_two_id" class="block w-full">
                                    <option value="" disabled>Banco</option>
                                    <option v-for="bank in banksForTwo" :key="bank.key" :value="bank.key">
                                        {{ bank.label }}
                                    </option>
                                </SelectInput>
                                <InputError :message="form.errors.institution_two_id" class="mt-1" />

                                <div v-if="accountBInTwo" class="mt-3 flex items-center justify-between text-sm">
                                    <span class="text-gray-700">{{ personName(form.person_b_id) }}</span>
                                    <span class="text-gray-500">
                                        {{ formatMoney(accountBInTwo.balance) }}
                                        <template v-if="previewAmount > 0">
                                            → <span class="font-semibold text-red-600">{{ formatMoney(balanceAfter(accountBInTwo, 'out')) }}</span>
                                        </template>
                                    </span>
                                </div>
                                <div v-if="accountAInTwo" class="mt-1 flex items-center justify-between text-sm">
                                    <span class="text-gray-700">{{ personName(form.person_a_id) }}</span>
                                    <span class="text-gray-500">
                                        {{ formatMoney(accountAInTwo.balance) }}
                                        <template v-if="previewAmount > 0">
                                            → <span class="font-semibold text-emerald-600">{{ formatMoney(balanceAfter(accountAInTwo, 'in')) }}</span>
                                        </template>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <p
                            v-if="previewAmount > 0 && form.institution_one_id && form.institution_two_id"
                            class="mt-4 text-sm text-gray-500"
                        >
                            {{ personName(form.person_a_id) }} paga {{ formatMoney(previewAmount) }} para
                            {{ personName(form.person_b_id) }} no {{ bankLabelFor(form.institution_one_id) }}, e
                            {{ personName(form.person_b_id) }} paga {{ formatMoney(previewAmount) }} de volta no
                            {{ bankLabelFor(form.institution_two_id) }}.
                        </p>

                        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Valor" />
                                <TextInput v-model="form.amount" type="number" step="0.01" min="0.01" class="mt-1 block w-full" />
                                <InputError :message="form.errors.amount" class="mt-1" />
                            </div>

                            <div>
                                <InputLabel value="Data" />
                                <TextInput v-model="form.date" type="date" class="mt-1 block w-full" />
                                <InputError :message="form.errors.date" class="mt-1" />
                            </div>
                        </div>

                        <div class="mt-4">
                            <InputLabel value="Descrição" />
                            <TextInput v-model="form.description" type="text" class="mt-1 block w-full" />
                            <InputError :message="form.errors.description" class="mt-1" />
                        </div>

                        <div class="mt-6 flex justify-end">
                            <PrimaryButton :disabled="form.processing">Fazer troca</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
