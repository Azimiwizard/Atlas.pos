import axios from 'axios';
import { getDesktopApi } from './desktop';

export const API_BASE_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api';
export const API_ROOT_URL = API_BASE_URL.replace(/\/api\/?$/, '');

const TENANT_STORAGE_KEY = 'atlas_pos_tenant';
const USER_STORAGE_KEY = 'atlas_pos_user';
const TOKEN_STORAGE_KEY = 'atlas_pos_token';
const STORE_STORAGE_KEY = 'atlas_pos_store';
const SINGLE_TENANT_ENV = import.meta.env.VITE_SINGLE_TENANT;
const DEFAULT_TENANT_ENV = import.meta.env.VITE_DEFAULT_TENANT_SLUG ?? null;

type TenancyState = {
  resolved: boolean;
  singleTenant: boolean;
  defaultTenantSlug: string | null;
};

const explicitSingleTenant =
  typeof SINGLE_TENANT_ENV === 'string' && SINGLE_TENANT_ENV !== ''
    ? SINGLE_TENANT_ENV === 'true'
    : null;

let tenancyState: TenancyState = {
  resolved: explicitSingleTenant !== null,
  singleTenant: explicitSingleTenant ?? false,
  defaultTenantSlug: explicitSingleTenant === true ? DEFAULT_TENANT_ENV ?? 'default' : null,
};

let tenancyPromise: Promise<TenancyState> | null = null;

export function getTenancyState(): TenancyState {
  return tenancyState;
}

export function isSingleTenantMode(): boolean {
  return tenancyState.singleTenant;
}

export function getDefaultTenantSlug(): string | null {
  return tenancyState.defaultTenantSlug;
}

function updateTenancyState(next: TenancyState): TenancyState {
  tenancyState = next;
  return tenancyState;
}

async function fetchTenancyFromHealth(): Promise<TenancyState> {
  try {
    const { data } = await axios.get(`${API_BASE_URL}/health`, { withCredentials: true });
    const singleTenant = Boolean(data?.singleTenant);
    const slug = typeof data?.tenantSlug === 'string' ? data.tenantSlug : null;
    const next: TenancyState = {
      resolved: true,
      singleTenant,
      defaultTenantSlug: singleTenant ? slug ?? DEFAULT_TENANT_ENV ?? 'default' : null,
    };
    updateTenancyState(next);

    if (next.singleTenant) {
      setTenant(null);
    } else {
      const tenant = getStoredTenant();
      if (tenant) {
        applyTenantHeader(tenant);
      }
    }

    return next;
  } catch {
    const fallback: TenancyState = {
      resolved: true,
      singleTenant: false,
      defaultTenantSlug: null,
    };

    return updateTenancyState(fallback);
  }
}

export async function ensureTenancyState(): Promise<TenancyState> {
  if (tenancyState.resolved) {
    return tenancyState;
  }

  if (!tenancyPromise) {
    tenancyPromise = fetchTenancyFromHealth().finally(() => {
      tenancyPromise = null;
    });
  }

  return tenancyPromise;
}

export const api = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
});

const desktopApi = getDesktopApi();
let desktopTokenCache: string | null = null;

if (desktopApi) {
  desktopApi.secureStore
    .getToken()
    .then((token) => {
      desktopTokenCache = token;
      if (token) {
        api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
      }
    })
    .catch(() => {
      desktopTokenCache = null;
    });
}

function applyTenantHeader(tenant: string | null): void {
  if (tenancyState.singleTenant) {
    delete api.defaults.headers.common['X-Tenant'];
    return;
  }

  if (tenant) {
    api.defaults.headers.common['X-Tenant'] = tenant;
  } else {
    delete api.defaults.headers.common['X-Tenant'];
  }
}

function clearLocalTenant(): void {
  if (typeof window !== 'undefined') {
    localStorage.removeItem(TENANT_STORAGE_KEY);
  }
}

function handleUnauthorized(): void {
  if (typeof window !== 'undefined') {
    localStorage.removeItem(USER_STORAGE_KEY);
    clearLocalTenant();
    setToken(null);
    setStoreId(null);
    delete api.defaults.headers.common['X-Tenant'];
    if (window.location.pathname !== '/login') {
      window.location.href = '/login';
    }
  }
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error?.response?.status === 401) {
      handleUnauthorized();
    }
    return Promise.reject(error);
  }
);

export function getStoredTenant(): string | null {
  if (tenancyState.singleTenant || typeof window === 'undefined') {
    return null;
  }

  return localStorage.getItem(TENANT_STORAGE_KEY);
}

export function setTenant(tenant: string | null): void {
  if (tenancyState.singleTenant) {
    clearLocalTenant();
    applyTenantHeader(null);
    return;
  }

  if (desktopApi) {
    applyTenantHeader(tenant);
    return;
  }

  if (typeof window === 'undefined') {
    return;
  }

  if (tenant) {
    localStorage.setItem(TENANT_STORAGE_KEY, tenant);
    applyTenantHeader(tenant);
  } else {
    clearLocalTenant();
    applyTenantHeader(null);
  }
}

const initialTenant = getStoredTenant();
if (initialTenant) {
  applyTenantHeader(initialTenant);
}

export function getStoredToken(): string | null {
  if (desktopApi) {
    return desktopTokenCache;
  }

  if (typeof window === 'undefined') {
    return null;
  }

  return localStorage.getItem(TOKEN_STORAGE_KEY);
}

export function setToken(token: string | null): void {
  if (desktopApi) {
    desktopTokenCache = token;
    if (token) {
      api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    } else {
      delete api.defaults.headers.common['Authorization'];
    }
    void desktopApi.secureStore.setToken(token);
    return;
  }

  if (typeof window === 'undefined') {
    return;
  }

  if (token) {
    localStorage.setItem(TOKEN_STORAGE_KEY, token);
    api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
  } else {
    localStorage.removeItem(TOKEN_STORAGE_KEY);
    delete api.defaults.headers.common['Authorization'];
  }
}

const initialToken = getStoredToken();
if (initialToken) {
  api.defaults.headers.common['Authorization'] = `Bearer ${initialToken}`;
}

export function getStoredStoreId(): string | null {
  if (typeof window === 'undefined') {
    return null;
  }

  return localStorage.getItem(STORE_STORAGE_KEY);
}

export function setStoreId(storeId: string | null): void {
  if (typeof window === 'undefined') {
    return;
  }

  if (storeId) {
    localStorage.setItem(STORE_STORAGE_KEY, storeId);
    api.defaults.headers.common['X-Store'] = storeId;
  } else {
    localStorage.removeItem(STORE_STORAGE_KEY);
    delete api.defaults.headers.common['X-Store'];
  }
}

const storedStore = getStoredStoreId();
if (storedStore) {
  api.defaults.headers.common['X-Store'] = storedStore;
}

export async function fetchCsrfCookie(): Promise<void> {
  await api.get('/sanctum/csrf-cookie', { baseURL: API_ROOT_URL });
}

export type ApiError = {
  message: string;
  status?: number;
  errors?: Record<string, string[]>;
};

export function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    return (
      error.response?.data?.message ??
      error.message ??
      'Something went wrong. Please try again.'
    );
  }

  return (error as Error)?.message ?? 'Unexpected error. Please try again.';
}
