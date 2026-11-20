<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseOrder extends Model
{
    use HasFactory;

    /** Expense types (same values as expense_lines.line_category) and their number prefixes. */
    public const TYPE_PREFIXES = [
        'fuel' => 'FUEL',
        'mengine' => 'OTH',
        'vibali_congo' => 'VC',
        'vibali_tunduma' => 'VT',
    ];

    public const FORMS = ['route', 'general', 'go', 'return', 'emergency'];

    /** Days a fuel order may wait for its price before Accountants are alerted (req. 10). */
    public const PRICE_GRACE_DAYS = 14;

    protected $fillable = [
        'approved_at',
        'approved_by',
        'booking_id',
        'category',
        'created_by',
        'expense_type',
        'form',
        'initiated_by',
        'order_number',
        'other_subcategory_id',
        'outbound_border',
        'paid_at',
        'paid_by',
        'parent_order_id',
        'payment_account_id',
        'payment_date',
        'payment_mode',
        'price_alerted_at',
        'reference_no',
        'return_border',
        'route_template_id',
        'serial',
        'status',
        'total_amount',
        'truck_id',
        'usd_rate',
        'usd_total',
        'vendor_id',
        'zmk_rate',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'price_alerted_at' => 'datetime',
        'serial' => 'integer',
        'total_amount' => 'decimal:2',
        'usd_total' => 'decimal:2',
    ];

    protected $appends = ['form_number', 'payment_account', 'is_legacy_mixed', 'pending_price', 'price_overdue'];

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class);
    }

    /** First linked booking (kept for older screens and exports). */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'expense_order_bookings')->withTimestamps();
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseSubcategory::class, 'other_subcategory_id');
    }

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_order_id');
    }

    public function priceChanges(): HasMany
    {
        return $this->hasMany(ExpensePriceChange::class)->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function trucks(): BelongsToMany
    {
        return $this->belongsToMany(Truck::class, 'expense_order_trucks');
    }

    /** UAT 20 Nov: the reference number typed on the form is the form number; the system number is a fallback. */
    public function getFormNumberAttribute(): string
    {
        return $this->reference_no ?: ($this->serial ?? $this->order_number);
    }

    /** Download name, e.g. "expense-VC 0125.pdf" (characters a file name can't hold become "-"). */
    public function fileName(string $extension, string $suffix = ''): string
    {
        return 'expense-' . preg_replace('#[\\\\/:*?"<>|]+#', '-', $this->form_number) . $suffix . '.' . $extension;
    }

    /** UAT 20 Nov: the user who approved an order may still edit it until it is paid. */
    public function canBeEditedBy(?User $user): bool
    {
        return $this->status === 'pending'
            || ($this->status === 'approved' && $user && $this->approved_by === $user->id);
    }

    /** Account name, so exports and the PDF keep printing it. */
    public function getPaymentAccountAttribute(): ?string
    {
        return $this->account?->name;
    }

    /** Orders saved before req. 1 may mix line types; they stay viewable but cannot be edited. */
    public function getIsLegacyMixedAttribute(): bool
    {
        $categories = $this->relationLoaded('lines')
            ? $this->lines->pluck('line_category')->unique()->count()
            : $this->lines()->distinct()->count('line_category');

        return $categories > 1;
    }

    /**
     * A fuel order with a fuel line still waiting for its price (req. 10). Only orders made with the
     * new forms (they have a series number): older orders kept lump-sum lines without a unit price.
     */
    public function getPendingPriceAttribute(): bool
    {
        if ($this->expense_type !== 'fuel' || $this->serial === null) {
            return false;
        }

        return $this->relationLoaded('lines')
            ? $this->lines->contains(fn (ExpenseLine $l) => $l->line_category === 'fuel' && $l->unit_rate === null)
            : $this->lines()->where('line_category', 'fuel')->whereNull('unit_rate')->exists();
    }

    /** Price still missing after PRICE_GRACE_DAYS; accounts cannot be closed. */
    public function getPriceOverdueAttribute(): bool
    {
        return $this->pending_price && $this->created_at?->lte(now()->subDays(self::PRICE_GRACE_DAYS));
    }

    /** USD is not converted on the form or its total for route orders and Vibali Congo (req. 16, 21). */
    public function keepsUsdSeparate(): bool
    {
        return $this->form === 'route' || $this->expense_type === 'vibali_congo';
    }

    /** total_amount is the printed TZS total; usd_total holds USD kept unconverted. */
    public function recalculateTotal(): void
    {
        $separate = $this->keepsUsdSeparate();

        $this->update([
            'total_amount' => $this->lines()->when($separate, fn ($q) => $q->where('currency', '!=', 'USD'))->sum('amount'),
            'usd_total' => $separate ? $this->lines()->where('currency', 'USD')->sum('original_amount') : 0,
        ]);
    }
}
