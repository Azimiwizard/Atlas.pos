<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLedger extends Model
{
    use HasFactory;
    use UsesUuid;
    use BelongsToTenant;

    protected $table = 'inventory_ledger';

    protected $fillable = [
        'tenant_id',
        'variant_id',
        'store_id',
        'qty_delta',
        'reason',
        'ref_type',
        'ref_id',
        'user_id',
        'note',
    ];

    protected $casts = [
        'qty_delta' => 'decimal:3',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
