import { api, getErrorMessage } from '../../lib/api';

export type ProductStockSummary = {
  store_id: string;
  store_name?: string | null;
  store_code?: string | null;
  qty_on_hand: number;
};

export type ProductStockRow = {
  tenant_id: string;
  store_id: string;
  store_name?: string | null;
  store_code?: string | null;
  variant_id?: string | null;
  variant_name?: string | null;
  variant_sku?: string | null;
  qty_on_hand: number;
};

export type Product = {
  id: string;
  title: string;
  price: number;
  category_id: string | null;
  category_name?: string | null;
  sku: string | null;
  barcode: string | null;
  tax_code: string | null;
  image_url: string | null;
  track_stock: boolean;
  is_active: boolean;
  stock_by_store?: ProductStockSummary[];
  stock_summary?: ProductStockRow[];
  variants?: Array<{
    id: string;
    name: string | null;
    sku: string | null;
    barcode?: string | null;
    track_stock: boolean;
    is_default?: boolean;
  }>;
  created_at: string;
  updated_at?: string;
};

export type PaginatedProducts = {
  data: Product[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type ProductListParams = {
  q?: string;
  category_id?: string;
  is_active?: 'true' | 'false';
  page?: number;
  sort?: string;
  per_page?: number;
};

export async function listProducts(params: ProductListParams = {}): Promise<PaginatedProducts> {
  const queryParams: Record<string, string | number> = {};

  if (params.q) {
    queryParams.q = params.q;
  }

  if (params.category_id) {
    queryParams.category_id = params.category_id;
  }

  if (params.is_active) {
    queryParams.is_active = params.is_active;
  }

  if (typeof params.page === 'number') {
    queryParams.page = params.page;
  }

  if (params.sort) {
    queryParams.sort = params.sort;
  }

  if (params.per_page) {
    queryParams.per_page = params.per_page;
  }

  const { data } = await api.get<PaginatedProducts>('/bo/products', {
    params: Object.keys(queryParams).length > 0 ? queryParams : undefined,
  });

  return {
    ...data,
    data: data.data.map(normalizeProduct),
  };
}

export async function getProduct(id: string): Promise<Product> {
  try {
    const { data } = await api.get<{ data: Product }>(`/bo/products/${id}`);
    return normalizeProduct(data.data);
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

export type ProductPayload = {
  title: string;
  price: number;
  category_id?: string | null;
  sku?: string | null;
  barcode?: string | null;
  tax_code?: string | null;
  track_stock?: boolean;
  is_active?: boolean;
  image_url?: string | null;
};

export async function createProduct(payload: ProductPayload): Promise<Product> {
  try {
    const { data } = await api.post<{ data: Product }>('/bo/products', payload);
    return data.data;
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

export async function updateProduct(
  id: string,
  payload: Partial<ProductPayload>
): Promise<Product> {
  try {
    const { data } = await api.put<{ data: Product }>(`/bo/products/${id}`, payload);
    return data.data;
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

export async function deleteProduct(id: string): Promise<void> {
  try {
    await api.delete(`/bo/products/${id}`);
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

export async function uploadImage(
  file: File,
  onProgress?: (progress: number) => void
): Promise<string> {
  const formData = new FormData();
  formData.append('file', file);

  try {
    const { data } = await api.post<{ url: string }>(
      '/bo/uploads/images',
      formData,
      {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (event) => {
          if (!onProgress || !event.total) {
            return;
          }

          const percent = Math.round((event.loaded / event.total) * 100);
          onProgress(percent);
        },
      }
    );

    return data.url;
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

export type StockLevel = {
  id?: string;
  tenant_id?: string;
  product_id?: string;
  store_id: string;
  store_name?: string | null;
  store_code?: string | null;
  variant_id?: string | null;
  variant_name?: string | null;
  variant_sku?: string | null;
  qty_on_hand: number;
  updated_at?: string;
};

export type ListStockParams = {
  product_id: string;
  store_id?: string;
  variant_id?: string;
};

export type StockAdjustPayload = {
  product_id: string;
  store_id: string;
  variant_id?: string;
  qty_delta: number;
  reason: 'manual_adjustment' | 'initial_stock' | 'correction' | 'wastage';
  note?: string | null;
};

export async function listStockLevels(params: ListStockParams): Promise<StockLevel[]> {
  try {
    const { data } = await api.get<{ data: StockLevel[] }>('/bo/stocks', { params });
    return (data.data ?? []).map(normalizeStockLevel);
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

export async function adjustStock(payload: StockAdjustPayload): Promise<StockLevel> {
  try {
    const { data } = await api.post<{ data: StockLevel }>('/bo/stocks/adjust', payload);
    return normalizeStockLevel(data.data);
  } catch (error) {
    throw new Error(getErrorMessage(error));
  }
}

function normalizeProduct(product: Product): Product {
  return {
    ...product,
    stock_by_store: Array.isArray(product.stock_by_store)
      ? product.stock_by_store.map((entry) => ({
          ...entry,
          qty_on_hand: Number(entry.qty_on_hand ?? 0),
        }))
      : product.stock_by_store,
    stock_summary: Array.isArray(product.stock_summary)
      ? product.stock_summary.map((entry) => ({
          ...entry,
          qty_on_hand: Number(entry.qty_on_hand ?? 0),
        }))
      : product.stock_summary,
  };
}

function normalizeStockLevel(level: StockLevel): StockLevel {
  return {
    ...level,
    qty_on_hand: Number(level?.qty_on_hand ?? 0),
  };
}
