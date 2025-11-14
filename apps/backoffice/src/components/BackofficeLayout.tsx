import { useMemo, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { Button } from '@atlas-pos/ui';
import { StoreSwitcher } from './StoreSwitcher';
import { useAuth } from '../hooks/useAuth';

const NAVIGATION = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Analytics', to: '/analytics' },
  { label: 'Products', to: '/products' },
  { label: 'Registers', to: '/registers' },
  { label: 'Shifts', to: '/shifts' },
  { label: 'Stores', to: '/stores' },
];

export function BackofficeLayout() {
  const { user, logout } = useAuth();
  const location = useLocation();
  const [isNavOpen, setIsNavOpen] = useState(false);

  const activeNav = useMemo(
    () => NAVIGATION.find((item) => location.pathname.startsWith(item.to)),
    [location.pathname]
  );

  const breadcrumb = activeNav?.label ?? 'Backoffice';

  const handleLogout = async () => {
    try {
      await logout();
    } catch (error) {
      console.error('Failed to logout', error);
    }
  };

  return (
    <div className="flex min-h-screen bg-slate-50">
      <aside
        className={`fixed inset-y-0 left-0 z-40 w-64 transform border-r border-slate-200 bg-white transition-transform duration-200 ease-out md:static md:translate-x-0 ${
          isNavOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'
        }`}
      >
        <div className="flex h-16 items-center border-b border-slate-200 px-6">
          <span className="text-base font-semibold text-slate-900">Atlas Backoffice</span>
        </div>
        <nav className="flex flex-1 flex-col gap-1 px-3 py-4">
          {NAVIGATION.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              className={({ isActive }) =>
                `rounded-lg px-3 py-2 text-sm font-medium transition ${
                  isActive
                    ? 'bg-blue-600 text-white shadow'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                }`
              }
              onClick={() => setIsNavOpen(false)}
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
        <div className="border-t border-slate-200 px-6 py-4 text-xs text-slate-500">
          <div className="font-semibold text-slate-600">{user?.name ?? 'Team member'}</div>
          <div>{user?.tenant?.name ?? 'Tenant'}</div>
        </div>
      </aside>

      <div className="flex flex-1 flex-col">
        <header className="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 md:px-6">
          <div className="flex items-center gap-3">
            <button
              type="button"
              className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:bg-slate-100 md:hidden"
              onClick={() => setIsNavOpen((prev) => !prev)}
              aria-label="Toggle navigation"
            >
              <svg
                className="h-5 w-5"
                viewBox="0 0 20 20"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
              >
                <path d="M3.25 6.25h13.5M3.25 10h13.5M3.25 13.75h13.5" strokeLinecap="round" />
              </svg>
            </button>
            <div>
              <p className="text-[11px] font-semibold uppercase tracking-wide text-blue-600">
                {user?.tenant?.name ?? 'Atlas POS'}
              </p>
              <span className="block text-sm font-semibold text-slate-700">{breadcrumb}</span>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <div className="hidden sm:flex sm:items-center sm:gap-2">
              <StoreSwitcher />
            </div>
            <Button variant="outline" size="sm" onClick={handleLogout}>
              Sign out
            </Button>
          </div>
        </header>

        <main className="flex-1 overflow-y-auto bg-slate-50">
          <div className="mx-auto w-full max-w-6xl px-4 py-6 md:px-6">
            <div className="mb-4 flex items-center justify-between gap-4 sm:hidden">
              <StoreSwitcher />
            </div>
            <Outlet />
          </div>
        </main>
      </div>

      {isNavOpen ? (
        <div
          className="fixed inset-0 z-30 bg-slate-900/30 backdrop-blur-sm md:hidden"
          onClick={() => setIsNavOpen(false)}
        />
      ) : null}
    </div>
  );
}
