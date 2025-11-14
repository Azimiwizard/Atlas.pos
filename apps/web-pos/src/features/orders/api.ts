import { api } from '../../lib/api';

export type OrderVariant = {
  id: string;
  sku: string | null;
  name: string | null;
  price: number;
};

export type OrderItem = {
  id: string;
  variant: OrderVariant | null;
  qty: number;
  unit_price: number;
  line_total: number;
};

export type OrderPayment = {
  id: string;
  method: string;
  amount: number;
  status: string;
  captured_at: string | null;
};

export type Order = {
  id: string;
  status: string;
  subtotal: number;
  tax: number;
  discount: number;
  manual_discount: number;
  total: number;
  store_id: string | null;
  cashier_id: string | null;
  created_at: string;
  updated_at: string;
  items: OrderItem[];
  payments: OrderPayment[];
  tax_breakdown: Array<{
    id?: string;
    name: string;
    inclusive: boolean;
    amount: number;
  }>;
  promotion_breakdown: string[];
  promotion_discount: number;
  exclusive_tax_total?: number;
  inclusive_tax_total?: number;
};

export type OrderSummary = {
  id: string;
  status: string;
  total: number;
  subtotal: number;
  discount: number;
  tax: number;
  created_at: string;
  store_id?: string | null;
};

export type PaginatedOrders = {
  data: OrderSummary[];
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

function toNumber(value: unknown, precision = 2): number {
  if (typeof value === 'number') {
    return value;
  }

  if (typeof value === 'string') {
    const parsed = parseFloat(value);
    return Number.isNaN(parsed) ? 0 : parseFloat(parsed.toFixed(precision));
  }

  return 0;
}

function mapVariant(variant: any): OrderVariant | null {
  if (!variant) {
    return null;
  }

  return {
    id: variant.id,
    sku: variant.sku ?? null,
    name: variant.name ?? null,
    price: toNumber(variant.price),
  };
}

function mapOrderItem(item: any): OrderItem {
  return {
    id: item.id,
    variant: mapVariant(item.variant ?? null),
    qty: toNumber(item.qty, 3),
    unit_price: toNumber(item.unit_price),
    line_total: toNumber(item.line_total),
  };
}

function mapPayment(payment: any): OrderPayment {
  return {
    id: payment.id,
    method: payment.method,
    amount: toNumber(payment.amount),
    status: payment.status,
    captured_at: payment.captured_at ?? null,
  };
}

function mapOrder(order: any): Order {
  if (!order || typeof order !== 'object') {
    throw new Error('Invalid order payload received from server.');
  }

  const rawId =
    ('id' in order ? order.id : null) ??
    ('order_id' in order ? order.order_id : null) ??
    ('uuid' in order ? order.uuid : null);

  const rawIdString = rawId === null || rawId === undefined ? '' : String(rawId);
  const id = rawIdString.trim();

  if (!id || id.toLowerCase() === 'undefined' || id.toLowerCase() === 'null') {
    throw new Error('Order payload is missing an identifier.');
  }

  return {
    id,
    status: order.status,
    subtotal: toNumber(order.subtotal),
    tax: toNumber(order.tax),
    discount: toNumber(order.discount),
    manual_discount: toNumber(order.manual_discount),
    total: toNumber(order.total),
    store_id: order.store_id ?? null,
    cashier_id: order.cashier_id ?? null,
    created_at: order.created_at,
    updated_at: order.updated_at,
    items: Array.isArray(order.items) ? order.items.map(mapOrderItem) : [],
    payments: Array.isArray(order.payments) ? order.payments.map(mapPayment) : [],
    tax_breakdown: Array.isArray(order.tax_breakdown)
      ? order.tax_breakdown.map((tax: any) => ({
          id: tax.id ?? null,
          name: tax.name ?? 'Tax',
          inclusive: Boolean(tax.inclusive),
          amount: toNumber(tax.amount),
        }))
      : [],
    promotion_breakdown: Array.isArray(order.promotion_breakdown)
      ? order.promotion_breakdown
      : [],
    promotion_discount: toNumber(order.promotion_discount),
    exclusive_tax_total: toNumber(order.exclusive_tax_total ?? 0),
    inclusive_tax_total: toNumber(order.inclusive_tax_total ?? 0),
  };
}

export async function createOrder(storeId?: string | null): Promise<Order> {
  const { data } = await api.post('/orders', storeId ? { store_id: storeId } : {});
  return mapOrder(data);
}

export async function addOrderItem(orderId: string, variantId: string, qty: number): Promise<Order> {
  const { data } = await api.post(`/orders/${orderId}/items`, {
    variant_id: variantId,
    qty,
  });
  return mapOrder(data);
}

export async function applyOrderDiscount(orderId: string, amount: number): Promise<Order> {
  const { data } = await api.post(`/orders/${orderId}/discount`, { amount });
  return mapOrder(data);
}

export async function checkoutOrder(orderId: string): Promise<Order> {
  const { data } = await api.post(`/orders/${orderId}/checkout`);
  return mapOrder(data);
}

function mapCaptureResponse(payload: any): OrderCaptureResponse {
  if (!payload || typeof payload !== 'object') {
    throw new Error('Invalid capture response.');
  }

  const rawId = payload.id ?? payload.order_id ?? payload.uuid ?? '';
  const id = String(rawId).trim();

  if (!id || id.toLowerCase() === 'undefined' || id.toLowerCase() === 'null') {
    throw new Error('Capture response is missing an order identifier.');
  }

  return {
    id,
    number:
      typeof payload.number === 'string' && payload.number.trim() !== ''
        ? payload.number.trim()
        : null,
    subtotal: toNumber(payload.subtotal),
    tax: toNumber(payload.tax),
    discount: toNumber(payload.discount),
    total: toNumber(payload.total),
    created_at:
      typeof payload.created_at === 'string' && payload.created_at.trim() !== ''
        ? payload.created_at
        : new Date().toISOString(),
  };
}

export async function captureOrder(
  orderId: string,
  method: 'cash' | 'card'
): Promise<OrderCaptureResponse> {
  const { data } = await api.post(`/orders/${orderId}/capture`, { method });
  return mapCaptureResponse(data);
}

export async function getOrder(orderId: string): Promise<Order> {
  const { data } = await api.get(`/orders/${orderId}`);
  return mapOrder(data);
}

export async function listOrders(filters: {
  status?: 'draft' | 'paid' | 'refunded';
  date?: string;
  page?: number;
  storeId?: string | null;
} = {}): Promise<PaginatedOrders> {
  const params: Record<string, string | number> = {};

  if (filters.status) {
    params.status = filters.status;
  }

  if (filters.date) {
    params.date = filters.date;
  }

  if (filters.page) {
    params.page = filters.page;
  }

  if (filters.storeId) {
    params.store_id = filters.storeId;
  }

  const { data } = await api.get<PaginatedOrders>('/orders', { params });

  return {
    data: (data.data ?? []).map((order: any) => ({
      id: order.id,
      status: order.status,
      total: toNumber(order.total),
      subtotal: toNumber(order.subtotal),
      discount: toNumber(order.discount),
      tax: toNumber(order.tax),
      created_at: order.created_at,
      store_id: order.store_id ?? null,
    })),
    meta: data.meta,
  };
}
export type OrderCaptureResponse = {
  id: string;
  number: string | null;
  subtotal: number;
  tax: number;
  discount: number;
  total: number;
  created_at: string;
};
