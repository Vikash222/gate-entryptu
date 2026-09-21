import React, { useState } from 'react';
import { Outlet, NavLink } from 'react-router-dom';
import {
  LayoutDashboard,
  Users,
  Shield,
  History,
  FileSpreadsheet,
  DoorClosed,
  LogOut,
  KeyRound,
  ShieldCheck,
} from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { TwoFactorSetupModal } from '../auth/TwoFactorSetupModal';
import { NotificationBell } from '../../components/shared/NotificationBell';

export const AdminLayout: React.FC = () => {
  const { user, logout, refreshUser } = useAuth();
  const [is2faModalOpen, setIs2faModalOpen] = useState(false);

  const navLinks = [
    { to: '/admin', end: true, label: 'Dashboard', icon: LayoutDashboard },
    { to: '/admin/students', label: 'Students Master', icon: Users },
    { to: '/admin/gates', label: 'Gates Config', icon: DoorClosed },
    { to: '/admin/security', label: 'Security & Duty', icon: Shield },
    { to: '/admin/movements', label: '1-Year Movement Logs', icon: History },
    { to: '/admin/audit-logs', label: 'Audit Trail', icon: FileSpreadsheet },
  ];

  return (
    <div className="min-h-screen bg-slate-50 flex">
      {/* Sidebar (Desktop-First) */}
      <aside className="w-64 bg-slate-950 text-white flex flex-col justify-between border-r border-slate-800 flex-shrink-0">
        <div>
          {/* Logo Brand Header */}
          <div className="p-6 border-b border-slate-800/80">
            <div className="flex items-center gap-3">
              <div className="p-2.5 bg-blue-600 rounded-2xl shadow-lg shadow-blue-500/20">
                <ShieldCheck className="h-6 w-6 text-white" />
              </div>
              <div>
                <h1 className="text-base font-black tracking-tight text-white">SMARTGATE</h1>
                <span className="text-[10px] font-bold text-blue-400 uppercase tracking-widest block">
                  Admin Control Panel
                </span>
              </div>
            </div>
          </div>

          {/* Navigation Links */}
          <nav className="p-4 space-y-1">
            {navLinks.map((link) => {
              const Icon = link.icon;
              return (
                <NavLink
                  key={link.to}
                  to={link.to}
                  end={link.end}
                  className={({ isActive }) =>
                    `flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-xs font-bold transition ${
                      isActive
                        ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/20'
                        : 'text-slate-400 hover:text-white hover:bg-slate-900'
                    }`
                  }
                >
                  <Icon className="h-4 w-4 flex-shrink-0" />
                  <span>{link.label}</span>
                </NavLink>
              );
            })}
          </nav>
        </div>

        {/* User Account & Security Footer */}
        <div className="p-4 border-t border-slate-800/80 space-y-2">
          <div className="p-3 bg-slate-900 rounded-xl border border-slate-800">
            <h5 className="text-xs font-bold text-white truncate">{user?.name}</h5>
            <p className="text-[10px] text-slate-400 truncate">{user?.email}</p>
            <div className="mt-2 flex items-center justify-between">
              <span className="text-[10px] font-bold px-2 py-0.5 rounded bg-blue-500/20 text-blue-400 border border-blue-500/30">
                ADMINISTRATOR
              </span>
              <button
                type="button"
                onClick={() => setIs2faModalOpen(true)}
                className={`text-[10px] font-bold flex items-center gap-1 ${
                  user?.totp_enabled ? 'text-emerald-400' : 'text-amber-400 hover:underline'
                }`}
              >
                <KeyRound className="h-3 w-3" />
                <span>{user?.totp_enabled ? '2FA Active' : 'Enable 2FA'}</span>
              </button>
            </div>
          </div>

          <button
            type="button"
            onClick={logout}
            className="w-full flex items-center justify-center gap-2 p-2.5 text-slate-400 hover:text-rose-400 hover:bg-slate-900 rounded-xl text-xs font-bold transition"
          >
            <LogOut className="h-4 w-4" />
            <span>Sign Out</span>
          </button>
        </div>
      </aside>

      {/* Main Panel */}
      <main className="flex-1 flex flex-col min-w-0 overflow-y-auto">
        <header className="h-16 border-b border-slate-200 bg-white px-8 flex items-center justify-between sticky top-0 z-30 shadow-sm">
          <div>
            <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">SmartGate Core</span>
            <h2 className="text-sm font-bold text-slate-900">University Gate Management System</h2>
          </div>
          <div className="flex items-center gap-4">
            <NotificationBell variant="light" />
            <div className="flex items-center gap-2 pl-3 border-l border-slate-200">
              <span className="h-2 w-2 rounded-full bg-emerald-500" />
              <span className="text-xs font-mono text-slate-500">Central DB Sync: Active</span>
            </div>
          </div>
        </header>

        <div className="p-8">
          <Outlet />
        </div>
      </main>

      {/* 2FA Setup Modal */}
      <TwoFactorSetupModal
        isOpen={is2faModalOpen}
        onClose={() => setIs2faModalOpen(false)}
        onSuccess={() => {
          setIs2faModalOpen(false);
          refreshUser();
        }}
      />
    </div>
  );
};
