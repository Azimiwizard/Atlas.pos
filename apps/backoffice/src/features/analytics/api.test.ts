import { describe, expect, it, vi, beforeEach } from 'vitest';
import { api } from '../../lib/api';
import {
  getSummary,
  getHourlyHeatmap,
  getCashiers,
  exportAnalyticsCsv,
  type AnalyticsFilters,
} from './api';

vi.mock('../../lib/api', () => ({
  api: {
    get: vi.fn(),
  },
}));

const filters: AnalyticsFilters = {
  date_from: '2025-01-01',
  date_to: '2025-01-31',
  tz: 'UTC',
  store_id: 'store-1',
  limit: 5,
};

describe('analytics api module', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('requests summary with normalized params', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({ data: { mock: true } });
    const response = await getSummary(filters);

    expect(api.get).toHaveBeenCalledWith('/bo/analytics/summary', {
      params: {
        date_from: '2025-01-01',
        date_to: '2025-01-31',
        tz: 'UTC',
        store_id: 'store-1',
        limit: 5,
      },
    });
    expect(response).toEqual({ mock: true });
  });

  it('requests hourly heatmap without store filter when null', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({ data: [] });
    await getHourlyHeatmap({ ...filters, store_id: null });

    expect(api.get).toHaveBeenCalledWith('/bo/analytics/hourly-heatmap', {
      params: {
        date_from: '2025-01-01',
        date_to: '2025-01-31',
        tz: 'UTC',
        store_id: undefined,
        limit: 5,
      },
    });
  });

  it('requests cashier stats', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({ data: [] });
    await getCashiers(filters);
    expect(api.get).toHaveBeenCalledWith('/bo/analytics/cashiers', {
      params: {
        date_from: '2025-01-01',
        date_to: '2025-01-31',
        tz: 'UTC',
        store_id: 'store-1',
        limit: 5,
      },
    });
  });

  it('triggers CSV download', async () => {
    const blob = new Blob(['test'], { type: 'text/csv' });
    vi.mocked(api.get).mockResolvedValueOnce({ data: blob });
    const anchor = document.createElement('a');
    const clickSpy = vi.spyOn(anchor, 'click').mockImplementation(() => {});
    const appendSpy = vi.spyOn(document.body, 'appendChild').mockImplementation(() => anchor);
    const removeSpy = vi.spyOn(document.body, 'removeChild').mockImplementation(() => anchor);
    const createElementSpy = vi.spyOn(document, 'createElement').mockReturnValue(anchor);

    if (typeof URL.createObjectURL !== 'function') {
      // @ts-expect-error jsdom polyfill for tests
      URL.createObjectURL = vi.fn();
    }
    if (typeof URL.revokeObjectURL !== 'function') {
      // @ts-expect-error jsdom polyfill for tests
      URL.revokeObjectURL = vi.fn();
    }

    const objectUrlSpy = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:test');
    const revokeSpy = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});

    await exportAnalyticsCsv(filters, 'analytics.csv');

    expect(api.get).toHaveBeenCalledWith('/bo/analytics/export.csv', {
      params: {
        date_from: '2025-01-01',
        date_to: '2025-01-31',
        tz: 'UTC',
        store_id: 'store-1',
        limit: 5,
      },
      responseType: 'blob',
    });
    expect(objectUrlSpy).toHaveBeenCalled();
    expect(clickSpy).toHaveBeenCalled();
    expect(revokeSpy).toHaveBeenCalledWith('blob:test');

    clickSpy.mockRestore();
    appendSpy.mockRestore();
    removeSpy.mockRestore();
    createElementSpy.mockRestore();
    objectUrlSpy.mockRestore();
    revokeSpy.mockRestore();
  });
});
