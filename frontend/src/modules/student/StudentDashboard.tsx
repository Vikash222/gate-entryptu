import React, { useState, useEffect } from 'react';
import { useOutletContext } from 'react-router-dom';
import { LogIn, LogOut, User as UserIcon, AlertCircle, Clock, AlertTriangle, Lock } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { apiClient, getErrorMessage } from '../../api/client';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Alert } from '../../components/ui/Alert';
import { Badge } from '../../components/ui/Badge';
import { Avatar } from '../../components/ui/Avatar';
import { CameraQrScanner } from '../../components/shared/CameraQrScanner';
import { MovementModal } from './MovementModal';
import { ReceiptModal } from '../../components/shared/ReceiptModal';
import type { ApiResponse, StudentStatus, StudentAccountStatus, GateVerificationReceipt } from '../../types';

interface StudentStatusResponse {
  current_status: StudentStatus;
  is_inside: boolean;
  is_outside: boolean;
  last_movement_at?: string | null;
  last_gate?: {
    id: number;
    name: string;
  } | null;
  server_time: string;
}

interface VerifyQrResponse {
  gate_session_token: string;
  gate_id: number;
  gate_name: string;
  gate_code: string;
  expires_at: string;
  student_current_status: StudentStatus;
}

export const StudentDashboard: React.FC = () => {
  const { user } = useAuth();
  const outletContext = useOutletContext<{ setShowProfile: (show: boolean) => void } | null>();

  const [currentStatus, setCurrentStatus] = useState<StudentStatus>(() => {
    return user?.student?.current_status || 'INSIDE';
  });
  const [accountStatus, setAccountStatus] = useState<StudentAccountStatus>(() => {
    return user?.student?.status || (user?.status as StudentAccountStatus) || 'ACTIVE';
  });
  const [lastMovementAt, setLastMovementAt] = useState<string | null>(null);
  const [lastGateName, setLastGateName] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [statusMessage, setStatusMessage] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Modals state
  const [pendingMovementType, setPendingMovementType] = useState<'IN' | 'OUT' | null>(null);
  const [isScannerOpen, setIsScannerOpen] = useState(false);
  const [gateSessionData, setGateSessionData] = useState<VerifyQrResponse | null>(null);
  const [isMovementModalOpen, setIsMovementModalOpen] = useState(false);
  const [activeReceipt, setActiveReceipt] = useState<GateVerificationReceipt | null>(null);

  const fetchStatus = async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const [statusRes, profileRes] = await Promise.allSettled([
        apiClient.get<ApiResponse<StudentStatusResponse>>('/student/status'),
        apiClient.get<ApiResponse<any>>('/student/profile'),
      ]);

      if (statusRes.status === 'fulfilled') {
        const data = statusRes.value.data.data;
        setCurrentStatus(data.current_status);
        setLastMovementAt(data.last_movement_at || null);
        setLastGateName(data.last_gate?.name || null);
      }

      if (profileRes.status === 'fulfilled' && profileRes.value.data.data?.status) {
        setAccountStatus(profileRes.value.data.data.status);
      }
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchStatus();
  }, []);

  const student = user?.student;
  const isInside = currentStatus === 'INSIDE';

  const handleAction = (actionType: 'IN' | 'OUT') => {
    setStatusMessage(null);
    setErrorMessage(null);

    // Gate entry locked for non-active accounts
    if (accountStatus !== 'ACTIVE') {
      if (accountStatus === 'PENDING') {
        setStatusMessage('Your registration is PENDING approval by University Administration. Gate Entry is currently locked.');
      } else if (accountStatus === 'SUSPENDED') {
        setStatusMessage('Your student gate access has been SUSPENDED by administration.');
      } else if (accountStatus === 'REJECTED') {
        setStatusMessage('Your student registration was REJECTED by administration.');
      }
      return;
    }

    // Validate state transition
    if (actionType === 'IN' && isInside) {
      setStatusMessage('You are already marked INSIDE the university. Exit gate first before entering.');
      return;
    }

    if (actionType === 'OUT' && !isInside) {
      setStatusMessage('You are already marked OUTSIDE the university. Enter gate first before exiting.');
      return;
    }

    // Set intended action and launch camera QR scanner
    setPendingMovementType(actionType);
    setIsScannerOpen(true);
  };

  const handleQrScanned = async (decodedText: string) => {
    setIsScannerOpen(false);
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const response = await apiClient.post<ApiResponse<VerifyQrResponse>>('/gate-entry/verify-qr', {
        qr_payload: decodedText,
      });

      const sessionData = response.data.data;
      setGateSessionData(sessionData);
      setIsMovementModalOpen(true);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  const handleMovementSuccess = (receipt: GateVerificationReceipt) => {
    setIsMovementModalOpen(false);
    setGateSessionData(null);
    setActiveReceipt(receipt);
    fetchStatus();
  };

  return (
    <div className="flex flex-col flex-1 justify-between space-y-6 text-left">
      {/* Welcome & Student Info Banner */}
      <div>
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-center gap-3">
            <button
              onClick={() => outletContext?.setShowProfile(true)}
              className="group focus:outline-none transition transform active:scale-95"
              title="Click to view/change profile photo"
            >
              <Avatar
                src={student?.profile_photo_url}
                name={student?.name || user?.name}
                size="lg"
                shape="rounded"
                className="ring-2 ring-blue-500/30 group-hover:ring-blue-600 shadow-sm"
              />
            </button>
            <div>
              <div className="flex items-center gap-1.5 flex-wrap">
                <span className="text-xs font-semibold uppercase tracking-wider text-slate-400">Welcome,</span>
                <span
                  className={`text-[10px] px-2 py-0.2 rounded-full font-bold uppercase tracking-wider ${
                    student?.category === 'DAY_SCHOLAR'
                      ? 'bg-amber-100 text-amber-800'
                      : 'bg-indigo-100 text-indigo-800'
                  }`}
                >
                  {student?.category === 'DAY_SCHOLAR' ? 'Day Scholar' : 'Hosteler'}
                </span>
              </div>
              <h2 className="text-xl font-black text-slate-900 tracking-tight leading-tight">
                {student?.name || user?.name}
              </h2>
              <p className="text-xs font-mono font-medium text-slate-500">
                Roll No: <span className="font-bold text-slate-800">{student?.roll_number || 'Pending'}</span>
              </p>
            </div>
          </div>
          <div>
            {accountStatus === 'PENDING' && (
              <Badge variant="warning" size="md">
                PENDING
              </Badge>
            )}
            {accountStatus === 'ACTIVE' && (
              <Badge variant="success" size="md">
                VERIFIED ACTIVE
              </Badge>
            )}
            {accountStatus === 'SUSPENDED' && (
              <Badge variant="danger" size="md">
                SUSPENDED
              </Badge>
            )}
            {accountStatus === 'REJECTED' && (
              <Badge variant="danger" size="md">
                REJECTED
              </Badge>
            )}
          </div>
        </div>

        {/* Account Lifecycle Warning Banners */}
        {accountStatus === 'PENDING' && (
          <div className="mt-4 p-4 bg-amber-50 rounded-2xl border border-amber-200 flex items-start gap-3">
            <Clock className="h-5 w-5 text-amber-600 flex-shrink-0 mt-0.5" />
            <div className="text-xs text-amber-900 leading-relaxed">
              <strong className="block text-amber-800 text-sm mb-0.5">Account Registration Pending Approval</strong>
              Your student registration has been submitted and is currently pending administrative verification. 
              <strong> Gate QR verification and IN/OUT gate movements are locked</strong> until an administrator reviews and approves your account.
            </div>
          </div>
        )}

        {accountStatus === 'SUSPENDED' && (
          <div className="mt-4 p-4 bg-rose-50 rounded-2xl border border-rose-200 flex items-start gap-3">
            <AlertTriangle className="h-5 w-5 text-rose-600 flex-shrink-0 mt-0.5" />
            <div className="text-xs text-rose-900 leading-relaxed">
              <strong className="block text-rose-800 text-sm mb-0.5">Gate Access Suspended</strong>
              Your student gate privileges have been suspended by University Administration. Please contact the administrative or proctorial office.
            </div>
          </div>
        )}

        {accountStatus === 'REJECTED' && (
          <div className="mt-4 p-4 bg-rose-50 rounded-2xl border border-rose-200 flex items-start gap-3">
            <AlertTriangle className="h-5 w-5 text-rose-600 flex-shrink-0 mt-0.5" />
            <div className="text-xs text-rose-900 leading-relaxed">
              <strong className="block text-rose-800 text-sm mb-0.5">Registration Rejected</strong>
              Your student self-registration was rejected by Administration. Please contact the campus administrator for assistance.
            </div>
          </div>
        )}

        {/* Notices */}
        {statusMessage && (
          <div className="mt-4">
            <Alert type="warning" message={statusMessage} />
          </div>
        )}
        {errorMessage && (
          <div className="mt-4">
            <Alert type="error" message={errorMessage} />
          </div>
        )}

        {/* Central Status Card */}
        <Card className="mt-6 border-slate-200 shadow-sm relative overflow-hidden">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Current Status</span>
            <span className="text-[10px] font-mono text-slate-400">Physical Campus</span>
          </div>

          <div className="mt-4 flex items-center gap-4">
            <div
              className={`h-14 w-14 rounded-2xl flex items-center justify-center text-white shadow-md transition-colors ${
                isInside ? 'bg-emerald-600 shadow-emerald-500/25' : 'bg-rose-600 shadow-rose-500/25'
              }`}
            >
              {isInside ? <LogIn className="h-7 w-7" /> : <LogOut className="h-7 w-7" />}
            </div>

            <div>
              <div className="flex items-center gap-2">
                <span
                  className={`inline-block h-3 w-3 rounded-full animate-pulse ${
                    isInside ? 'bg-emerald-500' : 'bg-rose-500'
                  }`}
                />
                <span className={`text-2xl font-black tracking-tight ${isInside ? 'text-emerald-700' : 'text-rose-700'}`}>
                  {isInside ? 'INSIDE' : 'OUTSIDE'}
                </span>
              </div>
              <p className="text-xs text-slate-500 mt-0.5">
                {isInside ? 'Present within university campus' : 'Currently outside campus grounds'}
              </p>
            </div>
          </div>

          {lastMovementAt && (
            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400 font-mono">
              <span>Last Movement: {new Date(lastMovementAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
              {lastGateName && <span>Gate: {lastGateName}</span>}
            </div>
          )}
        </Card>

        {/* Anti-spoofing Physical Verification Notice */}
        <div className="mt-3 p-3.5 bg-blue-50/70 rounded-2xl border border-blue-100 flex items-start gap-2.5">
          <AlertCircle className="h-4 w-4 text-blue-600 flex-shrink-0 mt-0.5" />
          <p className="text-xs text-blue-900 leading-relaxed font-medium">
            <strong>Gate Verification Required:</strong> You must physically scan the temporary QR code displayed on the Security Guard's screen at the university gate. Movement cannot be recorded remotely.
          </p>
        </div>
      </div>

      {/* Movement Action Buttons (High-visibility, large touch targets) */}
      <div className="space-y-3 pt-4">
        {accountStatus !== 'ACTIVE' ? (
          <div className="p-5 bg-slate-100/90 rounded-2xl border border-slate-200 text-center space-y-2">
            <div className="inline-flex items-center justify-center p-3 bg-slate-200 rounded-full text-slate-600 mb-1">
              <Lock className="h-6 w-6" />
            </div>
            <h3 className="text-sm font-bold uppercase tracking-wider text-slate-800">
              Gate Movement Locked
            </h3>
            <p className="text-xs text-slate-500 max-w-sm mx-auto">
              {accountStatus === 'PENDING'
                ? 'Your account is pending administrative verification. QR scanner and gate entry buttons will automatically unlock once approved.'
                : 'Your gate entry access is currently suspended or inactive. Please contact administration.'}
            </p>
          </div>
        ) : (
          <>
            <Button
              size="xl"
              variant="success"
              className="w-full text-lg font-bold shadow-lg shadow-emerald-600/20 flex items-center justify-center gap-3"
              onClick={() => handleAction('IN')}
              disabled={isLoading}
            >
              <LogIn className="h-6 w-6" />
              <span>ENTER GATE</span>
            </Button>

            <Button
              size="xl"
              variant="danger"
              className="w-full text-lg font-bold shadow-lg shadow-rose-600/20 flex items-center justify-center gap-3"
              onClick={() => handleAction('OUT')}
              disabled={isLoading}
            >
              <LogOut className="h-6 w-6" />
              <span>EXIT GATE</span>
            </Button>
          </>
        )}

        {/* Quick Profile Link */}
        <div className="pt-2 text-center">
          <button
            type="button"
            onClick={() => outletContext?.setShowProfile(true)}
            className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-800 transition py-2"
          >
            <UserIcon className="h-4 w-4" />
            <span>View Student Profile</span>
          </button>
        </div>
      </div>

      {/* Camera Scanner Modal */}
      <CameraQrScanner
        isOpen={isScannerOpen}
        onClose={() => setIsScannerOpen(false)}
        onScanSuccess={handleQrScanned}
        title="Scan Temporary Gate QR"
        description="Scan the temporary QR code displayed on the Security Guard screen at the gate"
      />

      {/* Movement Submission Modal */}
      {gateSessionData && pendingMovementType && (
        <MovementModal
          isOpen={isMovementModalOpen}
          onClose={() => setIsMovementModalOpen(false)}
          movementType={pendingMovementType}
          gateSessionToken={gateSessionData.gate_session_token}
          gateName={gateSessionData.gate_name}
          onSuccess={handleMovementSuccess}
        />
      )}

      {/* 60s Verified Receipt */}
      <ReceiptModal receipt={activeReceipt} onClose={() => setActiveReceipt(null)} />
    </div>
  );
};
