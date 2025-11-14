import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button, Card } from '@atlas-pos/ui';
import { useStore } from '../hooks/useStore';
import {
  getBrowserTimezone,
  getFinanceHealth,
  type FinanceHealthSignal,
} from '../features/finance/api';
import { getErrorMessage } from '../lib/api';

type HealthPreset = 'last30' | 'last60' | 'last120';

const HEALTH_PRESETS: Array<{ value: HealthPreset; label: string; days: number }> = [
  { value: 'last30', label: 'Last 30 days', days: 30 },
  { value: 'last60', label: 'Last 60 days', days: 60 },
  { value: 'last120', label: 'Last 120 days', days: 120 },
];

const DEFAULT_PRESET: HealthPreset = 'last120';
const DEFAULT_CURRENCY = 'USD';

const formatDate = (date: Date): string => date.toISOString().slice(0, 10);

const getRangeForPreset = (preset: HealthPreset): { from: string; to: string } => {
  const option = HEALTH_PRESETS.find((item) => item.value === preset) ?? HEALTH_PRESETS[0];
  const end = new Date();
  const start = new Date();
  start.setDate(start.getDate() - (option.days - 1));
  return { from: formatDate(start), to: formatDate(end) };
};

const LEVEL_STYLES: Record<
  FinanceHealthSignal['level'],
  { badge: string; card: string; text: string; border: string }
> = {
  info: {
    badge: 'bg-slate-100 text-slate-700',
    card: 'bg-slate-50',
    text: 'text-slate-700',
    border: 'border-slate-200',
  },
  warn: {
    badge: 'bg-amber-100 text-amber-800',
    card: 'bg-amber-50/60',
    text: 'text-amber-800',
    border: 'border-amber-200',
  },
  alert: {
    badge: 'bg-rose-100 text-rose-800',
    card: 'bg-rose-50/80',
    text: 'text-rose-800',
    border: 'border-rose-200',
  },
};

const SCORE_COLORS = ['bg-rose-500', 'bg-amber-500', 'bg-emerald-500'] as const;

const periodLabel = (period: string): string =>
  period.charAt(0).toUpperCase() + period.slice(1).toLowerCase();

const buildQueryKey = (filters: {
  date_from: string;
  date_to: string;
  currency: string;
  tz: string;
  store_id?: string;
}) => ['finance-health', filters] as const;

export default function DashboardPage() {
  const { currentStore, currentStoreId, loading: storeLoading } = useStore();
  const [preset, setPreset] = useState<HealthPreset>(DEFAULT_PRESET);
  const timezone = useMemo(() => getBrowserTimezone(), []);
  const range = useMemo(() => getRangeForPreset(preset), [preset]);

  const filters = useMemo(
    () => ({
      date_from: range.from,
      date_to: range.to,
      currency: DEFAULT_CURRENCY,
      tz: timezone,
      store_id: currentStoreId ?? undefined,
    }),
    [range, timezone, currentStoreId]
  );

  const healthQuery = useQuery({
    queryKey: buildQueryKey(filters),
    queryFn: () => getFinanceHealth(filters),
    enabled: !storeLoading,
    staleTime: 60_000,
  });

  const health = healthQuery.data;
  const storeLabel = currentStore?.name ?? 'All stores';
  const rangeLabel = `${range.from} → ${range.to}`;

  const score = health?.score ?? 0;
  const scoreTone =
    score >= 75 ? SCORE_COLORS[2] : score >= 50 ? SCORE_COLORS[1] : SCORE_COLORS[0];

  const renderSignals = () => {
    if (healthQuery.isLoading) {
      return (
        <div className="space-y-3">
          {[0, 1, 2].map((item) => (
            <div
              key={item}
              className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
            >
              <div className="h-4 w-32 animate-pulse rounded bg-slate-200" />
              <div className="mt-2 h-3 w-full animate-pulse rounded bg-slate-100" />
              <div className="mt-2 h-3 w-3/4 animate-pulse rounded bg-slate-100" />
            </div>
          ))}
        </div>
      );
    }

    if (healthQuery.isError) {
      return (
        <div className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
          {getErrorMessage(healthQuery.error)}
        </div>
      );
    }

    if (!health || health.signals.length === 0) {
      return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
          Finance signals will appear once there is at least one full week of activity for the
          selected range.
        </div>
      );
    }

    return (
      <div className="space-y-3">
        {health.signals.map((signal) => {
          const styles = LEVEL_STYLES[signal.level];
          return (
            <div
              key={`${signal.label}-${signal.period}`}
              className={`rounded-xl border p-4 shadow-sm ${styles.card} ${styles.border}`}
            >
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-semibold text-slate-900">{signal.label}</p>
                <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${styles.badge}`}>
                  {signal.level === 'info'
                    ? 'Info'
                    : signal.level === 'warn'
                      ? 'Watch'
                      : 'Alert'}
                </span>
              </div>
              <p className="mt-2 text-sm text-slate-700">{signal.detail}</p>
              <p className={`mt-2 text-xs font-medium uppercase tracking-wide ${styles.text}`}>
                Period: {periodLabel(signal.period)}
              </p>
            </div>
          );
        })}
      </div>
    );
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Dashboard</h1>
        <p className="mt-1 text-sm text-slate-600">
          Snapshot of finance health based on rolling revenue, margin, and expense trends.
        </p>
      </div>

      <Card heading="Finance health" description="Signals refresh every time you change filters.">
        <div className="flex flex-col gap-6">
          <div className="flex flex-wrap items-center gap-4">
            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Store</p>
              <p className="text-sm font-semibold text-slate-900">{storeLabel}</p>
              <p className="text-xs text-slate-500">{rangeLabel}</p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
              {HEALTH_PRESETS.map((option) => (
                <button
                  key={option.value}
                  type="button"
                  className={`rounded-full border px-3 py-1 text-xs font-semibold transition ${
                    preset === option.value
                      ? 'border-blue-500 bg-blue-50 text-blue-700 shadow-sm'
                      : 'border-slate-200 bg-transparent text-slate-600 hover:border-blue-200 hover:text-blue-600'
                  }`}
                  onClick={() => setPreset(option.value)}
                  disabled={healthQuery.isFetching && preset === option.value}
                >
                  {option.label}
                </button>
              ))}
            </div>

            <div className="ml-auto">
              <Button
                variant="outline"
                size="sm"
                onClick={() => healthQuery.refetch()}
                disabled={healthQuery.isFetching}
              >
                {healthQuery.isFetching ? 'Refreshing…' : 'Refresh'}
              </Button>
            </div>
          </div>

          <div className="grid gap-6 lg:grid-cols-[280px_minmax(0,1fr)]">
            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
              <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">
                Health score
              </p>
              {healthQuery.isLoading ? (
                <div className="mt-4 h-14 w-20 animate-pulse rounded bg-slate-200" />
              ) : (
                <div className="mt-3 text-5xl font-semibold text-slate-900">{score}</div>
              )}
              <div className="mt-4 h-2 rounded-full bg-slate-200">
                <div
                  className={`h-2 rounded-full ${healthQuery.isLoading ? 'bg-slate-300' : scoreTone}`}
                  style={{ width: `${Math.min(Math.max(score, 0), 100)}%` }}
                />
              </div>
              <p className="mt-3 text-xs text-slate-500">
                Scores combine net and gross margins. Rules for drops and spikes may require up to
                four months of history.
              </p>
            </div>

            <div>{renderSignals()}</div>
          </div>
        </div>
      </Card>
    </div>
  );
}
