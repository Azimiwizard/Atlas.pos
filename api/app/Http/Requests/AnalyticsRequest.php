<?php

namespace App\Http\Requests;

use App\DataTransferObjects\AnalyticsQuery;
use App\Services\TenantManager;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

class AnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $tz = $this->input('tz', 'UTC');
        $tz = $this->normalizeTimezone($tz);

        $defaultTo = now($tz)->toDateString();
        $defaultFrom = now($tz)->subDays(29)->toDateString();

        $this->merge([
            'tz' => $tz,
            'date_to' => $this->input('date_to', $defaultTo),
            'date_from' => $this->input('date_from', $defaultFrom),
            'limit' => $this->input('limit', 10),
        ]);
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id ?? app(TenantManager::class)->id();

        if (!$tenantId) {
            throw new RuntimeException('Tenant context is required before validating analytics filters.');
        }

        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'tz' => ['required', 'timezone'],
            'store_id' => [
                'nullable',
                'uuid',
                Rule::exists('stores', 'id')->where(function ($query) use ($tenantId) {
                    $query->where('tenant_id', $tenantId);
                }),
            ],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function toAnalyticsQuery(): AnalyticsQuery
    {
        $user = $this->user();
        $tenantId = (string) ($user?->tenant_id ?? app(TenantManager::class)->id());

        if (!$tenantId) {
            throw new RuntimeException('Tenant context missing when building analytics query.');
        }

        $validated = $this->validated();
        $tz = $validated['tz'];
        $storeId = $validated['store_id'] ?? null;

        if ($user && $user->role === 'cashier') {
            $cashierStoreId = $user->store_id;

            if (!$cashierStoreId) {
                abort(403, 'Cashiers must be assigned to a store to view analytics.');
            }

            if ($storeId && $storeId !== $cashierStoreId) {
                abort(403, 'Cashiers are restricted to their assigned store.');
            }

            $storeId = $cashierStoreId;
        }

        $rangeStartLocal = CarbonImmutable::createFromFormat('Y-m-d', $validated['date_from'], new DateTimeZone($tz))
            ->startOfDay();
        $rangeEndLocal = CarbonImmutable::createFromFormat('Y-m-d', $validated['date_to'], new DateTimeZone($tz))
            ->endOfDay();

        $limit = (int) ($validated['limit'] ?? 10);
        $limit = max(1, min(100, $limit));

        return new AnalyticsQuery(
            tenantId: $tenantId,
            rangeStartUtc: $rangeStartLocal->setTimezone('UTC'),
            rangeEndUtc: $rangeEndLocal->setTimezone('UTC'),
            rangeStartLocal: $rangeStartLocal,
            rangeEndLocal: $rangeEndLocal,
            timezone: $tz,
            storeId: $storeId,
            limit: $limit
        );
    }

    protected function normalizeTimezone(string $tz): string
    {
        try {
            return (new DateTimeZone($tz))->getName();
        } catch (\Throwable) {
            return 'UTC';
        }
    }
}
