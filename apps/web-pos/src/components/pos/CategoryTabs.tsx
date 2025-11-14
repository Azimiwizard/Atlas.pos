type CategoryOption = {
  id: string | null;
  name: string;
};

type CategoryTabsProps = {
  categories: CategoryOption[];
  activeCategory: string | null;
  onSelect: (categoryId: string | null) => void;
};

export function CategoryTabs({ categories, activeCategory, onSelect }: CategoryTabsProps) {
  return (
    <div className="flex snap-x snap-mandatory items-center gap-2 overflow-x-auto pb-2">
      {categories.map((category) => {
        const isActive = activeCategory === category.id;
        return (
          <button
            key={category.id ?? 'all'}
            type="button"
            onClick={() => onSelect(category.id)}
            className={`whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition ${
              isActive
                ? 'bg-[color:var(--pos-accent)] text-[color:var(--pos-accent-contrast)] shadow'
                : 'bg-[color:var(--pos-surface-muted)] text-[color:var(--pos-text-muted)] hover:bg-[color:var(--pos-accent)] hover:text-[color:var(--pos-accent-contrast)]'
            }`}
          >
            {category.name}
          </button>
        );
      })}
    </div>
  );
}
