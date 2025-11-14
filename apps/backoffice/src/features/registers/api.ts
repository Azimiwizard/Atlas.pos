import { api } from '../../lib/api';

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

export type RegisterPayload = {
  name: string;
  location?: string | null;
  is_active?: boolean;
  store_id?: string | null;
};

export type RegisterUpdatePayload = Partial<RegisterPayload>;

export async function fetchRegisters(
  includeInactive = true,
  storeId?: string | null
): Promise<Register[]> {
  const params: Record<string, string | number | boolean> = {};

  if (includeInactive) {
    params.include_inactive = true;
  }

  if (storeId) {
    params.store_id = storeId;
  }

  const { data } = await api.get<Register[]>('/registers', {
    params: Object.keys(params).length > 0 ? params : undefined,
  });
  return data;
}

export async function createRegister(payload: RegisterPayload): Promise<Register> {
  const { data } = await api.post<Register>('/registers', payload);
  return data;
}

export async function updateRegister(
  id: string,
  payload: RegisterUpdatePayload
): Promise<Register> {
  const { data } = await api.put<Register>(`/registers/${id}`, payload);
  return data;
}
