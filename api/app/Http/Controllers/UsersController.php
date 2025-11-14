<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UserResetPasswordRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UsersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;
        $perPage = (int) $request->query('per_page', 15);
        $search = trim((string) $request->query('search', ''));

        $query = User::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if ($search !== '') {
            $op = $this->likeOperator();
            $query->where(function ($q) use ($search, $op) {
                $q->where('name', $op, "%{$search}%")
                    ->orWhere('email', $op, "%{$search}%")
                    ->orWhere('role', $op, "%{$search}%");
            });
        }

        $results = $query->paginate($perPage)->through(function (User $u) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'store_id' => $u->store_id,
                'created_at' => $u->created_at,
            ];
        });

        return response()->json($results);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->ensureTenant($request, $user);
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'store_id' => $user->store_id,
            'created_at' => $user->created_at,
        ]);
    }

    public function store(UserStoreRequest $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;
        $data = $request->validated();

        $tempPassword = Str::password(12);

        $storeId = $data['store_id'] ?? null;
        if (isset($storeId) && $storeId !== 'all') {
            $store = Store::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->findOrFail($storeId);
            $storeId = $store->id;
        } else {
            $storeId = null;
        }

        if ($data['role'] === UserRole::CASHIER && !$storeId) {
            throw ValidationException::withMessages([
                'store_id' => ['Cashiers must be assigned to a store.'],
            ]);
        }

        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => $tempPassword,
            'store_id' => $storeId,
        ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'store_id' => $user->store_id,
                'created_at' => $user->created_at,
            ],
            'temp_password' => $tempPassword,
        ], 201);
    }

    public function update(UserUpdateRequest $request, User $user): JsonResponse
    {
        $this->ensureTenant($request, $user);
        $data = $request->validated();

        if (array_key_exists('role', $data)) {
            $this->assertNotDemotingLastAdmin($request, $user, $data['role']);
        }

        if (array_key_exists('store_id', $data)) {
            $storeId = $data['store_id'];

            if ($storeId && $storeId !== 'all') {
                $store = Store::query()
                    ->where('tenant_id', $request->user()->tenant_id)
                    ->where('is_active', true)
                    ->findOrFail($storeId);
                $user->store_id = $store->id;
            } else {
                $user->store_id = null;
            }

            unset($data['store_id']);
        }

        $targetRole = $data['role'] ?? $user->role;
        if ($targetRole === UserRole::CASHIER && !$user->store_id) {
            throw ValidationException::withMessages([
                'store_id' => ['Cashiers must be assigned to a store.'],
            ]);
        }

        $user->fill($data)->save();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'store_id' => $user->store_id,
            'created_at' => $user->created_at,
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->ensureTenant($request, $user);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => ['You cannot delete your own user.'],
            ]);
        }

        $this->assertNotDeletingLastAdmin($request, $user);

        $user->delete();
        return response()->json([], 204);
    }

    public function resetPassword(UserResetPasswordRequest $request, User $user): JsonResponse
    {
        $this->ensureTenant($request, $user);
        $temp = Str::password(12);
        $user->password = $temp;
        $user->save();

        return response()->json([
            'temp_password' => $temp,
        ]);
    }

    public function changeMyPassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $current = $request->validated()['current_password'];
        $new = $request->validated()['new_password'];

        if (!Hash::check($current, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->password = $new;
        $user->save();

        return response()->json(['message' => 'Password updated.']);
    }

    protected function ensureTenant(Request $request, User $user): void
    {
        if ($user->tenant_id !== $request->user()->tenant_id) {
            abort(404);
        }
    }

    protected function assertNotDemotingLastAdmin(Request $request, User $user, string $newRole): void
    {
        if ($user->role === UserRole::ADMIN && $newRole !== UserRole::ADMIN) {
            $admins = User::query()
                ->where('tenant_id', $request->user()->tenant_id)
                ->where('role', UserRole::ADMIN)
                ->where('id', '!=', $user->id)
                ->count();

            if ($admins === 0) {
                throw ValidationException::withMessages([
                    'role' => ['Cannot remove the last admin in this tenant.'],
                ]);
            }
        }
    }

    protected function assertNotDeletingLastAdmin(Request $request, User $user): void
    {
        if ($user->role !== UserRole::ADMIN) {
            return;
        }

        $admins = User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('role', UserRole::ADMIN)
            ->where('id', '!=', $user->id)
            ->count();

        if ($admins === 0) {
            throw ValidationException::withMessages([
                'user' => ['Cannot delete the last admin in this tenant.'],
            ]);
        }
    }

    protected function likeOperator(): string
    {
        return User::query()->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }
}
