import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Card } from '@atlas-pos/ui';
import { useAuth } from '../hooks/useAuth';
import { getErrorMessage } from '../lib/api';
import { useToast } from '../components/ToastProvider';

export function LoginPage() {
  const navigate = useNavigate();
  const { login, singleTenant, tenancyReady, defaultTenantSlug } = useAuth();
  const { addToast } = useToast();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [tenant, setTenant] = useState('');
  const [tenantPrefilled, setTenantPrefilled] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!singleTenant && tenancyReady && defaultTenantSlug && !tenantPrefilled) {
      setTenant(defaultTenantSlug);
      setTenantPrefilled(true);
    }
  }, [singleTenant, tenancyReady, defaultTenantSlug, tenantPrefilled]);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    setSubmitting(true);

    try {
      await login({ email, password, tenant: singleTenant ? undefined : tenant });
      addToast({ type: 'success', message: 'Logged in successfully.' });
      navigate('/pos', { replace: true });
    } catch (error) {
      const message = getErrorMessage(error);
      addToast({ type: 'error', message });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-10">
      <div className="w-full max-w-md space-y-6">
        <div className="text-center">
          <p className="text-sm font-semibold uppercase tracking-wide text-blue-600">Atlas POS</p>
          <h1 className="mt-2 text-3xl font-bold text-slate-900">Welcome back</h1>
          <p className="mt-1 text-sm text-slate-500">
            Sign in to manage your point-of-sale experience.
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
                  Tenant Slug
                </label>
                <input
                  id="tenant"
                  name="tenant"
                  type="text"
                  value={tenant}
                  onChange={(event) => setTenant(event.target.value)}
                  required
                  className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                  placeholder={defaultTenantSlug ?? 'e.g. demo'}
                />
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

            <Button type="submit" className="w-full" disabled={submitting || !tenancyReady}>
              {submitting ? 'Signing in...' : 'Login'}
            </Button>
          </form>
        </Card>
      </div>
    </div>
  );
}
