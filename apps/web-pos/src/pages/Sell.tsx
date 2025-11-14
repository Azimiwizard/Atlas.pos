import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  listProducts,
  type ProductListItem,
  type ProductListVariant,
} from '../features/products/api';
import {
  fetchCategories,
  fetchPromotions,
  type Category,
  type Promotion,
} from '../features/catalog/api';
import { useCart } from '../hooks/useCart';
import { useToast } from '../components/ToastProvider';
import { useStore } from '../hooks/useStore';
import { ProductCard } from '../components/pos/ProductCard';
import { CategoryTabs } from '../components/pos/CategoryTabs';
import { FloatingCartButton } from '../components/pos/FloatingCartButton';
import { PosHeader } from '../components/pos/PosHeader';
import { VariantSelectModal } from '../components/pos/VariantSelectModal';

type CategoryOption = {
  id: string | null;
  name: string;
};

const formatCount = (value: number) =>
  value >= 1000 ? `${(value / 1000).toFixed(1)}k` : value.toString();

const getPromotionLabelForProduct = (
  product: ProductListItem,
  promotions: Promotion[]
): string | null => {
  for (const promotion of promotions) {
    if (!promotion.is_active) {
      continue;
    }

    if (promotion.applies_to === 'all') {
      return promotion.name;
    }

    if (promotion.applies_to === 'product' && promotion.product_id === product.id) {
      return promotion.name;
    }

    if (promotion.applies_to === 'category' && promotion.category_id) {
      const match = product.categories.some((category) => category.id === promotion.category_id);
      if (match) {
        return promotion.name;
      }
    }
  }
  return null;
};

export default function SellPage() {
  const navigate = useNavigate();
  const cart = useCart();
  const { addToast } = useToast();
  const { currentStore } = useStore();

  const [search, setSearch] = useState('');
  const [categoryId, setCategoryId] = useState<string | null>(null);
  const [variantModalProduct, setVariantModalProduct] = useState<ProductListItem | null>(null);

  const categoriesQuery = useQuery({
    queryKey: ['catalog', 'categories'],
    queryFn: fetchCategories,
    staleTime: 60_000,
  });

  const productsQuery = useQuery({
    queryKey: ['catalog', 'products'],
    queryFn: () => listProducts(),
    staleTime: 60_000,
  });

  const promotionsQuery = useQuery({
    queryKey: ['catalog', 'promotions'],
    queryFn: fetchPromotions,
    staleTime: 60_000,
  });

  const categories: CategoryOption[] = useMemo(() => {
    const options: CategoryOption[] = [{ id: null, name: 'All' }];
    const data = categoriesQuery.data ?? [];

    data
      .filter((category) => category.is_active)
      .forEach((category) => {
        options.push({ id: category.id, name: category.name });
      });

    return options;
  }, [categoriesQuery.data]);

  const promotions = promotionsQuery.data ?? [];

  const filteredProducts = useMemo(() => {
    const products = productsQuery.data ?? [];
    const searchTerm = search.trim().toLowerCase();

    return products.filter((product) => {
      const matchesCategory =
        !categoryId || product.categories.some((category) => category.id === categoryId);

      const matchesSearch =
        searchTerm.length === 0 ||
        product.title.toLowerCase().includes(searchTerm) ||
        product.barcode?.toLowerCase().includes(searchTerm) ||
        product.variants.some(
          (variant) =>
            variant.name?.toLowerCase().includes(searchTerm) ||
            variant.sku?.toLowerCase().includes(searchTerm)
        );

      return matchesCategory && matchesSearch;
    });
  }, [categoryId, productsQuery.data, search]);

  const handleAdd = (
    product: ProductListItem,
    variant: ProductListVariant | null | undefined
  ) => {
    if (!variant) {
      addToast({ type: 'info', message: 'No variants available for this product.' });
      return;
    }

    const existingLine = cart.lines.find((line) => line.variantId === variant.id);
    const available =
      typeof variant.stock_on_hand === 'number' ? Math.max(0, variant.stock_on_hand) : null;
    const formatAvailable = (value: number) =>
      Number.isInteger(value) ? value.toString() : value.toFixed(2);

    if (available !== null) {
      const insufficientMessage = 'Not enough stock for this item.';
      if (available <= 0) {
        addToast({
          type: 'error',
          message: insufficientMessage,
        });
        return;
      }

      const nextQuantity = (existingLine?.qty ?? 0) + 1;
      if (nextQuantity > available) {
        addToast({
          type: 'error',
          message: `${insufficientMessage} Only ${formatAvailable(available)} remaining.`,
        });
        return;
      }
    }

    cart.addItem({
      variantId: variant.id,
      productId: product.id,
      title: product.title,
      price: variant.price,
      sku: variant.sku,
      qty: 1,
      categories: product.categories.map((category: Category) => ({
        id: category.id,
        name: category.name,
      })),
      taxes: [],
      stockOnHand: available ?? null,
    });

    addToast({ type: 'success', message: `${product.title} added to cart.` });
  };

  const handleQuickAdd = (product: ProductListItem, variant: ProductListVariant) => {
    handleAdd(product, variant);
  };

  const handleSelectVariants = (product: ProductListItem) => {
    setVariantModalProduct(product);
  };

  const handleVariantPicked = (product: ProductListItem, variant: ProductListVariant) => {
    handleAdd(product, variant);
    setVariantModalProduct(null);
  };

  const handleSelectCategory = (id: string | null) => {
    setCategoryId(id);
  };

  const isLoading = productsQuery.isLoading;
  const cartCount = cart.lines.reduce((sum, line) => sum + line.qty, 0);

  return (
    <div className="pb-24">
      <PosHeader
        title={currentStore?.name ?? 'Sell'}
        subtitle={
          cartCount > 0
            ? `${formatCount(cartCount)} items in cart`
            : 'Tap a tile to add it to the order'
        }
        searchValue={search}
        onSearchChange={setSearch}
        searchPlaceholder="Scan or search menu..."
        startAdornment={
          <CategoryTabs
            categories={categories}
            activeCategory={categoryId}
            onSelect={handleSelectCategory}
          />
        }
      />

      {isLoading ? (
        <div className="mt-16 flex justify-center">
          <div className="flex items-center gap-3 rounded-full border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] px-6 py-3 text-sm font-medium text-[color:var(--pos-text-muted)] shadow-sm">
            Loading menu...
          </div>
        </div>
      ) : (
        <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {filteredProducts.map((product) => (
            <ProductCard
              key={product.id}
              product={product}
              promotionLabel={getPromotionLabelForProduct(product, promotions)}
              onQuickAdd={handleQuickAdd}
              onSelectVariants={product.variants.length > 1 ? handleSelectVariants : undefined}
            />
          ))}
        </div>
      )}

      {cart.lines.length > 0 ? (
        <FloatingCartButton
          count={cartCount}
          total={cart.totals.total}
          onClick={() => navigate('/pos/cart')}
        />
      ) : null}

      <VariantSelectModal
        product={variantModalProduct}
        open={Boolean(variantModalProduct)}
        onClose={() => setVariantModalProduct(null)}
        onSelect={handleVariantPicked}
      />
    </div>
  );
}

