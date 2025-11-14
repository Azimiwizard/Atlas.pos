import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { Button, Card } from '@atlas-pos/ui';
import { ImageUploader } from '../../features/products/ImageUploader';
import {
  createProduct,
  getProduct,
  updateProduct,
  type Product,
  type ProductPayload,
} from '../../features/products/api';
import { listCategories, type CategoryOption } from '../../features/categories/api';
import { useToast } from '../../components/toastContext';
import { LoadingScreen } from '../../components/LoadingScreen';
import { useAuth } from '../../hooks/useAuth';
import { ProductStockTab } from './components/ProductStockTab';

type FormValues = {
  title: string;
  price: string;
  category_id: string;
  sku: string;
  barcode: string;
  tax_code: string;
  track_stock: boolean;
  is_active: boolean;
  image_url: string | null;
};

function productToForm(product: Product): FormValues {
  return {
    title: product.title,
    price: product.price != null ? String(product.price) : '0',
    category_id: product.category_id ?? '',
    sku: product.sku ?? '',
    barcode: product.barcode ?? '',
    tax_code: product.tax_code ?? '',
    track_stock: product.track_stock,
    is_active: product.is_active,
    image_url: product.image_url ?? null,
  };
}

function sanitizePayload(values: FormValues): ProductPayload {
  const priceNumber = Number(values.price);

  const payload: ProductPayload = {
    title: values.title.trim(),
    price: Number.isFinite(priceNumber) && priceNumber >= 0 ? priceNumber : 0,
    category_id: values.category_id ? values.category_id : null,
    sku: values.sku.trim() || null,
    barcode: values.barcode.trim() || null,
    tax_code: values.tax_code.trim() || null,
    track_stock: values.track_stock,
    is_active: values.is_active,
    image_url: values.image_url,
  };

  return payload;
}

