import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, DataTable, type DataTableColumn } from '@atlas-pos/ui';
import {
  deleteProduct,
  listProducts,
  updateProduct,
  type PaginatedProducts,
  type Product,
} from '../../features/products/api';
import { listCategories, type CategoryOption } from '../../features/categories/api';
import { useToast } from '../../components/toastContext';
import { LoadingScreen } from '../../components/LoadingScreen';
import { Money } from '../../components/Money';
import { Modal } from '../../components/Modal';

const SORT_OPTIONS = [
  { label: 'Newest first', value: 'created_at:desc' },
  { label: 'Oldest first', value: 'created_at:asc' },
  { label: 'Title A-Z', value: 'title:asc' },
  { label: 'Title Z-A', value: 'title:desc' },
  { label: 'Price low to high', value: 'price:asc' },
  { label: 'Price high to low', value: 'price:desc' },
];

const PAGE_SIZE = 15;

export function ProductsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { addToast } = useToast();
  const [productToDelete, setProductToDelete] = useState<Product | null>(null);
  const [searchValue, setSearchValue] = useState(searchParams.get('q') ?? '');

  const page = Number(searchParams.get('page') ?? '1');
  const q = searchParams.get('q') ?? '';
  const categoryId = searchParams.get('category_id') ?? '';
  const isActiveParam = searchParams.get('is_active') ?? '';
  const sort = searchParams.get('sort') ?? 'created_at:desc';
  const isActiveFilter =
    isActiveParam === 'true' ? 'true' : isActiveParam === 'false' ? 'false' : undefined;

  useEffect(() => {
    setSearchValue(q);
  }, [q]);

  const applyParams = (updates: Record<string, string | null>) => {
    const next = new URLSearchParams(searchParams);

    Object.entries(updates).forEach(([key, value]) => {
      if (value === null || value === '') {
        next.delete(key);
      } else {
        next.set(key, value);
      }
    });

    if (!next.has('page')) {
      next.set('page', '1');
    }

    setSearchParams(next);
  };

  const productsQuery = useQuery<PaginatedProducts, Error>({
    queryKey: ['bo', 'products', { q, categoryId, isActive: isActiveFilter ?? null, page, sort }],
    queryFn: () =>
      listProducts({
        q: q || undefined,
        category_id: categoryId || undefined,
        is_active: isActiveFilter,
        page,
        sort,
        per_page: PAGE_SIZE,
      }),
    placeholderData: keepPreviousData,
  });

  const categoriesQuery = useQuery({
    queryKey: ['bo', 'categories'],
    queryFn: listCategories,
  });

  const toggleActiveMutation = useMutation({
    mutationFn: (input: { id: string; is_active: boolean }) =>
      updateProduct(input.id, { is_active: input.is_active }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bo', 'products'] });
    },
    onError: (error: unknown) => {
      addToast({ type: 'error', message: error instanceof Error ? error.message : 'Failed to update product.' });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: deleteProduct,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bo', 'products'] });
      addToast({ type: 'success', message: 'Product deleted.' });
    },
    onError: (error: unknown) => {
      addToast({ type: 'error', message: error instanceof Error ? error.message : 'Failed to delete product.' });
    },
    onSettled: () => {
      setProductToDelete(null);
    },
  });

  const { mutate: toggleProductActive, isPending: toggleProductPending } = toggleActiveMutation;
  const { mutate: deleteProductMutate, isPending: deleteProductPending } = deleteMutation;

  const handleSearchSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    applyParams({ q: searchValue, page: '1' });
  };

  const handleCategoryChange = (event: React.ChangeEvent<HTMLSelectElement>) => {
    applyParams({ category_id: event.target.value || null, page: '1' });
  };

  const handleSortChange = (event: React.ChangeEvent<HTMLSelectElement>) => {
    applyParams({ sort: event.target.value });
  };

  const handleActiveToggle = (event: React.ChangeEvent<HTMLInputElement>) => {
    applyParams({ is_active: event.target.checked ? 'true' : null, page: '1' });
  };

  const goToPage = (nextPage: number) => {
    applyParams({ page: String(nextPage) });
  };

  const categories = categoriesQuery.data ?? [];
  const products = productsQuery.data?.data ?? [];
  const meta = productsQuery.data?.meta;
  const productsError =
    productsQuery.isError && productsQuery.error instanceof Error
      ? productsQuery.error.message
      : productsQuery.isError
        ? 'Failed to load products.'
        : null;

  const columns: Array<DataTableColumn<Product>> = useMemo(
    () => [
      {
        id: 'image',
        header: 'Image',
        className: 'w-[80px]',
        render: (product) => (
          <div className="h-12 w-12 overflow-hidden rounded-md bg-slate-100">
            {product.image_url ? (
              <img src={product.image_url} alt={product.title} className="h-full w-full object-cover" />
            ) : (
              <div className="flex h-full items-center justify-center text-xs font-semibold text-slate-400">
                {product.title.charAt(0).toUpperCase()}
              </div>
            )}
          </div>
        ),
      },
      {
        id: 'title',
        header: 'Title',
        render: (product) => (
          <div>
            <p className="font-medium text-slate-800">{product.title}</p>
            <p className="text-xs text-slate-500">{product.barcode ?? 'No barcode'}</p>
          </div>
        ),
      },
      {
        id: 'price',
        header: 'Price',
        className: 'whitespace-nowrap',
        render: (product) => <Money value={product.price} />,
      },
      {
        id: 'category',
        header: 'Category',
        render: (product) => product.category_name ?? '—',
      },
      {
        id: 'sku',
        header: 'SKU',
        render: (product) => product.sku ?? '—',
      },
      {
        id: 'active',
        header: 'Active',
        render: (product) => (
          <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-medium ${
              product.is_active
                ? 'bg-green-100 text-green-700'
                : 'bg-slate-200 text-slate-600'
            }`}
          >
            <span className="inline-block h-2 w-2 rounded-full bg-current" />
            {product.is_active ? 'Active' : 'Inactive'}
          </span>
        ),
      },
      {
        id: 'actions',
        header: 'Actions',
        className: 'w-[240px]',
        render: (product) => (
          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => navigate(`/products/${product.id}/edit`)}
            >
              Edit
            </Button>
            <Button
              variant="secondary"
              size="sm"
              onClick={() =>
                toggleProductActive({ id: product.id, is_active: !product.is_active })
              }
              disabled={toggleProductPending}
            >
              {product.is_active ? 'Deactivate' : 'Activate'}
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="text-red-600"
              onClick={() => setProductToDelete(product)}
              disabled={deleteProductPending && productToDelete?.id === product.id}
            >
              Delete
            </Button>
          </div>
        ),
      },
    ],
    [navigate, toggleProductPending, deleteProductPending, productToDelete, toggleProductActive]
  );

  if (productsQuery.isLoading) {
    return <LoadingScreen />;
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Products</h1>
          <p className="text-sm text-slate-500">Manage your product catalog, pricing, and availability.</p>
        </div>
        <Button onClick={() => navigate('/products/new')}>New Product</Button>
      </div>

      <Card>
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <form onSubmit={handleSearchSubmit} className="flex flex-1 flex-col gap-2 sm:flex-row sm:items-center">
            <div className="flex flex-1 flex-col">
              <label htmlFor="product-search" className="text-xs font-medium uppercase text-slate-500">
                Search
              </label>
              <div className="mt-1 flex rounded-md border border-slate-300 shadow-sm focus-within:ring-2 focus-within:ring-blue-500/50">
                <input
                  id="product-search"
                  className="w-full rounded-l-md px-3 py-2 text-sm focus:outline-none"
                  value={searchValue}
                  onChange={(event) => setSearchValue(event.target.value)}
                  placeholder="Search by title, SKU, or barcode"
                />
                <Button type="submit" variant="secondary" size="sm" className="rounded-l-none border-l">
                  Search
                </Button>
              </div>
            </div>

            <div className="flex flex-1 flex-col">
              <label htmlFor="product-category-filter" className="text-xs font-medium uppercase text-slate-500">
                Category
              </label>
              <select
                id="product-category-filter"
                value={categoryId}
                onChange={handleCategoryChange}
                className="mt-1 rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
              >
                <option value="">All categories</option>
                {categories.map((category: CategoryOption) => (
                  <option key={category.id} value={category.id}>
                    {category.name}
                  </option>
                ))}
              </select>
            </div>
          </form>

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <label className="inline-flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={isActiveParam === 'true'}
                onChange={handleActiveToggle}
                className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
              />
              Active only
            </label>

            <div>
              <label htmlFor="product-sort" className="block text-xs font-medium uppercase text-slate-500">
                Sort by
              </label>
              <select
                id="product-sort"
                value={sort}
                onChange={handleSortChange}
                className="mt-1 rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
              >
                {SORT_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </div>
          </div>
        </div>
      </Card>

      <Card>
        {productsError ? (
          <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {productsError}
          </div>
        ) : (
          <>
            <DataTable data={products} columns={columns} emptyMessage="No products found." />

            {meta ? (
              <div className="mt-4 flex items-center justify-between text-sm text-slate-500">
                <div>
                  Page {meta.current_page} of {meta.last_page} - {meta.total} products
                </div>
                <div className="flex gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={meta.current_page <= 1}
                    onClick={() => goToPage(Math.max(1, meta.current_page - 1))}
                  >
                    Previous
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => goToPage(Math.min(meta.last_page, meta.current_page + 1))}
                  >
                    Next
                  </Button>
                </div>
              </div>
            ) : null}
          </>
        )}
      </Card>

      <Modal
        open={Boolean(productToDelete)}
        onClose={() => setProductToDelete(null)}
        title="Delete product"
        footer={
          <div className="flex items-center justify-end gap-2">
            <Button variant="outline" onClick={() => setProductToDelete(null)} disabled={deleteProductPending}>
              Cancel
            </Button>
            <Button
              variant="primary"
              className="bg-red-600 hover:bg-red-700"
              onClick={() => productToDelete && deleteProductMutate(productToDelete.id)}
              disabled={deleteProductPending}
            >
              {deleteProductPending ? 'Deleting...' : 'Delete'}
            </Button>
          </div>
        }
      >
        <p className="text-sm text-slate-600">
          This action permanently removes <strong>{productToDelete?.title}</strong> and its variants. This cannot be
          undone.
        </p>
      </Modal>
    </div>
  );
}












