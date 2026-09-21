import React, { Suspense, lazy } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { NotificationProvider } from './context/NotificationContext';
import { ProtectedRoute } from './components/shared/ProtectedRoute';

// Lazy load route bundles to optimize bundle size and initial load time
const LoginPage = lazy(() => import('./modules/auth/LoginPage').then(m => ({ default: m.LoginPage })));
const RegisterStudentPage = lazy(() => import('./modules/auth/RegisterStudentPage').then(m => ({ default: m.RegisterStudentPage })));
const TwoFactorChallengePage = lazy(() => import('./modules/auth/TwoFactorChallengePage').then(m => ({ default: m.TwoFactorChallengePage })));

const StudentLayout = lazy(() => import('./modules/student/StudentLayout').then(m => ({ default: m.StudentLayout })));
const StudentDashboard = lazy(() => import('./modules/student/StudentDashboard').then(m => ({ default: m.StudentDashboard })));

const SecurityLayout = lazy(() => import('./modules/security/SecurityLayout').then(m => ({ default: m.SecurityLayout })));
const SecurityDashboard = lazy(() => import('./modules/security/SecurityDashboard').then(m => ({ default: m.SecurityDashboard })));

const AdminLayout = lazy(() => import('./modules/admin/AdminLayout').then(m => ({ default: m.AdminLayout })));
const AdminDashboard = lazy(() => import('./modules/admin/AdminDashboard').then(m => ({ default: m.AdminDashboard })));
const AdminStudentsPage = lazy(() => import('./modules/admin/AdminStudentsPage').then(m => ({ default: m.AdminStudentsPage })));
const AdminGatesPage = lazy(() => import('./modules/admin/AdminGatesPage').then(m => ({ default: m.AdminGatesPage })));
const AdminSecurityPage = lazy(() => import('./modules/admin/AdminSecurityPage').then(m => ({ default: m.AdminSecurityPage })));
const AdminMovementsPage = lazy(() => import('./modules/admin/AdminMovementsPage').then(m => ({ default: m.AdminMovementsPage })));
const AdminAuditLogsPage = lazy(() => import('./modules/admin/AdminAuditLogsPage').then(m => ({ default: m.AdminAuditLogsPage })));

const RouteLoadingFallback: React.FC = () => (
  <div className="min-h-screen flex flex-col items-center justify-center bg-slate-50">
    <div className="h-8 w-8 border-3 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
    <p className="mt-3 text-xs font-semibold text-slate-400 tracking-wider uppercase">Loading...</p>
  </div>
);

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
        <NotificationProvider>
          <Suspense fallback={<RouteLoadingFallback />}>
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
          </Suspense>
        </NotificationProvider>
      </AuthProvider>
    </BrowserRouter>
  );
};

export default App;
