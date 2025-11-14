import { api } from '../../lib/api';

export type FinanceFilters = {
  date_from: string;
  date_to: string;
  store_id?: string | null;
  currency: string;
  tz?: string;
  limit?: number;
  bucket?: 'day' | 'week' | 'month';
};

export type FinanceSummaryResponse = {
  revenue: number;
  cogs: number;
  gross_profit: number;
  gross_margin: number;
  expenses_total: number;
  net_profit: number;
  net_margin: number;
  avg_ticket: number;
  orders_count: number;
};

export type FinanceFlowPoint = {
  period: string;
  cash_in: number;
  cash_out: number;
  net: number;
  profit: number;
};

export type FinanceExpense = {
  category: string;
  amount: number;
  percent: number;
};

export type FinanceHealthSignal = {
  label: string;
  level: 'info' | 'warn' | 'alert';
  detail: string;
  period: string;
};

export type FinanceHealth = {
  score: number;
  signals: FinanceHealthSignal[];
};

export type FinanceMetaResponse = {
  summary: string | null;
  flow: string | null;
  expenses: string | null;
  health: string | null;
  last_updated_at: string | null;
};

export type FinanceExportJobResponse = {
  job_id: string;
  status_url: string;
};

export type FinanceExportStatus =
  | {
      job_id: string;
      status: 'pending' | 'processing';
      type: 'csv' | 'pdf';
    }
  | {
      job_id: string;
      status: 'completed';
      type: 'csv' | 'pdf';
      download_url: string;
    }
  | {
      job_id: string;
      status: 'failed';
      type: 'csv' | 'pdf';
      error?: string;
    };

export const getBrowserTimezone = (): string => {
  try {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    return tz || 'UTC';
  } catch {
    return 'UTC';
  }
};

export const createFinanceRequestParams = (filters: FinanceFilters) => ({
  date_from: filters.date_from,
  date_to: filters.date_to,
  store_id: filters.store_id ?? undefined,
  currency: filters.currency,
  tz: filters.tz ?? getBrowserTimezone(),
  limit: filters.limit ?? undefined,
  bucket: filters.bucket ?? undefined,
});

export async function getFinanceSummary(
  filters: FinanceFilters
): Promise<FinanceSummaryResponse> {
  const { data } = await api.get<FinanceSummaryResponse>('/bo/finance/summary', {
    params: createFinanceRequestParams(filters),
  });
  return data;
}

export async function getFinanceFlow(filters: FinanceFilters): Promise<FinanceFlowPoint[]> {
  const { data } = await api.get<FinanceFlowPoint[]>('/bo/finance/flow', {
    params: createFinanceRequestParams(filters),
  });
  return data;
}

export async function getFinanceExpenses(filters: FinanceFilters): Promise<FinanceExpense[]> {
  const { data } = await api.get<FinanceExpense[]>('/bo/finance/expenses', {
    params: createFinanceRequestParams(filters),
  });
  return data;
}

export async function getFinanceHealth(filters: FinanceFilters): Promise<FinanceHealth> {
  const { data } = await api.get<FinanceHealth>('/bo/finance/health', {
    params: createFinanceRequestParams(filters),
  });
  return data;
}

export async function getFinanceMeta(filters: FinanceFilters): Promise<FinanceMetaResponse> {
  const { data } = await api.get<FinanceMetaResponse>('/bo/finance/meta', {
    params: createFinanceRequestParams(filters),
  });
  return data;
}

export async function requestFinanceExport(
  type: 'csv' | 'pdf',
  filters: FinanceFilters
): Promise<FinanceExportJobResponse> {
  const { data } = await api.get<FinanceExportJobResponse>(`/bo/finance/export.${type}`, {
    params: createFinanceRequestParams(filters),
  });
  return data;
}

export async function getFinanceExportStatus(jobId: string): Promise<FinanceExportStatus> {
  const { data } = await api.get<FinanceExportStatus>(`/bo/finance/export/status/${jobId}`);
  return data;
}

export async function downloadFinanceExport(
  downloadUrl: string,
  fallbackFileName: string
): Promise<void> {
  const response = await api.get<Blob>(downloadUrl, {
    baseURL: '',
    responseType: 'blob',
  });

  const disposition = response.headers['content-disposition'] as string | undefined;
  let fileName = fallbackFileName;
  if (disposition) {
    const match = disposition.match(/filename="?([^";]+)"?/i);
    if (match?.[1]) {
      fileName = match[1];
    }
  }

  const blob = new Blob([response.data]);
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = fileName;
  document.body.appendChild(anchor);
  anchor.click();
  document.body.removeChild(anchor);
  URL.revokeObjectURL(url);
}

