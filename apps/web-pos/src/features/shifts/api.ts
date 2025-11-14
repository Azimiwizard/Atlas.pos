import { api, setStoreId as persistStoreHeader } from '../../lib/api';

export type Register = {
  id: string;
  name: string;
  location: string | null;
  is_active: boolean;
  store_id?: string | null;
  store?: {
    id: string;
    name: string;
  } | null;
};

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
    store?: {
      id: string;
      name: string;
    } | null;
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

export type CurrentShiftResponse = {
  shift: ShiftSummary | null;
};

function normalizeRegister(payload: any): Register {
  const rawId = payload?.id ?? payload?.register_id ?? '';
  const id = String(rawId);

  const rawStore = payload?.store ?? null;
  const normalizedStore =
    rawStore && typeof rawStore === 'object'
      ? {
        id: String(rawStore.id ?? rawStore.store_id ?? ''),
        name: rawStore.name ?? 'Store',
      }
      : null;

  const rawStoreId = payload?.store_id ?? normalizedStore?.id ?? null;

  return {
    id,
    name: payload?.name ?? `Register ${id}`,
    location: payload?.location ?? null,
    is_active: Boolean(payload?.is_active ?? true),
    store_id: rawStoreId !== null && rawStoreId !== undefined ? String(rawStoreId) : null,
    store: normalizedStore,
  };
}

export async function listRegisters(options: {
  includeInactive?: boolean;
  storeId?: string | null;
} = {}): Promise<Register[]> {
  const params: Record<string, string | boolean> = {};

  if (options.includeInactive) {
    params.include_inactive = true;
  }

  if (options.storeId) {
    persistStoreHeader(options.storeId);
  }

  const { data } = await api.get('/registers', {
    params: Object.keys(params).length > 0 ? params : undefined,
  });
  const items = Array.isArray(data) ? data : [];
  return items.map((item) => normalizeRegister(item));
}

export async function openShift(registerId: string, openingFloat: number) {
  const { data } = await api.post('/shifts/open', {
    register_id: registerId,
    opening_float: openingFloat,
  });
  return data;
}

export async function closeShift(shiftId: string, closingCash: number) {
  const { data } = await api.post(`/shifts/${shiftId}/close`, {
    closing_cash: closingCash,
  });
  return data as ShiftSummary;
}

export async function addCashMovement(
  shiftId: string,
  type: 'cash_in' | 'cash_out',
  amount: number,
  reason?: string
) {
  const { data } = await api.post(`/shifts/${shiftId}/cash`, {
    type,
    amount,
    reason,
  });
  return data;
}

export async function getCurrentShift(): Promise<CurrentShiftResponse> {
  const { data } = await api.get<CurrentShiftResponse>('/shifts/current');
  return data;
}

export async function getShiftReport(id: string): Promise<ShiftSummary> {
  const { data } = await api.get<ShiftSummary>(`/shifts/${id}`);
  return data;
}

export async function attachOrderToShift(orderId: string, shiftId: string) {
  const { data } = await api.post(`/orders/${orderId}/attach-shift`, {
    shift_id: shiftId,
  });
  return data;
}

