
import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, DataTable, type DataTableColumn } from '@atlas-pos/ui';
import { Modal } from '../components/Modal';
import { useToast } from '../components/toastContext';
import {
  createRegister,
  fetchRegisters,
  updateRegister,
  type Register,
  type RegisterPayload,
  type RegisterUpdatePayload,
} from '../features/registers/api';
import { getErrorMessage } from '../lib/api';
import { useStore } from '../hooks/useStore';
import type { Store as StoreModel } from '../features/stores/api';
import { useAuth } from '../hooks/useAuth';

type RegisterFormValues = {
  name: string;
  location: string;
  is_active: boolean;
  store_id: string;
};

function RegisterForm({
  values,
  onChange,
  includeStatus = false,
  storeOptions = [],
  allowStoreSelection = false,
}: {
  values: RegisterFormValues;
  onChange: (values: RegisterFormValues) => void;
  includeStatus?: boolean;
  storeOptions?: StoreModel[];
  allowStoreSelection?: boolean;
}) {
  return (
    <form className="space-y-4">
      <div>
        <label htmlFor="register-name" className="block text-sm font-medium text-slate-700">
          Name
        </label>
        <input
          id="register-name"
          value={values.name}
          onChange={(event) => onChange({ ...values, name: event.target.value })}
          required
          className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          placeholder="Front Counter"
        />
      </div>

      <div>
        <label htmlFor="register-location" className="block text-sm font-medium text-slate-700">
          Location
        </label>
        <input
          id="register-location"
          value={values.location}
          onChange={(event) => onChange({ ...values, location: event.target.value })}
          className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          placeholder="Optional location label"
        />
      </div>
      {allowStoreSelection && storeOptions.length > 0 ? (
        <div>
          <label htmlFor="register-store" className="block text-sm font-medium text-slate-700">
            Store
          </label>
          <select
            id="register-store"
            value={values.store_id}
            onChange={(event) => onChange({ ...values, store_id: event.target.value })}
            className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          >
            {storeOptions.map((store) => (
              <option key={store.id} value={store.id}>
                {store.name}
              </option>
            ))}
          </select>
        </div>
      ) : null}

      {includeStatus ? (
        <label className="inline-flex items-center gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            checked={values.is_active}
            onChange={(event) => onChange({ ...values, is_active: event.target.checked })}
            className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
          />
          Active
        </label>
      ) : null}
    </form>
  );
}

