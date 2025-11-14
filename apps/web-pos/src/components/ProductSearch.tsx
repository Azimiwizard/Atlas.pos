import type { KeyboardEvent } from 'react';
import {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useMemo,
  useRef,
  useState,
} from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Card } from '@atlas-pos/ui';
import { api } from '../lib/api';
import { useStore } from '../hooks/useStore';

type VariantFromApi = {
  id: string;
  sku: string | null;
  price: string;
  name: string | null;
};

type CategoryTag = {
  id: string;
  name: string;
};

type TaxTag = {
  id: string;
  name: string;
  rate: string;
  inclusive: boolean;
  is_active?: boolean;
};

type ProductResult = {
  id: string;
  title: string;
  barcode: string | null;
  first_variant_price: string | null;
  variants: VariantFromApi[];
  categories: CategoryTag[];
  taxes: TaxTag[];
};

type ProductsResponse = {
  data: ProductResult[];
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type SelectableVariant = {
  id: string;
  productId: string;
  productTitle: string;
  price: number;
  sku: string | null;
  name: string | null;
  barcode: string | null;
  categories: CategoryTag[];
  taxes: Array<{
    id: string;
    name: string;
    rate: number;
    inclusive: boolean;
  }>;
  stockOnHand?: number;
};

export type ProductSearchHandle = {
  focus: () => void;
  reset: () => void;
  getResults: () => SelectableVariant[];
};

type ProductSearchProps = {
  onSelectVariant: (variant: SelectableVariant) => void;
  categoryId?: string;
};

export const ProductSearch = forwardRef<ProductSearchHandle, ProductSearchProps>(
  ({ onSelectVariant, categoryId }, ref) => {
    const { currentStore, currentStoreId, loading: storeLoading } = useStore();
    const storeId = currentStore?.id ?? currentStoreId ?? null;

    const inputRef = useRef<HTMLInputElement | null>(null);
    const listRef = useRef<HTMLUListElement | null>(null);
    const [queryText, setQueryText] = useState('');
    const [debounced, setDebounced] = useState('');
    const [highlightIndex, setHighlightIndex] = useState<number | null>(null);
    const [shouldFocusList, setShouldFocusList] = useState(false);

    const focusInput = useCallback(() => {
      inputRef.current?.focus();
      inputRef.current?.select();
    }, []);

    const resetSearch = useCallback(() => {
      setQueryText('');
      setDebounced('');
      setHighlightIndex(null);
    }, []);

    useImperativeHandle(
      ref,
      () => ({
        focus: focusInput,
        reset: resetSearch,
        getResults: () => resultsRef.current,
      }),
      [focusInput, resetSearch]
    );

    useEffect(() => {
      const handle = window.setTimeout(() => setDebounced(queryText.trim()), 250);
      return () => window.clearTimeout(handle);
    }, [queryText]);

    const productsQuery = useQuery<ProductsResponse, Error>({
      queryKey: ['products-search', storeId ?? 'none', debounced, categoryId ?? 'all'],
      queryFn: async () => {
        const search = debounced === '' ? undefined : debounced;
        const { data } = await api.get<ProductsResponse>('/products', {
          params: {
            search,
            per_page: 25,
            category_id: categoryId || undefined,
            store_id: storeId || undefined,
          },
        });
        return data;
      },
      placeholderData: keepPreviousData,
      enabled: storeId !== null,
    });

    const results = useMemo<SelectableVariant[]>(() => {
      const products = productsQuery.data?.data ?? [];

      return products
        .map((product) => {
          const variant = product.variants[0];
          if (!variant) {
            return null;
          }

          const priceString = variant.price ?? product.first_variant_price ?? '0';
          const price = parseFloat(priceString);

          if (Number.isNaN(price)) {
            return null;
          }

          // Extract stock information from the API response
          // Note: This assumes the API includes stock data, otherwise it will be undefined
          const stockOnHand = (product as any).stock_on_hand ?? (variant as any).stock_on_hand ?? 0;

          return {
            id: variant.id,
            productId: product.id,
            productTitle: product.title,
            price,
            sku: variant.sku,
            name: variant.name,
            barcode: product.barcode,
            categories: product.categories ?? [],
            taxes: (product.taxes ?? []).map((tax) => ({
              id: tax.id,
              name: tax.name,
              rate: Number.parseFloat(tax.rate),
              inclusive: Boolean(tax.inclusive),
            })),
            stockOnHand: stockOnHand > 0 ? stockOnHand : undefined,
          };
        })
        .filter(Boolean) as SelectableVariant[];
    }, [productsQuery.data]);

    const resultsRef = useRef<SelectableVariant[]>(results);
    useEffect(() => {
      resultsRef.current = results;
    }, [results]);

    useEffect(() => {
      setHighlightIndex(null);
    }, [results]);

    useEffect(() => {
      if (!shouldFocusList || highlightIndex === null) {
        return;
      }

      const button = listRef.current?.querySelector<HTMLButtonElement>(
        `[data-index="${highlightIndex}"]`
      );
      if (button) {
        button.focus();
      }
      setShouldFocusList(false);
    }, [shouldFocusList, highlightIndex]);

    const handleSelect = useCallback(
      (variant: SelectableVariant) => {
        onSelectVariant(variant);
        resetSearch();
        focusInput();
      },
      [focusInput, onSelectVariant, resetSearch]
    );

    const handleEnter = useCallback(() => {
      if (results.length === 0) {
        return;
      }

      if (results.length === 1) {
        handleSelect(results[0]);
        return;
      }

      setHighlightIndex(0);
      setShouldFocusList(true);
    }, [handleSelect, results]);

    const handleInputKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        handleEnter();
      } else if (event.key === 'ArrowDown' && results.length > 0) {
        event.preventDefault();
        setHighlightIndex((prev) => {
          if (prev === null) {
            return 0;
          }
          return Math.min(prev + 1, results.length - 1);
        });
        setShouldFocusList(true);
      }
    };

    const handleListKeyDown = (event: KeyboardEvent<HTMLUListElement>) => {
      if (results.length === 0) {
        return;
      }

      if (event.key === 'ArrowDown') {
        event.preventDefault();
        setHighlightIndex((prev) => {
          const next = prev === null ? 0 : Math.min(prev + 1, results.length - 1);
          return next;
        });
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        setHighlightIndex((prev) => {
          const next = prev === null ? results.length - 1 : Math.max(prev - 1, 0);
          return next;
        });
      } else if (event.key === 'Enter' && highlightIndex !== null) {
        event.preventDefault();
        const highlighted = results[highlightIndex];
        if (highlighted) {
          handleSelect(highlighted);
        }
      } else if (event.key === 'Escape') {
        focusInput();
      }
    };

    const isInitialLoading = productsQuery.isLoading && !productsQuery.data;

    return (
      <Card
        heading="Search Catalog"
        description="Browse or search to add items to the cart."
      >
        <div className="space-y-4">
          <input
            ref={inputRef}
            type="search"
            value={queryText}
            onChange={(event) => setQueryText(event.target.value)}
            onKeyDown={handleInputKeyDown}
            placeholder="Search products or scan barcode..."
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
          />

          {storeId === null ? (
            <div className="rounded-lg border border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">
              {storeLoading ? 'Loading stores...' : 'Select a store to view inventory.'}
            </div>
          ) : productsQuery.isError ? (
            <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-6 text-sm text-red-600">
              {productsQuery.error.message}
            </div>
          ) : isInitialLoading ? (
            <div className="flex items-center justify-center py-10">
              <div className="flex flex-col items-center gap-3 text-sm text-slate-500">
                <div className="h-10 w-10 animate-spin rounded-full border-4 border-blue-500 border-t-transparent" />
                <span>Loading catalog...</span>
              </div>
            </div>
          ) : results.length === 0 ? (
            <div className="rounded-lg border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-500">
              <p>No products available yet.</p>
              <p className="mt-2">
                <a
                  href="http://127.0.0.1:5174/products"
                  target="_blank"
                  rel="noreferrer"
                  className="text-blue-600 underline"
                >
                  Open Backoffice to add products
                </a>
              </p>
            </div>
          ) : (
            <ul
              ref={listRef}
              tabIndex={-1}
              onKeyDown={handleListKeyDown}
              className="space-y-2 outline-none"
            >
              {results.map((variant, index) => {
                const highlighted = highlightIndex === index;
                return (
                  <li
                    key={variant.id}
                    className={`rounded-lg border border-slate-200 bg-white shadow-sm transition focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-200 ${highlighted ? 'border-blue-400 ring-2 ring-blue-200' : ''
                      }`}
                  >
                    <button
                      type="button"
                      data-index={index}
                      onClick={() => handleSelect(variant)}
                      onFocus={() => setHighlightIndex(index)}
                      className="flex w-full items-center justify-between rounded-lg px-3 py-3 text-left text-sm text-slate-700 focus:outline-none"
                    >
                      <div>
                        <p className="text-sm font-medium text-slate-900">
                          {variant.productTitle}
                          {variant.name ? ` - ${variant.name}` : ''}
                        </p>
                        <p className="text-xs text-slate-500">
                          SKU: {variant.sku ?? 'N/A'}
                          {variant.barcode ? ` - Barcode: ${variant.barcode}` : ''}
                        </p>
                        <p className="text-xs text-slate-500">
                          In stock: {variant.stockOnHand ?? 'N/A'}
                        </p>
                        <p className="text-xs font-semibold text-slate-700">
                          ${variant.price.toFixed(2)}
                        </p>
                      </div>
                      <span className="inline-flex items-center rounded-md border border-slate-300 px-3 py-1 text-sm font-medium text-slate-700">
                        Add
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </Card>
    );
  }
);

ProductSearch.displayName = 'ProductSearch';
