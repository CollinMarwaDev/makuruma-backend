<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Expense UAT req. 26–28: a named list users manage without a developer (payment accounts,
 * vendor types, Other subcategories). Everyone reads; Admin, Manager and Accountant change.
 * A used entry is deactivated instead of deleted so old records keep their label.
 */
abstract class LookupController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function inUse(Model $item): bool;

    public function index()
    {
        return $this->model()::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $this->authorizeEditor($request);
        $validated = $request->validate($this->rules());

        return response()->json($this->model()::create($this->values($validated)), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeEditor($request);
        $item = $this->model()::findOrFail($id);
        $item->update($request->validate($this->rules($item)));

        return $item;
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeEditor($request);
        $item = $this->model()::findOrFail($id);

        if ($this->inUse($item)) {
            $item->update(['is_active' => false]);

            return response()->json($item);
        }

        $item->delete();

        return response()->json(null, 204);
    }

    /** Extra values on create (e.g. a slug). */
    protected function values(array $validated): array
    {
        return $validated;
    }

    private function rules(?Model $item = null): array
    {
        $table = (new ($this->model()))->getTable();

        return [
            'is_active' => 'sometimes|boolean',
            'name' => ['sometimes', 'required', 'string', 'max:60', Rule::unique($table, 'name')->ignore($item?->id)],
        ];
    }

    private function authorizeEditor(Request $request): void
    {
        abort_unless(in_array($request->user()?->role?->slug, ['admin', 'manager', 'accountant'], true), 403, 'Only Admin, Manager or Accountant can change this list.');
    }
}
