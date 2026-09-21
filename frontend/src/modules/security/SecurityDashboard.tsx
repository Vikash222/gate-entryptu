import React, { useState, useEffect, useRef, useCallback } from 'react';
import QRCode from 'qrcode';
import { ShieldCheck, RefreshCw, Radio, Search, Calendar, History, PowerOff, KeyRound, AlertCircle, UserCheck } from 'lucide-react';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import { Modal } from '../../components/ui/Modal';
import { TodayMovementsModal } from './TodayMovementsModal';
import { StudentSearchModal } from './StudentSearchModal';
import { SecurityHistoryModal } from './SecurityHistoryModal';
import { ManualEntryModal } from './ManualEntryModal';
import { apiClient, getErrorMessage, API_BASE_URL } from '../../api/client';
import type { ApiResponse, Gate } from '../../types';

interface ActiveDutyData {
  id: number;
  gate_id: number;
  gate_name: string;
  gate_code: string;
  started_at: string;
}

interface LiveMovementEvent {
  id: number;
  verification_code: string;
  type: 'IN' | 'OUT';
  student_name: string;
  roll_number: string;
  gate_name: string;
  display_time: string;
  destination?: string | null;
  purpose?: string | null;
  vehicle_present?: boolean;
  vehicle_number?: string | null;
}

