<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;

/**
 * Lightweight request-scoped store helper resolved out of the container.
 * The instance itself is a singleton, so it must clear its mutable state
 * after each request (handled via EnsureStoreContext middleware).
 */
class StoreManager
{
    protected ?Store $store = null;

    protected ?string $storeId = null;

    /**
     * Remember the fully-hydrated store for the current request.
     */
    public function set(?Store $store): void
    {
        $this->store = $store;
        $this->storeId = $store?->id;
    }

    /**
     * Assign the store identifier when only the id is known.
     */
    public function setId(?string $storeId): void
    {
        $this->storeId = $storeId;
        if ($storeId === null) {
            $this->store = null;
        }
    }

    /**
     * Retrieve the current store identifier for the request.
     */
    public function id(): ?string
    {
        return $this->storeId;
    }

    /**
     * Retrieve the hydrated store model for the request.
     */
    public function store(): ?Store
    {
        return $this->store;
    }

    /**
     * Clear any store context lingering on the singleton.
     */
    public function forget(): void
    {
        $this->store = null;
        $this->storeId = null;
    }
}
