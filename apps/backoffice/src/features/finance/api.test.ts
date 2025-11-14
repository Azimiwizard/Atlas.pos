import { describe, expect, it } from 'vitest';
import { createFinanceRequestParams } from './api';

const baseFilters = {
  date_from: '2025-01-01',
  date_to: '2025-01-31',
  currency: 'USD',
  tz: 'UTC',
} as const;

describe('finance api helpers', () => {
  it('builds params with optional store and bucket', () => {
    const params = createFinanceRequestParams({
      ...baseFilters,
      store_id: 'store-1',
      bucket: 'month',
      limit: 20,
    });

    expect(params).toEqual({
      date_from: '2025-01-01',
      date_to: '2025-01-31',
      store_id: 'store-1',
      currency: 'USD',
      tz: 'UTC',
      limit: 20,
      bucket: 'month',
    });
  });

  it('omits optional values when not provided', () => {
    const params = createFinanceRequestParams({
      ...baseFilters,
      store_id: null,
    });

    expect(params).toEqual({
      date_from: '2025-01-01',
      date_to: '2025-01-31',
      currency: 'USD',
      tz: 'UTC',
    });
  });
});
