import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuth } from '../hooks/useAuth';
import { useStore } from '../hooks/useStore';
import { useToast } from '../components/ToastProvider';
import {
  listRegisters,
  getCurrentShift,
  getShiftReport,
  openShift,
  closeShift,
  addCashMovement,
  type ShiftSummary,
} from '../features/shifts/api';
import { PosHeader } from '../components/pos/PosHeader';
import { getErrorMessage } from '../lib/api';

type CashAction = 'cash_in' | 'cash_out';

type CashDrawerModal = {
  open: boolean;
  type: CashAction;
};

const formatCurrency = (value: number) => `$${value.toFixed(2)}`;

const getInitials = (name?: string | null) => {
  if (!name) {
    return 'POS';
  }
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) {
    return parts[0]!.slice(0, 2).toUpperCase();
  }
  return `${parts[0]!.charAt(0)}${parts[parts.length - 1]!.charAt(0)}`.toUpperCase();
};

const formatTimeLabel = (iso?: string | null) => {
  if (!iso) {
    return null;
  }
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return null;
  }
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

export default function ProfilePage() {
  const { user, logout } = useAuth();
  const { stores, currentStoreId, setCurrentStoreId, currentStore } = useStore();
  const { addToast } = useToast();
  const queryClient = useQueryClient();

  const [selectedRegisterId, setSelectedRegisterId] = useState<string | null>(null);
  const [openingFloat, setOpeningFloat] = useState('0.00');
  const [closingCash, setClosingCash] = useState('0.00');
  const [cashAmount, setCashAmount] = useState('0.00');
  const [cashReason, setCashReason] = useState('');
  const [cashDrawerModal, setCashDrawerModal] = useState<CashDrawerModal>({
    open: false,
    type: 'cash_in',
  });
  const [now, setNow] = useState(() => new Date());

  useEffect(() => {
    const interval = window.setInterval(() => {
      setNow(new Date());
    }, 60_000);
    return () => {
      window.clearInterval(interval);
    };
  }, []);

  const registersQuery = useQuery({
    queryKey: ['registers', 'profile', currentStoreId ?? 'none'],
    queryFn: () =>
      listRegisters({
        includeInactive: true,
        storeId: currentStoreId ?? undefined,
      }),
    enabled: Boolean(currentStoreId),
    staleTime: 60_000,
  });

  const availableRegisters = useMemo(() => {
    const all = registersQuery.data ?? [];
    if (!currentStoreId) {
      return all;
    }
    return all.filter((register) => {
      const registerStoreId = register.store?.id ?? register.store_id ?? null;
      if (!registerStoreId) {
        return true;
      }
      return String(registerStoreId) === currentStoreId;
    });
  }, [registersQuery.data, currentStoreId]);

  useEffect(() => {
    if (availableRegisters.length === 0) {
      setSelectedRegisterId(null);
      return;
    }

    setSelectedRegisterId((previous) => {
      if (previous && availableRegisters.some((register) => register.id === previous)) {
        return previous;
      }
      const fallback =
        availableRegisters.find((register) => register.is_active) ?? availableRegisters[0];
      return fallback?.id ?? null;
    });
  }, [availableRegisters]);

  const currentShiftQuery = useQuery({
    queryKey: ['shift', 'current'],
    queryFn: getCurrentShift,
    staleTime: 10_000,
  });

  const activeShift = currentShiftQuery.data?.shift ?? null;
  const activeShiftId = activeShift?.shift.id ?? null;

  const shiftReportQuery = useQuery({
    queryKey: ['shift', 'report', activeShiftId],
    queryFn: () => getShiftReport(activeShiftId!),
    enabled: Boolean(activeShiftId),
    staleTime: 5_000,
  });

  const stats = useMemo(() => {
    if (!shiftReportQuery.data) {
      return [];
    }
    const report = shiftReportQuery.data as ShiftSummary;
    return [
      { label: 'Orders Today', value: report.sales.total_orders.toString() },
      { label: 'Gross Sales', value: formatCurrency(report.sales.gross) },
      { label: 'Refunds', value: formatCurrency(report.sales.refunds) },
      { label: 'Net Sales', value: formatCurrency(report.sales.net) },
    ];
  }, [shiftReportQuery.data]);

  const cashSnapshot = useMemo(() => {
    if (!activeShift) {
      return [];
    }
    const items = [
      { label: 'Opening Float', value: formatCurrency(activeShift.shift.opening_float) },
      { label: 'Cash In', value: formatCurrency(activeShift.cash_movements.cash_in) },
      { label: 'Cash Out', value: formatCurrency(activeShift.cash_movements.cash_out) },
      { label: 'Expected Cash', value: formatCurrency(activeShift.expected_cash) },
    ];
    if (typeof activeShift.cash_over_short === 'number') {
      items.push({
        label: 'Over / Short',
        value: formatCurrency(activeShift.cash_over_short),
      });
    }
    return items;
  }, [activeShift]);

  const selectedRegister = useMemo(
    () => availableRegisters.find((register) => register.id === selectedRegisterId) ?? null,
    [availableRegisters, selectedRegisterId]
  );

  const isLoadingRegisters = registersQuery.isLoading;
  const shiftActive = Boolean(activeShiftId);
  const canModifyCash = shiftActive;
  const shiftStatusLabel = shiftActive ? 'On shift' : 'Off shift';
  const shiftStatusClass = shiftActive
    ? 'bg-[color:var(--pos-success)] text-[color:var(--pos-success-contrast)]'
    : 'bg-[color:var(--pos-surface-muted)] text-[color:var(--pos-text-muted)]';
  const shiftOpenedAt = formatTimeLabel(activeShift?.shift.opened_at);
  const shiftRegisterName = activeShift?.shift.register.name ?? selectedRegister?.name ?? '—';
  const shiftStoreName =
    activeShift?.shift.store?.name ??
    selectedRegister?.store?.name ??
    currentStore?.name ??
    '—';
  const roleLabel = user?.role
    ? user.role.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
    : 'Team Member';
  const userInitials = getInitials(user?.name);
  const timeLabel = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const storeSelectDisabled = stores.length <= 1;

  const openShiftMutation = useMutation({
    mutationFn: async () => {
      if (!selectedRegisterId) {
        throw new Error('Select a register to open a shift.');
      }
      const amount = Number.parseFloat(openingFloat) || 0;
      await openShift(selectedRegisterId, amount);
    },
    onSuccess: () => {
      addToast({ type: 'success', message: 'Shift opened.' });
      queryClient.invalidateQueries({ queryKey: ['shift', 'current'] });
    },
    onError: (error) => {
      addToast({ type: 'error', message: getErrorMessage(error) });
    },
  });

  const closeShiftMutation = useMutation({
    mutationFn: async () => {
      if (!activeShiftId) {
        return;
      }
      const amount = Number.parseFloat(closingCash) || 0;
      await closeShift(activeShiftId, amount);
    },
    onSuccess: () => {
      addToast({ type: 'success', message: 'Shift closed.' });
      queryClient.invalidateQueries({ queryKey: ['shift', 'current'] });
      queryClient.invalidateQueries({ queryKey: ['shift', 'report', activeShiftId] });
    },
    onError: (error) => {
      addToast({ type: 'error', message: getErrorMessage(error) });
    },
  });

  const cashMovementMutation = useMutation({
    mutationFn: async () => {
      if (!activeShiftId) {
        throw new Error('Open a shift before recording cash movements.');
      }
      const amount = Number.parseFloat(cashAmount) || 0;
      await addCashMovement(activeShiftId, cashDrawerModal.type, amount, cashReason || undefined);
    },
    onSuccess: () => {
      addToast({ type: 'success', message: 'Cash movement recorded.' });
      setCashAmount('0.00');
      setCashReason('');
      setCashDrawerModal((prev) => ({ ...prev, open: false }));
      queryClient.invalidateQueries({ queryKey: ['shift', 'report', activeShiftId] });
      queryClient.invalidateQueries({ queryKey: ['shift', 'current'] });
    },
    onError: (error) => {
      addToast({ type: 'error', message: getErrorMessage(error) });
    },
  });

  const handleOpenCashModal = (type: CashAction) => {
    setCashDrawerModal({ open: true, type });
    setCashAmount('0.00');
    setCashReason('');
  };

  const handleLogout = async () => {
    try {
      await logout();
    } catch (error) {
      addToast({ type: 'error', message: (error as Error).message });
    }
  };

  return (
    <div className="pb-24">
      <PosHeader
        title="Shift & Profile"
        subtitle="Manage your shift, cash drawer, and store preferences."
        endAdornment={
          <div className="rounded-full border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] px-4 py-2 text-xs font-semibold text-[color:var(--pos-text-muted)] shadow-sm">
            {timeLabel}
          </div>
        }
      />

      <div className="mt-8 grid gap-6 lg:grid-cols-[1.4fr,2fr]">
        <div className="space-y-6">
          <section className="rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] p-6 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div className="flex items-center gap-4">
                <div className="flex h-16 w-16 items-center justify-center rounded-full bg-[color:var(--pos-surface-muted)] text-xl font-semibold text-[color:var(--pos-text)]">
                  {userInitials}
                </div>
                <div>
                  <h2 className="text-xl font-semibold text-[color:var(--pos-text)]">
                    {user?.name ?? 'Team Member'}
                  </h2>
                  <p className="text-sm text-[color:var(--pos-text-muted)]">{roleLabel}</p>
                </div>
              </div>
              <span
                className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-semibold ${shiftStatusClass}`}
              >
                <span className="h-2 w-2 rounded-full bg-current" />
                {shiftStatusLabel}
              </span>
            </div>

            <div className="mt-6 grid gap-4">
              <label className="text-xs font-semibold uppercase text-[color:var(--pos-text-muted)]">
                Store location
                <select
                  value={currentStoreId ?? ''}
                  onChange={(event) => setCurrentStoreId(event.target.value)}
                  disabled={storeSelectDisabled}
                  className="mt-2 w-full rounded-2xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface-muted)] px-4 py-2 text-sm text-[color:var(--pos-text)] outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--pos-accent)] disabled:cursor-not-allowed disabled:opacity-70"
                >
                  {storeSelectDisabled && stores.length === 0 ? (
                    <option value="">No stores available</option>
                  ) : (
                    stores.map((store) => (
                      <option key={store.id} value={store.id}>
                        {store.name}
                      </option>
                    ))
                  )}
                </select>
              </label>

              <div className="rounded-2xl bg-[color:var(--pos-surface-muted)] p-4">
                <p className="text-xs font-semibold uppercase text-[color:var(--pos-text-muted)]">
                  Active register
                </p>
                <p className="mt-1 text-sm font-semibold text-[color:var(--pos-text)]">
                  {shiftRegisterName}
                </p>
                <p className="text-xs text-[color:var(--pos-text-muted)]">
                  {shiftActive
                    ? `Started ${shiftOpenedAt ?? 'earlier today'}`
                    : 'Open a shift to begin capturing sales.'}
                </p>
              </div>
            </div>
          </section>

          <section className="rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] p-6 shadow-sm">
           <div className="flex flex-wrap items-start justify-between gap-4">
             <div>
               <h3 className="text-lg font-semibold text-[color:var(--pos-text)]">
                 Register controls
               </h3>
               <p className="text-sm text-[color:var(--pos-text-muted)]">
                 Open or close shifts for the selected register.
               </p>
             </div>
              <label className="text-xs font-semibold uppercase text-[color:var(--pos-text-muted)]">
                Register
                <select
                  value={selectedRegisterId ?? ''}
                  onChange={(event) => setSelectedRegisterId(event.target.value || null)}
                  disabled={availableRegisters.length === 0}
                  className="mt-2 min-w-[180px] rounded-2xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface-muted)] px-4 py-2 text-sm text-[color:var(--pos-text)] outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--pos-accent)] disabled:cursor-not-allowed disabled:opacity-70"
                >
                  {availableRegisters.length === 0 ? (
                    <option value="">No registers available</option>
                  ) : (
                    availableRegisters.map((register) => (
                      <option key={register.id} value={register.id}>
                        {register.name}
                      </option>
                    ))
                  )}
                </select>
              </label>
            </div>

            <div className="mt-4 grid gap-3 sm:grid-cols-2">
              <label className="text-xs font-semibold uppercase text-[color:var(--pos-text-muted)]">
                Register
                <select
                  value={selectedRegisterId ?? ''}
                  onChange={(event) => setSelectedRegisterId(event.target.value)}
                  disabled={isLoadingRegisters || availableRegisters.length === 0}
                  className="mt-2 w-full rounded-2xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface-muted)] px-4 py-2 text-sm text-[color:var(--pos-text)] outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--pos-accent)] disabled:cursor-not-allowed disabled:opacity-70"
                >
                  {isLoadingRegisters ? (
                    <option value="">Loading registers...</option>
                  ) : availableRegisters.length === 0 ? (
                    <option value="">No registers for this store</option>
                  ) : (
                    availableRegisters.map((register) => (
                      <option key={register.id} value={register.id}>
                        {register.name}
                      </option>
                    ))
                  )}
                </select>
              </label>
              <div className="rounded-2xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface-muted)] p-3 text-xs text-[color:var(--pos-text-muted)]">
                <p className="font-semibold uppercase">Store</p>
                <p className="mt-1 text-sm font-semibold text-[color:var(--pos-text)]">
                  {shiftStoreName}
                </p>
              </div>
            </div>

            <div className="mt-6 grid gap-4 md:grid-cols-2">
              <div className="flex flex-col gap-3 rounded-2xl bg-[color:var(--pos-surface-muted)] p-4">
                <label className="text-xs font-semibold uppercase text-[color:var(--pos-text-muted)]">
                  Opening float
                  <input
                    type="number"
                    min={0}
                    step="0.01"
                    value={openingFloat}
                    onChange={(event) => setOpeningFloat(event.target.value)}
                    className="mt-2 w-full rounded-xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] px-3 py-2 text-sm text-[color:var(--pos-text)] outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--pos-accent)]"
                  />
                </label>
                <button
                  type="button"
                  onClick={() => openShiftMutation.mutate()}
                  disabled={!selectedRegisterId || openShiftMutation.isPending}
                  className="rounded-full bg-[color:var(--pos-accent)] px-4 py-2 text-sm font-semibold text-[color:var(--pos-accent-contrast)] shadow transition hover:-translate-y-[1px] disabled:cursor-not-allowed disabled:opacity-70"
                >
                  {openShiftMutation.isPending ? 'Opening...' : 'Open shift'}
                </button>
              </div>
              <div className="flex flex-col gap-3 rounded-2xl bg-[color:var(--pos-surface-muted)] p-4">
                <label className="text-xs font-semibold uppercase text-[color:var(--pos-text-muted)]">
                  Closing cash
                  <input
                    type="number"
                    min={0}
                    step="0.01"
                    value={closingCash}
                    onChange={(event) => setClosingCash(event.target.value)}
                    className="mt-2 w-full rounded-xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] px-3 py-2 text-sm text-[color:var(--pos-text)] outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--pos-accent)]"
                  />
                </label>
                <button
                  type="button"
                  onClick={() => closeShiftMutation.mutate()}
                  disabled={!activeShiftId || closeShiftMutation.isPending}
                  className="rounded-full border border-[color:var(--pos-accent)] px-4 py-2 text-sm font-semibold text-[color:var(--pos-accent)] transition hover:bg-[color:var(--pos-accent)] hover:text-[color:var(--pos-accent-contrast)] disabled:cursor-not-allowed disabled:opacity-70"
                >
                  {closeShiftMutation.isPending ? 'Closing...' : 'Close shift'}
                </button>
              </div>
            </div>

            <div className="mt-6 grid gap-3 sm:grid-cols-2">
              <button
                type="button"
                onClick={() => handleOpenCashModal('cash_in')}
                disabled={!canModifyCash}
                className="rounded-full border border-[color:var(--pos-border)] px-4 py-3 text-sm font-semibold text-[color:var(--pos-text)] transition hover:bg-[color:var(--pos-surface-muted)] disabled:cursor-not-allowed disabled:opacity-60"
              >
                Cash in
              </button>
              <button
                type="button"
                onClick={() => handleOpenCashModal('cash_out')}
                disabled={!canModifyCash}
                className="rounded-full border border-[color:var(--pos-border)] px-4 py-3 text-sm font-semibold text-[color:var(--pos-text)] transition hover:bg-[color:var(--pos-surface-muted)] disabled:cursor-not-allowed disabled:opacity-60"
              >
                Cash out
              </button>
            </div>
            {!shiftActive ? (
              <p className="mt-4 text-xs text-[color:var(--pos-text-muted)]">
                Open a shift before recording cash movements.
              </p>
            ) : null}
          </section>

          <section className="rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] p-6 shadow-sm">
            <h3 className="text-lg font-semibold text-[color:var(--pos-text)]">Actions</h3>
            <p className="text-sm text-[color:var(--pos-text-muted)]">
              Manage your POS session.
            </p>
            <div className="mt-4 space-y-3">
              <button
                type="button"
                onClick={handleLogout}
                className="w-full rounded-2xl border border-[color:var(--pos-border)] px-4 py-3 text-sm font-semibold text-[color:var(--pos-text)] transition hover:bg-[color:var(--pos-surface-muted)]"
              >
                Sign out
              </button>
            </div>
          </section>
        </div>

        <div className="space-y-6">
          <section className="rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] p-6 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div>
                <h3 className="text-lg font-semibold text-[color:var(--pos-text)]">
                  Session overview
                </h3>
                <p className="text-sm text-[color:var(--pos-text-muted)]">
                  Real-time stats for your current shift.
                </p>
              </div>
              <div className="rounded-2xl bg-[color:var(--pos-surface-muted)] px-3 py-2 text-xs text-[color:var(--pos-text-muted)]">
                {shiftActive
                  ? `Register ${shiftRegisterName}${
                      shiftOpenedAt ? ` · since ${shiftOpenedAt}` : ''
                    }`
                  : 'No active shift'}
              </div>
            </div>

            {stats.length > 0 ? (
              <dl className="mt-6 grid gap-4 sm:grid-cols-2">
                {stats.map((stat) => (
                  <div
                    key={stat.label}
                    className="rounded-2xl bg-[color:var(--pos-surface-muted)] p-4 shadow-inner"
                  >
                    <dt className="text-xs uppercase text-[color:var(--pos-text-muted)]">
                      {stat.label}
                    </dt>
                    <dd className="mt-2 text-2xl font-semibold text-[color:var(--pos-text)]">
                      {stat.value}
                    </dd>
                  </div>
                ))}
              </dl>
            ) : (
              <p className="mt-6 text-sm text-[color:var(--pos-text-muted)]">
                Open a shift to see live performance metrics.
              </p>
            )}
          </section>

          <section className="rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] p-6 shadow-sm">
            <h3 className="text-lg font-semibold text-[color:var(--pos-text)]">Cash drawer</h3>
            <p className="text-sm text-[color:var(--pos-text-muted)]">
              Track real-time cash movement for this shift.
            </p>

            {cashSnapshot.length > 0 ? (
              <dl className="mt-6 grid gap-4 sm:grid-cols-2">
                {cashSnapshot.map((item) => (
                  <div
                    key={item.label}
                    className="rounded-2xl bg-[color:var(--pos-surface-muted)] p-4"
                  >
                    <dt className="text-xs uppercase text-[color:var(--pos-text-muted)]">
                      {item.label}
                    </dt>
                    <dd className="mt-2 text-lg font-semibold text-[color:var(--pos-text)]">
                      {item.value}
                    </dd>
                  </div>
                ))}
              </dl>
            ) : (
              <p className="mt-6 text-sm text-[color:var(--pos-text-muted)]">
                Cash totals will appear once a shift is active.
              </p>
            )}
          </section>
        </div>
      </div>

      {cashDrawerModal.open ? (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/60 px-4 py-8">
          <div className="w-full max-w-md rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] p-6 shadow-2xl">
            <h3 className="text-lg font-semibold text-[color:var(--pos-text)]">
              {cashDrawerModal.type === 'cash_in' ? 'Record cash in' : 'Record cash out'}
            </h3>
            <p className="mt-1 text-sm text-[color:var(--pos-text-muted)]">
              {cashDrawerModal.type === 'cash_in'
                ? 'Add a deposit to the drawer and leave an optional note.'
                : 'Record a withdrawal from the drawer and leave an optional note.'}
            </p>
            <div className="mt-4 space-y-4">
              <label className="text-xs font-semibold uppercase text-[color:var(--pos-text-muted)]">
                Amount
                <input
                  type="number"
                  min={0}
                  step="0.01"
                  value={cashAmount}
                  onChange={(event) => setCashAmount(event.target.value)}
                  className="mt-2 w-full rounded-2xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface-muted)] px-4 py-2 text-sm text-[color:var(--pos-text)] outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--pos-accent)]"
                />
              </label>
              <label className="text-xs font-semibold uppercase text-[color:var(--pos-text-muted)]">
                Reason (optional)
                <input
                  type="text"
                  value={cashReason}
                  onChange={(event) => setCashReason(event.target.value)}
                  placeholder="Add a short note"
                  className="mt-2 w-full rounded-2xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface-muted)] px-4 py-2 text-sm text-[color:var(--pos-text)] outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--pos-accent)]"
                />
              </label>
            </div>
            <div className="mt-6 flex items-center justify-end gap-3">
              <button
                type="button"
                onClick={() => setCashDrawerModal({ ...cashDrawerModal, open: false })}
                className="rounded-full border border-[color:var(--pos-border)] px-4 py-2 text-sm font-medium text-[color:var(--pos-text-muted)] hover:text-[color:var(--pos-text)]"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={() => cashMovementMutation.mutate()}
                disabled={cashMovementMutation.isPending}
                className="rounded-full bg-[color:var(--pos-accent)] px-4 py-2 text-sm font-semibold text-[color:var(--pos-accent-contrast)] shadow transition hover:-translate-y-[1px] disabled:cursor-not-allowed disabled:opacity-70"
              >
                {cashMovementMutation.isPending ? 'Saving...' : 'Save'}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
