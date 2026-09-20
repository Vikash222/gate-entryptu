import React, { useState, useEffect } from 'react';
import { RefreshCw } from 'lucide-react';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Alert } from '../../components/ui/Alert';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse, AuditLog } from '../../types';

export const AdminAuditLogsPage: React.FC = () => {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [moduleFilter, setModuleFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const fetchLogs = async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const params = new URLSearchParams();
      if (moduleFilter) params.append('module', moduleFilter);
      if (statusFilter) params.append('status', statusFilter);

      const res = await apiClient.get<ApiResponse<{ data: AuditLog[] }>>(
        `/admin/audit-logs?${params.toString()}`
      );
      setLogs(res.data.data.data || []);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchLogs();
  }, [moduleFilter, statusFilter]);

  return (
    <div className="space-y-6 text-left max-w-7xl mx-auto">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-black text-slate-900 tracking-tight">System Security & Audit Trail</h2>
          <p className="text-xs text-slate-500 mt-1">Immutable security event logs, logins, QR generation, and state transitions</p>
        </div>
        <Button variant="outline" size="sm" onClick={fetchLogs} isLoading={isLoading}>
          <RefreshCw className="h-3.5 w-3.5 mr-1.5" /> Refresh Logs
        </Button>
      </div>

      {errorMessage && <Alert type="error" message={errorMessage} />}

      {/* Filter Bar */}
      <Card className="p-4 border-slate-200">
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
          <div>
            <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">Module</label>
            <select
              value={moduleFilter}
              onChange={(e) => setModuleFilter(e.target.value)}
              className="w-full p-2 border border-slate-200 rounded-xl bg-white text-xs"
            >
              <option value="">All Modules</option>
              <option value="AUTH">AUTH</option>
              <option value="GATE">GATE</option>
              <option value="MOVEMENT">MOVEMENT</option>
              <option value="DUTY">DUTY</option>
              <option value="ADMIN">ADMIN</option>
            </select>
          </div>

          <div>
            <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">Status</label>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="w-full p-2 border border-slate-200 rounded-xl bg-white text-xs"
            >
              <option value="">All Statuses</option>
              <option value="SUCCESS">SUCCESS</option>
              <option value="FAILED">FAILED</option>
              <option value="WARNING">WARNING</option>
            </select>
          </div>

          <div className="flex items-end">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setModuleFilter('');
                setStatusFilter('');
              }}
              className="text-xs w-full"
            >
              Reset Filters
            </Button>
          </div>
        </div>
      </Card>

      {/* Audit Logs Table */}
      <Card className="p-0 border-slate-200 overflow-hidden shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full text-xs text-left">
            <thead className="text-[10px] text-slate-400 uppercase bg-slate-50 border-b border-slate-200">
              <tr>
                <th className="py-3 px-4">Action</th>
                <th className="py-3 px-4">Module</th>
                <th className="py-3 px-4">Actor</th>
                <th className="py-3 px-4">IP Address</th>
                <th className="py-3 px-4">Status</th>
                <th className="py-3 px-4 text-right">Server Timestamp (IST)</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 font-mono text-[11px]">
              {logs.length === 0 && !isLoading ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-400 font-sans">
                    Zero audit logs found.
                  </td>
                </tr>
              ) : (
                logs.map((l) => (
                  <tr key={l.id} className="hover:bg-slate-50">
                    <td className="py-3 px-4 font-bold text-slate-800">{l.action}</td>
                    <td className="py-3 px-4 text-slate-500">{l.module}</td>
                    <td className="py-3 px-4 font-sans text-slate-700">
                      {l.user?.name || 'System / Guest'}
                    </td>
                    <td className="py-3 px-4 text-slate-500">{l.ip_address || '-'}</td>
                    <td className="py-3 px-4 font-sans">
                      <span
                        className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                          l.status === 'SUCCESS'
                            ? 'bg-emerald-50 text-emerald-700'
                            : l.status === 'FAILED'
                            ? 'bg-rose-50 text-rose-700'
                            : 'bg-amber-50 text-amber-700'
                        }`}
                      >
                        {l.status}
                      </span>
                    </td>
                    <td className="py-3 px-4 text-right text-slate-500">
                      {new Date(l.server_timestamp).toLocaleString([], {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                      })}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
};
