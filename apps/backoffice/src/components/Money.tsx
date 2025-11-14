import { useMemo } from 'react';

type MoneyProps = {
  value: number | string | null | undefined;
  currency?: string;
  minimumFractionDigits?: number;
  maximumFractionDigits?: number;
};

export function Money({
  value,
  currency = 'USD',
  minimumFractionDigits = 2,
  maximumFractionDigits = 2,
}: MoneyProps) {
  const formatted = useMemo(() => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
      return '--';
    }

    const numericValue = typeof value === 'string' ? Number(value) : value;

    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency,
      minimumFractionDigits,
      maximumFractionDigits,
    }).format(numericValue);
  }, [currency, maximumFractionDigits, minimumFractionDigits, value]);

  return <span>{formatted}</span>;
}
