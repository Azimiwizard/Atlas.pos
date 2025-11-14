import { api } from '../../lib/api';

export type ShiftSummary = {
  shift: {
    id: string;
    opened_at: string;
    closed_at: string | null;
    register: {
      id: string;
      name: string;
      location?: string | null;
    };
    user: {
      id: string;
      name: string;
      email?: string;
    };
    opening_float: number;
    closing_cash: number | null;
  };
  sales: {
    total_orders: number;
    gross: number;
    refunds: number;
    net: number;
    cash_sales_total: number;
    cash_refunds_total: number;
  };
  cash_movements: {
    cash_in: number;
    cash_out: number;
  };
  expected_cash: number;
  cash_over_short: number | null;
};

export type PaginatedShifts = {
  data: ShiftSummary[];
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type ShiftFilters = {
  date?: string;
  register_id?: string;
  user_id?: string;
  page?: number;
  per_page?: number;
  store_id?: string;
};

export async function fetchShifts(filters: ShiftFilters = {}): Promise<PaginatedShifts> {
  const { data } = await api.get<PaginatedShifts>('/shifts', { params: filters });
  return data;
}

export async function getShiftReport(id: string): Promise<ShiftSummary> {
  const { data } = await api.get<ShiftSummary>(`/shifts/${id}`);
  return data;
}
