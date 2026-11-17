<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A user-editable list of route costs an expense order starts from (Expense UAT req. 12). */
class RouteTemplate extends Model
{
    public const SEGMENTS = ['dar_tunduma', 'zambia', 'border_drc'];

    protected $fillable = ['expense_type', 'is_active', 'name', 'outbound_border', 'return_border'];

    protected $casts = ['is_active' => 'boolean'];

    public function lines(): HasMany
    {
        return $this->hasMany(RouteTemplateLine::class)->orderBy('sort_order')->orderBy('id');
    }
}
