import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Button, Card } from '@atlas-pos/ui';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { Money } from '../components/Money';
import { useToast } from '../components/toastContext';
import { useAuth } from '../hooks/useAuth';
import { useStore } from '../hooks/useStore';
import {
  getSummary,
  getHourlyHeatmap,
  getCashiers,
  exportAnalyticsCsv,
  type AnalyticsSummaryResponse,
  type HeatmapPoint,
  type CashierStat,
} from '../features/analytics/api';
import { getErrorMessage } from '../lib/api';

type DatePreset = 'last7' | 'last30' | 'mtd' | 'qtd' | 'custom';

type FilterState = {
  preset: DatePreset;
  date_from: string;
  date_to: string;
  tz: string;
  store: string;
};

type PresetOption = {
  value: DatePreset;
  label: string;
};

const PRESETS: PresetOption[] = [
  { value: 'last7', label: 'Last 7 days' },
  { value: 'last30', label: 'Last 30 days' },
  { value: 'mtd', label: 'Month to date' },
  { value: 'qtd', label: 'Quarter to date' },
  { value: 'custom', label: 'Custom' },
];

const STORAGE_KEY = 'atlas_pos_analytics_filters';
const DEFAULT_PRESET: DatePreset = 'last30';
const DEFAULT_LIMIT = 10;

const COMMON_TIMEZONES = [
  'UTC',
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Los_Angeles',
  'Europe/London',
  'Europe/Berlin',
  'Europe/Paris',
  'Europe/Madrid',
  'Asia/Dubai',
  'Asia/Singapore',
  'Asia/Tokyo',
  'Australia/Sydney',
];

const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const HEATMAP_COLORS = ['#e2e8f0', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6', '#2563eb', '#1d4ed8'];
const CHART_COLORS = ['#2563eb', '#ea580c', '#16a34a', '#7c3aed', '#db2777', '#0f172a'];

const formatDate = (date: Date): string => date.toISOString().slice(0, 10);

const startOfMonth = (date: Date): Date => new Date(date.getFullYear(), date.getMonth(), 1);

const startOfQuarter = (date: Date): Date => {
  const quarter = Math.floor(date.getMonth() / 3);
  return new Date(date.getFullYear(), quarter * 3, 1);
};

const getPresetRange = (preset: DatePreset, reference = new Date()): { from: string; to: string } => {
  const end = new Date(reference);
  const start = new Date(reference);

  switch (preset) {
    case 'last7':
      start.setDate(start.getDate() - 6);
      break;
    case 'last30':
      start.setDate(start.getDate() - 29);
      break;
    case 'mtd':
      return { from: formatDate(startOfMonth(end)), to: formatDate(end) };
    case 'qtd':
      return { from: formatDate(startOfQuarter(end)), to: formatDate(end) };
    default:
      return { from: formatDate(start), to: formatDate(end) };
  }

  return { from: formatDate(start), to: formatDate(end) };
};

const parseStoredFilters = (browserTz: string): FilterState | null => {
  if (typeof window === 'undefined') {
    return null;
  }

  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw) as Partial<FilterState>;
    if (!parsed.date_from || !parsed.date_to) {
      return null;
    }

    return {
      preset: parsed.preset ?? DEFAULT_PRESET,
      date_from: parsed.date_from,
      date_to: parsed.date_to,
      tz: parsed.tz ?? browserTz,
      store: parsed.store ?? 'all',
    };
  } catch {
    return null;
  }
};

const deriveInitialFilters = (browserTz: string): FilterState => {
  const defaults = getPresetRange(DEFAULT_PRESET);
  const state: FilterState = {
    preset: DEFAULT_PRESET,
    date_from: defaults.from,
    date_to: defaults.to,
    tz: browserTz,
    store: 'all',
  };

  if (typeof window === 'undefined') {
    return state;
  }

  const params = new URLSearchParams(window.location.search);
  const stored = parseStoredFilters(browserTz);

  const preset = (params.get('preset') as DatePreset) ?? stored?.preset ?? DEFAULT_PRESET;
  const range =
    preset === 'custom'
      ? {
          from: params.get('from') ?? stored?.date_from ?? state.date_from,
          to: params.get('to') ?? stored?.date_to ?? state.date_to,
        }
      : getPresetRange(preset);

  return {
    preset,
    date_from: range.from,
    date_to: range.to,
    tz: params.get('tz') ?? stored?.tz ?? browserTz,
    store: params.get('store') ?? stored?.store ?? 'all',
  };
};

