<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Register;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ShiftService
{
    public function __construct(
        protected DatabaseManager $db,
        protected StoreManager $stores
    ) {
    }

    public function openRegister(User $user, string $registerId, float $openingFloat): Shift
    {
        $register = Register::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->findOrFail($registerId);

        if (!$register->store_id) {
            throw ValidationException::withMessages([
                'register_id' => 'Register is missing a store assignment.',
            ]);
        }

        $currentStoreId = $this->stores->id();
        if ($currentStoreId && $register->store_id !== $currentStoreId) {
            throw ValidationException::withMessages([
                'register_id' => 'Register belongs to a different store.',
            ]);
        }

        $existing = Shift::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('store_id', $register->store_id)
            ->where('register_id', $register->id)
            ->whereNull('closed_at')
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'register_id' => 'This register already has an open shift.',
            ]);
        }

        return Shift::create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $register->store_id,
            'register_id' => $register->id,
            'user_id' => $user->id,
            'opened_at' => Carbon::now(),
            'opening_float' => max($openingFloat, 0),
        ])->load(['register', 'user', 'store']);
    }

    public function closeRegister(string $shiftId, float $closingCash, User $user): array
    {
        return $this->db->transaction(function () use ($shiftId, $closingCash, $user) {
            $shift = $this->getShiftForTenant($shiftId, $user->tenant_id, lock: true);
            $this->ensureCashierOwnsShift($user, $shift);

            if ($shift->closed_at !== null) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift is already closed.',
                ]);
            }

            $shift->forceFill([
                'closed_at' => Carbon::now(),
                'closing_cash' => max($closingCash, 0),
            ])->save();

            return $this->buildReport($shift->id, $user->tenant_id);
        });
    }

    public function listShifts(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = max(1, min($perPage, 100));

        $query = Shift::query()
            ->with(['register', 'user'])
            ->where('tenant_id', $user->tenant_id);

        $storeFilter = $filters['store_id'] ?? $this->stores->id();

        if ($user->role === 'cashier') {
            $storeFilter = $user->store_id;
        }
        if (($filters['store_id'] ?? null) === 'all' && $user->role !== 'cashier') {
            $storeFilter = null;
        }

        if ($storeFilter) {
            $query->where('store_id', $storeFilter);
        }

        if (!empty($filters['register_id'])) {
            $query->where('register_id', $filters['register_id']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['date'])) {
            try {
                $date = Carbon::parse($filters['date'])->toDateString();
                $query->whereDate('opened_at', $date);
            } catch (\Throwable) {
                // Ignore invalid date filters.
            }
        }

        $paginator = $query
            ->orderByDesc('opened_at')
            ->paginate($perPage);

        $paginator->getCollection()->transform(
            fn (Shift $shift) => $this->buildReport($shift->id, $user->tenant_id)
        );

        return $paginator;
    }

    public function moveCash(string $shiftId, string $type, float $amount, ?string $reason, User $user): CashMovement
    {
        if (!in_array($type, ['cash_in', 'cash_out'], true)) {
            throw ValidationException::withMessages([
                'type' => 'Invalid cash movement type.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be positive.',
            ]);
        }

        $shift = $this->getShiftForTenant($shiftId, $user->tenant_id);
        $this->ensureCashierOwnsShift($user, $shift);

        if ($shift->closed_at) {
            throw ValidationException::withMessages([
                'shift' => 'Cannot add cash movements to a closed shift.',
            ]);
        }

        return CashMovement::create([
            'tenant_id' => $user->tenant_id,
            'shift_id' => $shift->id,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'created_by' => $user->id,
        ])->load(['creator', 'shift']);
    }

    public function attachOrder(string $orderId, string $shiftId, User $user): Order
    {
        return $this->db->transaction(function () use ($orderId, $shiftId, $user) {
            $shift = $this->getShiftForTenant($shiftId, $user->tenant_id);
            $this->ensureCashierOwnsShift($user, $shift);
            if ($shift->closed_at) {
                throw ValidationException::withMessages([
                    'shift' => 'Cannot attach orders to a closed shift.',
                ]);
            }

            $order = Order::query()
                ->where('tenant_id', $user->tenant_id)
                ->findOrFail($orderId);

            if ($order->store_id && $order->store_id !== $shift->store_id) {
                throw ValidationException::withMessages([
                    'order' => 'Order belongs to a different store.',
                ]);
            }

            if (!$order->store_id) {
                $order->store_id = $shift->store_id;
            }

            if ($order->shift_id && $order->shift_id !== $shift->id) {
                throw ValidationException::withMessages([
                    'order' => 'Order already belongs to another shift.',
                ]);
            }

            if (!$order->shift_id) {
                $order->shift_id = $shift->id;
            }

            if ($order->isDirty(['store_id', 'shift_id'])) {
                $order->save();
            }

            return $order->load(['shift']);
        });
    }

    public function getCurrentShift(User $user): ?array
    {
        $shift = Shift::query()
            ->with(['register', 'user'])
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->when($this->stores->id() ?? $user->store_id, function ($query, $storeId) {
                $query->where('store_id', $storeId);
            })
            ->whereNull('closed_at')
            ->orderByDesc('opened_at')
            ->first();

        if (!$shift) {
            return null;
        }

        return $this->buildReport($shift->id, $user->tenant_id);
    }

    public function getReport(string $shiftId, User $user): array
    {
        return $this->buildReport($shiftId, $user->tenant_id);
    }

    protected function getShiftForTenant(string $shiftId, string $tenantId, bool $lock = false): Shift
    {
        $query = Shift::query()
            ->where('tenant_id', $tenantId);

        $storeId = $this->stores->id();
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($shiftId);
    }

    protected function ensureCashierOwnsShift(User $user, Shift $shift): void
    {
        if ($user->role === 'cashier' && $shift->user_id !== $user->id) {
            abort(403, 'Cashiers may only operate on their own shift.');
        }
    }

    protected function buildReport(string $shiftId, string $tenantId): array
    {
        $query = Shift::query()
            ->with(['register', 'user', 'store'])
            ->where('tenant_id', $tenantId);

        $storeId = $this->stores->id();
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $shift = $query->findOrFail($shiftId);

        $orders = Order::query()
            ->with('payments')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $shift->store_id)
            ->where('shift_id', $shift->id)
            ->get(['id', 'status', 'total']);

        $payments = PaymentSummary::summarize($orders);

        $cashMovements = CashMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->get();

        $cashIn = $cashMovements->where('type', 'cash_in')->sum('amount');
        $cashOut = $cashMovements->where('type', 'cash_out')->sum('amount');

        $openingFloat = (float) $shift->opening_float;
        $cashSales = $payments['cash_sales'];
        $cashRefunds = $payments['cash_refunds'];

        $expectedCash = $openingFloat + $cashIn - $cashOut + $cashSales - $cashRefunds;
        $closingCash = $shift->closing_cash !== null ? (float) $shift->closing_cash : null;
        $overShort = $closingCash !== null ? round($closingCash - $expectedCash, 2) : null;

        return [
            'shift' => [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at,
                'closed_at' => $shift->closed_at,
                'register' => $shift->register,
                'store' => $shift->store,
                'user' => $shift->user,
                'opening_float' => $openingFloat,
                'closing_cash' => $closingCash,
            ],
            'sales' => [
                'total_orders' => $payments['sales_count'],
                'gross' => $payments['gross_sales'],
                'refunds' => $payments['refund_total'],
                'net' => $payments['gross_sales'] - $payments['refund_total'],
                'cash_sales_total' => $cashSales,
                'cash_refunds_total' => $cashRefunds,
            ],
            'cash_movements' => [
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
            ],
            'expected_cash' => round($expectedCash, 2),
            'cash_over_short' => $overShort,
        ];
    }
}

/**
 * Helper class to avoid cluttering ShiftService with static summary logic.
 */
class PaymentSummary
{
    public static function summarize($orders): array
    {
        $salesCount = 0;
        $grossSales = 0.0;
        $refundTotal = 0.0;
        $cashSales = 0.0;
        $cashRefunds = 0.0;

        foreach ($orders as $order) {
            $total = (float) $order->total;
            $cashPayments = $order->payments
                ->where('method', 'cash')
                ->sum('amount');

            if ($order->status === 'paid') {
                $salesCount++;
                $grossSales += $total;
                $cashSales += $cashPayments > 0 ? $cashPayments : $total;
            } elseif ($order->status === 'refunded') {
                $refundTotal += $total;
                $cashRefunds += $cashPayments > 0 ? $cashPayments : $total;
            }
        }

        return [
            'sales_count' => $salesCount,
            'gross_sales' => round($grossSales, 2),
            'refund_total' => round($refundTotal, 2),
            'cash_sales' => round($cashSales, 2),
            'cash_refunds' => round($cashRefunds, 2),
        ];
    }
}
