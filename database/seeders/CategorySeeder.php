<?php

namespace Database\Seeders;

use App\Enums\TransactionType;
use App\Models\User;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Seed default categories (with subcategories) for every user that has none yet.
     */
    public function run(): void
    {
        User::all()->each(function (User $user) {
            if ($user->categories()->exists()) {
                return;
            }

            $this->createTree($user, self::tree(TransactionType::Expense), TransactionType::Expense);
            $this->createTree($user, self::tree(TransactionType::Income), TransactionType::Income);
        });
    }

    private function createTree(User $user, array $nodes, TransactionType $type, ?int $parentId = null): void
    {
        foreach ($nodes as $node) {
            $category = $user->categories()->create([
                'name' => $node['name'],
                'type' => $type,
                'color' => $node['color'],
                'parent_id' => $parentId,
            ]);

            if (! empty($node['children'])) {
                $this->createTree($user, $node['children'], $type, $category->id);
            }
        }
    }

    /**
     * Default category tree, shared with the categories:link-subcategories
     * command so already-seeded flat categories can be re-parented.
     */
    public static function tree(TransactionType $type): array
    {
        return $type === TransactionType::Expense ? self::expenseTree() : self::incomeTree();
    }

    private static function expenseTree(): array
    {
        return [
            ['name' => 'Alimentação', 'color' => '#f97316', 'children' => [
                ['name' => 'Supermercado', 'color' => '#fb923c'],
                ['name' => 'Restaurantes', 'color' => '#fdba74'],
            ]],
            ['name' => 'Transporte', 'color' => '#3b82f6', 'children' => [
                ['name' => 'Combustível', 'color' => '#60a5fa'],
            ]],
            ['name' => 'Moradia', 'color' => '#8b5cf6', 'children' => [
                ['name' => 'Contas de Casa', 'color' => '#a78bfa'],
                ['name' => 'Internet e Telefone', 'color' => '#7c3aed'],
            ]],
            ['name' => 'Saúde', 'color' => '#ef4444', 'children' => [
                ['name' => 'Farmácia', 'color' => '#f87171'],
                ['name' => 'Academia', 'color' => '#dc2626'],
            ]],
            ['name' => 'Lazer', 'color' => '#ec4899', 'children' => [
                ['name' => 'Viagem', 'color' => '#f472b6'],
            ]],
            ['name' => 'Educação', 'color' => '#0ea5e9', 'children' => [
                ['name' => 'Cursos', 'color' => '#38bdf8'],
            ]],
            ['name' => 'Compras', 'color' => '#f59e0b', 'children' => [
                ['name' => 'Vestuário', 'color' => '#fbbf24'],
                ['name' => 'Eletrônicos', 'color' => '#d97706'],
            ]],
            ['name' => 'Assinaturas', 'color' => '#6366f1', 'children' => [
                ['name' => 'Streaming', 'color' => '#818cf8'],
            ]],
            ['name' => 'Pets', 'color' => '#10b981'],
            ['name' => 'Filhos', 'color' => '#06b6d4'],
            ['name' => 'Presentes', 'color' => '#e879f9'],
            ['name' => 'Impostos e Taxas', 'color' => '#78716c'],
            ['name' => 'Seguros', 'color' => '#57534e'],
            ['name' => 'Investimentos', 'color' => '#65a30d'],
            ['name' => 'Dívidas e Empréstimos', 'color' => '#b91c1c'],
            ['name' => 'Doações', 'color' => '#f43f5e'],
            ['name' => 'Outros', 'color' => '#64748b'],
        ];
    }

    private static function incomeTree(): array
    {
        return [
            ['name' => 'Salário', 'color' => '#22c55e', 'children' => [
                ['name' => '13º Salário', 'color' => '#16a34a'],
            ]],
            ['name' => 'Freelance', 'color' => '#14b8a6'],
            ['name' => 'Investimentos', 'color' => '#65a30d', 'children' => [
                ['name' => 'Rendimentos', 'color' => '#84cc16'],
                ['name' => 'Aluguel Recebido', 'color' => '#0d9488'],
            ]],
            ['name' => 'Reembolso', 'color' => '#059669'],
            ['name' => 'Presente Recebido', 'color' => '#e879f9'],
            ['name' => 'Vendas', 'color' => '#4d7c0f'],
            ['name' => 'Outros', 'color' => '#64748b'],
        ];
    }
}
