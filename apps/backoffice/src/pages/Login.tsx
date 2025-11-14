import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Card } from '@atlas-pos/ui';
import { useAuth } from '../hooks/useAuth';
import { api, getErrorMessage } from '../lib/api';
import { useToast } from '../components/toastContext';

type TenantPreset = {
  slug: string;
  name: string;
};

export function LoginPage() {
  const navigate = useNavigate();
  const { login, singleTenant, tenancyReady, defaultTenantSlug } = useAuth();
  const { addToast } = useToast();

  const [tenant, setTenant] = useState('');
  const [tenantOptions, setTenantOptions] = useState<TenantPreset[]>([]);
  const [tenantOptionsLoading, setTenantOptionsLoading] = useState(false);
  const [tenantOptionsError, setTenantOptionsError] = useState<string | null>(null);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!singleTenant && tenancyReady && defaultTenantSlug && !tenant) {
      setTenant(defaultTenantSlug);
    }
  }, [singleTenant, tenancyReady, defaultTenantSlug, tenant]);

  useEffect(() => {
    if (singleTenant || !tenancyReady) {
      return;
    }

    let cancelled = false;
    setTenantOptionsLoading(true);
    setTenantOptionsError(null);

    api
      .get<TenantPreset[]>('/public/tenants/presets')
      .then(({ data }) => {
        if (cancelled) {
          return;
        }

        const presets = Array.isArray(data) ? data : [];
        setTenantOptions(presets);

        if (presets.length > 0) {
          setTenant((currentTenant) => {
            if (
              currentTenant &&
              presets.some((preset) => preset.slug === currentTenant)
            ) {
              return currentTenant;
            }

            return presets[0].slug;
          });
        }
      })
      .catch((error) => {
        if (cancelled) {
          return;
        }

        setTenantOptions([]);
        setTenantOptionsError(getErrorMessage(error));
      })
      .finally(() => {
        if (!cancelled) {
          setTenantOptionsLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [singleTenant, tenancyReady]);

  const tenantChoices =
    tenantOptions.length > 0
      ? tenantOptions
      : tenant
        ? [{ slug: tenant, name: tenant }]
        : [];

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    setSubmitting(true);

    try {
      const loggedInUser = await login({
        email,
        password,
        tenant: singleTenant ? undefined : tenant,
      });
      addToast({ type: 'success', message: 'Logged in successfully.' });
      if (loggedInUser?.role === 'cashier') {
        window.location.href = 'http://localhost:5173/pos';
        return;
      }

      if (loggedInUser?.role === 'admin' || loggedInUser?.role === 'manager') {
        navigate('/dashboard', { replace: true });
        return;
      }

      navigate('/dashboard', { replace: true });
    } catch (error) {
      addToast({ type: 'error', message: getErrorMessage(error) });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-10">
      <div className="w-full max-w-md space-y-6">
        <div className="text-center">
          <p className="text-sm font-semibold uppercase tracking-wide text-blue-600">Atlas POS</p>
          <h1 className="mt-2 text-3xl font-bold text-slate-900">Backoffice Login</h1>
          <p className="mt-1 text-sm text-slate-500">
            Access tenant configuration, provisioning, and system insights.
          </p>
        </div>

        <Card className="border border-slate-200 shadow-sm">
          <form className="space-y-4" onSubmit={handleSubmit}>
            {!tenancyReady ? (
              <div className="text-sm text-slate-500">Checking environment...</div>
            ) : null}
            {!singleTenant ? (
              <div>
                <label htmlFor="tenant" className="text-sm font-medium text-slate-700">
                  Tenant
                </label>
                <select
                  id="tenant"
                  name="tenant"
                  value={tenant}
                  onChange={(event) => setTenant(event.target.value)}
                  required
                  disabled={tenantChoices.length === 0}
                  className="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                >
                  <option value="" disabled>
                    {tenantOptionsLoading ? 'Loading tenants...' : 'Select a tenant'}
                  </option>
                  {tenantChoices.map((option) => (
                    <option key={option.slug} value={option.slug}>
                      {option.name} ({option.slug})
                    </option>
                  ))}
                </select>
                {tenantOptionsError ? (
                  <p className="mt-2 text-xs text-rose-600">{tenantOptionsError}</p>
                ) : null}
                <p className="mt-2 text-xs text-slate-500">
                  Submitting slug:{' '}
                  <span className="font-mono text-slate-700">{tenant || 'Not selected'}</span>
                </p>
              </div>
            ) : null}
            <div>
              <label htmlFor="email" className="text-sm font-medium text-slate-700">
                Email
              </label>
              <input
                id="email"
                name="email"
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                required
                className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                placeholder="admin@example.com"
              />
            </div>

            <div>
              <label htmlFor="password" className="text-sm font-medium text-slate-700">
                Password
              </label>
              <input
                id="password"
                name="password"
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
                className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                placeholder="********"
              />
            </div>

            <Button
              type="submit"
              className="w-full"
              disabled={submitting || !tenancyReady || (!singleTenant && !tenant)}
            >
              {submitting ? 'Signing in...' : 'Login'}
            </Button>
          </form>
        </Card>
      </div>
    </div>
  );
}


