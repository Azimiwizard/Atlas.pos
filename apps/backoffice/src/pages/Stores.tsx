import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, DataTable, type DataTableColumn } from '@atlas-pos/ui';
import { Modal } from '../components/Modal';
import { useToast } from '../components/toastContext';
import {
  createStore,
  deactivateStore,
  listStores,
  updateStore,
  type Store,
  type StorePayload,
} from '../features/stores/api';
import { fetchUsers, updateUser } from '../features/users/api';
import { fetchRegisters, updateRegister } from '../features/registers/api';
import { getErrorMessage } from '../lib/api';
import { useAuth } from '../hooks/useAuth';
import { useStore as useStoreContext } from '../hooks/useStore';

type StoreFormValues = {
  name: string;
  code: string;
  address: string;
  phone: string;
  is_active: boolean;
};

function StoreForm({
  values,
  onChange,
  includeStatus = false,
}: {
  values: StoreFormValues;
  onChange: (values: StoreFormValues) => void;
  includeStatus?: boolean;
}) {
  return (
    <form className="space-y-4">
      <div>
        <label htmlFor="store-name" className="block text-sm font-medium text-slate-700">
          Name
        </label>
        <input
          id="store-name"
          value={values.name}
          onChange={(event) => onChange({ ...values, name: event.target.value })}
          required
          className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          placeholder="Main Store"
        />
      </div>

      <div>
        <label htmlFor="store-code" className="block text-sm font-medium text-slate-700">
          Code
        </label>
        <input
          id="store-code"
          value={values.code}
          onChange={(event) => onChange({ ...values, code: event.target.value.toUpperCase() })}
          required
          maxLength={20}
          className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          placeholder="MAIN"
        />
      </div>

      <div>
        <label htmlFor="store-address" className="block text-sm font-medium text-slate-700">
          Address
        </label>
        <input
          id="store-address"
          value={values.address}
          onChange={(event) => onChange({ ...values, address: event.target.value })}
          className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          placeholder="123 Market St"
        />
      </div>

      <div>
        <label htmlFor="store-phone" className="block text-sm font-medium text-slate-700">
          Phone
        </label>
        <input
          id="store-phone"
          value={values.phone}
          onChange={(event) => onChange({ ...values, phone: event.target.value })}
          className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          placeholder="555-0100"
        />
      </div>

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

export function StoresPage() {
  const queryClient = useQueryClient();
  const { addToast } = useToast();
  const { user } = useAuth();
  const [search, setSearch] = useState('');
  const [showActiveOnly, setShowActiveOnly] = useState(false);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [editingStore, setEditingStore] = useState<Store | null>(null);
  const [managingUsersStore, setManagingUsersStore] = useState<Store | null>(null);
  const [managingRegistersStore, setManagingRegistersStore] = useState<Store | null>(null);
  const { setCurrentStoreId, currentStoreId } = useStoreContext();

  const storesQuery = useQuery({
    queryKey: ['stores', 'manage', showActiveOnly ? 'active' : 'all'],
    queryFn: () => listStores(showActiveOnly ? { isActive: true } : {}),
  });

  const createMutation = useMutation({
    mutationFn: async (payload: StorePayload) => createStore(payload),
    onSuccess: (store) => {
      addToast({ type: 'success', message: 'Store created.' });
      setIsCreateOpen(false);
      if (store.is_active) {
        setCurrentStoreId(store.id);
      }
      queryClient.invalidateQueries({ queryKey: ['stores'] });
    },
    onError: (error) => {
      addToast({ type: 'error', message: getErrorMessage(error) });
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: StorePayload }) => updateStore(id, payload),
    onSuccess: () => {
      addToast({ type: 'success', message: 'Store updated.' });
      setEditingStore(null);
      queryClient.invalidateQueries({ queryKey: ['stores'] });
    },
    onError: (error) => {
      addToast({ type: 'error', message: getErrorMessage(error) });
    },
  });

  const deactivateMutation = useMutation({
    mutationFn: async (store: Store) => {
      if (store.is_active) {
        await deactivateStore(store.id);
      } else {
        await updateStore(store.id, {
          name: store.name,
          code: store.code,
          address: store.address ?? undefined,
          phone: store.phone ?? undefined,
          is_active: true,
        });
      }
    },
    onSuccess: (_, store) => {
      const wasActive = store.is_active;
      addToast({
        type: 'success',
        message: wasActive ? 'Store deactivated.' : 'Store reactivated.',
      });
      if (wasActive && currentStoreId === store.id) {
        const existingStores =
          queryClient.getQueryData<Store[]>(['stores', 'manage']) ?? [];
        const fallback = existingStores.find(
          (candidate) => candidate.is_active && candidate.id !== store.id
        );
        setCurrentStoreId(fallback?.id ?? '');
      } else if (!wasActive && !currentStoreId) {
        setCurrentStoreId(store.id);
      }
      queryClient.invalidateQueries({ queryKey: ['stores'] });
    },
    onError: (error) => {
      addToast({ type: 'error', message: getErrorMessage(error) });
    },
  });

  const stores = useMemo(() => storesQuery.data ?? [], [storesQuery.data]);

  const filteredStores = useMemo(() => {
    if (!search.trim()) {
      return stores;
    }

    const term = search.trim().toLowerCase();
    return stores.filter((store) => {
      return (
        store.name.toLowerCase().includes(term) ||
        store.code.toLowerCase().includes(term) ||
        (store.address ?? '').toLowerCase().includes(term)
      );
    });
  }, [stores, search]);

  const columns: DataTableColumn<Store>[] = [
    { id: 'name', header: 'Name' },
    { id: 'code', header: 'Code' },
    {
      id: 'status',
      header: 'Status',
      render: (store) => (
        <span
          className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ${
            store.is_active ? 'bg-green-100 text-green-800' : 'bg-slate-200 text-slate-600'
          }`}
        >
          {store.is_active ? 'Active' : 'Inactive'}
        </span>
      ),
    },
    {
      id: 'address',
      header: 'Address',
      render: (store) => store.address ?? '-',
    },
    {
      id: 'phone',
      header: 'Phone',
      render: (store) => store.phone ?? '-',
    },
    {
      id: 'actions',
      header: 'Actions',
      render: (store) => (
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" onClick={() => setEditingStore(store)}>
            Edit
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={() => setManagingRegistersStore(store)}
          >
            Registers
          </Button>
          {user?.role === 'admin' ? (
            <Button
              size="sm"
              variant="outline"
              onClick={() => setManagingUsersStore(store)}
            >
              Users
            </Button>
          ) : null}
          <Button
            size="sm"
            variant="outline"
            onClick={() => deactivateMutation.mutate(store)}
            disabled={deactivateMutation.isPending}
          >
            {store.is_active ? 'Deactivate' : 'Activate'}
          </Button>
        </div>
      ),
    },
  ];

  const handleCreate = async (values: StoreFormValues) => {
    const payload: StorePayload = {
      name: values.name.trim(),
      code: values.code.trim().toUpperCase(),
      address: values.address.trim() || undefined,
      phone: values.phone.trim() || undefined,
    };

    await createMutation.mutateAsync(payload);
  };

  const handleUpdate = async (store: Store, values: StoreFormValues) => {
    const payload: StorePayload = {
      name: values.name.trim(),
      code: values.code.trim().toUpperCase(),
      address: values.address.trim() || undefined,
      phone: values.phone.trim() || undefined,
      is_active: values.is_active,
    };

    await updateMutation.mutateAsync({ id: store.id, payload });
  };

  return (
    <div className="min-h-screen bg-slate-50 pb-16">
      <div className="mx-auto flex max-w-6xl flex-col gap-6 px-4 pt-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-blue-600">Backoffice</p>
            <h1 className="text-3xl font-bold text-slate-900">Stores</h1>
            <p className="text-sm text-slate-500">
              Manage physical locations, assign cashiers and registers, and control availability.
            </p>
          </div>
          <div className="flex flex-col items-stretch gap-2 sm:items-end">
            <label className="flex items-center justify-end gap-2 text-xs font-semibold uppercase text-slate-500">
              <input
                type="checkbox"
                checked={showActiveOnly}
                onChange={(event) => setShowActiveOnly(event.target.checked)}
                className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
              />
              Active only
            </label>
            <Button onClick={() => setIsCreateOpen(true)}>New Store</Button>
          </div>
        </div>

        <Card>
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search by name, code, or address"
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50 sm:max-w-sm"
            />
          </div>

          <div className="mt-4">
            <DataTable
              columns={columns}
              data={filteredStores}
              emptyMessage={
                storesQuery.isLoading
                  ? 'Loading stores...'
                  : showActiveOnly
                    ? 'No active stores found.'
                    : 'No stores found.'
              }
            />
          </div>
        </Card>
      </div>

      <CreateStoreModal
        open={isCreateOpen}
        onClose={() => setIsCreateOpen(false)}
        onSubmit={handleCreate}
        isSubmitting={createMutation.isPending}
      />

      <EditStoreModal
        store={editingStore}
        onClose={() => setEditingStore(null)}
        onSubmit={handleUpdate}
        isSubmitting={updateMutation.isPending}
      />

      <ManageUsersModal
        store={managingUsersStore}
        onClose={() => setManagingUsersStore(null)}
        storeOptions={stores}
      />

      <ManageRegistersModal
        store={managingRegistersStore}
        onClose={() => setManagingRegistersStore(null)}
        storeOptions={stores}
      />
    </div>
  );
}

function CreateStoreModal({
  open,
  onClose,
  onSubmit,
  isSubmitting,
}: {
  open: boolean;
  onClose: () => void;
  onSubmit: (values: StoreFormValues) => Promise<void>;
  isSubmitting: boolean;
}) {
  const [values, setValues] = useState<StoreFormValues>({
    name: '',
    code: '',
    address: '',
    phone: '',
    is_active: true,
  });

  useEffect(() => {
    if (!open) {
      setValues({
        name: '',
        code: '',
        address: '',
        phone: '',
        is_active: true,
      });
    }
  }, [open]);

  const handleSubmit = async () => {
    await onSubmit(values);
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="New Store"
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
      <StoreForm values={values} onChange={setValues} />
    </Modal>
  );
}

function EditStoreModal({
  store,
  onClose,
  onSubmit,
  isSubmitting,
}: {
  store: Store | null;
  onClose: () => void;
  onSubmit: (store: Store, values: StoreFormValues) => Promise<void>;
  isSubmitting: boolean;
}) {
  const [values, setValues] = useState<StoreFormValues>({
    name: '',
    code: '',
    address: '',
    phone: '',
    is_active: true,
  });

  useEffect(() => {
    if (store) {
      setValues({
        name: store.name,
        code: store.code,
        address: store.address ?? '',
        phone: store.phone ?? '',
        is_active: store.is_active,
      });
    }
  }, [store]);

  if (!store) {
    return null;
  }

  const handleSubmit = async () => {
    await onSubmit(store, values);
  };

  return (
    <Modal
      open={Boolean(store)}
      onClose={onClose}
      title="Edit Store"
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
      <StoreForm values={values} onChange={setValues} includeStatus />
    </Modal>
  );
}

function ManageUsersModal({
  store,
  onClose,
  storeOptions,
}: {
  store: Store | null;
  onClose: () => void;
  storeOptions: Store[];
}) {
  const { addToast } = useToast();
  const queryClient = useQueryClient();
  const stores = storeOptions.filter((storeOption) => storeOption.is_active);

  const usersQuery = useQuery({
    queryKey: ['users', 'store-management'],
    queryFn: () => fetchUsers(),
    enabled: Boolean(store),
  });

  const [assignments, setAssignments] = useState<Record<string, string>>({});

  useEffect(() => {
    if (store && usersQuery.data) {
      const next: Record<string, string> = {};
      usersQuery.data.forEach((user) => {
        next[user.id] = user.store_id ?? '';
      });
      setAssignments(next);
    }
  }, [store, usersQuery.data]);

  if (!store) {
    return null;
  }

  const handleSave = async () => {
    const updates = (usersQuery.data ?? []).filter((user) => {
      const desired = assignments[user.id] ?? '';
      return desired !== (user.store_id ?? '');
    });

    if (updates.length === 0) {
      onClose();
      return;
    }

    try {
      await Promise.all(
        updates.map((user) =>
          updateUser(user.id, {
            store_id: assignments[user.id] ? assignments[user.id] : null,
          })
        )
      );
      addToast({ type: 'success', message: 'User assignments updated.' });
      void queryClient.invalidateQueries({ queryKey: ['users', 'store-management'] });
      onClose();
    } catch (error) {
      addToast({ type: 'error', message: getErrorMessage(error) });
    }
  };

  return (
    <Modal
      open={Boolean(store)}
      onClose={onClose}
      title={`Assign Users - ${store.name}`}
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
          <Button onClick={handleSave} disabled={usersQuery.isLoading}>
            Save Changes
          </Button>
        </>
      }
    >
      {usersQuery.isLoading ? (
        <div className="text-sm text-slate-500">Loading users...</div>
      ) : usersQuery.isError ? (
        <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">
          {getErrorMessage(usersQuery.error)}
        </div>
      ) : (
        <div className="space-y-3">
          {(usersQuery.data ?? []).map((user) => (
            <div key={user.id} className="flex items-center justify-between rounded-md border border-slate-200 bg-white px-3 py-2 text-sm">
              <div>
                <div className="font-medium text-slate-900">{user.name}</div>
                <div className="text-xs text-slate-500">
                  {user.email} - {user.role}
                </div>
              </div>
              <select
                value={assignments[user.id] ?? ''}
                onChange={(event) =>
                  setAssignments((prev) => ({
                    ...prev,
                    [user.id]: event.target.value,
                  }))
                }
                className="rounded-md border border-slate-300 px-2 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-300/50"
              >
                <option value="">No Store</option>
                {stores.map((storeOption) => (
                  <option key={storeOption.id} value={storeOption.id}>
                    {storeOption.name}
                  </option>
                ))}
              </select>
            </div>
          ))}
        </div>
      )}
    </Modal>
  );
}

function ManageRegistersModal({
  store,
  onClose,
  storeOptions,
}: {
  store: Store | null;
  onClose: () => void;
  storeOptions: Store[];
}) {
  const { addToast } = useToast();
  const queryClient = useQueryClient();

  const registersQuery = useQuery({
    queryKey: ['registers', 'store-management'],
    queryFn: () => fetchRegisters(true),
    enabled: Boolean(store),
  });

  const [assignments, setAssignments] = useState<Record<string, string>>({});

  useEffect(() => {
    if (store && registersQuery.data) {
      const next: Record<string, string> = {};
      registersQuery.data.forEach((register) => {
        next[register.id] = register.store_id ?? '';
      });
      setAssignments(next);
    }
  }, [store, registersQuery.data]);

  if (!store) {
    return null;
  }

  const handleSave = async () => {
    const updates = (registersQuery.data ?? []).filter((register) => {
      const desired = assignments[register.id] ?? '';
      return desired !== (register.store_id ?? '');
    });

    if (updates.length === 0) {
      onClose();
      return;
    }

    try {
      await Promise.all(
        updates.map((register) =>
          updateRegister(register.id, {
            store_id: assignments[register.id] ? assignments[register.id] : null,
          })
        )
      );
      addToast({ type: 'success', message: 'Register assignments updated.' });
      void queryClient.invalidateQueries({ queryKey: ['registers', 'store-management'] });
      void queryClient.invalidateQueries({ queryKey: ['registers'] });
      onClose();
    } catch (error) {
      addToast({ type: 'error', message: getErrorMessage(error) });
    }
  };

  const stores = storeOptions.filter((storeOption) => storeOption.is_active);

  return (
    <Modal
      open={Boolean(store)}
      onClose={onClose}
      title={`Assign Registers - ${store.name}`}
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
          <Button onClick={handleSave} disabled={registersQuery.isLoading}>
            Save Changes
          </Button>
        </>
      }
    >
      {registersQuery.isLoading ? (
        <div className="text-sm text-slate-500">Loading registers...</div>
      ) : registersQuery.isError ? (
        <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">
          {getErrorMessage(registersQuery.error)}
        </div>
      ) : (
        <div className="space-y-3">
          {(registersQuery.data ?? []).map((register) => (
            <div key={register.id} className="flex items-center justify-between rounded-md border border-slate-200 bg-white px-3 py-2 text-sm">
              <div>
                <div className="font-medium text-slate-900">{register.name}</div>
                <div className="text-xs text-slate-500">
                  {register.location ? `Location: ${register.location}` : 'No location'}
                </div>
              </div>
              <select
                value={assignments[register.id] ?? ''}
                onChange={(event) =>
                  setAssignments((prev) => ({
                    ...prev,
                    [register.id]: event.target.value,
                  }))
                }
                className="rounded-md border border-slate-300 px-2 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-300/50"
              >
                <option value="">No Store</option>
                {stores.map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.name}
                  </option>
                ))}
              </select>
            </div>
          ))}
        </div>
      )}
    </Modal>
  );
}


