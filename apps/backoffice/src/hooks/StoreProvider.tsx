import {
  createElement,
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { useQuery } from '@tanstack/react-query';
import { listStores, type Store } from '../features/stores/api';
import { getStoredStoreId, setStoreId } from '../lib/api';
import { useAuth } from './useAuth';
import { StoreContext, type StoreContextValue } from './useStore';

export function StoreProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const [currentStoreId, setCurrentStoreIdState] = useState<string | null>(() => getStoredStoreId());

  const storesQuery = useQuery({
    queryKey: ['stores', 'all'],
    queryFn: () => listStores({ isActive: true }),
    enabled: Boolean(user),
    staleTime: 60_000,
  });

  const allStores = useMemo<Store[]>(
    () => (storesQuery.data ? [...storesQuery.data] : []),
    [storesQuery.data]
  );

  const activeStores = useMemo(
    () => allStores.filter((store) => store.is_active),
    [allStores]
  );

  useEffect(() => {
    if (!user) {
      setCurrentStoreIdState(null);
      setStoreId(null);
      return;
    }

    if (user.role === 'cashier') {
      const cashierStoreId = user.store_id ?? null;
      setCurrentStoreIdState(cashierStoreId);
      setStoreId(cashierStoreId);
      return;
    }

    if (currentStoreId) {
      const exists = activeStores.some((store) => store.id === currentStoreId);
      if (!exists) {
        const fallback = activeStores[0]?.id ?? null;
        setCurrentStoreIdState(fallback);
        setStoreId(fallback);
      }
      return;
    }

    const stored = getStoredStoreId();
    if (stored && activeStores.some((store) => store.id === stored)) {
      setCurrentStoreIdState(stored);
      setStoreId(stored);
      return;
    }

    const fallback = user.store_id ?? activeStores[0]?.id ?? null;
    setCurrentStoreIdState(fallback);
    setStoreId(fallback);
  }, [user, activeStores, currentStoreId]);

  const setCurrentStoreId = useCallback((storeId: string | null) => {
    const normalized = storeId || null;
    setCurrentStoreIdState(normalized);
    setStoreId(normalized);
  }, []);

  const currentStore = useMemo(
    () => activeStores.find((store) => store.id === currentStoreId) ?? null,
    [activeStores, currentStoreId]
  );

  const value = useMemo<StoreContextValue>(
    () => ({
      stores: activeStores,
      currentStore,
      currentStoreId,
      setCurrentStoreId,
      loading: storesQuery.isLoading,
    }),
    [activeStores, currentStore, currentStoreId, setCurrentStoreId, storesQuery.isLoading]
  );

  return createElement(StoreContext.Provider, { value }, children);
}

