import axios from 'axios';
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
import {
  api,
  ensureTenancyState,
  fetchCsrfCookie,
  getDefaultTenantSlug,
  getErrorMessage,
  getTenancyState,
  setStoreId,
  setTenant,
  setToken,
} from '../lib/api';

type Tenant = {
  id: string;
  name: string;
  slug: string;
};

export type AuthUser = {
  id: string;
  name: string;
  email: string;
  role: string;
  tenant: Tenant;
  store_id?: string | null;
  store?: {
    id: string;
    name: string;
    code: string;
    is_active: boolean;
  } | null;
};

type LoginArgs = {
  email: string;
  password: string;
  tenant?: string;
};

type AuthContextValue = {
  user: AuthUser | null;
  loading: boolean;
  error: string | null;
  tenancyReady: boolean;
  singleTenant: boolean;
  defaultTenantSlug: string | null;
  login: (credentials: LoginArgs) => Promise<AuthUser | null>;
  logout: () => Promise<void>;
  fetchUser: () => Promise<AuthUser | null>;
};

const AuthContext = createContext<AuthContextValue | undefined>(undefined);
const USER_STORAGE_KEY = 'atlas_pos_backoffice_user';

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(() => {
    if (typeof window === 'undefined') {
      return null;
    }

    const stored = localStorage.getItem(USER_STORAGE_KEY);
    if (!stored) {
      return null;
    }

    try {
      return JSON.parse(stored) as AuthUser;
    } catch {
      localStorage.removeItem(USER_STORAGE_KEY);
      return null;
    }
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [tenancy, setTenancy] = useState(getTenancyState);

  const fetchUser = useCallback(async () => {
    setLoading(true);
    const mode = await ensureTenancyState();
    setTenancy(mode);

    try {
      const { data } = await api.get<{ user: AuthUser }>('/me');
      setUser(data.user);
      setError(null);
      setStoreId(data.user.store_id ?? null);
      if (typeof window !== 'undefined') {
        localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(data.user));
      }
      return data.user;
    } catch (err) {
      if (axios.isAxiosError(err) && err.response?.status === 401) {
        setUser(null);
        setError(null);
        setStoreId(null);
        if (typeof window !== 'undefined') {
          localStorage.removeItem(USER_STORAGE_KEY);
        }
        return null;
      }

      setUser(null);
      setStoreId(null);
      setError(getErrorMessage(err));
      if (typeof window !== 'undefined') {
        localStorage.removeItem(USER_STORAGE_KEY);
      }
      return null;
    } finally {
      setLoading(false);
    }
  }, []);

  const login = useCallback(
    async ({ email, password, tenant }: LoginArgs) => {
      const mode = await ensureTenancyState();
      setTenancy(mode);

      try {
        const payload: Record<string, string> = {
          email,
          password,
        };

        if (!mode.singleTenant) {
          if (tenant && tenant.trim()) {
            payload.tenant = tenant.trim();
            setTenant(payload.tenant);
          } else {
            throw new Error('Tenant is required.');
          }
        } else {
          setTenant(null);
        }

        await fetchCsrfCookie();

        const { data } = await api.post<{
          token: string;
          tenant: Tenant;
          user: AuthUser;
        }>('/auth/login', payload);

        setToken(data.token);
        if (!mode.singleTenant) {
          setTenant(data.tenant.slug);
        }

        setUser(data.user);
        setError(null);
        setStoreId(data.user.store_id ?? null);
        if (typeof window !== 'undefined') {
          localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(data.user));
        }

        return data.user;
      } catch (err) {
        const message = getErrorMessage(err);
        setError(message);
        setUser(null);
        setToken(null);
        setStoreId(null);
        if (typeof window !== 'undefined') {
          localStorage.removeItem(USER_STORAGE_KEY);
        }
        throw err;
      }
    },
    []
  );

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } finally {
      setTenant(null);
      setToken(null);
      setStoreId(null);
      setUser(null);
      if (typeof window !== 'undefined') {
        localStorage.removeItem(USER_STORAGE_KEY);
      }
    }
  }, []);

  useEffect(() => {
    void fetchUser();
  }, [fetchUser]);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      loading,
      error,
      tenancyReady: tenancy.resolved,
      singleTenant: tenancy.singleTenant,
      defaultTenantSlug: tenancy.defaultTenantSlug ?? getDefaultTenantSlug(),
      login,
      logout,
      fetchUser,
    }),
    [user, loading, error, tenancy, login, logout, fetchUser]
  );

  return createElement(AuthContext.Provider, { value }, children);
}

export function useAuth() {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }

  return context;
}
