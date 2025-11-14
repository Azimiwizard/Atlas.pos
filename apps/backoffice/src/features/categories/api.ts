import { api, getErrorMessage } from '../../lib/api';

export type MenuCategory = {
  id: string;
  name: string;
  sort_order: number;
  is_active: boolean;
  image_url: string | null;
  created_at?: string;
  updated_at?: string;
};

export type MenuCategoryPayload = {
  name: string;
  sort_order?: number;
  is_active?: boolean;
  image_url?: string | null;
};

export type CategoryOption = MenuCategory;

type UnknownRecord = Record<string, unknown>;

type MenuCategoryEnvelope = {
  data?: unknown;
};

type MenuCategoryResponse = MenuCategoryEnvelope | unknown[];

function isRecord(value: unknown): value is UnknownRecord {
  return typeof value === 'object' && value !== null;
}

function isEnvelope(value: MenuCategoryResponse): value is MenuCategoryEnvelope & UnknownRecord {
  return isRecord(value) && Object.prototype.hasOwnProperty.call(value, 'data');
}

function normalizeCategory(category: unknown): MenuCategory {
  const record = isRecord(category) ? (category as UnknownRecord) : {};
  const sortOrderRaw = record.sort_order;
  const numericSortOrder =
    typeof sortOrderRaw === 'number'
      ? sortOrderRaw
      : typeof sortOrderRaw === 'string'
        ? Number(sortOrderRaw)
        : 0;

  const imageUrl = record.image_url;
  const createdAt = record.created_at;
  const updatedAt = record.updated_at;

  return {
    id: String(record.id ?? ''),
    name: String(record.name ?? ''),
    sort_order: Number.isFinite(numericSortOrder) ? numericSortOrder : 0,
    is_active: Boolean(record.is_active ?? false),
    image_url: typeof imageUrl === 'string' ? imageUrl : null,
    created_at: typeof createdAt === 'string' ? createdAt : undefined,
    updated_at: typeof updatedAt === 'string' ? updatedAt : undefined,
  };
}

function extractCategories(payload: MenuCategoryResponse): MenuCategory[] {
  const items = Array.isArray(payload)
    ? payload
    : isEnvelope(payload) && Array.isArray(payload.data)
      ? payload.data
      : [];

  return items.map(normalizeCategory);
}

export async function listCategories(
  options: { includeInactive?: boolean } = {}
): Promise<MenuCategory[]> {
  try {
    const { data } = await api.get<MenuCategoryResponse>('/bo/menu/categories', {
      params: {
        per_page: 0,
        sort: 'sort_order:asc',
      },
    });

    const categories = extractCategories(data);

    if (options.includeInactive) {
      return categories;
    }

    return categories.filter((category) => category.is_active);
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

export async function getCategory(id: string): Promise<MenuCategory> {
  try {
    const { data } = await api.get<{ data: MenuCategory }>(`/bo/menu/categories/${id}`);
    return normalizeCategory(data.data);
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

export async function createCategory(payload: MenuCategoryPayload): Promise<MenuCategory> {
  try {
    const { data } = await api.post<{ data: MenuCategory }>('/bo/menu/categories', payload);
    return normalizeCategory(data.data);
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

export async function updateCategory(
  id: string,
  payload: MenuCategoryPayload
): Promise<MenuCategory> {
  try {
    const { data } = await api.put<{ data: MenuCategory }>(
      `/bo/menu/categories/${id}`,
      payload
    );
    return normalizeCategory(data.data);
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

export async function deleteCategory(id: string): Promise<void> {
  try {
    await api.delete(`/bo/menu/categories/${id}`);
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

export async function toggleCategory(id: string): Promise<MenuCategory> {
  try {
    const { data } = await api.patch<{ data: MenuCategory }>(
      `/bo/menu/categories/${id}/toggle`
    );
    return normalizeCategory(data.data);
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}
