import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card } from '@atlas-pos/ui';
import {
  adjustStock,
  listStockLevels,
  type Product,
  type StockAdjustPayload,
  type StockLevel,
} from '../../../features/products/api';
import { listStores, type Store } from '../../../features/stores/api';
import { useToast } from '../../../components/toastContext';

const REASONS: StockAdjustPayload['reason'][] = [
  'manual_adjustment',
  'initial_stock',
  'correction',
  'wastage',
];

type ProductStockTabProps = {
  productId: string;
  product?: Product;
};

type GroupedVariant = {
  variantId: string;
  name: string | null;
  rows: StockLevel[];
};

type ProductVariant = NonNullable<Product['variants']>[number];

const formatQty = (value: number): string => {
  if (!Number.isFinite(value)) {
    return '0';
  }

  return Number(value).toFixed(3).replace(/\.000$/, '');
};

export function ProductStockTab({ productId, product }: ProductStockTabProps) {
  const { addToast } = useToast();
  const queryClient = useQueryClient();
  const formId = `stock-adjust-${productId}`;

  const stocksQuery = useQuery({
    queryKey: ['bo', 'products', productId, 'stocks'],
    queryFn: () => listStockLevels({ product_id: productId }),
  });

  const storesQuery = useQuery({
    queryKey: ['bo', 'stores', 'active'],
    queryFn: () => listStores({ isActive: true }),
  });

  const mutation = useMutation({
    mutationFn: adjustStock,
    onSuccess: (data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['bo', 'products', productId, 'stocks'] });

      const delta = Number(variables.qty_delta ?? 0);
      const formattedDelta = `${delta > 0 ? '+' : ''}${formatQty(delta)}`;
      const newQty = formatQty(Number(data.qty_on_hand ?? 0));

      addToast({
        type: 'success',
        message: `Stock updated: ${formattedDelta} -> ${newQty} on hand`,
      });
    },
    onError: (error: unknown) => {
      const message = error instanceof Error ? error.message : 'Failed to adjust stock.';
      addToast({ type: 'error', message });
    },
  });

  const grouped = useMemo<GroupedVariant[]>(() => {
    const byVariant = new Map<string, GroupedVariant>();

    (stocksQuery.data ?? []).forEach((level) => {
      const key = level.variant_id ?? 'default';
      if (!byVariant.has(key)) {
        byVariant.set(key, {
          variantId: key,
          name: level.variant_name ?? null,
          rows: [],
        });
      }

      byVariant.get(key)!.rows.push(level);
    });

    return Array.from(byVariant.values()).map((entry) => ({
      ...entry,
      rows: entry.rows.sort((a, b) => {
        const nameA = a.store_name ?? '';
        const nameB = b.store_name ?? '';
        return nameA.localeCompare(nameB);
      }),
    }));
  }, [stocksQuery.data]);

  const hasExistingRows = grouped.length > 0;
  const stores = storesQuery.data ?? [];
  const selectableVariants = (product?.variants ?? []).filter((variant) => variant.track_stock !== false);
  const requiresVariantSelection = selectableVariants.length > 1;
  const isSimpleProduct = (product?.variants?.length ?? 0) <= 1;
  const simpleVariantRows = grouped[0]?.rows ?? [];
  const scrollToForm = () => {
    const target = document.getElementById(formId);
    if (target) {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  };

  return (
    <div className="space-y-4">
      <div>
        <h3 className="text-lg font-semibold text-slate-900">Stock levels</h3>
        <p className="text-sm text-slate-500">
          Adjust quantities for each store. Positive numbers increase stock; negative numbers decrease it.
        </p>
      </div>

      <AdjustStockForm
        formId={formId}
        productId={productId}
        product={product}
        stores={stores}
        selectableVariants={selectableVariants}
        requiresVariant={requiresVariantSelection}
        isLoadingStores={storesQuery.isLoading}
        mutate={mutation.mutateAsync}
        isMutating={mutation.isPending}
      />

      {stocksQuery.isLoading ? (
        <Card className="flex items-center justify-between gap-4 px-4 py-3 text-sm text-slate-500">
          <span>Loading stock levels...</span>
        </Card>
      ) : !hasExistingRows ? (
        <Card className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm text-slate-500">
          <span>No stock data yet. Create an adjustment to seed initial quantities.</span>
          <Button type="button" size="sm" onClick={scrollToForm}>
            Add initial stock
          </Button>
        </Card>
      ) : isSimpleProduct ? (
        <Card>
          <StockTable
            productId={productId}
            rows={simpleVariantRows}
            isMutating={mutation.isPending}
            mutate={mutation.mutateAsync}
            getRowKey={(level) => `simple:${level.store_id ?? level.id ?? ''}`}
          />
        </Card>
      ) : (
        grouped.map((group) => (
          <Card key={group.variantId} className="space-y-4">
            <header className="flex flex-col gap-1 border-b border-slate-100 pb-3 text-sm text-slate-600 sm:flex-row sm:items-baseline sm:justify-between">
              <div>
                <p className="text-base font-semibold text-slate-900">
                  {group.name || 'Variant'}
                </p>
              </div>
            </header>

            <StockTable
              productId={productId}
              rows={group.rows}
              isMutating={mutation.isPending}
              mutate={mutation.mutateAsync}
              getRowKey={(level) => `${group.variantId}:${level.store_id ?? level.id ?? ''}`}
            />
          </Card>
        ))
      )}

      {stocksQuery.isLoading ? (
        <Card className="flex items-center justify-between gap-4 px-4 py-3 text-sm text-slate-500">
          <span>Loading stock levels...</span>
        </Card>
      ) : hasExistingRows ? (
        grouped.map((group) => (
          <Card key={group.variantId} className="space-y-4">
            <header className="flex flex-col gap-1 border-b border-slate-100 pb-3 text-sm text-slate-600 sm:flex-row sm:items-baseline sm:justify-between">
              <div>
                <p className="text-base font-semibold text-slate-900">
                  {group.name || 'Default Variant'}
                </p>
              </div>
            </header>

            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Store</th>
                    <th className="px-4 py-3">Current Stock</th>
                    <th className="px-4 py-3">Adjust (+/-)</th>
                    <th className="px-4 py-3">Reason</th>
                    <th className="px-4 py-3">Note (optional)</th>
                    <th className="px-4 py-3 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {group.rows.map((level) => (
                    <StockRow
                      key={`${group.variantId}:${level.store_id}`}
                      productId={productId}
                      level={level}
                      isMutating={mutation.isPending}
                      mutate={mutation.mutateAsync}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        ))
      ) : (
        <Card className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm text-slate-500">
          <span>No stock data yet. Create an adjustment to seed initial quantities.</span>
          <Button
            type="button"
            size="sm"
            onClick={() => {
              const target = document.getElementById(formId);
              if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
              }
            }}
          >
            Add initial stock
          </Button>
        </Card>
      )}
    </div>
  );
}

