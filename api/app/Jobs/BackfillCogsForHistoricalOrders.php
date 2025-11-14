<?php

namespace App\Jobs;

use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BackfillCogsForHistoricalOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public ?string $storeId = null,
        public ?string $fromDate = null,
        public ?string $toDate = null
    ) {
    }

    public function handle(): void
    {
        $query = OrderItem::query()
            ->where('tenant_id', $this->tenantId)
            ->where(function ($builder) {
                $builder
                    ->whereNull('cogs_amount')
                    ->orWhere('cogs_amount', '<=', 0);
            })
            ->whereHas('order', function ($orderQuery) {
                $orderQuery->where('status', 'paid');

                if ($this->storeId) {
                    $orderQuery->where('store_id', $this->storeId);
                }

                if ($this->fromDate) {
                    $orderQuery->whereDate('created_at', '>=', $this->fromDate);
                }

                if ($this->toDate) {
                    $orderQuery->whereDate('created_at', '<=', $this->toDate);
                }
            })
            ->with(['variant:id,cost'])
            ->orderBy('created_at');

        $query->lazy(200)->each(function (OrderItem $item) {
            $qty = (float) $item->qty;

            if ($qty <= 0) {
                return;
            }

            $unitCost = $item->variant ? max((float) ($item->variant->cost ?? 0), 0) : 0;
            $cogs = round($qty * $unitCost, 2);

            if ($cogs <= 0) {
                return;
            }

            $item->forceFill(['cogs_amount' => $cogs])->save();
        });
    }
}
