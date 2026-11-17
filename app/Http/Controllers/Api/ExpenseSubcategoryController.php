<?php

namespace App\Http\Controllers\Api;

use App\Models\ExpenseOrder;
use App\Models\ExpenseSubcategory;
use Illuminate\Database\Eloquent\Model;

class ExpenseSubcategoryController extends LookupController
{
    protected function model(): string
    {
        return ExpenseSubcategory::class;
    }

    protected function inUse(Model $item): bool
    {
        return ExpenseOrder::where('other_subcategory_id', $item->id)->exists();
    }
}
