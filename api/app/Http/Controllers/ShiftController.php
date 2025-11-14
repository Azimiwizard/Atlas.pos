<?php

namespace App\Http\Controllers;

use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function __construct(protected ShiftService $shiftService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeRoles($user->role, ['admin', 'manager', 'cashier']);

        $filters = $request->validate([
            'date' => ['nullable', 'string'],
            'register_id' => ['nullable', 'string'],
            'user_id' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'store_id' => ['nullable', 'string'],
        ]);

        $shifts = $this->shiftService->listShifts($user, $filters);

        return response()->json($shifts);
    }

    public function open(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeRoles($user->role, ['admin', 'manager', 'cashier']);

        $data = $request->validate([
            'register_id' => ['required', 'string'],
            'opening_float' => ['nullable', 'numeric', 'min:0'],
        ]);

        $shift = $this->shiftService->openRegister(
            $user,
            $data['register_id'],
            (float) ($data['opening_float'] ?? 0)
        );

        return response()->json($shift, 201);
    }

    public function close(Request $request, string $shiftId): JsonResponse
    {
        $user = $request->user();
        $this->authorizeRoles($user->role, ['admin', 'manager']);

        $data = $request->validate([
            'closing_cash' => ['required', 'numeric', 'min:0'],
        ]);

        $report = $this->shiftService->closeRegister($shiftId, (float) $data['closing_cash'], $user);

        return response()->json($report);
    }

    public function cashMovement(Request $request, string $shiftId): JsonResponse
    {
        $user = $request->user();
        $this->authorizeRoles($user->role, ['admin', 'manager', 'cashier']);

        $data = $request->validate([
            'type' => ['required', 'in:cash_in,cash_out'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = $this->shiftService->moveCash(
            $shiftId,
            $data['type'],
            (float) $data['amount'],
            $data['reason'] ?? null,
            $user
        );

        return response()->json($movement, 201);
    }

    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $shift = $this->shiftService->getCurrentShift($user);

        return response()->json([
            'shift' => $shift,
        ]);
    }

    public function show(Request $request, string $shiftId): JsonResponse
    {
        $user = $request->user();
        $this->authorizeRoles($user->role, ['admin', 'manager']);

        $report = $this->shiftService->getReport($shiftId, $user);

        return response()->json($report);
    }

    protected function authorizeRoles(string $role, array $allowed): void
    {
        if (!in_array($role, $allowed, true)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }
}
