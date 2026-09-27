<?php

namespace App\Console\Commands;

use App\Enums\TransactionType;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('categories:link-subcategories {--dry-run : List the changes without saving them}')]
#[Description('Set parent_id on already-seeded categories to match the default subcategory tree')]
class LinkCategorySubcategories extends Command
{
    private bool $dryRun = false;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        User::all()->each(function (User $user) use (&$updated) {
            $updated += $this->linkTree($user, CategorySeeder::tree(TransactionType::Expense), TransactionType::Expense, null);
            $updated += $this->linkTree($user, CategorySeeder::tree(TransactionType::Income), TransactionType::Income, null);
        });

        $prefix = $this->dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}{$updated} categoria(s) atualizada(s).");
    }

    private function linkTree(User $user, array $nodes, TransactionType $type, ?int $parentId): int
    {
        $updated = 0;

        foreach ($nodes as $node) {
            $category = $user->categories()
                ->where('type', $type)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($node['name'])])
                ->first();

            if (! $category) {
                continue;
            }

            if ($category->parent_id !== $parentId) {
                $this->line(sprintf(
                    'Usuário #%d: "%s" (%s) -> parent_id %s',
                    $user->id,
                    $category->name,
                    $type->value,
                    $parentId ?? 'null'
                ));

                if (! $this->dryRun) {
                    $category->update(['parent_id' => $parentId]);
                }

                $updated++;
            }

            if (! empty($node['children'])) {
                $updated += $this->linkTree($user, $node['children'], $type, $category->id);
            }
        }

        return $updated;
    }
}
