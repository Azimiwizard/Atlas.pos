import type { ReactNode } from 'react';
import { ThemeToggle } from './ThemeToggle';

type PosHeaderProps = {
  title: string;
  subtitle?: string;
  searchPlaceholder?: string;
  searchValue?: string;
  onSearchChange?: (value: string) => void;
  startAdornment?: ReactNode;
  endAdornment?: ReactNode;
};

export function PosHeader({
  title,
  subtitle,
  searchPlaceholder,
  searchValue,
  onSearchChange,
  startAdornment,
  endAdornment,
}: PosHeaderProps) {
  return (
    <header className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-[color:var(--pos-text)]">{title}</h1>
          {subtitle ? (
            <p className="text-sm text-[color:var(--pos-text-muted)]">{subtitle}</p>
          ) : null}
        </div>
        <div className="flex items-center gap-3">
          {endAdornment}
          <ThemeToggle />
        </div>
      </div>
      {startAdornment}
      {onSearchChange ? (
        <input
          type="search"
          value={searchValue}
          onChange={(event) => onSearchChange(event.target.value)}
          placeholder={searchPlaceholder ?? 'Search products...'}
          className="w-full rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] px-5 py-3 text-sm text-[color:var(--pos-text)] shadow-sm outline-none ring-[color:var(--pos-accent)] focus-visible:ring-2"
        />
      ) : null}
    </header>
  );
}
