import { useMemo } from 'react';
import { useStore } from '../hooks/useStore';
import { useAuth } from '../hooks/useAuth';

type StoreSwitcherProps = {
  className?: string;
};

export function StoreSwitcher({ className }: StoreSwitcherProps) {
  const { user } = useAuth();
  const { stores, currentStore, currentStoreId, setCurrentStoreId, loading } = useStore();

  const canSwitch = useMemo(() => {
    if (!user) {
      return false;
    }

    return user.role === 'admin' || user.role === 'manager';
  }, [user]);

  if (!user) {
    return null;
  }

  const label = currentStore?.name ?? (stores[0]?.name ?? 'Store');

  if (!canSwitch) {
    return (
      <div className={className}>
        <span className="text-xs font-semibold uppercase text-slate-500">Store</span>
        <div className="text-sm font-medium text-slate-900">{label}</div>
      </div>
    );
  }

  if (loading && stores.length === 0) {
    return (
      <div className={className}>
        <span className="text-xs font-semibold uppercase text-slate-500">Store</span>
        <div className="text-xs text-slate-500">Loading stores…</div>
      </div>
    );
  }

  if (stores.length === 0) {
    return (
      <div className={className}>
        <span className="text-xs font-semibold uppercase text-slate-500">Store</span>
        <div className="text-xs text-slate-500">
          No stores found yet. Create one from the Stores page.
        </div>
      </div>
    );
  }

  return (
    <div className={className}>
      <label className="text-xs font-semibold uppercase text-slate-500" htmlFor="store-switcher">
        Store
      </label>
      <select
        id="store-switcher"
        value={currentStoreId ?? ''}
        onChange={(event) => setCurrentStoreId(event.target.value)}
        disabled={loading || stores.length === 0}
        className="mt-1 rounded-md border border-slate-300 px-2 py-1 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-300/50"
      >
        {stores.length === 0 ? (
          <option value="">No active stores</option>
        ) : (
          stores.map((store) => (
            <option key={store.id} value={store.id}>
              {store.name}
            </option>
          ))
        )}
      </select>
    </div>
  );
}
