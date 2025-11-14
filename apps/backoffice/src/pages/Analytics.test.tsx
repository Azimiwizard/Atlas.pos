import type { ReactNode } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AnalyticsPage from './Analytics';
import {
  getSummary,
  getHourlyHeatmap,
  getCashiers,
  type AnalyticsSummaryResponse,
  type HeatmapPoint,
  type CashierStat,
} from '../features/analytics/api';

vi.mock('../hooks/useAuth', () => ({
  useAuth: () => ({
    user: {
      id: 'user-1',
      name: 'Admin User',
      email: 'admin@example.com',
      role: 'admin',
      tenant: { id: 'tenant-1', name: 'Demo Tenant', slug: 'demo' },
      store_id: null,
    },
    loading: false,
  }),
}));

vi.mock('../hooks/useStore', () => ({
  useStore: () => ({
    stores: [
      { id: 'store-1', name: 'Main Store', code: 'MAIN', address: null, phone: null, is_active: true },
    ],
    currentStore: { id: 'store-1', name: 'Main Store', code: 'MAIN', address: null, phone: null, is_active: true },
    currentStoreId: 'store-1',
    setCurrentStoreId: vi.fn(),
    loading: false,
  }),
}));

vi.mock('../components/toastContext', () => ({
  useToast: () => ({ addToast: vi.fn() }),
}));

vi.mock('../features/analytics/api', () => ({
  getSummary: vi.fn(),
  getHourlyHeatmap: vi.fn(),
  getCashiers: vi.fn(),
  exportAnalyticsCsv: vi.fn(),
}));

vi.mock('recharts', () => {
  const Wrapper = ({ children }: { children?: ReactNode }) => <div>{children}</div>;
  return {
    ResponsiveContainer: Wrapper,
    LineChart: Wrapper,
    Line: () => null,
    CartesianGrid: () => null,
    XAxis: () => null,
    YAxis: () => null,
    Tooltip: () => null,
    Legend: () => null,
    BarChart: Wrapper,
    Bar: () => null,
    PieChart: Wrapper,
    Pie: Wrapper,
    Cell: () => null,
  };
});

const mockSummary: AnalyticsSummaryResponse = {
  range: { from: '2025-01-01', to: '2025-01-30', tz: 'UTC' },
  filters: { store_id: null },
  kpis: {
    revenue_gross: 1234,
    orders: 42,
    aov: 29.38,
    items_per_order: 2.4,
    refunds_amount: 15,
    discounts_amount: 45,
    taxes_collected: 120,
    gross_margin_estimate: 340,
    unique_customers: 30,
  },
  trend_daily: [
    { date: '2025-01-01', revenue_gross: 100, orders: 4, refunds_amount: 0 },
    { date: '2025-01-02', revenue_gross: 200, orders: 6, refunds_amount: 0 },
  ],
  tender_mix: [
    { tender: 'Cash', amount: 400 },
    { tender: 'Card', amount: 600 },
  ],
  top_products: [
    { id: 'p1', name: 'Latte', qty: 20, revenue: 200 },
    { id: 'p2', name: 'Espresso', qty: 10, revenue: 100 },
  ],
  top_categories: [
    { id: 'c1', name: 'Drinks', qty: 30, revenue: 300 },
    { id: 'c2', name: 'Snacks', qty: 5, revenue: 50 },
  ],
};

const mockHeatmap: HeatmapPoint[] = [
  { dow: 0, hour: 9, orders: 5, revenue_gross: 50 },
  { dow: 1, hour: 15, orders: 8, revenue_gross: 120 },
];

const mockCashiers: CashierStat[] = [
  { user_id: 'u1', name: 'Alice', orders: 20, revenue_gross: 500, avg_handle_time_seconds: 120 },
  { user_id: 'u2', name: 'Bob', orders: 10, revenue_gross: 200, avg_handle_time_seconds: 80 },
];

describe('AnalyticsPage', () => {
  beforeEach(() => {
    vi.mocked(getSummary).mockResolvedValue(mockSummary);
    vi.mocked(getHourlyHeatmap).mockResolvedValue(mockHeatmap);
    vi.mocked(getCashiers).mockResolvedValue(mockCashiers);
    localStorage.clear();
  });

  const renderPage = () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });

    return render(
      <MemoryRouter initialEntries={['/analytics']}>
        <QueryClientProvider client={queryClient}>
          <AnalyticsPage />
        </QueryClientProvider>
      </MemoryRouter>
    );
  };

  it('renders analytics metrics once data loads', async () => {
    renderPage();

    await waitFor(() => {
      expect(getSummary).toHaveBeenCalled();
    });

    expect(await screen.findByText('Key Metrics')).toBeInTheDocument();
    expect(screen.getByText('$1,234.00')).toBeInTheDocument();
    expect(screen.getByText('Unique Customers')).toBeInTheDocument();
    expect(screen.getByText('Tender Mix')).toBeInTheDocument();
    expect(screen.getByText('Cashier Performance')).toBeInTheDocument();
  });
});



