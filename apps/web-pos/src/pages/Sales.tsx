import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Button, Card } from '@atlas-pos/ui';
import { listOrders, type OrderSummary, type PaginatedOrders } from '../features/orders/api';
import { useStore } from '../hooks/useStore';
import { useToast } from '../components/ToastProvider';
import { useAuth } from '../hooks/useAuth';
import { getErrorMessage } from '../lib/api';

const STATUS_OPTIONS: Array<{ label: string; value: '' | 'paid' | 'refunded' | 'draft' }> = [
  { label: 'All', value: '' },
  { label: 'Paid', value: 'paid' },
  { label: 'Refunded', value: 'refunded' },
  { label: 'Draft', value: 'draft' },
];

function formatCurrency(value: number): string {
  return `$${value.toFixed(2)}`;
}

export function SalesPage() {
  const navigate = useNavigate();
  const { addToast } = useToast();
  const { user } = useAuth();
  const {
    currentStore,
    currentStoreId,
    stores,
    setCurrentStoreId,
    loading: storesLoading,
  } = useStore();
  const storeId = currentStore?.id ?? currentStoreId ?? null;
  const [status, setStatus] = useState<'' | 'paid' | 'refunded' | 'draft'>('');
  const [date, setDate] = useState<string>(new Date().toISOString().slice(0, 10));
  const [page, setPage] = useState(1);

  useEffect(() => {
    if (storeId) {
      setPage(1);
    }
  }, [storeId]);

  const ordersQuery = useQuery<PaginatedOrders, Error>({
    queryKey: ['orders', storeId ?? 'none', status, date, page],
    queryFn: () =>
      listOrders({
        status: status || undefined,
        date: date || undefined,
        page,
        storeId: storeId ?? undefined,
      }),
    placeholderData: keepPreviousData,
    enabled: Boolean(storeId),
  });

  const orders: OrderSummary[] = ordersQuery.data?.data ?? [];
  const meta: PaginatedOrders['meta'] = ordersQuery.data?.meta;

  // Handle errors
  useEffect(() => {
    if (ordersQuery.error) {
      addToast({ type: 'error', message: getErrorMessage(ordersQuery.error) });
    }
  }, [ordersQuery.error, addToast]);

  const isLoading = ordersQuery.isLoading && !ordersQuery.data;

  const canGoPrev = (meta?.current_page ?? 1) > 1;
  const canGoNext = meta ? meta.current_page < meta.last_page : false;

  const handleStatusChange = (value: string) => {
    setStatus(value as '' | 'paid' | 'refunded' | 'draft');
    setPage(1);
  };

  const handleDateChange = (value: string) => {
    setDate(value);
    setPage(1);
  };

  const setToday = () => {
    const todayValue = new Date().toISOString().slice(0, 10);
    setDate(todayValue);
    setPage(1);
  };

  const clearDate = () => {
    setDate('');
    setPage(1);
  };

  return (
    <div className="min-h-screen bg-slate-50 pb-16">
      <div className="mx-auto flex max-w-5xl flex-col gap-6 px-4 pt-6">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-blue-600">Atlas POS</p>
            <h1 className="text-3xl font-bold text-slate-900">Sales</h1>
            <p className="text-sm text-slate-500">Review recent orders and print receipts.</p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {storeId ? (
              user && (user.role === 'admin' || user.role === 'manager') && stores.length > 1 ? (
                <div className="flex items-center gap-2">
                  <label className="text-xs font-semibold uppercase text-slate-500">Store</label>
                  <select
                    value={currentStoreId ?? ''}
                    onChange={(event) => setCurrentStoreId(event.target.value)}
                    className="rounded-md border border-slate-300 px-2 py-1 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-300/50"
                  >
                    {stores.map((store) => (
                      <option key={store.id} value={store.id}>
                        {store.name}
                      </option>
                    ))}
                  </select>
                </div>
              ) : (
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  {currentStore?.name ?? 'Store'}
                </div>
              )
            ) : (
              <div className="text-xs text-slate-500">
                {storesLoading ? 'Loading stores…' : 'Select a store'}
              </div>
            )}
            <Button variant="outline" onClick={() => navigate('/pos')}>
              Back to Sell
            </Button>
          </div>
        </div>

        <Card heading="Filters" description="Refine results by status or date.">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div className="flex flex-col gap-2">
              <label className="text-xs font-semibold uppercase text-slate-500">Status</label>
              <select
                value={status}
                onChange={(event) => handleStatusChange(event.target.value)}
                className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                disabled={!storeId}
              >
                {STATUS_OPTIONS.map((option) => (
                  <option key={option.value || 'all'} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </div>

            <div className="flex flex-col gap-2">
              <label className="text-xs font-semibold uppercase text-slate-500">Date</label>
              <div className="flex gap-2">
                <input
                  type="date"
                  value={date}
                  onChange={(event) => handleDateChange(event.target.value)}
                  className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                  disabled={!storeId}
                />
                <Button variant="outline" onClick={setToday} disabled={!storeId}>
                  Today
                </Button>
                <Button variant="outline" onClick={clearDate} disabled={!storeId}>
                  Clear
                </Button>
              </div>
            </div>
          </div>
        </Card>

        <Card heading="Recent Orders" description="Click a row to view the receipt.">
          {storeId === null ? (
            <div className="rounded-lg border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-500">
              {storesLoading ? 'Loading stores...' : 'Select a store to view orders.'}
            </div>
          ) : isLoading ? (
            <div className="flex items-center justify-center py-16 text-sm text-slate-500">
              <div className="flex flex-col items-center gap-3">
                <div className="h-10 w-10 animate-spin rounded-full border-4 border-blue-500 border-t-transparent" />
                <span>Loading orders...</span>
              </div>
            </div>
          ) : orders.length === 0 ? (
            <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">
              No orders match the selected filters.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm text-slate-700">
                <thead className="text-xs uppercase text-slate-400">
                  <tr>
                    <th className="px-3 py-2">Order</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2">Created</th>
                    <th className="px-3 py-2 text-right">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {orders.map((order) => {
                    const createdAt = new Date(order.created_at);
                    return (
                      <tr
                        key={order.id}
                        className="cursor-pointer border-t border-slate-200 transition hover:bg-slate-50"
                        onClick={() => navigate(`/receipt/${order.id}`)}
                      >
                        <td className="px-3 py-2 font-medium text-slate-900">
                          #{order.id.slice(0, 8).toUpperCase()}
                        </td>
                        <td className="px-3 py-2 capitalize text-slate-600">{order.status}</td>
                        <td className="px-3 py-2 text-slate-500">
                          {createdAt.toLocaleDateString()} at{' '}
                          {createdAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                        </td>
                        <td className="px-3 py-2 text-right font-semibold text-slate-900">
                          {formatCurrency(order.total)}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          <div className="mt-4 flex items-center justify-between text-sm text-slate-500">
            <span>
              Page {meta?.current_page ?? 1} of {meta?.last_page ?? 1}
            </span>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setPage((prev) => Math.max(prev - 1, 1))}
                disabled={!canGoPrev}
              >
                Previous
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setPage((prev) => prev + 1)}
                disabled={!canGoNext}
              >
                Next
              </Button>
            </div>
          </div>
        </Card>
      </div>
    </div>
  );
}

