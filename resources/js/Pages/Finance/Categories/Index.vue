<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { buildCategoryTree, categoryTreeOptions } from '@/finance';
import { computed, ref } from 'vue';

const props = defineProps({
    categories: Array,
});

const expenseTree = computed(() => buildCategoryTree(props.categories.filter((c) => c.type === 'expense')));
const incomeTree = computed(() => buildCategoryTree(props.categories.filter((c) => c.type === 'income')));

function flatten(nodes, depth = 0) {
    return nodes.flatMap((node) => [{ ...node, depth }, ...flatten(node.children, depth + 1)]);
}

const expenseList = computed(() => flatten(expenseTree.value));
const incomeList = computed(() => flatten(incomeTree.value));

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    type: 'expense',
    color: '#64748b',
    parent_id: '',
});

function descendantIds(category) {
    return category.children.flatMap((child) => [child.id, ...descendantIds(child)]);
}

const parentOptions = computed(() => {
    const options = categoryTreeOptions(props.categories.filter((c) => c.type === form.type));

    if (!editing.value) return options;

    const excluded = new Set([editing.value.id, ...descendantIds(findNode(editing.value.id))]);

    return options.filter((option) => !excluded.has(option.id));
});

function findNode(id) {
    const search = (nodes) => {
        for (const node of nodes) {
            if (node.id === id) return node;
            const found = search(node.children);
            if (found) return found;
        }
        return null;
    };

    return search([...expenseTree.value, ...incomeTree.value]) ?? { id, children: [] };
}

function openCreate(type, parentId = '') {
    editing.value = null;
    form.reset();
    form.type = type;
    form.parent_id = parentId;
    form.clearErrors();
    showModal.value = true;
}

function openEdit(category) {
    editing.value = category;
    form.name = category.name;
    form.type = category.type;
    form.color = category.color;
    form.parent_id = category.parent_id ?? '';
    form.clearErrors();
    showModal.value = true;
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (showModal.value = false) };

    if (editing.value) {
        form.put(route('finance.categories.update', editing.value.id), options);
    } else {
        form.post(route('finance.categories.store'), options);
    }
}

function destroy(category) {
    if (confirm(`Remover "${category.name}"?`)) {
        useForm({}).delete(route('finance.categories.destroy', category.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Categorias" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Categorias</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="flex items-center justify-between border-b px-4 py-3 sm:px-6">
                        <h3 class="font-medium text-gray-900">Despesas</h3>
                        <PrimaryButton @click="openCreate('expense')">Nova categoria</PrimaryButton>
                    </div>
                    <ul class="divide-y divide-gray-200">
                        <li
                            v-for="category in expenseList"
                            :key="category.id"
                            class="flex items-center justify-between px-4 py-3 sm:px-6"
                            :style="{ paddingLeft: `${1 + category.depth * 1.5}rem` }"
                        >
                            <div class="flex items-center gap-3">
                                <span v-if="category.depth > 0" class="text-gray-300">↳</span>
                                <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: category.color }" />
                                <span class="text-gray-900">{{ category.name }}</span>
                            </div>
                            <div class="flex gap-3">
                                <button class="text-sm text-gray-500 hover:text-gray-900" @click="openCreate('expense', category.id)">
                                    + Subcategoria
                                </button>
                                <button class="text-sm text-indigo-600 hover:text-indigo-900" @click="openEdit(category)">Editar</button>
                                <button class="text-sm text-red-600 hover:text-red-900" @click="destroy(category)">Remover</button>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="flex items-center justify-between border-b px-4 py-3 sm:px-6">
                        <h3 class="font-medium text-gray-900">Receitas</h3>
                        <PrimaryButton @click="openCreate('income')">Nova categoria</PrimaryButton>
                    </div>
                    <ul class="divide-y divide-gray-200">
                        <li
                            v-for="category in incomeList"
                            :key="category.id"
                            class="flex items-center justify-between px-4 py-3 sm:px-6"
                            :style="{ paddingLeft: `${1 + category.depth * 1.5}rem` }"
                        >
                            <div class="flex items-center gap-3">
                                <span v-if="category.depth > 0" class="text-gray-300">↳</span>
                                <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: category.color }" />
                                <span class="text-gray-900">{{ category.name }}</span>
                            </div>
                            <div class="flex gap-3">
                                <button class="text-sm text-gray-500 hover:text-gray-900" @click="openCreate('income', category.id)">
                                    + Subcategoria
                                </button>
                                <button class="text-sm text-indigo-600 hover:text-indigo-900" @click="openEdit(category)">Editar</button>
                                <button class="text-sm text-red-600 hover:text-red-900" @click="destroy(category)">Remover</button>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="text-lg font-medium text-gray-900">
                    {{ editing ? 'Editar categoria' : 'Nova categoria' }}
                </h2>

                <div class="mt-6">
                    <InputLabel for="name" value="Nome" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div class="mt-4">
                    <InputLabel for="type" value="Tipo" />
                    <SelectInput id="type" v-model="form.type" class="mt-1 block w-full" :disabled="!!editing">
                        <option value="expense">Despesa</option>
                        <option value="income">Receita</option>
                    </SelectInput>
                    <InputError class="mt-2" :message="form.errors.type" />
                </div>

                <div class="mt-4">
                    <InputLabel for="parent_id" value="Categoria pai" />
                    <SelectInput id="parent_id" v-model="form.parent_id" class="mt-1 block w-full">
                        <option value="">Sem categoria pai</option>
                        <option v-for="option in parentOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
                    </SelectInput>
                    <InputError class="mt-2" :message="form.errors.parent_id" />
                </div>

                <div class="mt-4">
                    <InputLabel for="color" value="Cor" />
                    <input id="color" v-model="form.color" type="color" class="mt-1 block h-10 w-20 rounded-md border-gray-300" />
                    <InputError class="mt-2" :message="form.errors.color" />
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="showModal = false">Cancelar</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">Salvar</PrimaryButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
