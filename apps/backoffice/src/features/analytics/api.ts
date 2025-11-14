import { api } from '../../lib/api';

export type AnalyticsFilters = {
  date_from: string;
  date_to: string;
  tz: string;
  store_id?: string | null;
  limit?: number;
};

export type AnalyticsKpis = {
  revenue_gross: number;
  orders: number;
  aov: number;
  items_per_order: number;
  refunds_amount: number;
  discounts_amount: number;
  taxes_collected: number;
  gross_margin_estimate: number;
  unique_customers: number;
};

export type DailyTrendPoint = {
  date: string;
  revenue_gross: number;
  orders: number;
  refunds_amount: number;
};

export type LeaderboardItem = {
  id: string;
  name: string;
  qty: number;
  revenue: number;
};

export type TenderSlice = {
  tender: string;
  amount: number;
};

export type AnalyticsSummaryResponse = {
  range: { from: string; to: string; tz: string };
  filters: { store_id: string | null };
  kpis: AnalyticsKpis;
  trend_daily: DailyTrendPoint[];
  tender_mix: TenderSlice[];
  top_products: LeaderboardItem[];
  top_categories: LeaderboardItem[];
};

export type HeatmapPoint = {
  dow: number;
  hour: number;
  orders: number;
  revenue_gross: number;
};

export type CashierStat = {
  user_id: string;
  name: string;
  orders: number;
  revenue_gross: number;
  avg_handle_time_seconds: number;
};

const buildParams = (filters: AnalyticsFilters) => ({
  date_from: filters.date_from,
  date_to: filters.date_to,
  tz: filters.tz,
  store_id: filters.store_id || undefined,
  limit: filters.limit ?? undefined,
});

export async function getSummary(filters: AnalyticsFilters): Promise<AnalyticsSummaryResponse> {
  const { data } = await api.get<AnalyticsSummaryResponse>('/bo/analytics/summary', {
    params: buildParams(filters),
  });
  return data;
}

export async function getHourlyHeatmap(filters: AnalyticsFilters): Promise<HeatmapPoint[]> {
  const { data } = await api.get<HeatmapPoint[]>('/bo/analytics/hourly-heatmap', {
    params: buildParams(filters),
  });
  return data;
}

export async function getCashiers(filters: AnalyticsFilters): Promise<CashierStat[]> {
  const { data } = await api.get<CashierStat[]>('/bo/analytics/cashiers', {
    params: buildParams(filters),
  });
  return data;
}

export async function exportAnalyticsCsv(
  filters: AnalyticsFilters,
  fileName = 'analytics-export.csv'
): Promise<void> {
  const { data } = await api.get<Blob>('/bo/analytics/export.csv', {
    params: buildParams(filters),
    responseType: 'blob',
  });

  const blob = new Blob([data], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = fileName;
  document.body.appendChild(anchor);
  anchor.click();
  document.body.removeChild(anchor);
  URL.revokeObjectURL(url);
}
