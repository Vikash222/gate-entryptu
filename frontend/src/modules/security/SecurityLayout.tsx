import React, { useState, useEffect } from 'react';
import { Outlet } from 'react-router-dom';
import { Shield, LogOut, WifiOff, PowerOff } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { apiClient } from '../../api/client';
import { Modal } from '../../components/ui/Modal';
import { Button } from '../../components/ui/Button';

export const SecurityLayout: React.FC = () => {
  const { user, logout } = useAuth();
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [isOnDuty, setIsOnDuty] = useState(false);
  const [showEndDutyConfirm, setShowEndDutyConfirm] = useState(false);
  const [isEndingDuty, setIsEndingDuty] = useState(false);

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    // Check duty status on mount
    apiClient
      .get('/security/duty/active')
      .then((res: any) => {
        setIsOnDuty(Boolean(res.data?.data?.has_active_duty));
      })
      .catch(() => setIsOnDuty(false));

    const handleDutyStatus = (e: any) => {
      setIsOnDuty(Boolean(e.detail?.isOnDuty));
    };

    window.addEventListener('smartgate:duty-status', handleDutyStatus);

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
      window.removeEventListener('smartgate:duty-status', handleDutyStatus);
    };
  }, []);

  const handleEndDuty = async () => {
    setIsEndingDuty(true);
    try {
      await apiClient.post('/security/duty/end');
      setShowEndDutyConfirm(false);
      setIsOnDuty(false);
      window.dispatchEvent(new CustomEvent('smartgate:duty-status', { detail: { isOnDuty: false } }));
      window.dispatchEvent(new CustomEvent('smartgate:duty-ended'));
    } catch (err) {
      console.error('End duty error:', err);
    } finally {
      setIsEndingDuty(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-100 flex flex-col items-center">
      {/* Offline Alert */}
      {!isOnline && (
        <div className="w-full bg-amber-500 text-slate-900 px-4 py-2.5 text-xs font-bold flex items-center justify-center gap-2 shadow-sm sticky top-0 z-50 animate-pulse">
          <WifiOff className="h-4 w-4" />
          <span>Offline. Central verification server disconnected.</span>
        </div>
      )}

      {/* Main Mobile Shell (Max width 480px for security phone) */}
      <div className="w-full max-w-md min-h-screen bg-white flex flex-col shadow-xl border-x border-slate-200">
        {/* Security Screen Header */}
        <header className="px-4 py-3.5 bg-slate-950 text-white flex items-center justify-between sticky top-0 z-40 shadow-sm">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-blue-600 rounded-xl shadow-sm">
              <Shield className="h-5 w-5 text-white" />
            </div>
            <div>
              <span className="text-[10px] font-black tracking-widest text-blue-400 block uppercase">
                SECURITY TERMINAL
              </span>
              <h1 className="text-sm font-extrabold tracking-tight truncate max-w-[160px]">
                {user?.name || 'Officer'}
              </h1>
            </div>
          </div>

          <div className="flex items-center gap-1.5">
            {isOnDuty ? (
              <button
                type="button"
                onClick={() => setShowEndDutyConfirm(true)}
                className="px-2.5 py-1.5 rounded-xl bg-rose-500/20 hover:bg-rose-500 hover:text-white text-rose-300 text-xs font-bold transition flex items-center gap-1 border border-rose-500/30"
                title="End Duty"
              >
                <PowerOff className="h-3.5 w-3.5 text-rose-400" />
                <span>End Duty</span>
              </button>
            ) : (
              <span className="px-2.5 py-1 rounded-xl bg-slate-800 text-slate-400 text-[10px] font-bold border border-slate-700/50">
                OFF DUTY
              </span>
            )}
            <button
              type="button"
              onClick={logout}
              className="p-2 text-slate-400 hover:text-white rounded-xl transition"
              title="Sign Out"
            >
              <LogOut className="h-4 w-4" />
            </button>
          </div>
        </header>

        {/* Content */}
        <main className="flex-1 p-4 flex flex-col">
          <Outlet />
        </main>
      </div>

      {/* End Duty Confirmation Modal */}
      <Modal
        isOpen={showEndDutyConfirm}
        onClose={() => setShowEndDutyConfirm(false)}
        title="End Security Duty Session?"
        description="This will release your duty slot for other officers. Your displayed temporary QR will immediately become invalid."
        size="sm"
      >
        <div className="space-y-4 pt-2">
          <div className="flex gap-2">
            <Button
              variant="outline"
              onClick={() => setShowEndDutyConfirm(false)}
              className="flex-1 text-xs"
              disabled={isEndingDuty}
            >
              Cancel
            </Button>
            <Button
              variant="danger"
              onClick={handleEndDuty}
              className="flex-1 text-xs font-bold"
              isLoading={isEndingDuty}
            >
              Confirm End Duty
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
};
