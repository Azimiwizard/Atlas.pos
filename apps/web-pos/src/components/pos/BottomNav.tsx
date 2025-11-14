import { NavLink } from 'react-router-dom';

type NavItem = {
  to: string;
  label: string;
  icon?: React.ReactNode;
};

const NAV_ITEMS: NavItem[] = [
  { to: '/pos/sell', label: 'Sell' },
  { to: '/pos/cart', label: 'Cart' },
  { to: '/pos/profile', label: 'Profile' },
];

export function BottomNav() {
  return (
    <nav className="fixed inset-x-0 bottom-0 z-30 border-t border-[color:var(--pos-border)] bg-[color:var(--pos-surface)]/95 backdrop-blur">
      <div className="mx-auto flex max-w-5xl items-stretch justify-around px-2 py-2">
        {NAV_ITEMS.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            className={({ isActive }) =>
              `flex flex-1 flex-col items-center rounded-2xl px-3 py-2 text-xs font-semibold transition ${
                isActive
                  ? 'bg-[color:var(--pos-accent)] text-[color:var(--pos-accent-contrast)] shadow'
                  : 'text-[color:var(--pos-text-muted)] hover:bg-[color:var(--pos-surface-muted)] hover:text-[color:var(--pos-text)]'
              }`
            }
          >
            {item.icon}
            <span>{item.label}</span>
          </NavLink>
        ))}
      </div>
    </nav>
  );
}
