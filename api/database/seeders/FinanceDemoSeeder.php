<?php

namespace Database\Seeders;

use App\Domain\Finance\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Variant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function collect;
use function config;
use function fake;
use function sprintf;

class FinanceDemoSeeder extends Seeder
{
    private const TENANT_SLUG = 'finance-demo';

    private const PRODUCT_BLUEPRINTS = [
        ['name' => 'House Blend Latte', 'sku' => 'LATTE', 'price' => 5.5, 'cost' => 2.1],
        ['name' => 'Pressed Sandwich', 'sku' => 'SANDWICH', 'price' => 11.0, 'cost' => 4.5],
        ['name' => 'Garden Salad', 'sku' => 'SALAD', 'price' => 9.5, 'cost' => 3.8],
        ['name' => 'Bottled Juice', 'sku' => 'JUICE', 'price' => 4.0, 'cost' => 1.5],
        ['name' => 'Seasonal Dessert', 'sku' => 'DESSERT', 'price' => 7.25, 'cost' => 2.9],
    ];

    private const EXPENSE_BASELINES = [
        'Rent' => 4800,
        'Utilities' => 950,
        'Salaries' => 16500,
        'Marketing' => 1800,
        'Supplies' => 620,
    ];

    private const PAYMENT_METHODS = ['cash', 'card', 'wallet'];

    private const CURRENCY_MULTIPLIERS = [
        'USD' => 1.0,
        'EUR' => 0.93,
        'GBP' => 0.78,
        'CAD' => 1.36,
        'MAD' => 10.11,
    ];

    private ?Tenant $tenant = null;
    private ?Store $primaryStore = null;
    private Collection $seededStores;
    private array $storeCurrencies = [];
    private CarbonImmutable $rangeStart;
    private CarbonImmutable $rangeEnd;

    public function run(): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->rangeEnd = $now->endOfDay();
        $this->rangeStart = $now->subMonths(6)->startOfMonth();
        $this->seededStores = collect();

        DB::transaction(function () {
            $this->tenant = $this->ensureTenant();
            $this->resetFinanceData();

            $currencies = $this->determineCurrencies();
            $stores = $this->ensureStores($currencies);
            $catalog = $this->buildCatalog($currencies);

            $this->primaryStore = $this->resolvePrimaryStore($stores);
            $this->seededStores = $stores;

            $this->seedOrders($stores, $catalog);
            $this->seedExpenses($stores);
        });

