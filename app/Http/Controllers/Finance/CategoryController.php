<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Finance/Categories/Index', [
            'categories' => Category::orderBy('type')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $request->user()->categories()->create($request->validated());

        return Redirect::route('finance.categories.index');
    }

    public function update(StoreCategoryRequest $request, Category $category): RedirectResponse
    {
        $data = $request->validated();

        if (($data['parent_id'] ?? null) && $this->isDescendantOf($data['parent_id'], $category)) {
            return Redirect::back()->withErrors([
                'parent_id' => 'Uma categoria não pode ser filha de si mesma ou de uma de suas subcategorias.',
            ]);
        }

        $category->update($data);

        return Redirect::route('finance.categories.index');
    }

    private function isDescendantOf(int $candidateParentId, Category $category): bool
    {
        if ($candidateParentId === $category->id) {
            return true;
        }

        $parent = Category::find($candidateParentId);

        while ($parent) {
            if ($parent->id === $category->id) {
                return true;
            }

            $parent = $parent->parent;
        }

        return false;
    }

    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        return Redirect::route('finance.categories.index');
    }
}
