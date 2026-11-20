<?php

namespace App\Services;

use App\Models\ExpenseOrder;

/** Which printout an expense order uses and the data its view needs (shared by the PDF and the Excel download). */
class ExpensePrintout
{
    /** The general layout for orders saved before the typed forms (no form, mixed lines). */
    public const GENERIC_VIEW = 'expenses.order-pdf';

    public static function view(ExpenseOrder $order): string
    {
        return match (true) {
            $order->expense_type === 'fuel' => 'expenses.fuel-pdf',
            $order->expense_type === 'vibali_tunduma' && $order->form === 'route' => 'expenses.vt-route-pdf',
            $order->expense_type === 'vibali_tunduma' && $order->form === 'general' => 'expenses.vt-general-pdf',
            $order->expense_type === 'vibali_congo' && $order->form === 'go' => 'expenses.vc-go-pdf',
            $order->expense_type === 'vibali_congo' && $order->form === 'return' => 'expenses.vc-return-pdf',
            $order->expense_type === 'vibali_congo' && $order->form === 'emergency' => 'expenses.vc-emergency-pdf',
            $order->expense_type === 'mengine' && $order->subcategory
                => preg_match('/mizani|weighbridge/i', $order->subcategory->name) ? 'expenses.other-mizani-pdf' : 'expenses.other-pdf',
            default => self::GENERIC_VIEW,
        };
    }

    /** @return array<string, mixed> */
    public static function data(ExpenseOrder $order, bool $showPrice = true): array
    {
        $data = [
            'expense' => $order,
            'logoBase64' => 'data:image/png;base64,' . base64_encode(file_get_contents(public_path('images/logo.png'))),
            'showPrice' => $showPrice,
        ];
        if (str_starts_with(self::view($order), 'expenses.v')) {
            $data['print'] = ExpenseAmountCalculator::printData($order);
        }

        return $data;
    }
}