        $this->printCurlExamples();
    }

    private function ensureTenant(): Tenant
    {
        return Tenant::firstOrCreate(
            ['slug' => self::TENANT_SLUG],
            [
                'name' => 'Finance Demo Tenant',
                'is_active' => true,
            ]
        );
    }

    private function resetFinanceData(): void
    {
        if (!$this->tenant) {
            return;
        }

        $tenantId = $this->tenant->id;

        OrderItem::where('tenant_id', $tenantId)->delete();
        Payment::where('tenant_id', $tenantId)->delete();
        Order::where('tenant_id', $tenantId)->delete();
        Expense::where('tenant_id', $tenantId)->delete();

        Product::where('tenant_id', $tenantId)->get()->each->delete();
    }

    private function determineCurrencies(): array
    {
        $supported = config('finance.supported_currencies', ['USD']);
        $default = config('finance.default_currency', 'USD');

        if (!$this->multiCurrencyEnabled()) {
            return [$default];
        }

        return array_values(array_unique($supported));
    }

    private function multiCurrencyEnabled(): bool
    {
        return (bool) config('finance.multi_currency', false);
    }

    private function ensureStores(array $currencies): Collection
    {
        $targetCodes = collect($currencies)
            ->map(fn (string $currency) => sprintf('FIN-%s', strtoupper($currency)))
            ->all();

        Store::where('tenant_id', $this->tenant->id)
            ->whereNotIn('code', $targetCodes)
            ->delete();

        $stores = collect();

        foreach ($currencies as $index => $currency) {
            $code = sprintf('FIN-%s', strtoupper($currency));

            $store = Store::updateOrCreate(
                [
                    'tenant_id' => $this->tenant->id,
                    'code' => $code,
                ],
                [
                    'name' => sprintf('Finance %s Store', strtoupper($currency)),
                    'address' => sprintf('%s Demo Plaza', strtoupper($currency)),
                    'phone' => sprintf('555-%04d', 1200 + $index),
                    'is_active' => true,
                ]
            );

            $stores->push($store);
            $this->storeCurrencies[$store->id] = $currency;
        }

        return $stores;
    }

    private function buildCatalog(array $currencies): array
    {
        $catalog = [];

        foreach ($currencies as $currency) {
            $catalog[$currency] = collect();

            foreach (self::PRODUCT_BLUEPRINTS as $blueprint) {
                $product = Product::updateOrCreate(
                    [
                        'tenant_id' => $this->tenant->id,
                        'title' => sprintf('%s (%s)', $blueprint['name'], $currency),
                    ],
                    [
                        'price' => $this->convertAmount($blueprint['price'], $currency),
                        'is_active' => true,
                    ]
                );

                $variant = Variant::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'sku' => sprintf('%s-%s', $blueprint['sku'], $currency),
                    ],
                    [
                        'name' => $blueprint['name'],
                        'price' => $this->convertAmount($blueprint['price'], $currency),
                        'cost' => $this->convertAmount($blueprint['cost'], $currency),
                        'track_stock' => false,
                        'is_default' => true,
                    ]
                );

                $catalog[$currency]->push($variant);
            }
        }

        return $catalog;
    }

    private function resolvePrimaryStore(Collection $stores): ?Store
    {
        $defaultCurrency = config('finance.default_currency', 'USD');

        return $stores->first(function (Store $store) use ($defaultCurrency) {
            return ($this->storeCurrencies[$store->id] ?? null) === $defaultCurrency;
        }) ?? $stores->first();
    }

    private function seedOrders(Collection $stores, array $catalogByCurrency): void
    {
        $cursor = $this->rangeStart;

        while ($cursor <= $this->rangeEnd) {
            foreach ($stores as $store) {
                $currency = $this->storeCurrencies[$store->id] ?? config('finance.default_currency', 'USD');
                $variants = $catalogByCurrency[$currency] ?? collect();

                if ($variants->isEmpty()) {
                    continue;
                }

                $ordersToday = $this->ordersPerDay($cursor);

                for ($i = 0; $i < $ordersToday; $i++) {
                    $this->createOrderForDay($store, $variants, $currency, $cursor);
                }
            }

            $cursor = $cursor->addDay();
        }
    }

    private function ordersPerDay(CarbonImmutable $day): int
    {
        $base = $day->isWeekend() ? 8 : 5;

        if ($day->dayOfWeekIso === 5) {
            $base += 2;
        }

        if ((int) $day->format('d') <= 3) {
            $base -= 1;
        }

        return max(2, $base + random_int(-2, 3));
    }

    private function createOrderForDay(Store $store, Collection $variants, string $currency, CarbonImmutable $day): void
    {
        $orderTimestamp = $day->setTime(random_int(8, 20), random_int(0, 59));

        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $store->id,
            'status' => 'paid',
            'subtotal' => 0,
            'tax' => 0,
            'discount' => 0,
            'manual_discount' => 0,
            'total' => 0,
            'payment_method' => fake()->randomElement(self::PAYMENT_METHODS),
        ]);

        $itemsCount = random_int(1, 3);
        $subtotal = 0;

        for ($i = 0; $i < $itemsCount; $i++) {
            /** @var Variant $variant */
            $variant = $variants->random();
            $qty = random_int(1, 4);
            $unitPrice = $this->jitterPrice($variant->price);
            $lineTotal = $unitPrice * $qty;
            $subtotal += $lineTotal;

            OrderItem::create([
                'tenant_id' => $this->tenant->id,
                'order_id' => $order->id,
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'qty' => $qty,
                'unit_price' => round($unitPrice, 2),
            ]);
        }

        $discount = $this->calculateDiscount($subtotal, $day);
        $tax = round($subtotal * 0.07, 2);
        $total = $subtotal + $tax - $discount;

        $order->forceFill([
            'subtotal' => round($subtotal, 2),
            'tax' => $tax,
            'discount' => $discount,
            'total' => round($total, 2),
            'created_at' => $orderTimestamp,
            'updated_at' => $orderTimestamp->addMinutes(random_int(5, 30)),
        ])->save();

        Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'method' => $order->payment_method ?? 'card',
            'amount' => round($total, 2),
            'status' => 'captured',
            'captured_at' => $orderTimestamp->addMinutes(random_int(10, 90)),
        ]);
    }

    private function jitterPrice(float $base): float
    {
        $delta = fake()->randomFloat(2, -0.45, 0.9);

        return max(1, round($base + $delta, 2));
    }

    private function calculateDiscount(float $subtotal, CarbonImmutable $day): float
    {
        if ($day->dayOfWeekIso === 1) {
            return round($subtotal * 0.05, 2);
        }

        if ($subtotal > 120) {
            return round($subtotal * 0.03, 2);
        }

        return 0.0;
    }

    private function seedExpenses(Collection $stores): void
    {
        $monthCursor = $this->rangeStart->startOfMonth();

        while ($monthCursor <= $this->rangeEnd) {
            foreach ($stores as $store) {
                $currency = $this->storeCurrencies[$store->id] ?? config('finance.default_currency', 'USD');

                foreach (self::EXPENSE_BASELINES as $category => $baseline) {
                    $incurredAt = $monthCursor->addDays(random_int(0, 25))
                        ->setTime(random_int(8, 11), random_int(0, 59));

                    Expense::create([
                        'tenant_id' => $this->tenant->id,
                        'store_id' => $store->id,
                        'category' => $category,
                        'amount' => $this->calculateExpenseAmount($category, $baseline, $currency, $monthCursor),
                        'incurred_at' => $incurredAt,
                        'vendor' => sprintf('%s Partner', $category),
                        'notes' => null,
                        'created_by' => null,
                    ]);
                }
            }

            $monthCursor = $monthCursor->addMonth();
        }
    }

    private function calculateExpenseAmount(
        string $category,
        float $baseline,
        string $currency,
        CarbonImmutable $month
    ): float {
        $variance = match ($category) {
            'Rent' => 0.03,
            'Salaries' => 0.08,
            'Utilities' => 0.2,
            'Marketing' => 0.35,
            default => 0.25,
        };

        $multiplier = 1 + fake()->randomFloat(3, -$variance, $variance);

        if ($category === 'Marketing' && $month->isSameMonth($this->rangeEnd->subMonth())) {
            $multiplier *= 2.5;
        }

        $amount = $baseline * $multiplier;

        return round(max($this->convertAmount($amount, $currency), 50), 2);
    }

    private function convertAmount(float $amount, string $currency): float
    {
        $multiplier = self::CURRENCY_MULTIPLIERS[$currency] ?? 1.0;

        return round($amount * $multiplier, 2);
    }

    private function printCurlExamples(): void
    {
        if (!$this->command || !$this->tenant || !$this->primaryStore) {
            return;
        }

        $baseUrl = rtrim(config('app.url', 'http://127.0.0.1:8000'), '/').'/api/bo/finance';
        $currency = $this->storeCurrencies[$this->primaryStore->id] ?? config('finance.default_currency', 'USD');

        $params = [
            "date_from={$this->rangeStart->toDateString()}",
            "date_to={$this->rangeEnd->toDateString()}",
            "store_id={$this->primaryStore->id}",
            "currency={$currency}",
            'tz=Africa/Casablanca',
        ];

        $encodedParams = implode(' ', array_map(
            fn (string $param) => sprintf('--data-urlencode "%s"', $param),
            $params
        ));

        $headers = sprintf(
            '-H "X-Tenant: %s" -H "X-Store: %s"',
            $this->tenant->slug,
            $this->primaryStore->id
        );

        $this->command->info('Finance demo API examples (tz=Africa/Casablanca):');

        foreach (['summary', 'flow', 'expenses', 'health'] as $endpoint) {
            $this->command->line(
                sprintf(
                    'curl -G "%s/%s" %s %s',
                    $baseUrl,
                    $endpoint,
                    $headers,
                    $encodedParams
                )
            );
        }

        if ($this->seededStores->count() > 1) {
            $this->command->line('');
            $this->command->line('Store IDs by currency:');
            $this->seededStores->each(function (Store $store) {
                $currency = $this->storeCurrencies[$store->id] ?? 'USD';
                $this->command->line(sprintf('- %s (%s) => %s', $store->name, $currency, $store->id));
            });
        }
    }
}
