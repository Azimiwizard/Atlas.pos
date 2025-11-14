type FloatingCartButtonProps = {
  count: number;
  total: number;
  onClick: () => void;
};

export function FloatingCartButton({ count, total, onClick }: FloatingCartButtonProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="fixed bottom-6 right-6 flex items-center gap-3 rounded-full bg-[color:var(--pos-accent)] px-5 py-3 text-sm font-semibold text-[color:var(--pos-accent-contrast)] shadow-[var(--pos-shadow)] transition hover:translate-y-[-2px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-[color:var(--pos-accent)]"
    >
      <span className="flex h-6 w-6 items-center justify-center rounded-full bg-[color:var(--pos-accent-contrast)] text-[color:var(--pos-accent-emphasis)]">
        {count}
      </span>
      <span>{total.toLocaleString(undefined, { style: 'currency', currency: 'USD' })}</span>
    </button>
  );
}
