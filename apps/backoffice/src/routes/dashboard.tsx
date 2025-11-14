import DashboardPage from '../pages/Dashboard';
import { ProtectedRoute } from '../components/ProtectedRoute';

export default {
  path: '/dashboard',
  element: (
    <ProtectedRoute>
      <DashboardPage />
    </ProtectedRoute>
  ),
};
