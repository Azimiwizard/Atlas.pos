import { api } from '../../lib/api';

export type Store = {
  id: string;
  name: string;
  code: string;
  address?: string | null;
  phone?: string | null;
  is_active: boolean;
};

export async function listStores(params?: {
  includeInactive?: boolean;
}): Promise<Store[]> {
  const { data } = await api.get<Store[]>('/stores', {
    params: params?.includeInactive ? { include_inactive: true } : undefined,
  });

  return data;
}