const currencyFormatter = (value: number | null | undefined) => <Money value={value ?? 0} />;

const numberFormatter = (value: number | null | undefined, fractionDigits = 2) => {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return '--';
  }
  return value.toFixed(fractionDigits);
};

const toHoursMinutes = (seconds: number): string => {
  if (!Number.isFinite(seconds) || seconds <= 0) {
    return 'â€”';
  }
  const minutes = Math.floor(seconds / 60);
  const remaining = Math.round(seconds % 60)
    .toString()
    .padStart(2, '0');
  return `${minutes}:${remaining}m`;
};

export function AnalyticsPage() {
  const { user } = useAuth();
  const { stores, loading: storesLoading } = useStore();
  const { addToast } = useToast();
  const [searchParams, setSearchParams] = useSearchParams();
  const browserTz = useMemo(
    () => Intl.DateTimeFormat().resolvedOptions().timeZone ?? 'UTC',
    []
  );
  const [filters, setFilters] = useState<FilterState>(() => deriveInitialFilters(browserTz));
  const [productMetric, setProductMetric] = useState<'qty' | 'revenue'>('qty');
  const [isExporting, setIsExporting] = useState(false);

  const canSelectStore = user?.role === 'admin' || user?.role === 'manager';
  const canViewCashiers = canSelectStore;

  useEffect(() => {
    if (!canSelectStore) {
      const forcedStore = user?.store_id ?? filters.store;
      if (forcedStore && forcedStore !== filters.store) {
        setFilters((prev) => ({ ...prev, store: forcedStore }));
      }
    }
  }, [canSelectStore, filters.store, user?.store_id]);

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(filters));

    const params = new URLSearchParams();
    params.set('preset', filters.preset);
    params.set('from', filters.date_from);
    params.set('to', filters.date_to);
    params.set('tz', filters.tz);
    if (filters.store && filters.store !== 'all') {
      params.set('store', filters.store);
    }
    setSearchParams(params, { replace: true });
  }, [filters, setSearchParams]);

  useEffect(() => {
    const preset = (searchParams.get('preset') as DatePreset) ?? null;
    const from = searchParams.get('from');
    const to = searchParams.get('to');
    const tz = searchParams.get('tz');
    const store = searchParams.get('store');

    if (!preset && !from && !to && !tz && !store) {
      return;
    }

    setFilters((prev) => {
      const next: FilterState = {
        preset: preset ?? prev.preset,
        date_from: from ?? prev.date_from,
        date_to: to ?? prev.date_to,
        tz: tz ?? prev.tz,
        store: store ?? prev.store,
      };

      const unchanged =
        next.preset === prev.preset &&
        next.date_from === prev.date_from &&
        next.date_to === prev.date_to &&
        next.tz === prev.tz &&
        next.store === prev.store;

      return unchanged ? prev : next;
    });
  }, [searchParams]);

  const queryFilters = useMemo(
    () => ({
      date_from: filters.date_from,
      date_to: filters.date_to,
      tz: filters.tz,
      store_id: filters.store === 'all' ? null : filters.store,
      limit: DEFAULT_LIMIT,
    }),
    [filters]
  );

  const summaryQuery = useQuery<AnalyticsSummaryResponse, Error>({
    queryKey: [
      'analytics-summary',
      queryFilters.date_from,
      queryFilters.date_to,
      queryFilters.tz,
      queryFilters.store_id ?? 'all',
    ],
    queryFn: () => getSummary(queryFilters),
  });

  const heatmapQuery = useQuery<HeatmapPoint[], Error>({
    queryKey: [
      'analytics-heatmap',
      queryFilters.date_from,
      queryFilters.date_to,
      queryFilters.tz,
      queryFilters.store_id ?? 'all',
    ],
    queryFn: () => getHourlyHeatmap(queryFilters),
  });

  const cashiersQuery = useQuery<CashierStat[], Error>({
    queryKey: [
      'analytics-cashiers',
      queryFilters.date_from,
      queryFilters.date_to,
      queryFilters.tz,
      queryFilters.store_id ?? 'all',
    ],
    queryFn: () => getCashiers(queryFilters),
    enabled: canViewCashiers,
  });

  useEffect(() => {
    if (summaryQuery.isError) {
      addToast({ type: 'error', message: getErrorMessage(summaryQuery.error) });
    }
  }, [addToast, summaryQuery.isError, summaryQuery.error]);

  useEffect(() => {
    if (heatmapQuery.isError) {
      addToast({ type: 'error', message: getErrorMessage(heatmapQuery.error) });
    }
  }, [addToast, heatmapQuery.isError, heatmapQuery.error]);

  useEffect(() => {
    if (cashiersQuery.isError) {
      addToast({ type: 'error', message: getErrorMessage(cashiersQuery.error) });
    }
  }, [addToast, cashiersQuery.isError, cashiersQuery.error]);

  const kpis = summaryQuery.data?.kpis;
  const trendData = summaryQuery.data?.trend_daily ?? [];
  const tenderMix = summaryQuery.data?.tender_mix ?? [];
  const topProducts = useMemo(
    () => summaryQuery.data?.top_products ?? [],
    [summaryQuery.data?.top_products]
  );
  const topCategories = useMemo(
    () => summaryQuery.data?.top_categories ?? [],
    [summaryQuery.data?.top_categories]
  );

  const storeOptions = useMemo(
    () =>
      (canSelectStore ? [{ id: 'all', name: 'All Stores' }] : []).concat(
        stores.map((store) => ({ id: store.id, name: store.name }))
      ),
    [canSelectStore, stores]
  );

  const timezoneOptions = useMemo(() => {
    const list = new Set(COMMON_TIMEZONES);
    list.add(browserTz);
    return Array.from(list).sort();
  }, [browserTz]);

  const handlePresetChange = (preset: DatePreset) => {
    if (preset === 'custom') {
      setFilters((prev) => ({ ...prev, preset }));
      return;
    }
    const range = getPresetRange(preset);
    setFilters((prev) => ({
      ...prev,
      preset,
      date_from: range.from,
      date_to: range.to,
    }));
  };

  const handleExport = async () => {
    try {
      setIsExporting(true);
      const label = filters.store === 'all' ? 'all' : filters.store;
      const fileName = `analytics-${label}-${filters.date_from}-to-${filters.date_to}.csv`;
      await exportAnalyticsCsv(queryFilters, fileName);
      addToast({ type: 'success', message: 'Analytics CSV exported.' });
    } catch (error) {
      addToast({ type: 'error', message: getErrorMessage(error) });
    } finally {
      setIsExporting(false);
    }
  };

  const totalTender = tenderMix.reduce((sum, slice) => sum + slice.amount, 0);

  const heatmapMatrix = useMemo(() => {
    const map = new Map<string, HeatmapPoint>();
    (heatmapQuery.data ?? []).forEach((point) => {
      map.set(`${point.dow}-${point.hour}`, point);
    });
    return map;
  }, [heatmapQuery.data]);

  const maxHeatmapValue = heatmapQuery.data?.reduce(
    (max, point) => Math.max(max, point.orders),
    0
  );

  const renderHeatCell = (dow: number, hour: number) => {
    const point = heatmapMatrix.get(`${dow}-${hour}`);
    const orders = point?.orders ?? 0;
    if (!maxHeatmapValue || maxHeatmapValue <= 0) {
      return '#e2e8f0';
    }
    const intensity = Math.min(orders / maxHeatmapValue, 1);
    const colorIndex = Math.min(
      HEATMAP_COLORS.length - 1,
      Math.floor(intensity * HEATMAP_COLORS.length)
    );
    return HEATMAP_COLORS[colorIndex];
  };

  const topProductMetric = useMemo(
    () =>
      topProducts.map((item) => ({
        label: item.name ?? 'Untitled product',
        qty: item.qty,
        revenue: item.revenue,
      })),
    [topProducts]
  );

  const topCategoryMetric = useMemo(
    () =>
      topCategories.map((item) => ({
        label: item.name ?? 'Uncategorized',
        qty: item.qty,
        revenue: item.revenue,
      })),
    [topCategories]
  );

  const renderBarChart = (
    data: { label: string; qty: number; revenue: number }[],
    metric: 'qty' | 'revenue'
  ) => {
    if (data.length === 0) {
      return <p className="text-sm text-slate-500">No leaderboard data yet.</p>;
    }

    return (
      <div className="h-72">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} margin={{ top: 10, right: 20, bottom: 10, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" />
            <XAxis dataKey="label" tick={{ fontSize: 12 }} />
            <YAxis
              tickFormatter={(value) =>
                metric === 'qty' ? value.toFixed(0) : `$${Number(value).toFixed(0)}`
              }
            />
            <Tooltip
              formatter={(value: number) =>
                metric === 'qty' ? value.toFixed(0) : `$${value.toFixed(2)}`
              }
            />
            <Bar dataKey={metric} fill="#2563eb" radius={[4, 4, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>
    );
  };

  const kpiCards = [
    { label: 'Revenue', value: kpis?.revenue_gross, formatter: currencyFormatter },
    { label: 'Orders', value: kpis?.orders, formatter: (v: number) => numberFormatter(v, 0) },
    { label: 'AOV', value: kpis?.aov, formatter: currencyFormatter },
    {
      label: 'Items / Order',
      value: kpis?.items_per_order,
      formatter: (v: number) => numberFormatter(v, 2),
    },
    { label: 'Taxes', value: kpis?.taxes_collected, formatter: currencyFormatter },
    { label: 'Discounts', value: kpis?.discounts_amount, formatter: currencyFormatter },
    { label: 'Refunds', value: kpis?.refunds_amount, formatter: currencyFormatter },
    { label: 'Unique Customers', value: kpis?.unique_customers, formatter: (v: number) => numberFormatter(v, 0) },
  ];

  return (
    <div className="space-y-6">
      <Card heading="Analytics Filters">
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap gap-2">
            {PRESETS.map((preset) => (
              <Button
                key={preset.value}
                variant={filters.preset === preset.value ? 'default' : 'outline'}
                size="sm"
                onClick={() => handlePresetChange(preset.value)}
              >
                {preset.label}
              </Button>
            ))}
          </div>
          {filters.preset === 'custom' ? (
            <div className="flex flex-col gap-3 sm:flex-row">
              <label className="flex flex-col text-xs font-semibold uppercase text-slate-500">
                From
                <input
                  type="date"
                  className="mt-1 rounded-md border border-slate-300 px-2 py-1 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                  value={filters.date_from}
                  onChange={(event) =>
                    setFilters((prev) => ({
                      ...prev,
                      date_from: event.target.value,
                    }))
                  }
                />
              </label>
              <label className="flex flex-col text-xs font-semibold uppercase text-slate-500">
                To
                <input
                  type="date"
                  className="mt-1 rounded-md border border-slate-300 px-2 py-1 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                  value={filters.date_to}
                  onChange={(event) =>
                    setFilters((prev) => ({
                      ...prev,
                      date_to: event.target.value,
                    }))
                  }
                />
              </label>
            </div>
          ) : null}
          <div className="flex flex-col gap-3 lg:flex-row">
            {canSelectStore ? (
              <label className="flex flex-1 flex-col text-xs font-semibold uppercase text-slate-500">
                Store
                <select
                  className="mt-1 rounded-md border border-slate-300 px-2 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                  value={filters.store}
                  onChange={(event) =>
                    setFilters((prev) => ({
                      ...prev,
                      store: event.target.value,
                    }))
                  }
                  disabled={storesLoading}
                >
                  {storeOptions.map((store) => (
                    <option key={store.id} value={store.id}>
                      {store.name}
                    </option>
                  ))}
                </select>
              </label>
            ) : null}
            <label className="flex flex-1 flex-col text-xs font-semibold uppercase text-slate-500">
              Timezone
              <select
                className="mt-1 rounded-md border border-slate-300 px-2 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                value={filters.tz}
                onChange={(event) =>
                  setFilters((prev) => ({
                    ...prev,
                    tz: event.target.value,
                  }))
                }
              >
                {timezoneOptions.map((tz) => (
                  <option key={tz} value={tz}>
                    {tz}
                  </option>
                ))}
              </select>
            </label>
            <div className="flex items-end">
              <Button onClick={handleExport} disabled={isExporting || summaryQuery.isLoading}>
                {isExporting ? 'Exportingâ€¦' : 'Export CSV'}
              </Button>
            </div>
          </div>
        </div>
      </Card>

      <Card heading="Key Metrics">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {kpiCards.map((card) => (
            <div key={card.label} className="rounded-lg border border-slate-100 bg-slate-50 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{card.label}</p>
              <p className="mt-2 text-2xl font-semibold text-slate-900">
                {summaryQuery.isLoading ? (
                  <span className="inline-flex h-6 w-24 animate-pulse rounded bg-slate-200" />
                ) : (
                  card.formatter(card.value ?? 0)
                )}
              </p>
            </div>
          ))}
        </div>
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card heading="Daily Revenue & Orders">
          {trendData.length === 0 ? (
            <p className="text-sm text-slate-500">No trend data for the selected range.</p>
          ) : (
            <div className="h-80">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={trendData} margin={{ top: 10, right: 20, bottom: 0, left: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="date" tick={{ fontSize: 12 }} />
                  <YAxis
                    yAxisId="left"
                    tickFormatter={(value) => `$${Number(value).toFixed(0)}`}
                  />
                  <YAxis yAxisId="right" orientation="right" />
                  <Tooltip
                    formatter={(value: number, name: string) =>
                      name === 'orders' ? value.toFixed(0) : `$${value.toFixed(2)}`
                    }
                  />
                  <Legend />
                  <Line
                    type="monotone"
                    dataKey="revenue_gross"
                    name="Revenue"
                    stroke="#2563eb"
                    strokeWidth={2}
                    yAxisId="left"
                  />
                  <Line
                    type="monotone"
                    dataKey="orders"
                    name="Orders"
                    stroke="#16a34a"
                    strokeWidth={2}
                    yAxisId="right"
                  />
                </LineChart>
              </ResponsiveContainer>
            </div>
          )}
        </Card>

        <Card heading="Tender Mix" description="Distribution of captured tenders.">
          {totalTender <= 0 ? (
            <p className="text-sm text-slate-500">No tender data for the selected range.</p>
          ) : (
            <div className="flex flex-col items-center gap-4 sm:flex-row">
              <div className="h-72 w-full sm:w-1/2">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie
                      data={tenderMix}
                      dataKey="amount"
                      nameKey="tender"
                      outerRadius={100}
                      innerRadius={60}
                      label={(entry) => `${entry.tender}: ${Math.round((entry.amount / totalTender) * 100)}%`}
                    >
                      {tenderMix.map((slice, index) => (
                        <Cell key={slice.tender} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                      ))}
                    </Pie>
                    <Tooltip
                      formatter={(value: number, name: string) =>
                        [`$${value.toFixed(2)}`, name ?? 'Tender']
                      }
                    />
                  </PieChart>
                </ResponsiveContainer>
              </div>
              <ul className="flex-1 space-y-2 text-sm">
                {tenderMix.map((slice, index) => (
                  <li key={slice.tender} className="flex items-center justify-between">
                    <span className="flex items-center gap-2">
                      <span
                        className="h-3 w-3 rounded-full"
                        style={{ backgroundColor: CHART_COLORS[index % CHART_COLORS.length] }}
                      />
                      {slice.tender}
                    </span>
                    <span>
                      <Money value={slice.amount} /> (
                      {Math.round((slice.amount / totalTender) * 100) || 0}%)
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card
          heading="Top Products"
          description="Performance by quantity or revenue."
          actions={
            <div className="inline-flex rounded-md border border-slate-200 p-1">
              {(['qty', 'revenue'] as const).map((metric) => (
                <button
                  key={metric}
                  type="button"
                  className={`rounded px-3 py-1 text-sm font-medium ${
                    productMetric === metric ? 'bg-blue-600 text-white' : 'text-slate-600'
                  }`}
                  onClick={() => setProductMetric(metric)}
                >
                  {metric === 'qty' ? 'Quantity' : 'Revenue'}
                </button>
              ))}
            </div>
          }
        >
          {renderBarChart(topProductMetric, productMetric)}
        </Card>

        <Card heading="Top Categories">
          {renderBarChart(topCategoryMetric, 'revenue')}
        </Card>
      </div>

      <Card heading="Hourly Heatmap" description="Orders by day of week and hour of day.">
        {heatmapQuery.isLoading ? (
          <div className="space-y-2">
            {DAY_LABELS.map((label) => (
              <div key={label} className="flex gap-2">
                <div className="h-6 w-10 bg-slate-100" />
                <div className="flex-1 space-x-1">
                  {Array.from({ length: 24 }).map((_, index) => (
                    <span key={index} className="inline-block h-6 w-6 animate-pulse rounded bg-slate-200" />
                  ))}
                </div>
              </div>
            ))}
          </div>
        ) : (
          <div className="space-y-2 overflow-x-auto">
            {DAY_LABELS.map((label, dow) => (
              <div key={label} className="flex items-center gap-2">
                <span className="w-10 text-xs font-semibold uppercase text-slate-500">{label}</span>
                <div className="flex flex-1 gap-1">
                  {Array.from({ length: 24 }).map((_, hour) => (
                    <div
                      key={`${label}-${hour}`}
                      className="h-6 w-6 rounded text-[10px] text-white"
                      style={{
                        backgroundColor: renderHeatCell(dow, hour),
                      }}
                      title={`Hour ${hour}: ${
                        heatmapMatrix.get(`${dow}-${hour}`)?.orders ?? 0
                      } orders`}
                    />
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      {canViewCashiers ? (
        <Card heading="Cashier Performance">
          {cashiersQuery.isLoading ? (
            <div className="space-y-2">
              {Array.from({ length: 4 }).map((_, index) => (
                <div key={index} className="h-4 w-full animate-pulse rounded bg-slate-200" />
              ))}
            </div>
          ) : cashiersQuery.data && cashiersQuery.data.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                    <th className="px-3 py-2">Cashier</th>
                    <th className="px-3 py-2 text-right">Orders</th>
                    <th className="px-3 py-2 text-right">Revenue</th>
                    <th className="px-3 py-2 text-right">Avg Handle Time</th>
                  </tr>
                </thead>
                <tbody>
                  {cashiersQuery.data.map((cashier) => (
                    <tr key={cashier.user_id} className="border-t border-slate-100">
                      <td className="px-3 py-2 font-medium text-slate-900">{cashier.name}</td>
                      <td className="px-3 py-2 text-right">{cashier.orders}</td>
                      <td className="px-3 py-2 text-right">
                        <Money value={cashier.revenue_gross} />
                      </td>
                      <td className="px-3 py-2 text-right">
                        {toHoursMinutes(cashier.avg_handle_time_seconds)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-sm text-slate-500">No cashier activity for the selected filters.</p>
          )}
        </Card>
      ) : null}
    </div>
  );
}

export default AnalyticsPage;






