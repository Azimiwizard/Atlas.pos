import { useCallback, useEffect, useMemo, useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router-dom';
import { api, getErrorMessage } from '../lib/api';
import { useToast } from '../components/ToastProvider';
import { useAuth } from '../hooks/useAuth';
import { getDesktopApi } from '../lib/desktop';

type ReceiptItem = {
  id: string;
  variant_id?: string | null;
  title: string;
  sku: string | null;
  qty: number;
  unit_price: number;
  line_total: number;
};

type ReceiptPayment = {
  method: string;
  amount: number;
  status: string;
  captured_at: string | null;
};

type ReceiptStore = {
  id: string;
  name: string;
  code: string | null;
} | null;

type ReceiptCashier = {
  id: string;
  name: string;
} | null;

type ReceiptCustomer = {
  id: string;
  name: string;
  loyalty_points: number | null;
} | null;

type ReceiptPayload = {
  id: string;
  number: string | null;
  store: ReceiptStore;
  cashier: ReceiptCashier;
  created_at: string;
  items: ReceiptItem[];
  subtotal: number;
  discount: number;
  tax: number;
  total: number;
  payments: ReceiptPayment[];
  customer: ReceiptCustomer;
  refunded_total?: number;
  net_total?: number;
};

const styles = `
@media print {
  @page { size: 80mm auto; margin: 0; }
}
.receipt { width: 80mm; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.center { text-align: center; }
.right { text-align: right; }
.row { display: flex; justify-content: space-between; }
.barcode { margin-top: 8px; }
.fine { font-size: 11px; color: #555; }
hr { border: none; border-top: 1px dashed #333; margin: 8px 0; }
button { margin-right: 8px; }
`;

export default function ReceiptPage() {
  const { id } = useParams<{ id: string }>();

  if (!id) {
    return <Navigate to="/pos" replace />;
  }

  const navigate = useNavigate();
  const { addToast } = useToast();
  const { user } = useAuth();
  const desktopApi = getDesktopApi();

  const [receipt, setReceipt] = useState<ReceiptPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [showRefund, setShowRefund] = useState(false);
  const [refundQty, setRefundQty] = useState<Record<string, number>>({});
  const [reason, setReason] = useState('');

  const canRefund = user?.role === 'admin' || user?.role === 'manager';

  useEffect(() => {
    let active = true;

    const load = async () => {
      try {
        const response = await api.get<ReceiptPayload>(`/orders/${id}/receipt`);
        if (!active) {
          return;
        }
        setReceipt(response.data);
        setLoadError(null);
      } catch (error: unknown) {
        if (!active) {
          return;
        }
        const message = getErrorMessage(error);
        const status = (error as { response?: { status?: number } })?.response?.status;

        if (status === 404) {
          addToast({ type: 'error', message: 'Receipt not found.' });
          navigate('/pos', { replace: true });
          return;
        }

        setLoadError(message);
        addToast({ type: 'error', message });
      } finally {
        if (active) {
          setLoading(false);
        }
      }
    };

    void load();

    return () => {
      active = false;
    };
  }, [id, addToast, navigate]);

  const totals = useMemo(() => {
    if (!receipt) {
      return null;
    }
    const refunded = receipt.refunded_total ?? 0;
    const net = receipt.net_total ?? Math.max(receipt.total - refunded, 0);
    return {
      subtotal: receipt.subtotal,
      discount: receipt.discount,
      tax: receipt.tax,
      total: receipt.total,
      refunded,
      net,
    };
  }, [receipt]);

  const printPayload = useMemo(() => {
    if (!receipt) {
      return null;
    }

    const footerLines: string[] = [];
    if (receipt.payments.length > 0) {
      footerLines.push(
        ...receipt.payments.map(
          (payment) =>
            `${payment.method.toUpperCase()} ${payment.status.toLowerCase()}: ${payment.amount.toFixed(
              2
            )}`
        )
      );
    }
    footerLines.push('Powered by Atlas POS');

    return {
      items: receipt.items.map((item) => ({
        name: item.title,
        qty: item.qty,
        unitPrice: item.unit_price,
        total: item.line_total,
      })),
      totals: {
        subtotal: receipt.subtotal,
        discount: receipt.discount,
        tax: receipt.tax,
        total: receipt.total,
        refunded: receipt.refunded_total ?? 0,
        net: receipt.net_total ?? undefined,
      },
      metadata: {
        orderId: receipt.id,
        orderNumber: receipt.number,
        cashier: receipt.cashier?.name ?? null,
        storeName: receipt.store?.name ?? null,
        storeCode: receipt.store?.code ?? null,
        printedAt: new Date().toISOString(),
      },
      footer: footerLines,
    };
  }, [receipt]);

  const doPrint = useCallback(async () => {
    if (!receipt) {
      return;
    }

    if (desktopApi && printPayload) {
      try {
        await desktopApi.printer.printReceipt(printPayload);
        addToast({ type: 'success', message: 'Receipt sent to printer.' });
      } catch (error: unknown) {
        console.error(error);
        addToast({
          type: 'error',
          message: 'Failed to print receipt. Please try again.',
        });
      }
      return;
    }

    window.print();
  }, [receipt, desktopApi, printPayload, addToast]);

  const submitRefund = async () => {
    if (!receipt) {
      return;
    }

    try {
      await api.post(`/orders/${receipt.id}/refund`, {
        item_ids: refundQty,
        reason,
      });
      const refreshed = await api.get<ReceiptPayload>(`/orders/${receipt.id}/receipt`);
      setReceipt(refreshed.data);
      setRefundQty({});
      setShowRefund(false);
      addToast({ type: 'success', message: 'Refund processed.' });
    } catch (error: unknown) {
      addToast({ type: 'error', message: getErrorMessage(error) });
    }
  };

  if (loading) {
    return <div className="p-6 text-sm text-slate-500">Loading receipt…</div>;
  }

  if (loadError) {
    return (
      <div className="p-6 text-sm text-red-600">
        {loadError}
        <div className="mt-3">
          <button onClick={() => navigate('/pos')} className="rounded border px-3 py-1">
            Back to POS
          </button>
        </div>
      </div>
    );
  }

  if (!receipt || !totals) {
    return (
      <div className="p-6 text-sm text-slate-600">
        Receipt data is not available. Please review the Sales list for recent orders.
        <div className="mt-3">
          <button onClick={() => navigate('/pos')} className="rounded border px-3 py-1">
            Back to POS
          </button>
        </div>
      </div>
    );
  }

  return (
    <div>
      <style>{styles}</style>
      <div className="receipt">
        <div className="center">
          <div>
            <strong>{receipt.store?.name ?? 'Atlas.POS Store'}</strong>
          </div>
          <div className="fine">Order #{(receipt.number ?? receipt.id).toString()}</div>
          <div className="fine">{new Date(receipt.created_at).toLocaleString()}</div>
          <div className="fine">Cashier: {receipt.cashier?.name ?? '—'}</div>
        </div>
        <hr />
        <div>
          {receipt.items.map((item) => (
            <div key={item.id}>
              <div className="row">
                <span>
                  {item.title}
                  {item.sku ? ` (${item.sku})` : ''}
                </span>
                <span className="right">
                  {item.qty} x {item.unit_price.toFixed(2)}
                </span>
              </div>
            </div>
          ))}
        </div>
        <hr />
        <div className="row">
          <span>Subtotal</span>
          <span className="right">{totals.subtotal.toFixed(2)}</span>
        </div>
        <div className="row">
          <span>Discount</span>
          <span className="right">-{totals.discount.toFixed(2)}</span>
        </div>
        <div className="row">
          <span>Tax</span>
          <span className="right">{totals.tax.toFixed(2)}</span>
        </div>
        <div className="row">
          <strong>Total</strong>
          <strong className="right">{totals.total.toFixed(2)}</strong>
        </div>
        {totals.refunded > 0 && (
          <>
            <div className="row">
              <span>Refunded</span>
              <span className="right">-{totals.refunded.toFixed(2)}</span>
            </div>
            <div className="row">
              <strong>Net Total</strong>
              <strong className="right">{totals.net.toFixed(2)}</strong>
            </div>
          </>
        )}
        <hr />
        {receipt.customer ? (
          <div className="center fine">
            Customer: {receipt.customer.name}{' '}
            {typeof receipt.customer.loyalty_points === 'number'
              ? `(Points: ${receipt.customer.loyalty_points})`
              : ''}
          </div>
        ) : (
          <div className="center fine">Thank you for shopping!</div>
        )}
        <div className="center barcode">
          <img
            alt="order-qr"
            src={`https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=${encodeURIComponent(
              receipt.id
            )}`}
          />
        </div>
      </div>

      <div style={{ marginTop: 12 }}>
        <button onClick={() => navigate('/pos')} className="rounded border px-3 py-1">
          New Sale
        </button>
        <button
          onClick={doPrint}
          disabled={loading || !receipt}
          className="rounded border px-3 py-1 disabled:cursor-not-allowed disabled:opacity-60"
        >
          Print Receipt
        </button>
        {canRefund && (
          <button onClick={() => setShowRefund(true)} className="rounded border px-3 py-1">
            Refund
          </button>
        )}
      </div>

      {showRefund && (
        <div
          style={{ border: '1px solid #ccc', padding: 12, marginTop: 12, maxWidth: 500 }}
          role="dialog"
          aria-modal="true"
        >
          <h3>Refund Items</h3>
          <div>
            {receipt.items.map((item) => {
              const key = item.variant_id ?? item.id;
              return (
                <div
                  key={item.id}
                  className="row"
                  style={{ alignItems: 'center', gap: 8, marginBottom: 6 }}
                >
                  <span style={{ flex: 1 }}>{item.title}</span>
                  <input
                    type="number"
                    min={0}
                    max={item.qty}
                    step={1}
                    style={{ width: 80 }}
                    value={refundQty[key] ?? 0}
                    onChange={(event) =>
                      setRefundQty({
                        ...refundQty,
                        [key]: Number(event.target.value),
                      })
                    }
                  />
                </div>
              );
            })}
          </div>
          <div style={{ marginTop: 8 }}>
            <input
              placeholder="Reason (optional)"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              className="w-full rounded border px-2 py-1"
            />
          </div>
          <div style={{ marginTop: 8 }}>
            <button onClick={submitRefund} className="rounded border px-3 py-1">
              Submit Refund
            </button>
            <button
              onClick={() => setShowRefund(false)}
              className="rounded border px-3 py-1"
            >
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