export function ProductFormPage() {
  const { id } = useParams();
  const isEditing = Boolean(id);
  const navigate = useNavigate();
  const { addToast } = useToast();
  const { user } = useAuth();
  const [values, setValues] = useState<FormValues>({
    title: '',
    price: '0.00',
    category_id: '',
    sku: '',
    barcode: '',
    tax_code: '',
    track_stock: true,
    is_active: true,
    image_url: null,
  });

  const categoriesQuery = useQuery({
    queryKey: ['bo', 'categories'],
    queryFn: listCategories,
  });

  const productQuery = useQuery({
    queryKey: ['bo', 'products', id],
    queryFn: () => getProduct(id as string),
    enabled: isEditing,
  });

  useEffect(() => {
    if (productQuery.data) {
      setValues(productToForm(productQuery.data));
    }
  }, [productQuery.data]);

  const categoryOptions = useMemo(() => categoriesQuery.data ?? [], [categoriesQuery.data]);
  const canManageStock = isEditing && ['admin', 'manager'].includes(user?.role ?? '') && values.track_stock;
  const [activeTab, setActiveTab] = useState<'details' | 'media' | 'stock'>('details');

  useEffect(() => {
    if (!canManageStock && activeTab === 'stock') {
      setActiveTab('details');
    }
  }, [canManageStock, activeTab]);

  const createMutation = useMutation({
    mutationFn: createProduct,
    onSuccess: () => {
      addToast({ type: 'success', message: 'Product created.' });
      navigate('/products');
    },
    onError: (error: unknown) => {
      addToast({ type: 'error', message: error instanceof Error ? error.message : 'Failed to create product.' });
    },
  });

  const updateMutation = useMutation({
    mutationFn: (input: { id: string; payload: Partial<ProductPayload> }) =>
      updateProduct(input.id, input.payload),
    onSuccess: () => {
      addToast({ type: 'success', message: 'Product updated.' });
      navigate('/products');
    },
    onError: (error: unknown) => {
      addToast({ type: 'error', message: error instanceof Error ? error.message : 'Failed to update product.' });
    },
  });

  const isLoading = productQuery.isInitialLoading || (isEditing && productQuery.isLoading);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const payload = sanitizePayload(values);

    if (isEditing && id) {
      await updateMutation.mutateAsync({ id, payload });
    } else {
      await createMutation.mutateAsync(payload);
    }
  };

  const handleChange = <K extends keyof FormValues>(key: K, value: FormValues[K]) => {
    setValues((prev) => ({
      ...prev,
      [key]: value,
    }));
  };

  if (isLoading) {
    return <LoadingScreen />;
  }

  const submitting = createMutation.isPending || updateMutation.isPending;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">
            {isEditing ? 'Edit Product' : 'New Product'}
          </h1>
          <p className="text-sm text-slate-500">
            {isEditing
              ? 'Update product details, pricing, and availability.'
              : 'Create a product to make it available in your POS and online channels.'}
          </p>
        </div>
        <div className="flex gap-2">
          <Button type="button" variant="outline" onClick={() => navigate('/products')}>
            Cancel
          </Button>
          <Button type="submit" form="product-form" disabled={submitting}>
            {submitting ? 'Saving...' : 'Save Product'}
          </Button>
        </div>
      </div>

      <nav className="flex gap-2 border-b border-slate-200 text-sm font-medium text-slate-600">
        <button
          type="button"
          className={`rounded-t-md px-3 py-2 transition ${
            activeTab === 'details'
              ? 'bg-white text-slate-900 shadow-sm'
              : 'text-slate-500 hover:text-slate-700'
          }`}
          onClick={() => setActiveTab('details')}
        >
          Details
        </button>
        <button
          type="button"
          className={`rounded-t-md px-3 py-2 transition ${
            activeTab === 'media'
              ? 'bg-white text-slate-900 shadow-sm'
              : 'text-slate-500 hover:text-slate-700'
          }`}
          onClick={() => setActiveTab('media')}
        >
          Media
        </button>
        {canManageStock ? (
          <button
            type="button"
            className={`rounded-t-md px-3 py-2 transition ${
              activeTab === 'stock'
                ? 'bg-white text-slate-900 shadow-sm'
                : 'text-slate-500 hover:text-slate-700'
            }`}
            onClick={() => setActiveTab('stock')}
          >
            Stock
          </button>
        ) : null}
      </nav>

      <form id="product-form" className="space-y-6" onSubmit={handleSubmit}>
        <Card
          heading="Details"
          description="Basic information and pricing."
          className={activeTab === 'details' ? 'block' : 'hidden'}
        >
          <div className="grid gap-5 md:grid-cols-2">
            <div className="space-y-4">
              <div>
                <label htmlFor="product-title" className="block text-sm font-medium text-slate-700">
                  Title
                </label>
                <input
                  id="product-title"
                  name="title"
                  value={values.title}
                  onChange={(event) => handleChange('title', event.target.value)}
                  required
                  className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                  placeholder="Product name"
                />
              </div>

              <div>
                <label htmlFor="product-price" className="block text-sm font-medium text-slate-700">
                  Price
                </label>
                <input
                  id="product-price"
                  name="price"
                  type="number"
                  min={0}
                  step="0.01"
                  value={values.price}
                  onChange={(event) => handleChange('price', event.target.value)}
                  required
                  className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                />
              </div>

              <div>
                <label htmlFor="product-category" className="block text-sm font-medium text-slate-700">
                  Category
                </label>
                <select
                  id="product-category"
                  name="category_id"
                  value={values.category_id}
                  onChange={(event) => handleChange('category_id', event.target.value)}
                  className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                >
                  <option value="">Uncategorized</option>
                  {categoryOptions.map((category: CategoryOption) => (
                    <option key={category.id} value={category.id}>
                      {category.name}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="space-y-4">
              <div>
                <label htmlFor="product-sku" className="block text-sm font-medium text-slate-700">
                  SKU
                </label>
                <input
                  id="product-sku"
                  name="sku"
                  value={values.sku}
                  onChange={(event) => handleChange('sku', event.target.value)}
                  className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                  placeholder="Optional stock keeping unit"
                />
              </div>

              <div>
                <label htmlFor="product-barcode" className="block text-sm font-medium text-slate-700">
                  Barcode
                </label>
                <input
                  id="product-barcode"
                  name="barcode"
                  value={values.barcode}
                  onChange={(event) => handleChange('barcode', event.target.value)}
                  className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                  placeholder="Scan or type a barcode"
                />
              </div>

              <div>
                <label htmlFor="product-tax-code" className="block text-sm font-medium text-slate-700">
                  Tax code
                </label>
                <input
                  id="product-tax-code"
                  name="tax_code"
                  value={values.tax_code}
                  onChange={(event) => handleChange('tax_code', event.target.value)}
                  className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                  placeholder="Optional tax code"
                />
              </div>

              <div className="flex items-center justify-between rounded-md border border-slate-200 bg-slate-50 px-4 py-3">
                <div>
                  <p className="text-sm font-semibold text-slate-700">Track stock</p>
                  <p className="text-xs text-slate-500">Keep inventory levels in sync across stores.</p>
                </div>
                <label className="inline-flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    checked={values.track_stock}
                    onChange={(event) => handleChange('track_stock', event.target.checked)}
                    className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                  />
                  Enable
                </label>
              </div>

              <div className="flex items-center justify-between rounded-md border border-slate-200 bg-slate-50 px-4 py-3">
                <div>
                  <p className="text-sm font-semibold text-slate-700">Active</p>
                  <p className="text-xs text-slate-500">Inactive products are hidden from selling channels.</p>
                </div>
                <label className="inline-flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    checked={values.is_active}
                    onChange={(event) => handleChange('is_active', event.target.checked)}
                    className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                  />
                  Active
                </label>
              </div>
            </div>
          </div>
        </Card>

        <Card
          heading="Media"
          description="Upload an image shown in the product list and receipts."
          className={activeTab === 'media' ? 'block' : 'hidden'}
        >
          <ImageUploader value={values.image_url} onChange={(url) => handleChange('image_url', url)} />
        </Card>
      </form>

      {canManageStock && id ? (
        <div className={activeTab === 'stock' ? 'block' : 'hidden'}>
          <ProductStockTab productId={id} product={productQuery.data} />
        </div>
      ) : null}
    </div>
  );
}





