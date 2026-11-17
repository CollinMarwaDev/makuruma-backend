<?php

namespace App\Http\Controllers\Api;

use App\Models\ExpenseOrder;
use App\Models\PaymentAccount;
use Illuminate\Database\Eloquent\Model;

class PaymentAccountController extends LookupController
{
    protected function model(): string
    {
        return PaymentAccount::class;
    }

    protected function inUse(Model $item): bool
    {
        return ExpenseOrder::where('payment_account_id', $item->id)->exists();
    }
}
