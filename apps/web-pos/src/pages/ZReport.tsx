import { useMemo } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Button, Card } from '@atlas-pos/ui';
import { getShiftReport, type ShiftSummary } from '../features/shifts/api';
import { useStore } from '../hooks/useStore';
import { getErrorMessage } from '../lib/api';

const formatCurrency = (value: number | null | undefined) =>
  typeof value === 'number' ? `$${value.toFixed(2)}` : '—';

export function ZReportPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { currentStore, currentStoreId } = useStore();
  const storeId = currentStore?.id ?? currentStoreId ?? null;

  const reportQuery = useQuery({
    queryKey: ['shift-report', id, storeId ?? 'none'],
    queryFn: () => {
      if (!id) {
        throw new Error('Missing shift id.');
      }
      return getShiftReport(id);
    },
    enabled: Boolean(id && storeId),
  });

  const report: ShiftSummary | undefined = reportQuery.data;

  const statusText = useMemo(() => {
    if (!report?.shift.closed_at) {
      return 'Open';
    }
    return 'Closed';
  }, [report]);

  return (
    <div className="min-h-screen bg-slate-50 pb-16">
      <style>{`
        @media print {
          .print-hidden { display: none !important; }
          body { background: #fff; }
        }
      `}</style>

      <div className="mx-auto max-w-4xl px-4 pt-8">
        <div className="mb-6 flex items-center justify-between print-hidden">
          <Button variant="outline" onClick={() => navigate('/pos')}>
            Back to POS
          </Button>
          <Button onClick={() => window.print()}>Print Z-Report</Button>
        </div>

        <Card heading="Z-Report" description="Summary of the completed register shift.">
          {reportQuery.isLoading ? (
            <div className="flex items-center gap-3 text-sm text-slate-500">
              <div className="h-3 w-24 animate-pulse rounded-full bg-slate-200" />
              <div className="h-3 w-32 animate-pulse rounded-full bg-slate-200" />
            </div>
          ) : reportQuery.isError ? (
            <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
              {getErrorMessage(reportQuery.error)}
            </div>
          ) : !report ? (
            <div className="rounded-md border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-700">
              Shift report not found.
            </div>
          ) : (
            <div className="space-y-6 text-sm text-slate-600">
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                  <h3 className="text-xs font-semibold uppercase text-slate-500">Register</h3>
                  <p className="mt-1 text-sm font-semibold text-slate-900">
                    {report.shift.register.name}
                  </p>
                  <p>Status: {statusText}</p>
                  <p>Store: {report.shift.store?.name ?? currentStore?.name ?? '—'}</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                  <h3 className="text-xs font-semibold uppercase text-slate-500">Cashier</h3>
                  <p className="mt-1 text-sm font-semibold text-slate-900">
                    {report.shift.user.name}
                  </p>
                  <p>{report.shift.user.email}</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                  <h3 className="text-xs font-semibold uppercase text-slate-500">Opened At</h3>
                  <p className="mt-1 text-sm font-semibold text-slate-900">
                    {new Date(report.shift.opened_at).toLocaleString()}
                  </p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                  <h3 className="text-xs font-semibold uppercase text-slate-500">Closed At</h3>
                  <p className="mt-1 text-sm font-semibold text-slate-900">
                    {report.shift.closed_at
                      ? new Date(report.shift.closed_at).toLocaleString()
                      : '—'}
                  </p>
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-3">
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                  <h3 className="text-xs font-semibold uppercase text-slate-500">
                    Opening Float
                  </h3>
                  <p className="mt-1 text-sm font-semibold text-slate-900">
                    {formatCurrency(report.shift.opening_float)}
                  </p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                  <h3 className="text-xs font-semibold uppercase text-slate-500">
                    Closing Cash
                  </h3>
                  <p className="mt-1 text-sm font-semibold text-slate-900">
                    {formatCurrency(report.shift.closing_cash)}
                  </p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                  <h3 className="text-xs font-semibold uppercase text-slate-500">
                    Expected Cash
                  </h3>
                  <p className="mt-1 text-sm font-semibold text-slate-900">
                    {formatCurrency(report.expected_cash)}
                  </p>
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                  <h3 className="text-xs font-semibold uppercase text-slate-500">Sales</h3>
                  <ul className="mt-2 space-y-1">
                    <li>Transactions: {report.sales.total_orders}</li>
                    <li>Gross Sales: {formatCurrency(report.sales.gross)}</li>
                    <li>Refunds: {formatCurrency(report.sales.refunds)}</li>
                    <li>Net Sales: {formatCurrency(report.sales.net)}</li>
                    <li>Cash Sales: {formatCurrency(report.sales.cash_sales_total)}</li>
                    <li>Cash Refunds: {formatCurrency(report.sales.cash_refunds_total)}</li>
                  </ul>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                  <h3 className="text-xs font-semibold uppercase text-slate-500">
                    Cash Movements
                  </h3>
                  <ul className="mt-2 space-y-1">
                    <li>Cash In: {formatCurrency(report.cash_movements.cash_in)}</li>
                    <li>Cash Out: {formatCurrency(report.cash_movements.cash_out)}</li>
                    <li>
                      Over / Short:{' '}
                      {report.cash_over_short !== null
                        ? formatCurrency(report.cash_over_short)
                        : 'Pending count'}
                    </li>
                  </ul>
                </div>
              </div>
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}