export function RegistersPage() {
  const [search, setSearch] = useState('');
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [editingRegister, setEditingRegister] = useState<Register | null>(null);
  const queryClient = useQueryClient();
  const { addToast } = useToast();
  const { user } = useAuth();
  const {
    stores: storeOptions,
    currentStore,
    currentStoreId,
    loading: storesLoading,
  } = useStore();
  const storeId = currentStore?.id ?? currentStoreId ?? null;
  const allowStoreSelection = user?.role === 'admin' || user?.role === 'manager';
  const registerScope = allowStoreSelection ? 'all' : storeId ?? 'none';
  const storeReady = allowStoreSelection ? storeOptions.length > 0 || storesLoading : Boolean(storeId);

  const registersQuery = useQuery({
    queryKey: ['registers', registerScope],
    queryFn: () =>
      fetchRegisters(true, allowStoreSelection ? undefined : storeId ?? undefined),
    enabled: storeReady,
  });

  const createMutation = useMutation({
    mutationFn: async (payload: RegisterPayload) => createRegister(payload),
    onSuccess: () => {
      addToast({ type: 'success', message: 'Register created.' });
      setIsCreateOpen(false);
      void queryClient.invalidateQueries({ queryKey: ['registers'], exact: false });
    },
    onError: (error) => {
      addToast({ type: 'error', message: getErrorMessage(error) });
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, values }: { id: string; values: RegisterUpdatePayload }) =>
      updateRegister(id, values),
    onSuccess: (register) => {
      addToast({ type: 'success', message: 'Register updated.' });
      setEditingRegister(register);
      void queryClient.invalidateQueries({ queryKey: ['registers'], exact: false });
    },
    onError: (error) => {
      addToast({ type: 'error', message: getErrorMessage(error) });
    },
  });

  const registers = useMemo(
    () => registersQuery.data ?? [],
    [registersQuery.data]
  );

  const filteredRegisters = useMemo(() => {
    if (!search.trim()) {
      return registers;
    }
    const term = search.trim().toLowerCase();
    return registers.filter((register) => {
      return (
        register.name.toLowerCase().includes(term) ||
        (register.location ?? '').toLowerCase().includes(term) ||
        (register.store?.name ?? '').toLowerCase().includes(term)
      );
    });
  }, [registers, search]);

  const columns: DataTableColumn<Register>[] = [
    {
      header: 'Register',
      render: (register) => (
        <div>
          <p className="font-medium text-slate-900">{register.name}</p>
          {register.location ? (
            <p className="text-xs text-slate-500">{register.location}</p>
          ) : null}
        </div>
      ),
    },
    {
      header: 'Store',
      render: (register) => register.store?.name ?? '—',
    },
    {
      header: 'Status',
      className: 'text-right',
      render: (register) => (
        <span
          className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
            register.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'
          }`}
        >
          {register.is_active ? 'Active' : 'Inactive'}
        </span>
      ),
    },
    {
      header: 'Actions',
      className: 'text-right',
      render: (register) => (
        <Button size="sm" variant="outline" onClick={() => setEditingRegister(register)}>
          Edit
        </Button>
      ),
    },
  ];

  const handleCreateSubmit = async (values: RegisterFormValues) => {
    const resolvedStoreId = allowStoreSelection ? values.store_id : storeId;

    if (!resolvedStoreId) {
      addToast({ type: 'error', message: 'Select a store for the register.' });
      return;
    }

    await createMutation.mutateAsync({
      name: values.name.trim(),
      location: values.location.trim() || undefined,
      is_active: values.is_active,
      store_id: resolvedStoreId,
    });
  };

  const handleUpdateSubmit = async (values: RegisterFormValues) => {
    if (!editingRegister) {
      return;
    }

    const resolvedStoreId = allowStoreSelection
      ? values.store_id
      : editingRegister.store_id ?? storeId;

    if (!resolvedStoreId) {
      addToast({ type: 'error', message: 'Select a store for the register.' });
      return;
    }

    await updateMutation.mutateAsync({
      id: editingRegister.id,
      values: {
        name: values.name.trim(),
        location: values.location.trim() || undefined,
        is_active: values.is_active,
        store_id: resolvedStoreId,
      },
    });
  };

  return (
    <div className="min-h-screen bg-slate-50 pb-16">
      <div className="mx-auto max-w-5xl px-6 pt-10 sm:px-10">
        <header className="flex flex-col gap-3 border-b border-slate-200 pb-6 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-blue-600">
              Atlas POS Backoffice
            </p>
            <h1 className="mt-2 text-3xl font-bold text-slate-900">Registers</h1>
            <p className="mt-1 text-sm text-slate-500">
              Manage cash drawers and track which registers are active.
            </p>
          </div>
          <div className="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center">
            <Button
              onClick={() => setIsCreateOpen(true)}
              disabled={registersQuery.isLoading || (!allowStoreSelection && !storeReady)}
            >
              New Register
            </Button>
          </div>
        </header>

        <div className="mt-6 space-y-4">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search registers..."
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50 sm:w-64"
              disabled={!storeReady || registersQuery.isLoading}
            />
          </div>

          <Card
            heading="Register List"
            description="Overview of all registers configured for this tenant."
          >
            <DataTable<Register>
              data={storeReady ? filteredRegisters : []}
              columns={columns}
              keyField="id"
              emptyMessage={
                !storeReady
                  ? storesLoading
                    ? 'Loading stores...'
                    : allowStoreSelection
                      ? 'No stores available yet. Create one to begin tracking registers.'
                      : 'Select a store to view registers.'
                  : registersQuery.isLoading
                    ? 'Loading registers...'
                    : 'No registers found.'
              }
            />
          </Card>
        </div>
      </div>

      <CreateRegisterModal
        open={isCreateOpen}
        onClose={() => setIsCreateOpen(false)}
        onSubmit={handleCreateSubmit}
        isSubmitting={createMutation.isPending}
        storeOptions={storeOptions}
        allowStoreSelection={allowStoreSelection}
        defaultStoreId={
          allowStoreSelection ? currentStoreId ?? storeOptions[0]?.id ?? '' : storeId ?? ''
        }
      />

      <EditRegisterModal
        register={editingRegister}
        onClose={() => setEditingRegister(null)}
        onSubmit={handleUpdateSubmit}
        isSubmitting={updateMutation.isPending}
        storeOptions={storeOptions}
        allowStoreSelection={allowStoreSelection}
      />
    </div>
  );
}

function CreateRegisterModal({
  open,
  onClose,
  onSubmit,
  isSubmitting,
  storeOptions,
  allowStoreSelection,
  defaultStoreId,
}: {
  open: boolean;
  onClose: () => void;
  onSubmit: (values: RegisterFormValues) => Promise<void>;
  isSubmitting: boolean;
  storeOptions: StoreModel[];
  allowStoreSelection: boolean;
  defaultStoreId: string;
}) {
  const [values, setValues] = useState<RegisterFormValues>({
    name: '',
    location: '',
    is_active: true,
    store_id: defaultStoreId,
  });

  useEffect(() => {
    if (!open) {
      setValues({
        name: '',
        location: '',
        is_active: true,
        store_id: defaultStoreId,
      });
    }
  }, [open, defaultStoreId]);

  useEffect(() => {
    if (open && !allowStoreSelection) {
      setValues((prev) => ({
        ...prev,
        store_id: defaultStoreId,
      }));
    }
  }, [open, allowStoreSelection, defaultStoreId]);

  const handleSubmit = async () => {
    await onSubmit(values);
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="New Register"
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={handleSubmit} disabled={isSubmitting}>
            {isSubmitting ? 'Saving...' : 'Create'}
          </Button>
        </>
      }
    >
      <RegisterForm
        values={values}
        onChange={setValues}
        allowStoreSelection={allowStoreSelection}
        storeOptions={storeOptions}
      />
    </Modal>
  );
}

function EditRegisterModal({
  register,
  onClose,
  onSubmit,
  isSubmitting,
  storeOptions,
  allowStoreSelection,
}: {
  register: Register | null;
  onClose: () => void;
  onSubmit: (values: RegisterFormValues) => Promise<void>;
  isSubmitting: boolean;
  storeOptions: StoreModel[];
  allowStoreSelection: boolean;
}) {
  const [values, setValues] = useState<RegisterFormValues>({
    name: '',
    location: '',
    is_active: true,
    store_id: storeOptions[0]?.id ?? '',
  });

  useEffect(() => {
    if (register) {
      setValues({
        name: register.name,
        location: register.location ?? '',
        is_active: register.is_active,
        store_id: register.store_id ?? storeOptions[0]?.id ?? '',
      });
    }
  }, [register, storeOptions]);

  if (!register) {
    return null;
  }

  const handleSubmit = async () => {
    await onSubmit(values);
  };

  return (
    <Modal
      open={Boolean(register)}
      onClose={onClose}
      title="Edit Register"
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
          <Button onClick={handleSubmit} disabled={isSubmitting}>
            {isSubmitting ? 'Saving...' : 'Save'}
          </Button>
        </>
      }
    >
      <RegisterForm
        values={values}
        onChange={setValues}
        includeStatus
        allowStoreSelection={allowStoreSelection}
        storeOptions={storeOptions}
      />
    </Modal>
  );
}