type AdjustStockFormProps = {
  formId: string;
  productId: string;
  product?: Product;
  stores: Store[];
  selectableVariants: ProductVariant[];
  requiresVariant: boolean;
  isLoadingStores: boolean;
  isMutating: boolean;
  mutate: (payload: StockAdjustPayload) => Promise<StockLevel>;
};

function AdjustStockForm({
  formId,
  productId,
  product,
  stores,
  selectableVariants,
  requiresVariant,
  isLoadingStores,
  isMutating,
  mutate,
}: AdjustStockFormProps) {
  const { addToast } = useToast();
  const [storeId, setStoreId] = useState<string>('');
  const [variantId, setVariantId] = useState<string>('');
  const [quantity, setQuantity] = useState<string>('');
  const [reason, setReason] = useState<StockAdjustPayload['reason']>('initial_stock');
  const [note, setNote] = useState<string>('');

  useEffect(() => {
    if (!storeId && stores.length > 0) {
      setStoreId(stores[0].id);
    }
  }, [storeId, stores]);

  useEffect(() => {
    if (!requiresVariant) {
      setVariantId('');
      return;
    }

    if (!variantId && selectableVariants.length > 0) {
      setVariantId(selectableVariants[0].id ?? '');
    }
  }, [requiresVariant, selectableVariants, variantId]);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (!storeId) {
      addToast({ type: 'info', message: 'Choose a store before adjusting stock.' });
      return;
    }

    const qtyValue = Number.parseFloat(quantity);

    if (!Number.isFinite(qtyValue) || qtyValue === 0) {
      addToast({ type: 'info', message: 'Enter a non-zero quantity adjustment.' });
      return;
    }

    if (requiresVariant && !variantId) {
      addToast({ type: 'info', message: 'Select a variant to adjust.' });
      return;
    }

    const payload: StockAdjustPayload = {
      product_id: productId,
      store_id: storeId,
      qty_delta: qtyValue,
      reason,
      note: note.trim() !== '' ? note.trim() : undefined,
    };

    if (variantId) {
      payload.variant_id = variantId;
    }

    try {
      await mutate(payload);
      setQuantity('');
      setNote('');
    } catch {
      // The mutation hook already surfaces the error toast.
    }
  };

  const disableSubmit = isMutating || !storeId || (requiresVariant && !variantId);

  const availableStores = stores.map((store) => ({
    id: store.id,
    name: store.name,
    code: store.code,
  }));

  return (
    <Card className="space-y-4">
      <div>
        <h4 className="text-base font-semibold text-slate-900">Adjust stock</h4>
        <p className="text-sm text-slate-500">
          Seed initial quantities or make manual corrections across stores.
        </p>
      </div>

      <form id={formId} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" onSubmit={handleSubmit}>
        <div className="sm:col-span-1">
          <label className="block text-sm font-medium text-slate-700">Store</label>
          <select
            value={storeId}
            onChange={(event) => setStoreId(event.target.value)}
            disabled={isLoadingStores || isMutating || availableStores.length === 0}
            className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          >
            {availableStores.length === 0 ? (
              <option value="">No stores available</option>
            ) : (
              availableStores.map((store) => (
                <option key={store.id} value={store.id}>
                  {store.name}
                  {store.code ? ` (${store.code})` : ''}
                </option>
              ))
            )}
          </select>
        </div>

        {requiresVariant ? (
          <div className="sm:col-span-1">
            <label className="block text-sm font-medium text-slate-700">Variant</label>
            <select
              value={variantId}
              onChange={(event) => setVariantId(event.target.value)}
              disabled={isMutating}
              className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
            >
              <option value="">Select a variant</option>
              {selectableVariants.map((variant) => (
                <option key={variant.id} value={variant.id}>
                  {variant.name ?? 'Variant'}
                  {variant.sku ? ` (${variant.sku})` : ''}
                </option>
              ))}
            </select>
          </div>
        ) : null}

        <div className="sm:col-span-1">
          <label className="block text-sm font-medium text-slate-700">Quantity</label>
          <input
            type="number"
            step="0.001"
            value={quantity}
            onChange={(event) => setQuantity(event.target.value)}
            placeholder="0.000"
            disabled={isMutating}
            className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          />
        </div>

        <div className="sm:col-span-1">
          <label className="block text-sm font-medium text-slate-700">Reason</label>
          <select
            value={reason}
            onChange={(event) => setReason(event.target.value as StockAdjustPayload['reason'])}
            disabled={isMutating}
            className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          >
            {REASONS.map((value) => (
              <option key={value} value={value}>
                {value.replace(/_/g, ' ')}
              </option>
            ))}
          </select>
        </div>

        <div className="sm:col-span-2 lg:col-span-3">
          <label className="block text-sm font-medium text-slate-700">Note (optional)</label>
          <input
            type="text"
            value={note}
            onChange={(event) => setNote(event.target.value)}
            placeholder="Add context for this adjustment"
            disabled={isMutating}
            className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          />
        </div>

        <div className="flex items-end sm:col-span-2 lg:col-span-1">
          <Button type="submit" disabled={disableSubmit} className="w-full sm:w-auto">
            {isMutating ? 'Saving...' : 'Apply adjustment'}
          </Button>
        </div>
      </form>

      {product && product.track_stock === false ? (
        <p className="text-xs text-amber-600">
          Stock tracking is disabled for this product. Adjustments will not affect inventory until it
          is enabled.
        </p>
      ) : null}
    </Card>
  );
}

