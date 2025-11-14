<?php

namespace App\Support\Environment;

use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class EnvironmentHealth
{
    private bool $fallbackApplied = false;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
    ) {
    }

    public function applyRuntimeFallbacks(): void
    {
        if ($this->redisAvailable()) {
            return;
        }

        $this->config->set('cache.default', 'file');
        $this->config->set('session.driver', 'file');
        $this->config->set('queue.default', 'sync');

        $this->fallbackApplied = true;
    }

    public function redisAvailable(): bool
    {
        $client = $this->config->get('database.redis.client', 'phpredis');

        if ($client === 'phpredis' && ! extension_loaded('redis')) {
            return false;
        }

        try {
            $connectionName = $this->config->get('cache.stores.redis.connection', 'cache');
            Redis::connection($connectionName)->ping();

            return true;
        } catch (Throwable $exception) {
            Log::warning('Redis unavailable; falling back to local drivers', [
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function cacheSupportsTags(): bool
    {
        $repository = $this->cache->store($this->config->get('cache.default'));

        if (method_exists($repository, 'getStore')) {
            $store = $repository->getStore();

            if ($store instanceof TaggableStore) {
                return true;
            }

            if (method_exists($store, 'supportsTags')) {
                return (bool) $store->supportsTags();
            }
        }

        return false;
    }

    public function usedFallbackDrivers(): bool
    {
        if ($this->fallbackApplied) {
            return true;
        }

        return in_array($this->config->get('cache.default'), ['file', 'array'], true)
            || $this->config->get('session.driver') === 'file'
            || $this->config->get('queue.default') === 'sync';
    }
}
