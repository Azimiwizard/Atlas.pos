import {
  createContext,
  createElement,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react';
import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from './useAuth';
import { listStores, type Store } from '../features/stores/api';
import { getStoredStoreId, setStoreId } from '../lib/api';

type StoreContextValue = {
  stores: Store[];
  currentStore: Store | null;
  currentStoreId: string | null;
  setCurrentStoreId: (storeId: string) => void;
  loading: boolean;
};

const StoreContext = createContext<StoreContextValue | undefined>(undefined);

export function StoreProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const [currentStoreId, setCurrentStoreIdState] = useState<string | null>(null);

  const canManageStores = user?.role === 'admin' || user?.role === 'manager';

  const storesQuery = useQuery({
    queryKey: ['stores'],
    queryFn: () => listStores({ includeInactive: false }),
    enabled: Boolean(user && canManageStores),
    staleTime: 60_000,
  });

  const stores: Store[] = useMemo(() => {
    if (!user) {
      return [];
    }

    if (canManageStores) {
      return storesQuery.data ?? [];
    }

    return user.store ? [user.store] : [];
  }, [canManageStores, storesQuery.data, user]);

  useEffect(() => {
    if (!user) {
      setCurrentStoreIdState(null);
      setStoreId(null);
      return;
    }

    if (user.role === 'cashier') {
      const cashierStore = user.store?.id ?? null;
      setCurrentStoreIdState(cashierStore);
      setStoreId(cashierStore);
      return;
    }

    const stored = getStoredStoreId();
    if (stored) {
      setCurrentStoreIdState(stored);
      return;
    }

    if (user.store?.id) {
      setCurrentStoreIdState(user.store.id);
    }
  }, [user]);

  useEffect(() => {
    if (!user) {
      return;
    }

    if (!currentStoreId && stores.length > 0) {
      const fallbackStore =
        user.role === 'cashier'
          ? user.store?.id ?? stores[0].id
          : stores[0]?.id ?? null;

      if (fallbackStore) {
        setCurrentStoreIdState(fallbackStore);
      }
      return;
    }

    if (currentStoreId && stores.length > 0) {
      const exists = stores.some((store) => store.id === currentStoreId);
      if (!exists) {
        const fallback = stores[0]?.id ?? null;
        setCurrentStoreIdState(fallback);
      }
    }
  }, [currentStoreId, stores, user]);

  useEffect(() => {
    if (!user) {
      setStoreId(null);
      return;
    }

    setStoreId(currentStoreId);
  }, [currentStoreId, user]);

  const setCurrentStoreId = useCallback((storeId: string) => {
    setCurrentStoreIdState(storeId || null);
    setStoreId(storeId || null);
  }, []);

  const currentStore =
    stores.find((store) => store.id === currentStoreId) ?? null;

  const loading = canManageStores ? storesQuery.isLoading : false;

  const value = useMemo<StoreContextValue>(
    () => ({
      stores,
      currentStore,
      currentStoreId,
      setCurrentStoreId,
      loading,
    }),
    [stores, currentStore, currentStoreId, setCurrentStoreId, loading]
  );

  return createElement(StoreContext.Provider, { value }, children);
}

export function useStore(): StoreContextValue {
  const context = useContext(StoreContext);

  if (!context) {
    throw new Error('useStore must be used within a StoreProvider');
  }

  return context;
}
