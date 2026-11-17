<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseOrder;
use App\Models\RouteTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Expense UAT req. 12: route templates. Everyone reads; Admin, Manager and Accountant change them. */
class RouteTemplateController extends Controller
{
    public function index(Request $request)
    {
        return RouteTemplate::with('lines')
            ->when($request->filled('expense_type'), fn ($q) => $q->where('expense_type', $request->expense_type))
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $this->authorizeEditor($request);
        $validated = $request->validate($this->rules());

        $template = DB::transaction(function () use ($validated) {
            $template = RouteTemplate::create(collect($validated)->except('lines')->all());
            $this->saveLines($template, $validated['lines']);

            return $template;
        });

        return response()->json($template->load('lines'), 201);
    }

    /** Editing replaces the template's lines; orders already created keep their own copy. */
    public function update(Request $request, RouteTemplate $routeTemplate)
    {
        $this->authorizeEditor($request);
        $validated = $request->validate($this->rules($routeTemplate));

        DB::transaction(function () use ($routeTemplate, $validated) {
            $routeTemplate->update(collect($validated)->except('lines')->all());
            $routeTemplate->lines()->delete();
            $this->saveLines($routeTemplate, $validated['lines']);
        });

        return $routeTemplate->load('lines');
    }

    /** A template used by an order is deactivated so the order keeps its link. */
    public function destroy(Request $request, RouteTemplate $routeTemplate)
    {
        $this->authorizeEditor($request);

        if (ExpenseOrder::where('route_template_id', $routeTemplate->id)->exists()) {
            $routeTemplate->update(['is_active' => false]);

            return response()->json($routeTemplate->load('lines'));
        }

        $routeTemplate->delete();

        return response()->json(null, 204);
    }

    private function saveLines(RouteTemplate $template, array $lines): void
    {
        foreach (array_values($lines) as $i => $line) {
            $template->lines()->create([...$line, 'is_flat' => $line['is_flat'] ?? false, 'sort_order' => $i]);
        }
    }

    private function rules(?RouteTemplate $template = null): array
    {
        return [
            'expense_type' => 'required|in:vibali_tunduma,vibali_congo',
            'is_active' => 'sometimes|boolean',
            'lines' => 'required|array|min:1',
            'lines.*.currency' => 'required|in:TZS,USD,ZMK',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.is_flat' => 'sometimes|boolean',
            'lines.*.multiplier' => 'nullable|numeric|min:0',
            'lines.*.segment' => ['nullable', Rule::in(RouteTemplate::SEGMENTS)],
            'lines.*.unit_amount' => 'required|numeric|min:0',
            'name' => ['required', 'string', 'max:120', Rule::unique('route_templates')->where('expense_type', request('expense_type'))->ignore($template?->id)],
            'outbound_border' => 'nullable|string|max:30',
            'return_border' => 'nullable|string|max:30',
        ];
    }

    private function authorizeEditor(Request $request): void
    {
        abort_unless(in_array($request->user()?->role?->slug, ['admin', 'manager', 'accountant'], true), 403, 'Only Admin, Manager or Accountant can change route templates.');
    }
}
