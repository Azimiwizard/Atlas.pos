<?php

namespace App\Http\Requests;

use App\DataTransferObjects\FinanceQuery;
use App\Enums\UserRole;
use App\Services\TenantManager;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

class FinanceRequest extends FormRequest
{
    private const SUPPORTED_CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'MAD'];
    private const DEFAULT_CURRENCY = 'USD';
    private const MAX_RANGE_DAYS = 370;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $currency = strtoupper((string) ($this->input('currency', self::DEFAULT_CURRENCY)));
        $bucket = $this->input('bucket');

        $this->merge([
            'currency' => $currency ?: self::DEFAULT_CURRENCY,
            'bucket' => $bucket ? strtolower((string) $bucket) : null,
        ]);
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id ?? app(TenantManager::class)->id();

        if (!$tenantId) {
            throw new RuntimeException('Tenant context is required for finance analytics.');
        }

        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'tz' => ['nullable', 'timezone'],
            'store_id' => [
                'nullable',
                'uuid',
                Rule::exists('stores', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'currency' => ['required', Rule::in(self::SUPPORTED_CURRENCIES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'bucket' => ['nullable', Rule::in(['day', 'week', 'month'])],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $from = $this->input('date_from');
            $to = $this->input('date_to');

            if (!$from || !$to) {
                return;
            }

            try {
                $start = CarbonImmutable::createFromFormat('Y-m-d', $from);
                $end = CarbonImmutable::createFromFormat('Y-m-d', $to);
            } catch (\Throwable) {
                return;
            }

            if ($start->diffInDays($end) > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('date_to', 'The selected date range may not exceed '.self::MAX_RANGE_DAYS.' days.');
            }
        });
    }

    public function toFinanceQuery(): FinanceQuery
    {
        $user = $this->user();
        $tenantId = (string) ($user?->tenant_id ?? app(TenantManager::class)->id());

        if (!$tenantId) {
            throw new RuntimeException('Tenant context missing when building finance query.');
        }

        $validated = $this->validated();
        $timezoneName = $validated['tz'] ?? 'Africa/Casablanca';
        $timezone = new DateTimeZone($timezoneName);

        $rangeStartLocal = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $validated['date_from'].' 00:00:00', $timezone);
        $rangeEndLocal = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $validated['date_to'].' 23:59:59', $timezone);

        $storeId = $this->enforceStoreAccess($validated['store_id'] ?? null);
        $limit = (int) ($validated['limit'] ?? 10);
        $bucket = strtolower((string) ($validated['bucket'] ?? 'day'));

        return new FinanceQuery(
            tenantId: $tenantId,
            rangeStartUtc: $rangeStartLocal->setTimezone('UTC'),
            rangeEndUtc: $rangeEndLocal->setTimezone('UTC'),
            rangeStartLocal: $rangeStartLocal,
            rangeEndLocal: $rangeEndLocal,
            timezone: $timezoneName,
            storeId: $storeId,
            currency: $validated['currency'],
            limit: $limit,
            bucket: in_array($bucket, ['day', 'week', 'month'], true) ? $bucket : 'day'
        );
    }

    protected function enforceStoreAccess(?string $requestedStoreId): ?string
    {
        $user = $this->user();

        if (!$user) {
            return $requestedStoreId;
        }

        if ($user->role === UserRole::ADMIN) {
            return $requestedStoreId;
        }

        $assignedStoreId = $user->store_id;

        if (!$assignedStoreId) {
            throw new AuthorizationException('Store access is required for this action.');
        }

        if ($requestedStoreId && $requestedStoreId !== $assignedStoreId) {
            throw new AuthorizationException('You are not allowed to access this store.');
        }

        return $assignedStoreId;
    }
}
