<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    institutions: Array,
    creditCards: Array,
});

const form = useForm({
    kind: 'credit',
    institution_id: '',
    credit_card_id: '',
    file: null,
});

const cardsOfInstitution = computed(() =>
    props.creditCards.filter((card) => card.institution_id === form.institution_id),
);

watch(
    () => [form.institution_id, form.kind],
    () => {
        form.credit_card_id = cardsOfInstitution.value.length === 1 ? cardsOfInstitution.value[0].id : '';
    },
);

const canSubmit = computed(
    () => form.institution_id && form.file && (form.kind === 'debit' || form.credit_card_id),
);

function handleFile(event) {
    form.file = event.target.files[0] ?? null;
}

function submit() {
    form.post(route('finance.statement-imports.upload'), {
        forceFormData: true,
    });
}
</script>

<template>
    <Head title="Importar fatura" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Importar fatura (PDF)</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <p class="text-sm text-gray-600">
                        Escolha o banco e envie o PDF da fatura do cartão (crédito) ou do extrato da conta (débito).
                        A OpenAI vai ler o documento e extrair os lançamentos — em faturas, as parcelas futuras de
                        compras parceladas são criadas automaticamente. Antes de importar, você revisa tudo e
                        define o dono de cada lançamento (incluindo transeuntes).
                    </p>

                    <form class="mt-6 space-y-6" @submit.prevent="submit">
                        <div>
                            <InputLabel for="institution_id" value="Banco" />
                            <SelectInput id="institution_id" v-model="form.institution_id" class="mt-1 block w-full">
                                <option value="" disabled>Selecione o banco</option>
                                <option v-for="institution in institutions" :key="institution.id" :value="institution.id">
                                    {{ institution.name }}
                                </option>
                            </SelectInput>
                            <InputError class="mt-2" :message="form.errors.institution_id" />
                        </div>

                        <div>
                            <InputLabel value="Tipo de extrato" />
                            <div class="mt-2 flex gap-6 text-sm text-gray-700">
                                <label class="flex items-center gap-2">
                                    <input v-model="form.kind" type="radio" value="credit" class="text-indigo-600" />
                                    Fatura do cartão (crédito)
                                </label>
                                <label class="flex items-center gap-2">
                                    <input v-model="form.kind" type="radio" value="debit" class="text-indigo-600" />
                                    Extrato da conta (débito)
                                </label>
                            </div>
                            <InputError class="mt-2" :message="form.errors.kind" />
                        </div>

                        <div v-if="form.kind === 'credit'">
                            <InputLabel for="credit_card_id" value="Cartão de crédito" />
                            <SelectInput
                                id="credit_card_id"
                                v-model="form.credit_card_id"
                                class="mt-1 block w-full"
                                :disabled="!form.institution_id"
                            >
                                <option value="" disabled>Selecione o cartão</option>
                                <option v-for="card in cardsOfInstitution" :key="card.id" :value="card.id">
                                    {{ card.name }}
                                </option>
                            </SelectInput>
                            <InputError class="mt-2" :message="form.errors.credit_card_id" />
                            <p v-if="form.institution_id && cardsOfInstitution.length === 0" class="mt-2 text-sm text-amber-600">
                                Nenhum cartão de crédito cadastrado para este banco.
                            </p>
                        </div>

                        <div>
                            <InputLabel for="file" value="Arquivo PDF" />
                            <input
                                id="file"
                                type="file"
                                accept="application/pdf"
                                class="mt-1 block w-full text-sm text-gray-600 file:mr-4 file:rounded-md file:border-0 file:bg-gray-800 file:px-4 file:py-2 file:text-xs file:font-semibold file:uppercase file:tracking-widest file:text-white hover:file:bg-gray-700"
                                @change="handleFile"
                            />
                            <InputError class="mt-2" :message="form.errors.file" />
                        </div>

                        <div v-if="form.processing" class="rounded-md bg-indigo-50 p-4 text-sm text-indigo-700">
                            <div v-if="form.progress && form.progress.percentage < 100">
                                Enviando arquivo... {{ form.progress.percentage }}%
                            </div>
                            <div v-else>
                                Lendo a fatura com a OpenAI, isso pode levar até um minuto...
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <PrimaryButton :disabled="form.processing || !canSubmit">
                                Enviar e analisar
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
