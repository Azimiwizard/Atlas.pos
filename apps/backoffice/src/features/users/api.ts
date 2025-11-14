import { api } from '../../lib/api';

export type BackofficeUser = {
  id: string;
  name: string;
  email: string;
  role: string;
  store_id: string | null;
  store?: {
    id: string;
    name: string;
  } | null;
};

export type UpdateUserPayload = {
  name?: string;
  email?: string;
  role?: string;
  store_id?: string | null;
};

export async function fetchUsers(): Promise<BackofficeUser[]> {
  const { data } = await api.get<BackofficeUser[]>('/users');
  return data;
}

export async function updateUser(id: string, payload: UpdateUserPayload): Promise<BackofficeUser> {
  const { data } = await api.put<BackofficeUser>(`/users/${id}`, payload);
  return data;
}