type StockRowProps = {
  productId: string;
  level: StockLevel;
  isMutating: boolean;
  mutate: (payload: StockAdjustPayload) => Promise<StockLevel>;
};

type StockTableProps = {
  productId: string;
  rows: StockLevel[];
  isMutating: boolean;
  mutate: (payload: StockAdjustPayload) => Promise<StockLevel>;
  getRowKey?: (level: StockLevel) => string;
};

function StockTable({ productId, rows, isMutating, mutate, getRowKey }: StockTableProps) {
  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
          <tr>
            <th className="px-4 py-3">Store</th>
            <th className="px-4 py-3">Current Stock</th>
            <th className="px-4 py-3">Adjust (+/-)</th>
            <th className="px-4 py-3">Reason</th>
            <th className="px-4 py-3">Note (optional)</th>
            <th className="px-4 py-3 text-right">Action</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((level) => (
            <StockRow
              key={getRowKey ? getRowKey(level) : `${level.variant_id ?? 'default'}:${level.store_id ?? level.id ?? ''}`}
              productId={productId}
              level={level}
              isMutating={isMutating}
              mutate={mutate}
            />
          ))}
        </tbody>
      </table>
    </div>
  );
}

function StockRow({ productId, level, isMutating, mutate }: StockRowProps) {
  const { addToast } = useToast();
  const [qtyDelta, setQtyDelta] = useState<string>('');
  const [reason, setReason] = useState<StockAdjustPayload['reason']>('manual_adjustment');
  const [note, setNote] = useState<string>('');

  const current = Number(level.qty_on_hand ?? 0);
  const variantId = level.variant_id ?? undefined;

  const handleSubmit = async () => {
    const parsed = Number.parseFloat(qtyDelta);

    if (!Number.isFinite(parsed) || parsed === 0) {
      addToast({ type: 'info', message: 'Enter a non-zero quantity adjustment.' });
      return;
    }

    try {
      await mutate({
        product_id: productId,
        variant_id: variantId,
        store_id: level.store_id ?? '',
        qty_delta: parsed,
        reason,
        note: note.trim() !== '' ? note.trim() : undefined,
      });

      setQtyDelta('');
      setNote('');
    } catch {
      // Error toast already handled by the mutation.
    }
  };

  return (
    <tr>
      <td className="px-4 py-3 text-sm font-medium text-slate-700">
        <div>
          {level.store_name ?? 'Unknown store'}
          {level.store_code ? (
            <span className="ml-2 text-xs uppercase text-slate-400">({level.store_code})</span>
          ) : null}
        </div>
      </td>
      <td className="px-4 py-3 text-sm text-slate-600">{formatQty(current)}</td>
      <td className="px-4 py-3">
        <input
          type="number"
          step="0.001"
          value={qtyDelta}
          onChange={(event) => setQtyDelta(event.target.value)}
          className="w-28 rounded-md border border-slate-300 px-2 py-1 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          placeholder="+/-"
          disabled={isMutating}
        />
      </td>
      <td className="px-4 py-3">
        <select
          value={reason}
          onChange={(event) => setReason(event.target.value as StockAdjustPayload['reason'])}
          className="rounded-md border border-slate-300 px-2 py-1 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          disabled={isMutating}
        >
          {REASONS.map((value) => (
            <option key={value} value={value}>
              {value.replace(/_/g, ' ')}
            </option>
          ))}
        </select>
      </td>
      <td className="px-4 py-3">
        <input
          type="text"
          value={note}
          onChange={(event) => setNote(event.target.value)}
          placeholder="Optional note"
          disabled={isMutating}
          className="w-full rounded-md border border-slate-300 px-2 py-1 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
        />
      </td>
      <td className="px-4 py-3 text-right">
        <Button
          type="button"
          variant="secondary"
          size="sm"
          disabled={isMutating}
          onClick={() => {
            void handleSubmit();
          }}
        >
          {isMutating ? 'Saving...' : 'Apply'}
        </Button>
      </td>
    </tr>
  );
}


