import { useTheme } from '../../hooks/useTheme';

export function ThemeToggle() {
  const { theme, toggleTheme } = useTheme();

  return (
    <button
      type="button"
      onClick={toggleTheme}
      className="rounded-full border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] px-3 py-2 text-xs font-medium text-[color:var(--pos-text)] transition hover:shadow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--pos-accent)]"
      aria-label="Toggle theme"
    >
      {theme === 'light' ? '🌞 Light' : '🌙 Dark'}
    </button>
  );
}
