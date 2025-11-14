import type { ProductListItem, ProductListVariant } from '../../features/products/api';

type ProductCardProps = {
  product: ProductListItem;
  promotionLabel?: string | null;
  onQuickAdd: (product: ProductListItem, variant: ProductListVariant) => void;
  onSelectVariants?: (product: ProductListItem) => void;
};

const FALLBACK_IMAGE =
  'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=400&q=80';

const formatAvailable = (value: number) =>
  Number.isInteger(value) ? value.toString() : value.toFixed(1);

export function ProductCard({
  product,
  promotionLabel,
  onQuickAdd,
  onSelectVariants,
}: ProductCardProps) {
  const primaryVariant = product.variants[0];

  if (!primaryVariant) {
    return null;
  }

  const imageUrl =
    product.image_url ??
    `https://images.unsplash.com/seed/${encodeURIComponent(product.id)}/420x420`;

  const hasMultipleVariants = product.variants.length > 1;
  const available =
    typeof primaryVariant.stock_on_hand === 'number'
      ? Math.max(0, primaryVariant.stock_on_hand)
      : null;
  const isOutOfStock = available !== null && available <= 0;
  const stockLabel =
    available === null
      ? null
      : available <= 0
        ? 'Out of stock'
        : `${formatAvailable(available)} in stock`;

  const handlePrimaryAction = () => {
    if (isOutOfStock) {
      return;
    }

    if (hasMultipleVariants && onSelectVariants) {
      onSelectVariants(product);
      return;
    }
    onQuickAdd(product, primaryVariant);
  };

  return (
    <div className="group flex h-full flex-col overflow-hidden rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] shadow-sm transition hover:-translate-y-1 hover:shadow-[var(--pos-shadow)]">
      <div className="relative aspect-square overflow-hidden">
        <img
          src={imageUrl || FALLBACK_IMAGE}
          onError={(event) => {
            event.currentTarget.src = FALLBACK_IMAGE;
          }}
          loading="lazy"
          alt={product.title}
          className="h-full w-full object-cover transition duration-300 group-hover:scale-105"
        />
        <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/30 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100" />
        {promotionLabel ? (
          <div className="absolute left-3 top-3 rounded-full bg-[color:var(--pos-accent)] px-3 py-1 text-xs font-semibold text-[color:var(--pos-accent-contrast)] shadow">
            {promotionLabel}
          </div>
        ) : null}
        {isOutOfStock ? (
          <div className="absolute right-3 top-3 rounded-full bg-red-500 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-white shadow">
            Sold out
          </div>
        ) : null}
      </div>
      <div className="flex flex-1 flex-col gap-2 p-4">
        <div className="flex-1">
          <p className="text-sm font-semibold text-[color:var(--pos-text)]">{product.title}</p>
          {hasMultipleVariants ? (
            <p className="text-xs text-[color:var(--pos-text-muted)]">
              {product.variants.length} variants available
            </p>
          ) : primaryVariant.name ? (
            <p className="text-xs text-[color:var(--pos-text-muted)]">{primaryVariant.name}</p>
          ) : null}
        </div>
        <div className="flex items-center justify-between text-sm">
          <span className="text-base font-semibold text-[color:var(--pos-text)]">
            ${primaryVariant.price.toFixed(2)}
          </span>
          {stockLabel ? (
            <span className="rounded-full bg-[color:var(--pos-surface-muted)] px-2 py-1 text-[10px] font-medium uppercase tracking-wide text-[color:var(--pos-text-muted)]">
              {stockLabel}
            </span>
          ) : null}
        </div>
      </div>
      <div className="flex items-center justify-between gap-3 px-4 pb-4">
        <button
          type="button"
          onClick={handlePrimaryAction}
          disabled={isOutOfStock}
          className="flex-1 rounded-full bg-[color:var(--pos-accent)] px-4 py-2 text-xs font-semibold uppercase tracking-wide text-[color:var(--pos-accent-contrast)] shadow transition hover:-translate-y-[1px] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:translate-y-0"
        >
          {isOutOfStock ? 'Unavailable' : hasMultipleVariants ? 'Select variant' : 'Add to cart'}
        </button>
        {!hasMultipleVariants ? null : (
          <button
            type="button"
            onClick={() => onQuickAdd(product, primaryVariant)}
            disabled={isOutOfStock}
            className="rounded-full border border-[color:var(--pos-border)] px-3 py-2 text-xs font-medium text-[color:var(--pos-text)] transition hover:bg-[color:var(--pos-surface-muted)] disabled:cursor-not-allowed disabled:opacity-60"
          >
            Quick add
          </button>
        )}
      </div>
    </div>
  );
}