export const SecurityDashboard: React.FC = () => {
  const [activeDuty, setActiveDuty] = useState<ActiveDutyData | null>(null);
  const [isCheckingDuty, setIsCheckingDuty] = useState(true);

  // Duty Activation Form State (Inline for seamless mobile officer UX)
  const [availableGates, setAvailableGates] = useState<Gate[]>([]);
  const [selectedGateId, setSelectedGateId] = useState<number | null>(null);
  const [adminOtp, setAdminOtp] = useState('');
  const [isActivatingDuty, setIsActivatingDuty] = useState(false);
  const [activationError, setActivationError] = useState<string | null>(null);

  // End Duty Modal State
  const [showEndDutyConfirm, setShowEndDutyConfirm] = useState(false);
  const [isEndingDuty, setIsEndingDuty] = useState(false);

  // Temporary QR State
  const [qrDataUrl, setQrDataUrl] = useState('');
  const [countdown, setCountdown] = useState(30);
  const [isRefreshingQr, setIsRefreshingQr] = useState(false);
  const [qrErrorMessage, setQrErrorMessage] = useState<string | null>(null);

  // Live Movements Feed
  const [liveMovements, setLiveMovements] = useState<LiveMovementEvent[]>([]);
  const eventSourceRef = useRef<EventSource | null>(null);

  // Modals
  const [isManualEntryOpen, setIsManualEntryOpen] = useState(false);
  const [isSearchOpen, setIsSearchOpen] = useState(false);
  const [isTodayOpen, setIsTodayOpen] = useState(false);
  const [isHistoryOpen, setIsHistoryOpen] = useState(false);

  // Check active duty session on mount
  const checkActiveDuty = async () => {
    setIsCheckingDuty(true);
    try {
      const response = await apiClient.get<ApiResponse<{ has_active_duty: boolean; duty_session: ActiveDutyData | null }>>(
        '/security/duty/active'
      );
      if (response.data.data.has_active_duty && response.data.data.duty_session) {
        setActiveDuty(response.data.data.duty_session);
        window.dispatchEvent(new CustomEvent('smartgate:duty-status', { detail: { isOnDuty: true } }));
      } else {
        setActiveDuty(null);
        window.dispatchEvent(new CustomEvent('smartgate:duty-status', { detail: { isOnDuty: false } }));
        fetchAvailableGates();
      }
    } catch {
      setActiveDuty(null);
      window.dispatchEvent(new CustomEvent('smartgate:duty-status', { detail: { isOnDuty: false } }));
      fetchAvailableGates();
    } finally {
      setIsCheckingDuty(false);
    }
  };

  const fetchAvailableGates = async () => {
    try {
      const res = await apiClient.get<ApiResponse<Gate[]>>('/gates');
      setAvailableGates(res.data.data);
      if (res.data.data.length > 0 && !selectedGateId) {
        setSelectedGateId(res.data.data[0].id);
      }
    } catch {
      // Gates fetch failure handled silently or in activation
    }
  };

  useEffect(() => {
    checkActiveDuty();

    const handleDutyEnded = () => {
      setActiveDuty(null);
      fetchAvailableGates();
    };

    window.addEventListener('smartgate:duty-ended', handleDutyEnded);
    return () => {
      window.removeEventListener('smartgate:duty-ended', handleDutyEnded);
    };
  }, []);

  // Handle duty activation directly from form
  const handleActivateDuty = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedGateId) {
      setActivationError('Please select your assigned gate.');
      return;
    }

    const trimmedOtp = adminOtp.trim();
    if (trimmedOtp.length !== 6 || !/^\d{6}$/.test(trimmedOtp)) {
      setActivationError('Please enter a valid 6-digit numeric Admin Authorization OTP.');
      return;
    }

    setIsActivatingDuty(true);
    setActivationError(null);

    try {
      const response = await apiClient.post<ApiResponse<ActiveDutyData>>('/security/duty/activate', {
        gate_id: selectedGateId,
        admin_otp: trimmedOtp,
      });

      setActiveDuty(response.data.data);
      setAdminOtp('');
      window.dispatchEvent(new CustomEvent('smartgate:duty-status', { detail: { isOnDuty: true } }));
    } catch (err) {
      setActivationError(getErrorMessage(err));
    } finally {
      setIsActivatingDuty(false);
    }
  };

  // Handle ending duty session
  const handleEndDuty = async () => {
    setIsEndingDuty(true);
    try {
      await apiClient.post('/security/duty/end');
      setShowEndDutyConfirm(false);
      setActiveDuty(null);
      setQrDataUrl('');
      setLiveMovements([]);
      window.dispatchEvent(new CustomEvent('smartgate:duty-status', { detail: { isOnDuty: false } }));
      fetchAvailableGates();
    } catch (err) {
      alert(getErrorMessage(err));
    } finally {
      setIsEndingDuty(false);
    }
  };

  // Fetch / Generate Temporary 30s QR Code
  const refreshQr = useCallback(async () => {
    if (!activeDuty) return;

    setIsRefreshingQr(true);
    setQrErrorMessage(null);

    try {
      const response = await apiClient.post<ApiResponse<{ qr_payload: string; lifetime_seconds: number }>>(
        `/gates/${activeDuty.gate_id}/qr`
      );

      const payload = response.data.data.qr_payload;
      const url = await QRCode.toDataURL(payload, {
        width: 320,
        margin: 2,
        color: {
          dark: '#0f172a',
          light: '#ffffff',
        },
      });

      setQrDataUrl(url);
      setCountdown(response.data.data.lifetime_seconds || 30);
    } catch (err) {
      setQrErrorMessage(getErrorMessage(err));
    } finally {
      setIsRefreshingQr(false);
    }
  }, [activeDuty]);

  // QR Countdown and Auto-Refresh every 30 seconds
  useEffect(() => {
    if (!activeDuty) return;

    refreshQr();

    const interval = setInterval(() => {
      setCountdown((prev) => {
        if (prev <= 1) {
          refreshQr();
          return 30;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(interval);
  }, [activeDuty, refreshQr]);

  // Connect to Real-time Server-Sent Events (SSE) Live Movement Feed
  useEffect(() => {
    if (!activeDuty) return;

    // Load initial today's movements
    apiClient.get<ApiResponse<any[]>>(`/security/today?gate_id=${activeDuty.gate_id}`).then((res) => {
      const formatted = res.data.data.slice(0, 10).map((m) => ({
        id: m.id,
        verification_code: m.verification_code,
        type: m.type,
        student_name: m.student?.name || 'Student',
        roll_number: m.student?.roll_number || 'N/A',
        gate_name: m.gate?.name || 'Gate',
        display_time: new Date(m.server_timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
        destination: m.destination,
        purpose: m.purpose,
        vehicle_present: m.vehicle_present,
        vehicle_number: m.vehicle_number,
      }));
      setLiveMovements(formatted);
    }).catch(() => {});

    // Open SSE connection
    const token = localStorage.getItem('smartgate_token');
    const sseUrl = `${API_BASE_URL}/security/live-stream?token=${encodeURIComponent(token || '')}`;

    try {
      const es = new EventSource(sseUrl);
      eventSourceRef.current = es;

      es.addEventListener('movement', (e) => {
        try {
          const newMovement = JSON.parse(e.data) as LiveMovementEvent;
          setLiveMovements((prev) => [newMovement, ...prev.filter((item) => item.id !== newMovement.id)].slice(0, 20));

          if ('vibrate' in navigator) {
            navigator.vibrate([100, 50, 100]);
          }
        } catch (err) {
          console.error('SSE parse error:', err);
        }
      });

      es.onerror = () => {};
    } catch (err) {
      console.error('SSE initialization error:', err);
    }

    const handleMovementRecorded = (e: Event) => {
      const customEvent = e as CustomEvent;
      if (customEvent.detail) {
        const m = customEvent.detail;
        const newMovement: LiveMovementEvent = {
          id: m.movement_id || m.id || Date.now(),
          verification_code: m.verification_code || '',
          type: m.type,
          student_name: m.student?.name || 'Student',
          roll_number: m.student?.roll_number || 'N/A',
          gate_name: m.gate_name || activeDuty.gate_name || 'Gate',
          display_time: new Date(m.server_timestamp || Date.now()).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
          destination: m.destination,
          purpose: m.purpose,
          vehicle_present: m.vehicle_present,
          vehicle_number: m.vehicle_number,
        };
        setLiveMovements((prev) => [newMovement, ...prev.filter((item) => item.id !== newMovement.id)].slice(0, 20));
      }
    };

    window.addEventListener('smartgate:movement-recorded', handleMovementRecorded);

    return () => {
      window.removeEventListener('smartgate:movement-recorded', handleMovementRecorded);
      if (eventSourceRef.current) {
        eventSourceRef.current.close();
      }
    };
  }, [activeDuty]);

  if (isCheckingDuty) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center py-12">
        <div className="h-8 w-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
        <p className="mt-3 text-xs text-slate-500 font-semibold uppercase tracking-wider">Checking Duty Status...</p>
      </div>
    );
  }

  // Not on duty state: Operational features strictly locked until Admin OTP is verified
  if (!activeDuty) {
    const isActivationValid = selectedGateId !== null && adminOtp.trim().length === 6 && /^\d{6}$/.test(adminOtp.trim());

    return (
      <div className="flex-1 flex flex-col justify-start py-2 space-y-4 text-left">
        {/* Officer & Duty Status Badge */}
        <div className="flex items-center justify-between p-3.5 bg-slate-900 text-white rounded-2xl shadow-sm">
          <div>
            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Security Duty Session</span>
            <h3 className="text-sm font-black tracking-tight flex items-center gap-2">
              <span>Status:</span>
              <span className="px-2 py-0.5 rounded text-[10px] font-black bg-slate-800 text-slate-300 border border-slate-700">
                ⚪ OFF DUTY
              </span>
            </h3>
          </div>
          <div className="text-right">
            <span className="text-[10px] font-bold text-amber-400 uppercase tracking-wider block">Requirement</span>
            <span className="text-[11px] font-mono text-amber-300 font-bold">Admin Duty OTP</span>
          </div>
        </div>

        {/* Duty Activation Form Card */}
        <Card className="p-5 border-slate-200 shadow-md">
          <div className="flex items-center gap-2 mb-3">
            <div className="p-2 bg-blue-50 text-blue-600 rounded-xl">
              <ShieldCheck className="h-5 w-5" />
            </div>
            <div>
              <h3 className="text-sm font-black text-slate-900">Activate Duty Session</h3>
              <p className="text-[11px] text-slate-500">Authorize duty with an Admin OTP to operate the gate</p>
            </div>
          </div>

          {activationError && (
            <div className="mb-3">
              <Alert type="error" message={activationError} />
            </div>
          )}

          <form onSubmit={handleActivateDuty} className="space-y-4">
            <div className="p-3 bg-amber-50 border border-amber-200 rounded-xl flex items-start gap-2.5 text-xs text-amber-900">
              <AlertCircle className="h-4 w-4 text-amber-600 shrink-0 mt-0.5" />
              <span>
                <strong>Mandatory Authorization:</strong> Request the University Administrator to generate a 6-digit Duty OTP for your gate. A login alone cannot operate the gate.
              </span>
            </div>

            <div>
              <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                1. Select Assigned Gate <span className="text-rose-500">*</span>
              </label>
              {availableGates.length === 0 ? (
                <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-500 text-center">
                  Loading university gates...
                </div>
              ) : (
                <div className="grid grid-cols-1 gap-2">
                  {availableGates.map((g) => (
                    <button
                      key={g.id}
                      type="button"
                      onClick={() => setSelectedGateId(g.id)}
                      className={`p-3 rounded-xl border text-left flex items-center justify-between transition ${
                        selectedGateId === g.id
                          ? 'bg-blue-50/80 border-blue-600 ring-2 ring-blue-500/20'
                          : 'bg-white border-slate-200 hover:bg-slate-50'
                      }`}
                    >
                      <div>
                        <h4 className="font-bold text-slate-900 text-sm">{g.name}</h4>
                        <p className="text-xs text-slate-500 font-mono">{g.code} &bull; {g.location || 'Campus Gate'}</p>
                      </div>
                      <span
                        className={`h-4 w-4 rounded-full border-2 flex items-center justify-center ${
                          selectedGateId === g.id ? 'border-blue-600 bg-blue-600' : 'border-slate-300'
                        }`}
                      >
                        {selectedGateId === g.id && <span className="h-1.5 w-1.5 rounded-full bg-white" />}
                      </span>
                    </button>
                  ))}
                </div>
              )}
            </div>

            <div>
              <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                2. Enter Admin Authorization OTP <span className="text-rose-500">*</span>
              </label>
              <Input
                type="text"
                inputMode="numeric"
                maxLength={6}
                placeholder="6-digit OTP (e.g. 849201)"
                value={adminOtp}
                onChange={(e) => setAdminOtp(e.target.value.replace(/\D/g, '').slice(0, 6))}
                leftIcon={<KeyRound className="h-4 w-4 text-slate-400" />}
                className="text-center font-mono text-lg font-bold tracking-widest"
                helperText="Single-use, short-lived code generated by Admin"
              />
            </div>

            <Button
              type="submit"
              size="xl"
              variant="primary"
              className="w-full font-bold shadow-lg shadow-blue-600/25"
              isLoading={isActivatingDuty}
              disabled={!isActivationValid || isActivatingDuty}
            >
              Verify OTP & Start Duty
            </Button>
          </form>
        </Card>
      </div>
    );
  }

  return (
    <div className="flex flex-col flex-1 space-y-4 text-left">
      {/* Active Gate Banner */}
      <div className="flex items-center justify-between p-3.5 bg-slate-900 text-white rounded-2xl shadow-sm">
        <div>
          <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Assigned Gate</span>
          <h3 className="text-lg font-black tracking-tight flex items-center gap-2">
            <span>{activeDuty.gate_name}</span>
            <span className="px-2 py-0.5 rounded text-[10px] font-black bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
              ACTIVE
            </span>
          </h3>
        </div>

        <div className="flex items-center gap-2">
          <div className="text-right mr-1">
            <div className="flex items-center gap-1 text-xs text-emerald-400 font-bold justify-end">
              <Radio className="h-3.5 w-3.5 animate-pulse text-emerald-400" />
              <span>LIVE</span>
            </div>
            <span className="text-[10px] font-mono text-slate-400">Terminal</span>
          </div>

          <button
            type="button"
            onClick={() => setShowEndDutyConfirm(true)}
            className="px-2.5 py-1.5 rounded-xl bg-rose-500/20 border border-rose-500/30 text-rose-300 hover:bg-rose-500 hover:text-white text-xs font-bold transition flex items-center gap-1"
            title="End Duty"
          >
            <PowerOff className="h-3.5 w-3.5" />
            <span>End Duty</span>
          </button>
        </div>
      </div>

      {/* Temporary QR Display Card */}
      <Card className="p-5 text-center flex flex-col items-center bg-white border-slate-200 shadow-md">
        <div className="w-full flex items-center justify-between mb-2">
          <span className="text-xs font-bold uppercase tracking-wider text-slate-600">Temporary Gate QR</span>
          <div className="flex items-center gap-2">
            <span className="text-xs font-mono font-bold text-blue-600">
              Refresh: <strong className="text-sm">{countdown}s</strong>
            </span>
            <button
              type="button"
              onClick={refreshQr}
              disabled={isRefreshingQr}
              className="p-1 text-slate-400 hover:text-blue-600 rounded transition"
              title="Manual refresh"
            >
              <RefreshCw className={`h-4 w-4 ${isRefreshingQr ? 'animate-spin text-blue-600' : ''}`} />
            </button>
          </div>
        </div>

        {qrErrorMessage ? (
          <div className="p-4 bg-rose-50 text-rose-700 text-xs rounded-xl my-4">
            {qrErrorMessage}
          </div>
        ) : qrDataUrl ? (
          <div className="relative p-3 bg-slate-50 border border-slate-200/80 rounded-2xl shadow-inner my-1">
            <img src={qrDataUrl} alt="Temporary Gate QR" className="w-56 h-56 rounded-xl bg-white p-2 shadow-sm" />
            <div className="absolute inset-x-0 bottom-1 flex justify-center">
              <span className="px-2.5 py-0.5 bg-slate-900/85 backdrop-blur-sm text-white rounded-full text-[9px] font-black tracking-wider uppercase">
                30s Security Token
              </span>
            </div>
          </div>
        ) : (
          <div className="w-56 h-56 flex items-center justify-center bg-slate-50 rounded-2xl">
            <div className="h-8 w-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
          </div>
        )}

        <div className="mt-3 flex items-center justify-center gap-1.5 text-xs text-slate-500 font-medium">
          <ShieldCheck className="h-4 w-4 text-emerald-600" />
          <span>Physical anti-spoofing presence lock</span>
        </div>
      </Card>

      {/* Live Movements Section */}
      <div className="flex-1 flex flex-col pt-1">
        <div className="flex items-center justify-between mb-2">
          <span className="text-xs font-extrabold uppercase tracking-wider text-slate-700 flex items-center gap-1.5">
            <span className="h-2 w-2 rounded-full bg-emerald-500 animate-ping" />
            <span>Live Movements</span>
          </span>
          <span className="text-[10px] font-mono text-slate-400">Instant Push Updates</span>
        </div>

        <div className="flex-1 bg-white border border-slate-200 rounded-2xl p-2.5 overflow-y-auto max-h-48 divide-y divide-slate-100 shadow-inner">
          {liveMovements.length === 0 ? (
            <div className="py-8 text-center text-slate-400 text-xs font-medium">
              Waiting for student gate movements...
            </div>
          ) : (
            liveMovements.map((m) => (
              <div key={m.id} className="py-2 px-1 flex items-center justify-between text-xs animate-fadeIn">
                <div>
                  <h5 className="font-bold text-slate-900 text-xs">{m.student_name}</h5>
                  <p className="text-[11px] text-slate-500 font-mono">
                    {m.roll_number}
                    {m.destination && <span className="ml-1 text-slate-400">&bull; {m.destination}</span>}
                  </p>
                </div>

                <div className="flex items-center gap-2">
                  <span
                    className={`px-2 py-0.5 rounded font-black text-[11px] ${
                      m.type === 'IN' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                    }`}
                  >
                    {m.type === 'IN' ? '🟢 IN' : '🔴 OUT'}
                  </span>
                  <span className="text-[10px] font-mono text-slate-400">{m.display_time}</span>
                </div>
              </div>
            ))
          )}
        </div>
      </div>

      {/* Manual Student Entry Action Button */}
      <div className="pt-2">
        <Button
          variant="primary"
          size="lg"
          onClick={() => setIsManualEntryOpen(true)}
          className="w-full font-black text-xs py-3.5 flex items-center justify-center gap-2 shadow-lg shadow-blue-600/25 uppercase tracking-wider"
        >
          <UserCheck className="h-5 w-5" />
          <span>MANUAL STUDENT ENTRY</span>
        </Button>
      </div>

      {/* Action Buttons Grid matching specification */}
      <div className="grid grid-cols-3 gap-2 pt-1">
        <Button
          variant="secondary"
          size="md"
          onClick={() => setIsSearchOpen(true)}
          className="text-xs font-bold flex flex-col py-3 gap-1 shadow-sm"
        >
          <Search className="h-4 w-4 text-blue-600" />
          <span>SEARCH</span>
        </Button>

        <Button
          variant="secondary"
          size="md"
          onClick={() => setIsTodayOpen(true)}
          className="text-xs font-bold flex flex-col py-3 gap-1 shadow-sm"
        >
          <Calendar className="h-4 w-4 text-emerald-600" />
          <span>TODAY</span>
        </Button>

        <Button
          variant="secondary"
          size="md"
          onClick={() => setIsHistoryOpen(true)}
          className="text-xs font-bold flex flex-col py-3 gap-1 shadow-sm"
        >
          <History className="h-4 w-4 text-purple-600" />
          <span>HISTORY</span>
        </Button>
      </div>

      {/* Modals */}
      <ManualEntryModal
        isOpen={isManualEntryOpen}
        onClose={() => setIsManualEntryOpen(false)}
        assignedGateName={activeDuty.gate_name}
      />
      <StudentSearchModal isOpen={isSearchOpen} onClose={() => setIsSearchOpen(false)} />
      <TodayMovementsModal isOpen={isTodayOpen} onClose={() => setIsTodayOpen(false)} gateId={activeDuty.gate_id} />
      <SecurityHistoryModal isOpen={isHistoryOpen} onClose={() => setIsHistoryOpen(false)} />

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
              isLoading={isEndingDuty}
              disabled={isEndingDuty}
              className="flex-1 text-xs font-bold"
            >
              Confirm End Duty
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
};
