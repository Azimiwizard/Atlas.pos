import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { useCart } from '../hooks/useCart';
import { useStore } from '../hooks/useStore';
import { useToast } from '../components/ToastProvider';
import { PosHeader } from '../components/pos/PosHeader';
import {
  createOrder,
  addOrderItem,
  checkoutOrder,
  captureOrder,
  type OrderCaptureResponse,
} from '../features/orders/api';
import { attachOrderToShift, getCurrentShift } from '../features/shifts/api';
import { isUuid } from '../lib/uuid';
import { getDesktopApi } from '../lib/desktop';

const formatCurrency = (value: number) => `$${value.toFixed(2)}`;
const formatAvailable = (value: number) =>
  Number.isInteger(value) ? value.toString() : value.toFixed(2);

export default function CartPage() {
  const cart = useCart();
  const navigate = useNavigate();
  const { currentStoreId, currentStore } = useStore();
  const { addToast } = useToast();
  const desktopApi = getDesktopApi();

  const [processing, setProcessing] = useState(false);
  const [loyaltyId, setLoyaltyId] = useState('');
  const [tipAmount, setTipAmount] = useState('0.00');

  const currentShiftQuery = useQuery({
    queryKey: ['shift', 'current'],
    queryFn: getCurrentShift,
    staleTime: 15_000,
  });

  const activeShiftId = currentShiftQuery.data?.shift?.shift.id ?? null;

  const tipValue = useMemo(() => Number.parseFloat(tipAmount) || 0, [tipAmount]);
  const totalWithTip = cart.totals.total + tipValue;

  const updateQuantity = (variantId: string, diff: number) => {
    const line = cart.lines.find((item) => item.variantId === variantId);
    if (!line) {
      return;
    }

    if (diff > 0) {
      const available =
        typeof line.stockOnHand === 'number' ? Math.max(0, line.stockOnHand) : null;
      if (available !== null && line.qty >= available) {
        addToast({
          type: 'error',
          message: `Not enough stock for this item. Only ${formatAvailable(available)} remaining.`,
        });
        return;
      }
      cart.increment(variantId);
    } else {
      cart.decrement(variantId);
    }
  };

  const handleCheckout = async (method: 'cash' | 'card') => {
    if (cart.lines.length === 0 || processing) {
      return;
    }

    if (!currentStoreId) {
      addToast({ type: 'error', message: 'Select a store before checking out.' });
      return;
    }

    setProcessing(true);

    try {
      const order = await createOrder(currentStoreId);

      if (activeShiftId) {
        await attachOrderToShift(order.id, activeShiftId);
      }

      for (const line of cart.lines) {
        await addOrderItem(order.id, line.variantId, line.qty);
      }

      await checkoutOrder(order.id);
      const capture: OrderCaptureResponse = await captureOrder(order.id, method);

      if (loyaltyId.trim().length > 0) {
        addToast({
          type: 'info',
          message: `Loyalty ${loyaltyId.trim()} credited.`,
        });
      }

      if (tipValue > 0) {
        addToast({
          type: 'info',
          message: `Tip of ${formatCurrency(tipValue)} recorded.`,
        });
      }

      cart.clear();
      setTipAmount('0.00');
      setLoyaltyId('');
      addToast({ type: 'success', message: `Payment captured (${method}).` });

      if (isUuid(capture.id)) {
        navigate(`/receipt/${capture.id}`);
      } else {
        addToast({
          type: 'error',
          message:
            'Sale completed but receipt is unavailable. Review the Sales list for details.',
        });
      }
    } catch (error) {
      if (
        desktopApi &&
        (!navigator.onLine ||
          (axios.isAxiosError(error) && (!error.response || error.code === 'ECONNABORTED')))
      ) {
        const orderId = crypto.randomUUID();
        await desktopApi.offline.queueOrder({
          id: orderId,
          createdAt: new Date().toISOString(),
          payload: {
            method,
            storeId: currentStoreId,
            totals: {
              subtotal: cart.totals.subtotal,
              tax: cart.totals.tax,
              discount: cart.discount,
              total: cart.totals.total,
              tip: tipValue,
            },
            lines: cart.lines.map((line) => ({
              ...line,
              categories: line.categories.map((category) => ({ ...category })),
              taxes: line.taxes.map((tax) => ({ ...tax })),
            })),
            loyaltyId: loyaltyId.trim() || null,
          },
          retries: 0,
          lastTriedAt: null,
        });
        cart.clear();
        setTipAmount('0.00');
        setLoyaltyId('');
        addToast({
          type: 'info',
          message: 'Network unavailable. Order queued and will sync when online.',
        });
        navigate('/pos');
        return;
      }

      let message = 'Checkout failed.';

      if (axios.isAxiosError(error)) {
        const response = error.response;

        if (response?.status === 422) {
          const data: any = response.data ?? {};
          const errors = (data.errors ?? {}) as Record<string, unknown>;
          const qtyError =
            (Array.isArray((errors as any).qty) && (errors as any).qty[0]) ||
            (Array.isArray((errors as any).quantity) && (errors as any).quantity[0]);

          message =
            (typeof qtyError === 'string' && qtyError.trim() !== '' ? qtyError : null) ??
            (typeof data.message === 'string' && data.message.trim() !== ''
              ? data.message
              : null) ??
            'Not enough stock for this item.';
        } else if (
          response?.data &&
          typeof response.data === 'object' &&
          typeof (response.data as any).message === 'string'
        ) {
          message = (response.data as any).message;
        } else if (typeof error.message === 'string' && error.message.trim() !== '') {
          message = error.message;
        }
      } else if (error instanceof Error && error.message.trim() !== '') {
        message = error.message;
      }

      addToast({ type: 'error', message });
    } finally {
      setProcessing(false);
    }
  };

  const itemCount = cart.lines.reduce((sum, line) => sum + line.qty, 0);

  return (
    <div className="pb-24">
      <PosHeader
        title="Cart"
        subtitle={
          cart.lines.length > 0
            ? `${itemCount} item${itemCount === 1 ? '' : 's'} ready for checkout`
            : 'Review order and finalize payment'
        }
        endAdornment={
          currentStore ? (
            <span className="rounded-full border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] px-4 py-2 text-xs font-semibold text-[color:var(--pos-text-muted)]">
              {currentStore.name}
            </span>
          ) : null
        }
      />

      {cart.lines.length === 0 ? (
        <div className="mt-12 rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] p-10 text-center text-sm text-[color:var(--pos-text-muted)] shadow-sm">
          Your cart is empty. Add items from the menu to begin an order.
          <div className="mt-6">
            <button
              type="button"
              onClick={() => navigate('/pos/sell')}
              className="rounded-full bg-[color:var(--pos-accent)] px-5 py-3 text-sm font-semibold text-[color:var(--pos-accent-contrast)] shadow"
            >
              Browse menu
            </button>
          </div>
        </div>
      ) : (
        <div className="mt-6 grid gap-6 lg:grid-cols-[2fr,1fr]">
          <div className="space-y-4">
            {cart.lines.map((line) => (
              <div
                key={line.variantId}
                className="rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] p-5 shadow-sm"
              >
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <h3 className="text-base font-semibold text-[color:var(--pos-text)]">
                      {line.title}
                    </h3>
                    {line.sku ? (
                      <p className="text-xs text-[color:var(--pos-text-muted)]">SKU: {line.sku}</p>
                    ) : null}
                    {line.stockOnHand !== undefined ? (
                      <p className="mt-1 text-xs text-[color:var(--pos-text-muted)]">
                        {line.stockOnHand === null
                          ? 'Stock unavailable'
                          : `${Math.max(0, Number(line.stockOnHand)).toFixed(0)} available`}
                      </p>
                    ) : null}
                  </div>
                  <button
                    type="button"
                    onClick={() => cart.remove(line.variantId)}
                    className="text-xs font-semibold text-[color:var(--pos-text-muted)] hover:text-[color:var(--pos-text)]"
                  >
                    Remove
                  </button>
                </div>
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                  <div className="flex items-center rounded-full bg-[color:var(--pos-surface-muted)] px-2 py-1">
                    <button
                      type="button"
                      onClick={() => updateQuantity(line.variantId, -1)}
                      className="flex h-9 w-9 items-center justify-center text-lg text-[color:var(--pos-text)]"
                    >
                      -
                    </button>
                    <span className="w-12 text-center text-sm font-semibold text-[color:var(--pos-text)]">
                      {line.qty}
                    </span>
                    <button
                      type="button"
                      onClick={() => updateQuantity(line.variantId, 1)}
                      className="flex h-9 w-9 items-center justify-center text-lg text-[color:var(--pos-text)]"
                    >
                      +
                    </button>
                  </div>
                  <div className="text-right text-sm font-semibold text-[color:var(--pos-text)]">
                    {formatCurrency(line.price * line.qty)}
                  </div>
                </div>
              </div>
            ))}
          </div>

          <aside className="flex flex-col gap-4 rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] p-6 shadow-sm">
            <div className="flex items-center justify-between text-sm font-medium text-[color:var(--pos-text-muted)]">
              <span>Items</span>
              <span>{itemCount}</span>
            </div>
            <div className="flex items-center justify-between text-sm font-medium">
              <span>Subtotal</span>
              <span>{formatCurrency(cart.totals.subtotal)}</span>
            </div>
            <label className="flex flex-col gap-2 text-xs font-medium text-[color:var(--pos-text-muted)]">
              Discount
              <input
                type="number"
                min={0}
                step="0.01"
                value={cart.discount}
                onChange={(event) => cart.setDiscount(Number.parseFloat(event.target.value) || 0)}
                className="rounded-2xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface-muted)] px-3 py-2 text-sm text-[color:var(--pos-text)] outline-none ring-[color:var(--pos-accent)] focus-visible:ring-2"
              />
            </label>
            <div className="flex items-center justify-between text-sm font-medium">
              <span>Tax</span>
              <span>{formatCurrency(cart.totals.tax)}</span>
            </div>
            <label className="flex flex-col gap-2 text-xs font-medium text-[color:var(--pos-text-muted)]">
              Tip
              <input
                type="number"
                min={0}
                step="0.01"
                value={tipAmount}
                onChange={(event) => setTipAmount(event.target.value)}
                className="rounded-2xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface-muted)] px-3 py-2 text-sm text-[color:var(--pos-text)] outline-none ring-[color:var(--pos-accent)] focus-visible:ring-2"
              />
            </label>
            <label className="flex flex-col gap-2 text-xs font-medium text-[color:var(--pos-text-muted)]">
              Loyalty ID (optional)
              <input
                value={loyaltyId}
                onChange={(event) => setLoyaltyId(event.target.value)}
                placeholder="Scan or enter loyalty number"
                className="rounded-2xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface-muted)] px-3 py-2 text-sm text-[color:var(--pos-text)] outline-none ring-[color:var(--pos-accent)] focus-visible:ring-2"
              />
            </label>
            <div className="flex items-center justify-between text-base font-semibold">
              <span>Total</span>
              <span>{formatCurrency(totalWithTip)}</span>
            </div>
            <div className="mt-4 space-y-3">
              <button
                type="button"
                onClick={() => handleCheckout('cash')}
                disabled={processing}
                className="w-full rounded-full bg-[color:var(--pos-accent)] px-4 py-3 text-sm font-semibold text-[color:var(--pos-accent-contrast)] shadow transition hover:-translate-y-[1px] disabled:cursor-not-allowed disabled:opacity-70"
              >
                {processing ? 'Processing...' : 'Pay with cash'}
              </button>
              <button
                type="button"
                onClick={() => handleCheckout('card')}
                disabled={processing}
                className="w-full rounded-full border border-[color:var(--pos-accent)] px-4 py-3 text-sm font-semibold text-[color:var(--pos-accent)] transition hover:bg-[color:var(--pos-accent)] hover:text-[color:var(--pos-accent-contrast)] disabled:cursor-not-allowed disabled:opacity-70"
              >
                {processing ? 'Processing...' : 'Pay with card'}
              </button>
              <button
                type="button"
                onClick={() => cart.clear()}
                className="w-full rounded-full border border-[color:var(--pos-border)] px-4 py-3 text-sm font-medium text-[color:var(--pos-text-muted)] hover:text-[color:var(--pos-text)]"
              >
                Clear cart
              </button>
            </div>
          </aside>
        </div>
      )}
    </div>
  );
}
