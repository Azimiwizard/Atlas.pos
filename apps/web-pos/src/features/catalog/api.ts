import { api } from '../../lib/api';

export type Category = {
  id: string;
  name: string;
  is_active: boolean;
};

export type Promotion = {
  id: string;
  name: string;
  type: 'percent' | 'amount';
  value: number;
  applies_to: 'all' | 'category' | 'product';
  category_id: string | null;
  product_id: string | null;
  is_active: boolean;
  starts_at: string | null;
  ends_at: string | null;
};

export async function fetchCategories(): Promise<Category[]> {
  const { data } = await api.get<Category[]>('/categories', {
    params: { is_active: true },
  });
  return data;
}

export async function fetchPromotions(): Promise<Promotion[]> {
  const { data } = await api.get<Promotion[]>('/promotions');
  return data;
}

