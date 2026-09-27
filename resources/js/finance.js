export function formatMoney(value) {
    const number = Number(value ?? 0);

    return number.toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    });
}

export function formatDate(value) {
    if (!value) return '';

    return new Date(value.slice(0, 10) + 'T00:00:00').toLocaleDateString('pt-BR');
}

export const accountTypeLabels = {
    checking: 'Conta corrente',
    savings: 'Poupança',
    wallet: 'Carteira/Dinheiro',
    investment: 'Investimento',
    credit_card: 'Cartão de crédito',
};

export const frequencyLabels = {
    weekly: 'Semanal',
    monthly: 'Mensal',
    yearly: 'Anual',
};

export const invoiceStatusLabels = {
    open: 'Aberta',
    closed: 'Fechada',
    paid: 'Paga',
};

export function buildCategoryTree(categories) {
    const byParent = new Map();
    for (const category of categories) {
        const key = category.parent_id ?? null;
        if (!byParent.has(key)) byParent.set(key, []);
        byParent.get(key).push(category);
    }

    function attach(parentId) {
        return (byParent.get(parentId) ?? []).map((category) => ({
            ...category,
            children: attach(category.id),
        }));
    }

    return attach(null);
}

/**
 * Flattens categories into depth-first order with an indented label, so any
 * node in the tree (not just leaves) can be picked in a <select>.
 */
export function categoryTreeOptions(categories) {
    const options = [];

    function walk(nodes, depth) {
        for (const node of nodes) {
            options.push({
                id: node.id,
                name: node.name,
                type: node.type,
                depth,
                label: `${'    '.repeat(depth)}${depth > 0 ? '↳ ' : ''}${node.name}`,
            });
            walk(node.children, depth + 1);
        }
    }

    walk(buildCategoryTree(categories), 0);

    return options;
}
