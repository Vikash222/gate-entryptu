import React, { useState, useEffect } from 'react';
import { LogIn, LogOut, Users, Shield, PowerOff, RefreshCw } from 'lucide-react';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse } from '../../types';

export const AdminDashboard: React.FC = () => {
  const [data, setData] = useState<any | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [endingSessionId, setEndingSessionId] = useState<number | null>(null);

  const fetchDashboard = async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const response = await apiClient.get<ApiResponse<any>>('/admin/dashboard');
      setData(response.data.data);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchDashboard();
  }, []);

  const handleForceEnd = async (sessionId: number) => {
    if (!confirm('Are you sure you want to terminate this active duty session?')) return;

    setEndingSessionId(sessionId);
    try {
      await apiClient.post(`/admin/security/sessions/${sessionId}/force-end`);
      fetchDashboard();
    } catch (err) {
      alert(getErrorMessage(err));
    } finally {
      setEndingSessionId(null);
    }
  };

  if (isLoading && !data) {
    return (
      <div className="py-20 flex flex-col items-center justify-center">
        <div className="h-8 w-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
        <p className="mt-3 text-xs text-slate-500 font-semibold uppercase">Loading Dashboard...</p>
      </div>
    );
  }

  const metrics = data?.metrics || {};
  const activeSessions = data?.active_duty_sessions || [];
  const gateStats = data?.gate_stats || [];

  return (
    <div className="space-y-8 text-left max-w-7xl mx-auto">
      {/* Top Header & Refresh */}
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-black text-slate-900 tracking-tight">Executive Dashboard</h2>
          <p className="text-xs text-slate-500 mt-1">Real-time gate movement metrics and campus occupancy</p>
        </div>
        <Button variant="outline" size="sm" onClick={fetchDashboard} isLoading={isLoading}>
          <RefreshCw className="h-3.5 w-3.5 mr-1.5" /> Refresh Data
        </Button>
      </div>

      {errorMessage && <div className="p-4 bg-rose-50 text-rose-700 text-xs rounded-xl">{errorMessage}</div>}

      {/* KPI Cards Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        {/* Students Inside */}
        <Card className="p-5 border-slate-200">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-slate-500 uppercase tracking-wider">Students Inside</span>
            <div className="p-2 bg-emerald-50 text-emerald-600 rounded-xl">
              <Users className="h-4 w-4" />
            </div>
          </div>
          <h3 className="text-3xl font-black text-emerald-700 mt-3">{metrics.students_inside ?? 0}</h3>
          <p className="text-[11px] text-slate-400 mt-1">On campus right now</p>
        </Card>

        {/* Students Outside */}
        <Card className="p-5 border-slate-200">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-slate-500 uppercase tracking-wider">Students Outside</span>
            <div className="p-2 bg-rose-50 text-rose-600 rounded-xl">
              <LogOut className="h-4 w-4" />
            </div>
          </div>
          <h3 className="text-3xl font-black text-rose-700 mt-3">{metrics.students_outside ?? 0}</h3>
          <p className="text-[11px] text-slate-400 mt-1">Checked out of gates</p>
        </Card>

        {/* Today's IN */}
        <Card className="p-5 border-slate-200">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-slate-500 uppercase tracking-wider">Today's Entries</span>
            <div className="p-2 bg-blue-50 text-blue-600 rounded-xl">
              <LogIn className="h-4 w-4" />
            </div>
          </div>
          <h3 className="text-3xl font-black text-blue-700 mt-3">{metrics.today_in ?? 0}</h3>
          <p className="text-[11px] text-slate-400 mt-1">Total IN movements</p>
        </Card>

        {/* Today's OUT */}
        <Card className="p-5 border-slate-200">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-slate-500 uppercase tracking-wider">Today's Exits</span>
            <div className="p-2 bg-purple-50 text-purple-600 rounded-xl">
              <LogOut className="h-4 w-4" />
            </div>
          </div>
          <h3 className="text-3xl font-black text-purple-700 mt-3">{metrics.today_out ?? 0}</h3>
          <p className="text-[11px] text-slate-400 mt-1">Total OUT movements</p>
        </Card>

        {/* Active Guard Sessions */}
        <Card className="p-5 border-slate-200">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-slate-500 uppercase tracking-wider">Duty Sessions</span>
            <div className="p-2 bg-amber-50 text-amber-600 rounded-xl">
              <Shield className="h-4 w-4" />
            </div>
          </div>
          <h3 className="text-3xl font-black text-slate-900 mt-3">
            {metrics.active_duty_sessions_count ?? 0}{' '}
            <span className="text-base text-slate-400 font-normal">/ 4 max</span>
          </h3>
          <p className="text-[11px] text-slate-400 mt-1">Guard slots active</p>
        </Card>
      </div>

      {/* Two Column Grid: Gate Activity & Active Duty Sessions */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Gate Activity Table */}
        <Card className="p-6 border-slate-200">
          <h4 className="text-base font-bold text-slate-900 mb-4">Gate Activity Breakdown (Today)</h4>
          <div className="overflow-x-auto">
            <table className="w-full text-xs text-left">
              <thead className="text-[10px] text-slate-400 uppercase bg-slate-50 border-b border-slate-200">
                <tr>
                  <th className="py-2.5 px-3">Gate Name</th>
                  <th className="py-2.5 px-3">Code</th>
                  <th className="py-2.5 px-3 text-right">IN</th>
                  <th className="py-2.5 px-3 text-right">OUT</th>
                  <th className="py-2.5 px-3 text-right">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {gateStats.map((g: any) => (
                  <tr key={g.id} className="hover:bg-slate-50">
                    <td className="py-3 px-3 font-bold text-slate-800">{g.name}</td>
                    <td className="py-3 px-3 font-mono text-slate-500">{g.code}</td>
                    <td className="py-3 px-3 text-right font-bold text-emerald-600">{g.today_in}</td>
                    <td className="py-3 px-3 text-right font-bold text-rose-600">{g.today_out}</td>
                    <td className="py-3 px-3 text-right font-black text-slate-900">{g.total_today}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>

        {/* Active Duty Sessions Monitor */}
        <Card className="p-6 border-slate-200">
          <div className="flex items-center justify-between mb-4">
            <h4 className="text-base font-bold text-slate-900">Active Security Duty Sessions</h4>
            <span className="text-xs font-bold text-blue-600 bg-blue-50 px-2.5 py-1 rounded-full">
              {activeSessions.length} / 4 Slots Used
            </span>
          </div>

          <div className="space-y-3">
            {activeSessions.length === 0 ? (
              <div className="py-12 text-center text-xs text-slate-400">
                Zero active guard sessions right now. All 4 slots available.
              </div>
            ) : (
              activeSessions.map((s: any) => (
                <div
                  key={s.id}
                  className="p-3.5 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between text-xs"
                >
                  <div>
                    <h5 className="font-bold text-slate-900">{s.user?.name || 'Officer'}</h5>
                    <p className="text-[11px] text-slate-500 font-mono">
                      Gate: <strong className="text-slate-700">{s.gate?.name}</strong> &bull; Started:{' '}
                      {new Date(s.started_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                    </p>
                  </div>

                  <Button
                    variant="danger"
                    size="sm"
                    onClick={() => handleForceEnd(s.id)}
                    isLoading={endingSessionId === s.id}
                    className="text-[11px] py-1 px-2.5"
                  >
                    <PowerOff className="h-3 w-3 mr-1" /> Force End
                  </Button>
                </div>
              ))
            )}
          </div>
        </Card>
      </div>
    </div>
  );
};
