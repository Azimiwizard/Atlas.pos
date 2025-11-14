import { Button, Card, DataTable, type DataTableColumn } from '@atlas-pos/ui';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { useToast } from '../components/ToastProvider';

type Order = {
  id: string;
  customer: string;
  total: string;
  status: 'Ready' | 'In Progress' | 'Paid';
};

const orders: Order[] = [
  { id: 'INV-1028', customer: 'Olivia Rhye', total: '$48.20', status: 'Ready' },
  { id: 'INV-1027', customer: 'Phoenix Baker', total: '$124.90', status: 'Paid' },
  { id: 'INV-1026', customer: 'Lana Steiner', total: '$15.40', status: 'In Progress' },
];

const columns: DataTableColumn<Order>[] = [
  { header: 'Ticket', accessor: 'id' },
  { header: 'Customer', accessor: 'customer' },
  { header: 'Total', accessor: 'total', className: 'text-right' },
  {
    header: 'Status',
    render: (row) => (
      <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
        {row.status}
      </span>
    ),
    className: 'text-right',
  },
];

export function PosPage() {
  const navigate = useNavigate();
  const { logout, user } = useAuth();
  const { addToast } = useToast();

  const handleLogout = async () => {
    await logout();
    addToast({ type: 'success', message: 'Logged out successfully.' });
    navigate('/login', { replace: true });
  };

  return (
    <div className="min-h-screen bg-slate-50 pb-16">
      <div className="mx-auto flex max-w-5xl flex-col gap-6 px-6 pt-10 sm:px-10">
        <header className="flex flex-col gap-4 border-b border-slate-200 pb-6 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-blue-600">Atlas POS</p>
            <h1 className="mt-2 text-3xl font-bold text-slate-900">Web Point of Sale</h1>
            <p className="mt-1 text-sm text-slate-500">
              Manage in-store sales, open tickets, and quick actions from a browser interface.
            </p>
          </div>
          <div className="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center">
            {user ? (
              <div className="text-right text-sm text-slate-500">
                <p className="font-medium text-slate-900">{user.name}</p>
                <p className="text-xs">{user.email}</p>
              </div>
            ) : null}
            <div className="flex gap-2">
              <Button variant="outline">Sync Catalog</Button>
              <Button>New Sale</Button>
              <Button variant="outline" onClick={() => void handleLogout()}>
                Logout
              </Button>
            </div>
          </div>
        </header>

        <Card
          heading="Recent Orders"
          description="Track tickets that are still open alongside recently completed sales."
          actions={<Button variant="outline">Export</Button>}
        >
          <DataTable<Order> data={orders} columns={columns} keyField="id" />
        </Card>
      </div>
    </div>
  );
}
