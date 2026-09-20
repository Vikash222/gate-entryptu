import React, { useState, useEffect } from 'react';
import { Outlet } from 'react-router-dom';
import { Shield, LogOut, WifiOff, User as UserIcon } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { Modal } from '../../components/ui/Modal';
import { Button } from '../../components/ui/Button';

export const StudentLayout: React.FC = () => {
  const { user, logout } = useAuth();
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [showProfile, setShowProfile] = useState(false);

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  const student = user?.student;

  return (
    <div className="min-h-screen bg-slate-100 flex flex-col items-center">
      {/* Offline Alert Banner */}
      {!isOnline && (
        <div className="w-full bg-amber-500 text-slate-900 px-4 py-2.5 text-xs font-bold flex items-center justify-center gap-2 shadow-sm sticky top-0 z-50 animate-pulse">
          <WifiOff className="h-4 w-4" />
          <span>Offline. Gate verification unavailable. Please reconnect.</span>
        </div>
      )}

      {/* Main Mobile App Shell (Max width 480px for native app aesthetic) */}
      <div className="w-full max-w-md min-h-screen bg-white flex flex-col shadow-xl border-x border-slate-200">
        {/* App Header */}
        <header className="px-5 py-4 bg-slate-900 text-white flex items-center justify-between sticky top-0 z-40 shadow-sm">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-blue-600 rounded-xl shadow-sm">
              <Shield className="h-5 w-5 text-white" />
            </div>
            <div>
              <span className="text-xs font-black tracking-widest text-blue-400 block uppercase">University</span>
              <h1 className="text-base font-extrabold tracking-tight">SMARTGATE</h1>
            </div>
          </div>

          <div className="flex items-center gap-1">
            <button
              onClick={() => setShowProfile(true)}
              className="p-2.5 text-slate-300 hover:text-white hover:bg-slate-800 rounded-xl transition"
              title="Student Profile"
            >
              <UserIcon className="h-5 w-5" />
            </button>
            <button
              onClick={logout}
              className="p-2.5 text-slate-300 hover:text-rose-400 hover:bg-slate-800 rounded-xl transition"
              title="Sign Out"
            >
              <LogOut className="h-5 w-5" />
            </button>
          </div>
        </header>

        {/* Content Outlet */}
        <main className="flex-1 p-5 flex flex-col">
          <Outlet context={{ setShowProfile }} />
        </main>
      </div>

      {/* Student Profile Modal */}
      <Modal
        isOpen={showProfile}
        onClose={() => setShowProfile(false)}
        title="Student Profile"
        description="Official university student identification details"
        size="md"
      >
        <div className="space-y-4 pt-2 text-left">
          <div className="flex items-center gap-3 p-3 bg-slate-50 rounded-2xl border border-slate-200/80">
            <div className="h-12 w-12 rounded-xl bg-blue-600 text-white font-bold flex items-center justify-center text-lg shadow-sm">
              {student?.name?.charAt(0) || 'S'}
            </div>
            <div>
              <h4 className="font-bold text-slate-900">{student?.name || user?.name}</h4>
              <p className="text-xs text-slate-500 font-mono">Roll: {student?.roll_number || 'N/A'}</p>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3 text-xs">
            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Student ID</span>
              <span className="font-semibold text-slate-800 font-mono">{student?.student_id || 'N/A'}</span>
            </div>

            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Program</span>
              <span className="font-semibold text-slate-800">{student?.program || 'N/A'}</span>
            </div>

            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Department</span>
              <span className="font-semibold text-slate-800">{student?.department || 'N/A'}</span>
            </div>

            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Semester / Year</span>
              <span className="font-semibold text-slate-800">
                {student?.semester ? `Sem ${student.semester}` : ''} {student?.year ? `(Yr ${student.year})` : 'N/A'}
              </span>
            </div>

            <div className="col-span-2 p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Official Email</span>
              <span className="font-semibold text-slate-800 break-all">{student?.email || user?.email}</span>
            </div>
          </div>

          <Button onClick={() => setShowProfile(false)} variant="outline" className="w-full">
            Close Profile
          </Button>
        </div>
      </Modal>
    </div>
  );
};
