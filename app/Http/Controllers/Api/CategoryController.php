<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::withCount('products');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        if ($request->query('flat') === 'true') {
            return $query->orderBy('name')->get();
        }

        return $query->whereNull('parent_id')
            ->with(['children' => fn($q) => $q->withCount('products')])
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        $name = trim($request->input('name'));
        $slug = Str::slug($name);

        // Collision-safe: return existing category if slug matches
        $existing = Category::where('slug', $slug)->first();
        if ($existing) {
            return response()->json([
                'data' => $existing->loadCount('products'),
                'existed' => true,
                'message' => 'Category already exists',
            ]);
        }

        $category = Category::create([
            'name' => $name,
            'slug' => $slug,
            'parent_id' => $request->input('parent_id'),
        ]);

        return response()->json([
            'data' => $category->loadCount('products'),
            'existed' => false,
            'message' => 'Category created',
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'exists:categories,id', Rule::notIn([$id])],
        ]);

        $name = trim($request->input('name'));
        $slug = Str::slug($name);

        // Check collision with other categories
        $collision = Category::where('slug', $slug)->where('id', '!=', $id)->first();
        if ($collision) {
            return response()->json([
                'message' => 'A category with this name already exists',
                'existing' => $collision,
            ], 409);
        }

        $category->update([
            'name' => $name,
            'slug' => $slug,
            'parent_id' => $request->input('parent_id'),
        ]);

        return response()->json([
            'data' => $category->fresh()->loadCount('products'),
            'message' => 'Category updated',
        ]);
    }

    public function destroy($id)
    {
        $category = Category::withCount('products')->findOrFail($id);

        if ($category->products_count > 0) {
            return response()->json([
                'message' => 'Cannot delete category with associated products',
                'products_count' => $category->products_count,
            ], 422);
        }

        // Re-parent children to grandparent
        Category::where('parent_id', $id)->update(['parent_id' => $category->parent_id]);

        $category->delete();

        return response()->json(['message' => 'Category deleted']);
    }
}
