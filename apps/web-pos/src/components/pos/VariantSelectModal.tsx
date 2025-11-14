import type { ProductListItem, ProductListVariant } from '../../features/products/api';

type VariantSelectModalProps = {
  product: ProductListItem | null;
  open: boolean;
  onSelect: (product: ProductListItem, variant: ProductListVariant) => void;
  onClose: () => void;
};

export function VariantSelectModal({ product, open, onSelect, onClose }: VariantSelectModalProps) {
  if (!open || !product) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/50 px-4">
      <div className="w-full max-w-lg rounded-3xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] p-6 shadow-2xl">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h3 className="text-lg font-semibold text-[color:var(--pos-text)]">
              {product.title}
            </h3>
            <p className="text-xs text-[color:var(--pos-text-muted)]">
              Select a variant to add to the cart.
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-full border border-[color:var(--pos-border)] px-3 py-1 text-xs font-semibold text-[color:var(--pos-text-muted)] hover:text-[color:var(--pos-text)]"
          >
            Close
          </button>
        </div>
        <div className="mt-6 grid gap-3">
          {product.variants.map((variant) => (
            <button
              key={variant.id}
              type="button"
              onClick={() => onSelect(product, variant)}
              className="flex items-center justify-between rounded-2xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface-muted)] px-4 py-3 text-sm font-medium text-[color:var(--pos-text)] transition hover:border-[color:var(--pos-accent)] hover:bg-[color:var(--pos-surface)]"
            >
              <div>
                <p>{variant.name ?? 'Standard'}</p>
                {variant.sku ? (
                  <p className="text-xs text-[color:var(--pos-text-muted)]">SKU: {variant.sku}</p>
                ) : null}
              </div>
              <div className="text-base font-semibold text-[color:var(--pos-text)]">
                ${variant.price.toFixed(2)}
              </div>
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
