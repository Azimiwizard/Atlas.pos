import { api } from '../../lib/api';

export type Store = {
  id: string;
  name: string;
  code: string;
  address: string | null;
  phone: string | null;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
};

export type StorePayload = {
  name: string;
  code: string;
  address?: string | null;
  phone?: string | null;
  is_active?: boolean;
};

export type ListStoresOptions = {
  isActive?: boolean | null;
};

export async function listStores(options: ListStoresOptions = {}): Promise<Store[]> {
  const params: Record<string, string> = {};

  if (options.isActive === true) {
    params.is_active = '1';
  } else if (options.isActive === false) {
    params.is_active = '0';
  }

  const { data } = await api.get<Store[]>('/stores', {
    params: Object.keys(params).length > 0 ? params : undefined,
  });
  return data;
}

export async function createStore(payload: StorePayload): Promise<Store> {
  const { data } = await api.post<Store>('/stores', payload);
  return data;
}

export async function updateStore(id: string, payload: StorePayload): Promise<Store> {
  const { data } = await api.put<Store>(`/stores/${id}`, payload);
  return data;
}

export async function deactivateStore(id: string): Promise<void> {
  await api.delete(`/stores/${id}`);
}
