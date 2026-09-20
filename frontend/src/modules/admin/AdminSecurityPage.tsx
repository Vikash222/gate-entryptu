import React, { useState, useEffect, useRef } from 'react';
import {
  Plus,
  KeyRound,
  Shield,
  RefreshCw,
  PowerOff,
  Radio,
  Clock,
  Building2,
} from 'lucide-react';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Modal } from '../../components/ui/Modal';
import { Alert } from '../../components/ui/Alert';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse } from '../../types';

interface GuardItem {
  id: number;
  name: string;
  email: string;
  account_status: 'ACTIVE' | 'SUSPENDED';
  is_account_active: boolean;
  duty_status: 'ACTIVE' | 'OFF_DUTY';
  is_on_duty: boolean;
  current_gate: { id: number; name: string; code: string } | null;
  current_gate_name: string;
  duty_started_at: string | null;
  duty_ended_at: string | null;
  active_session?: { id: number; gate_id: number; gate_name: string; started_at: string } | null;
}

export const AdminSecurityPage: React.FC = () => {
  const [guards, setGuards] = useState<GuardItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [endingSessionId, setEndingSessionId] = useState<number | null>(null);

  // New Guard Modal
  const [isGuardModalOpen, setIsGuardModalOpen] = useState(false);
  const [guardForm, setGuardForm] = useState({ name: '', email: '', password: '' });
  const [isGuardSubmitting, setIsGuardSubmitting] = useState(false);
  const [guardModalError, setGuardModalError] = useState<string | null>(null);

  // OTP Modal
  const [isOtpModalOpen, setIsOtpModalOpen] = useState(false);
  const [generatedOtp, setGeneratedOtp] = useState<any | null>(null);
  const [isOtpLoading, setIsOtpLoading] = useState(false);
  const [otpError, setOtpError] = useState<string | null>(null);

  const isMountedRef = useRef(true);

  const fetchGuards = async (showFullLoading = false) => {
    if (showFullLoading) setIsLoading(true);
    setIsRefreshing(true);
    setErrorMessage(null);
    try {
      const res = await apiClient.get<ApiResponse<GuardItem[]>>('/admin/security');
      if (isMountedRef.current) {
        setGuards(res.data.data || []);
      }
    } catch (err) {
      if (isMountedRef.current) {
        setErrorMessage(getErrorMessage(err));
      }
    } finally {
      if (isMountedRef.current) {
        setIsLoading(false);
        setIsRefreshing(false);
      }
    }
  };

  useEffect(() => {
    isMountedRef.current = true;
    fetchGuards(true);

    // Auto-polling every 4 seconds to immediately reflect duty changes from security phones
    const pollInterval = setInterval(() => {
      fetchGuards(false);
    }, 4000);

    return () => {
      isMountedRef.current = false;
      clearInterval(pollInterval);
    };
  }, []);

  const handleCreateGuard = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsGuardSubmitting(true);
    setGuardModalError(null);

    try {
      await apiClient.post('/admin/security', guardForm);
      setIsGuardModalOpen(false);
      setGuardForm({ name: '', email: '', password: '' });
      fetchGuards(true);
    } catch (err) {
      setGuardModalError(getErrorMessage(err));
    } finally {
      setIsGuardSubmitting(false);
    }
  };

  const handleGenerateOtp = async () => {
    setIsOtpLoading(true);
    setOtpError(null);
    try {
      const res = await apiClient.post<ApiResponse<any>>('/admin/security/duty-otp', {
        ttl_minutes: 15,
      });
      setGeneratedOtp(res.data.data);
    } catch (err) {
      setOtpError(getErrorMessage(err));
    } finally {
      setIsOtpLoading(false);
    }
  };

  const handleForceEndDuty = async (sessionId: number, guardName: string) => {
    if (!window.confirm(`Force-terminate active duty session for officer "${guardName}"? Gate slot will be released immediately.`)) {
      return;
    }

    setEndingSessionId(sessionId);
    try {
      await apiClient.post(`/admin/security/sessions/${sessionId}/force-end`);
      fetchGuards(false);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setEndingSessionId(null);
    }
  };

  const activeDutiesCount = guards.filter((g) => g.is_on_duty).length;

  return (
    <div className="space-y-6 text-left max-w-7xl mx-auto">
      {/* Top Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-black text-slate-900 tracking-tight">Security Officer & Duty Management</h2>
          <p className="text-xs text-slate-500 mt-1">
            Real-time duty sessions, Admin OTP generation, and gate assignment controls (Max 4 active duties)
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => fetchGuards(false)}
            isLoading={isRefreshing}
            className="text-xs font-bold"
            title="Refresh duty states"
          >
            <RefreshCw className="h-3.5 w-3.5 mr-1" /> Refresh
          </Button>

          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              setGeneratedOtp(null);
              setIsOtpModalOpen(true);
              handleGenerateOtp();
            }}
            className="text-xs font-bold bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100"
          >
            <KeyRound className="h-4 w-4 mr-1.5 text-blue-600" /> Generate Duty OTP
          </Button>

          <Button
            size="sm"
            onClick={() => setIsGuardModalOpen(true)}
            className="text-xs font-bold"
          >
            <Plus className="h-4 w-4 mr-1.5" /> Add Security Guard
          </Button>
        </div>
      </div>

      {errorMessage && <Alert type="error" message={errorMessage} />}

      {/* KPI Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <Card className="p-4 border-slate-200">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-slate-500 uppercase tracking-wider">Active Duty Sessions</span>
            <div className="p-2 bg-emerald-50 text-emerald-600 rounded-xl">
              <Radio className="h-4 w-4 animate-pulse" />
            </div>
          </div>
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-2xl font-black text-emerald-700">{activeDutiesCount}</span>
            <span className="text-xs text-slate-400 font-bold font-mono">/ 4 max slots</span>
          </div>
          <p className="text-[11px] text-slate-400 mt-0.5">Live gate operators right now</p>
        </Card>

        <Card className="p-4 border-slate-200">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-slate-500 uppercase tracking-wider">Available Duty Slots</span>
            <div className="p-2 bg-blue-50 text-blue-600 rounded-xl">
              <Shield className="h-4 w-4" />
            </div>
          </div>
          <div className="mt-2">
            <span className="text-2xl font-black text-blue-700">{Math.max(0, 4 - activeDutiesCount)}</span>
          </div>
          <p className="text-[11px] text-slate-400 mt-0.5">Unfilled duty slots remaining</p>
        </Card>

        <Card className="p-4 border-slate-200">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-slate-500 uppercase tracking-wider">Registered Officers</span>
            <div className="p-2 bg-slate-50 text-slate-600 rounded-xl">
              <Building2 className="h-4 w-4" />
            </div>
          </div>
          <div className="mt-2">
            <span className="text-2xl font-black text-slate-900">{guards.length}</span>
          </div>
          <p className="text-[11px] text-slate-400 mt-0.5">Total registered Security accounts</p>
        </Card>
      </div>

      {/* Security Officers Table */}
      <Card className="p-0 border-slate-200 overflow-hidden shadow-sm">
        <div className="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
          <span className="text-xs font-bold text-slate-700 uppercase tracking-wider">
            Officers & Live Duty Roster
          </span>
          <div className="flex items-center gap-1 text-[11px] text-slate-500 font-mono">
            <Clock className="h-3.5 w-3.5 text-blue-500 animate-spin" style={{ animationDuration: '6s' }} />
            <span>Auto-refreshing live (4s)</span>
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-xs text-left">
            <thead className="text-[10px] text-slate-400 uppercase bg-slate-50/70 border-b border-slate-200">
              <tr>
                <th className="py-3 px-4">Officer Name</th>
                <th className="py-3 px-4">Email / Login</th>
                <th className="py-3 px-4">Account Status</th>
                <th className="py-3 px-4">Active Duty Status</th>
                <th className="py-3 px-4">Current Gate</th>
                <th className="py-3 px-4">Duty Timestamp</th>
                <th className="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {guards.length === 0 && !isLoading ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-slate-400 font-medium">
                    Zero security guards registered. Click "Add Security Guard" above to create an account.
                  </td>
                </tr>
              ) : (
                guards.map((g) => {
                  const isOnDuty = g.is_on_duty;
                  const activeSessionId = g.active_session?.id;
                  const isTerminating = endingSessionId === activeSessionId;

                  return (
                    <tr key={g.id} className={`hover:bg-slate-50/80 transition-colors ${isOnDuty ? 'bg-emerald-50/20' : ''}`}>
                      <td className="py-3 px-4 font-bold text-slate-900">
                        <div className="flex items-center gap-2">
                          <span className={`h-2 w-2 rounded-full ${isOnDuty ? 'bg-emerald-500 animate-ping' : 'bg-slate-300'}`} />
                          <span>{g.name}</span>
                        </div>
                      </td>

                      <td className="py-3 px-4 font-mono text-slate-600 text-[11px]">
                        {g.email}
                      </td>

                      <td className="py-3 px-4">
                        <span
                          className={`px-2 py-0.5 rounded-full font-bold text-[10px] ${
                            g.is_account_active
                              ? 'bg-blue-100 text-blue-800'
                              : 'bg-rose-100 text-rose-800'
                          }`}
                        >
                          {g.account_status || 'ACTIVE'}
                        </span>
                      </td>

                      <td className="py-3 px-4">
                        <span
                          className={`px-2.5 py-1 rounded-full font-black text-[10px] inline-flex items-center gap-1 ${
                            isOnDuty
                              ? 'bg-emerald-100 text-emerald-800 border border-emerald-200 shadow-sm'
                              : 'bg-slate-100 text-slate-600 border border-slate-200'
                          }`}
                        >
                          {isOnDuty ? '🟢 ON DUTY' : '⚪ OFF DUTY'}
                        </span>
                      </td>

                      <td className="py-3 px-4 font-bold">
                        {isOnDuty && g.current_gate ? (
                          <span className="text-emerald-800 font-bold">
                            {g.current_gate.name} <span className="font-mono text-[10px] text-emerald-600 font-normal">({g.current_gate.code})</span>
                          </span>
                        ) : (
                          <span className="text-slate-400 font-mono">-</span>
                        )}
                      </td>

                      <td className="py-3 px-4 font-mono text-[11px] text-slate-600">
                        {isOnDuty && g.duty_started_at ? (
                          <div className="text-emerald-700">
                            <span className="text-[10px] font-bold block text-emerald-600 uppercase">Started:</span>
                            {new Date(g.duty_started_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                          </div>
                        ) : g.duty_ended_at ? (
                          <div className="text-slate-500">
                            <span className="text-[10px] font-bold block text-slate-400 uppercase">Last Ended:</span>
                            {new Date(g.duty_ended_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                          </div>
                        ) : (
                          <span className="text-slate-400">-</span>
                        )}
                      </td>

                      <td className="py-3 px-4 text-right">
                        {isOnDuty && activeSessionId ? (
                          <Button
                            size="sm"
                            variant="danger"
                            className="text-xs font-bold"
                            disabled={isTerminating}
                            isLoading={isTerminating}
                            onClick={() => handleForceEndDuty(activeSessionId, g.name)}
                          >
                            <PowerOff className="h-3 w-3 mr-1" /> Force End
                          </Button>
                        ) : (
                          <span className="text-[11px] text-slate-400 font-medium">Idle</span>
                        )}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </Card>

      {/* Add Security Guard Modal */}
      <Modal
        isOpen={isGuardModalOpen}
        onClose={() => setIsGuardModalOpen(false)}
        title="Add Security Officer Account"
        description="Creates official login credentials for a campus security officer"
        size="md"
      >
        <form onSubmit={handleCreateGuard} className="space-y-4 pt-2 text-left">
          {guardModalError && <Alert type="error" message={guardModalError} />}

          <Input
            label="Officer Full Name"
            required
            placeholder="e.g. Ram Singh"
            value={guardForm.name}
            onChange={(e) => setGuardForm({ ...guardForm, name: e.target.value })}
          />

          <Input
            label="Official Login Email"
            type="email"
            required
            placeholder="guard@university.edu"
            value={guardForm.email}
            onChange={(e) => setGuardForm({ ...guardForm, email: e.target.value })}
          />

          <Input
            label="Login Password"
            type="password"
            required
            placeholder="Minimum 8 characters"
            value={guardForm.password}
            onChange={(e) => setGuardForm({ ...guardForm, password: e.target.value })}
          />

          <Button type="submit" size="lg" className="w-full font-bold mt-2" isLoading={isGuardSubmitting}>
            Create Officer Account
          </Button>
        </form>
      </Modal>

      {/* Admin OTP Generator Modal */}
      <Modal
        isOpen={isOtpModalOpen}
        onClose={() => {
          setIsOtpModalOpen(false);
          fetchGuards(false);
        }}
        title="Admin Duty Authorization OTP"
        description="Provide this single-use, 6-digit OTP to a security officer to authorize active duty at a gate"
        size="md"
      >
        <div className="space-y-4 pt-2 text-center">
          {otpError && <Alert type="error" message={otpError} />}

          {isOtpLoading ? (
            <div className="py-8 flex flex-col items-center">
              <div className="h-8 w-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
            </div>
          ) : generatedOtp ? (
            <div className="p-6 bg-blue-50 rounded-2xl border border-blue-200 space-y-3">
              <span className="text-xs font-bold uppercase tracking-wider text-blue-600">
                Single-Use Authorization OTP
              </span>
              <div className="text-5xl font-black font-mono tracking-widest text-slate-900 select-all">
                {generatedOtp.otp}
              </div>
              <p className="text-[11px] text-slate-500 font-mono">
                Valid for {generatedOtp.ttl_minutes} minutes &bull; Expires at{' '}
                {new Date(generatedOtp.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
              </p>
              <div className="pt-2 text-xs text-slate-600">
                The officer must enter this code and select their assigned gate on their Security Terminal to activate duty.
              </div>
            </div>
          ) : null}

          <div className="flex gap-2 pt-2">
            <Button onClick={handleGenerateOtp} variant="outline" className="flex-1 text-xs">
              Regenerate OTP
            </Button>
            <Button
              onClick={() => {
                setIsOtpModalOpen(false);
                fetchGuards(false);
              }}
              className="flex-1 text-xs font-bold"
            >
              Done
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
};
