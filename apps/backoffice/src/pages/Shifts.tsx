import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { Button, Card, DataTable, type DataTableColumn } from '@atlas-pos/ui';
import { Modal } from '../components/Modal';
import { useToast } from '../components/toastContext';
import {
  fetchShifts,
  getShiftReport,
  type PaginatedShifts,
  type ShiftSummary,
} from '../features/shifts/api';
import { fetchRegisters } from '../features/registers/api';
import { getErrorMessage } from '../lib/api';
import { useStore } from '../hooks/useStore';

const formatCurrency = (value: number | null | undefined) =>
  typeof value === 'number' ? `$${value.toFixed(2)}` : 'Pending';

const formatDateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString() : '--';

type ToastHandler = (toast: {
  type: 'success' | 'error' | 'info';
  message: string;
  duration?: number;
}) => void;

export function ShiftsPage() {
  const [dateFilter, setDateFilter] = useState('');
  const [registerFilter, setRegisterFilter] = useState('');
  const [userFilter, setUserFilter] = useState('');
  const [page, setPage] = useState(1);
  const [selectedShiftId, setSelectedShiftId] = useState<string | null>(null);
  const queryClient = useQueryClient();
  const { addToast } = useToast();
  const navigate = useNavigate();
  const { currentStore, currentStoreId, loading: storesLoading } = useStore();
  const storeId = currentStore?.id ?? currentStoreId ?? null;
  const storeReady = Boolean(storeId);

  useEffect(() => {
    setPage(1);
  }, [dateFilter, registerFilter]);

  useEffect(() => {
    setRegisterFilter('');
    setPage(1);
    setSelectedShiftId(null);
  }, [storeId]);

  const registersQuery = useQuery({
    queryKey: ['registers', 'shifts', storeId ?? 'none'],
    queryFn: () => fetchRegisters(false, storeId ?? undefined),
    enabled: storeReady,
  });

  const shiftsQuery = useQuery<PaginatedShifts, Error>({
    queryKey: ['shifts', storeId ?? 'none', { dateFilter, registerFilter, page }],
    queryFn: () =>
      fetchShifts({
        date: dateFilter || undefined,
        register_id: registerFilter || undefined,
        page,
        store_id: storeId ?? undefined,
      }),
    enabled: storeReady,
  });

  const shiftReportQuery = useQuery<ShiftSummary, Error>({
    queryKey: ['shift-report', storeId ?? 'none', selectedShiftId],
    queryFn: () => getShiftReport(selectedShiftId!),
    enabled: storeReady && Boolean(selectedShiftId),
  });

  const registers = useMemo(
    () => (storeReady ? registersQuery.data ?? [] : []),
    [storeReady, registersQuery.data]
  );
  const shifts: ShiftSummary[] = useMemo(
    () => (storeReady ? shiftsQuery.data?.data ?? [] : []),
    [storeReady, shiftsQuery.data]
  );

  const filteredShifts = useMemo(() => {
    if (!storeReady) {
      return [];
    }

    if (!userFilter.trim()) {
      return shifts;
    }
    const term = userFilter.trim().toLowerCase();
    return shifts.filter((summary) =>
      summary.shift.user.name.toLowerCase().includes(term) ||
      (summary.shift.user.email ?? '').toLowerCase().includes(term)
    );
  }, [storeReady, shifts, userFilter]);

  const columns: DataTableColumn<ShiftSummary>[] = [
    {
      header: 'Register',
      render: (summary) => (
        <div>
          <p className="font-medium text-slate-900">{summary.shift.register.name}</p>
          {summary.shift.register.location ? (
            <p className="text-xs text-slate-500">{summary.shift.register.location}</p>
          ) : null}
        </div>
      ),
    },
    {
      header: 'Cashier',
      render: (summary) => (
        <div>
          <p className="font-medium text-slate-900">{summary.shift.user.name}</p>
          {summary.shift.user.email ? (
            <p className="text-xs text-slate-500">{summary.shift.user.email}</p>
          ) : null}
        </div>
      ),
    },
    {
      header: 'Opened',
      render: (summary) => formatDateTime(summary.shift.opened_at),
    },
    {
      header: 'Closed',
      render: (summary) => formatDateTime(summary.shift.closed_at),
    },
    {
      header: 'Net',
      className: 'text-right',
      render: (summary) => (
        <span className="font-semibold text-slate-900">
          {formatCurrency(summary.sales.net)}
        </span>
      ),
    },
    {
      header: 'Over / Short',
      className: 'text-right',
      render: (summary) =>
        summary.cash_over_short === null ? (
          <span className="text-slate-500">Pending</span>
        ) : (
          <span
            className={
              summary.cash_over_short === 0
                ? 'text-slate-900'
                : summary.cash_over_short > 0
                  ? 'text-green-600'
                  : 'text-red-600'
            }
          >
            {formatCurrency(summary.cash_over_short)}
          </span>
        ),
    },
    {
      header: 'Actions',
      className: 'text-right',
      render: (summary) => (
        <Button size="sm" variant="outline" onClick={() => setSelectedShiftId(summary.shift.id)}>
          View
        </Button>
      ),
    },
  ];

  const meta = storeReady ? shiftsQuery.data?.meta : undefined;

  const handleRefresh = () => {
    queryClient.invalidateQueries({ queryKey: ['shifts'] });
  };

  const storeStatusMessage = !storeReady
    ? storesLoading
      ? 'Loading stores...'
      : 'Select a store to view shifts.'
    : null;

  return (
    <div className="min-h-screen bg-slate-50 pb-16">
      <div className="mx-auto max-w-6xl px-6 pt-10 sm:px-10">
        <header className="flex flex-col gap-3 border-b border-slate-200 pb-6 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-blue-600">
              Atlas POS Backoffice
            </p>
            <h1 className="mt-2 text-3xl font-bold text-slate-900">Shift Reports</h1>
            <p className="mt-1 text-sm text-slate-500">
              Review cash drawer activity and generate printable Z-Reports.
            </p>
          </div>
          <div className="flex flex-col items-stretch gap-2 sm:items-end">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <Button variant="outline" onClick={() => navigate('/registers')}>
                Manage Registers
              </Button>
              <Button variant="outline" onClick={handleRefresh} disabled={!storeReady}>
                Refresh
              </Button>
            </div>
          </div>
        </header>

        <Card heading="Filters" description="Refine the list of shifts by date, register, or cashier.">
          {storeStatusMessage ? (
            <div className="mb-4 rounded-md border border-slate-200 bg-slate-100 px-3 py-2 text-sm text-slate-600">
              {storeStatusMessage}
            </div>
          ) : null}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <label className="text-xs font-semibold uppercase text-slate-500">Date</label>
              <input
                type="date"
                value={dateFilter}
                onChange={(event) => setDateFilter(event.target.value)}
                disabled={!storeReady}
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
              />
            </div>
            <div>
              <label className="text-xs font-semibold uppercase text-slate-500">Register</label>
              <select
                value={registerFilter}
                onChange={(event) => setRegisterFilter(event.target.value)}
                disabled={!storeReady || registersQuery.isLoading}
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
              >
                <option value="">
                  {registersQuery.isLoading ? 'Loading registers...' : 'All registers'}
                </option>
                {registers.map((register) => (
                  <option key={register.id} value={register.id}>
                    {register.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="sm:col-span-2 lg:col-span-2">
              <label className="text-xs font-semibold uppercase text-slate-500">Cashier</label>
              <input
                type="search"
                value={userFilter}
                onChange={(event) => setUserFilter(event.target.value)}
                placeholder="Filter by cashier name or email"
                disabled={!storeReady}
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
              />
            </div>
          </div>
        </Card>

        <div className="mt-6 space-y-4">
          <Card
            heading="Shifts"
            description="Click View to inspect details and print the Z-Report."
          >
            {storeStatusMessage ? (
              <div className="text-sm text-slate-500">{storeStatusMessage}</div>
            ) : shiftsQuery.isError ? (
              <div className="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                {getErrorMessage(shiftsQuery.error)}
              </div>
            ) : (
              <>
                <DataTable<ShiftSummary>
                  data={filteredShifts}
                  columns={columns}
                  emptyMessage={
                    shiftsQuery.isLoading
                      ? 'Loading shifts...'
                      : filteredShifts.length === 0
                        ? 'No shifts found.'
                        : undefined
                  }
                />

                {meta ? (
                  <div className="mt-4 flex flex-col gap-2 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      Page {meta.current_page} of {meta.last_page} • {meta.total} shifts
                    </div>
                    <div className="flex items-center gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={meta.current_page <= 1 || shiftsQuery.isFetching}
                        onClick={() => setPage((prev) => Math.max(prev - 1, 1))}
                      >
                        Previous
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={meta.current_page >= meta.last_page || shiftsQuery.isFetching}
                        onClick={() => setPage((prev) => prev + 1)}
                      >
                        Next
                      </Button>
                    </div>
                  </div>
                ) : null}
              </>
            )}
          </Card>
        </div>
      </div>

      <ShiftDetailModal
        shiftId={selectedShiftId}
        reportQuery={shiftReportQuery}
        onClose={() => setSelectedShiftId(null)}
        addToast={addToast}
      />
    </div>
  );
}

function ShiftDetailModal({
  shiftId,
  reportQuery,
  onClose,
  addToast,
}: {
  shiftId: string | null;
  reportQuery: UseQueryResult<ShiftSummary, Error>;
  onClose: () => void;
  addToast: ToastHandler;
}) {
  const report = reportQuery.data;

  const openZReport = () => {
    if (!shiftId) {
      return;
    }
    const url = `http://127.0.0.1:5173/z-report/${shiftId}`;
    window.open(url, '_blank', 'noopener');
    addToast({ type: 'info', message: 'Opened printable Z-Report in a new tab.' });
  };

  return (
    <Modal
      open={Boolean(shiftId)}
      onClose={onClose}
      title="Shift Details"
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
          <Button onClick={openZReport}>Open Z-Report</Button>
        </>
      }
    >
      {reportQuery.isLoading ? (
        <div className="flex items-center gap-3 text-sm text-slate-500">
          <div className="h-3 w-24 animate-pulse rounded-full bg-slate-200" />
          <div className="h-3 w-32 animate-pulse rounded-full bg-slate-200" />
        </div>
      ) : reportQuery.isError ? (
        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
          {getErrorMessage(reportQuery.error)}
        </div>
      ) : report ? (
        <div className="space-y-4 text-sm text-slate-600">
          <div>
            <p className="font-semibold text-slate-900">{report.shift.register.name}</p>
            <p>{report.shift.user.name}</p>
            <p>{report.shift.user.email}</p>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
              <p className="text-xs font-semibold uppercase text-slate-500">Opened</p>
              <p className="mt-1 font-semibold text-slate-900">
                {formatDateTime(report.shift.opened_at)}
              </p>
            </div>
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
              <p className="text-xs font-semibold uppercase text-slate-500">Closed</p>
              <p className="mt-1 font-semibold text-slate-900">
                {formatDateTime(report.shift.closed_at)}
              </p>
            </div>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
              <p className="text-xs font-semibold uppercase text-slate-500">Opening Float</p>
              <p className="mt-1 font-semibold text-slate-900">
                {formatCurrency(report.shift.opening_float)}
              </p>
            </div>
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
              <p className="text-xs font-semibold uppercase text-slate-500">Closing Cash</p>
              <p className="mt-1 font-semibold text-slate-900">
                {formatCurrency(report.shift.closing_cash)}
              </p>
            </div>
          </div>
          <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p className="text-xs font-semibold uppercase text-slate-500">Sales</p>
            <ul className="mt-2 space-y-1">
              <li>Transactions: {report.sales.total_orders}</li>
              <li>Gross Sales: {formatCurrency(report.sales.gross)}</li>
              <li>Refunds: {formatCurrency(report.sales.refunds)}</li>
              <li>Net Sales: {formatCurrency(report.sales.net)}</li>
            </ul>
          </div>
          <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p className="text-xs font-semibold uppercase text-slate-500">Cash Totals</p>
            <ul className="mt-2 space-y-1">
              <li>Cash In: {formatCurrency(report.cash_movements.cash_in)}</li>
              <li>Cash Out: {formatCurrency(report.cash_movements.cash_out)}</li>
              <li>Expected Cash: {formatCurrency(report.expected_cash)}</li>
              <li>Over / Short: {formatCurrency(report.cash_over_short)}</li>
            </ul>
          </div>
        </div>
      ) : null}
    </Modal>
  );
}

