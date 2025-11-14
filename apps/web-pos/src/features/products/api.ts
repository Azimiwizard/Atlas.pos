import { api, API_ROOT_URL } from '../../lib/api';
import type { Category } from '../catalog/api';

export type ProductListVariant = {
  id: string;
  name: string | null;
  sku: string | null;
  price: number;
  stock_on_hand?: number | null;
};

export type ProductListItem = {
  id: string;
  title: string;
  barcode: string | null;
  image_url?: string | null;
  categories: Category[];
  variants: ProductListVariant[];
};

type RawVariant = {
  id: string;
  name: string | null;
  sku: string | null;
  price: string | number;
  stock_on_hand?: number | string | null;
};

type RawProduct = {
  id: string;
  title: string;
  barcode: string | null;
  image_url?: string | null;
  categories?: Category[];
  variants?: RawVariant[];
};

type ProductsResponse = {
  data?: RawProduct[];
};

function resolveImageUrl(url: string | null | undefined): string | null {
  if (!url) {
    return null;
  }

  if (/^https?:\/\//i.test(url)) {
    return url;
  }

  try {
    return new URL(url, API_ROOT_URL).toString();
  } catch (error) {
    if (typeof import.meta !== 'undefined' && import.meta.env?.DEV) {
      console.warn('Unable to resolve product image URL', url, error);
    }
    return url;
  }
}

function toNumber(value: unknown): number {
  if (typeof value === 'number') {
    return value;
  }
  if (typeof value === 'string') {
    const parsed = Number.parseFloat(value);
    return Number.isNaN(parsed) ? 0 : parsed;
  }
  return 0;
}

function mapVariant(variant: RawVariant): ProductListVariant {
  return {
    id: variant.id,
    name: variant.name ?? null,
    sku: variant.sku ?? null,
    price: toNumber(variant.price),
    stock_on_hand:
      variant.stock_on_hand === undefined || variant.stock_on_hand === null
        ? undefined
        : toNumber(variant.stock_on_hand),
  };
}

function mapProduct(product: RawProduct): ProductListItem {
  return {
    id: product.id,
    title: product.title,
    barcode: product.barcode,
    image_url: resolveImageUrl(product.image_url),
    categories: product.categories ?? [],
    variants: Array.isArray(product.variants)
      ? product.variants.map(mapVariant)
      : [],
  };
}

export async function listProducts(options: {
  search?: string;
  categoryId?: string | null;
  page?: number;
} = {}): Promise<ProductListItem[]> {
  const params: Record<string, string | number> = {};

  if (options.search) {
    params.search = options.search;
  }

  if (options.categoryId) {
    params.category_id = options.categoryId;
  }

  if (options.page) {
    params.page = options.page;
  }

  const { data } = await api.get<ProductsResponse | RawProduct[]>('/products', {
    params: Object.keys(params).length > 0 ? params : undefined,
  });

  const products = Array.isArray(data)
    ? data
    : Array.isArray(data?.data)
      ? data.data
      : [];

  return products.map(mapProduct);
}
