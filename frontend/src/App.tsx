import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { ProtectedRoute } from './components/shared/ProtectedRoute';
import { LoginPage } from './modules/auth/LoginPage';
import { RegisterStudentPage } from './modules/auth/RegisterStudentPage';
import { TwoFactorChallengePage } from './modules/auth/TwoFactorChallengePage';
import { StudentLayout } from './modules/student/StudentLayout';
import { StudentDashboard } from './modules/student/StudentDashboard';
import { SecurityLayout } from './modules/security/SecurityLayout';
import { SecurityDashboard } from './modules/security/SecurityDashboard';
import { AdminLayout } from './modules/admin/AdminLayout';
import { AdminDashboard } from './modules/admin/AdminDashboard';
import { AdminStudentsPage } from './modules/admin/AdminStudentsPage';
import { AdminGatesPage } from './modules/admin/AdminGatesPage';
import { AdminSecurityPage } from './modules/admin/AdminSecurityPage';
import { AdminMovementsPage } from './modules/admin/AdminMovementsPage';
import { AdminAuditLogsPage } from './modules/admin/AdminAuditLogsPage';

// Root redirect handler based on authenticated user's role
const RootRedirect: React.FC = () => {
  const { user, isLoading } = useAuth();

  if (isLoading) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center bg-slate-50">
        <div className="h-10 w-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
        <p className="mt-4 text-xs font-semibold text-slate-500 tracking-wider uppercase">Loading SmartGate...</p>
      </div>
    );
  }

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (user.role === 'STUDENT') return <Navigate to="/student" replace />;
  if (user.role === 'SECURITY') return <Navigate to="/security" replace />;
  if (user.role === 'ADMIN') return <Navigate to="/admin" replace />;

  return <Navigate to="/login" replace />;
};

export const App: React.FC = () => {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          {/* Public Auth Routes */}
          <Route path="/login" element={<LoginPage />} />
          <Route path="/register" element={<RegisterStudentPage />} />
          <Route path="/auth/2fa" element={<TwoFactorChallengePage />} />

          {/* Student PWA Root */}
          <Route
            path="/student"
            element={
              <ProtectedRoute allowedRoles={['STUDENT']}>
                <StudentLayout />
              </ProtectedRoute>
            }
          >
            <Route index element={<StudentDashboard />} />
          </Route>

          {/* Security PWA Root */}
          <Route
            path="/security"
            element={
              <ProtectedRoute allowedRoles={['SECURITY']}>
                <SecurityLayout />
              </ProtectedRoute>
            }
          >
            <Route index element={<SecurityDashboard />} />
          </Route>

          {/* Admin Web Root */}
          <Route
            path="/admin"
            element={
              <ProtectedRoute allowedRoles={['ADMIN']}>
                <AdminLayout />
              </ProtectedRoute>
            }
          >
            <Route index element={<AdminDashboard />} />
            <Route path="students" element={<AdminStudentsPage />} />
            <Route path="gates" element={<AdminGatesPage />} />
            <Route path="security" element={<AdminSecurityPage />} />
            <Route path="movements" element={<AdminMovementsPage />} />
            <Route path="audit-logs" element={<AdminAuditLogsPage />} />
          </Route>

          {/* Default Root */}
          <Route path="/" element={<RootRedirect />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
};

export default App;
