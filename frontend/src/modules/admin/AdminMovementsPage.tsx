import React, { useState, useEffect } from 'react';
import { Download } from 'lucide-react';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import { apiClient, getErrorMessage, API_BASE_URL } from '../../api/client';
import type { ApiResponse, Movement, Gate } from '../../types';

export const AdminMovementsPage: React.FC = () => {
  const [movements, setMovements] = useState<Movement[]>([]);
  const [gates, setGates] = useState<Gate[]>([]);
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [gateId, setGateId] = useState('');
  const [type, setType] = useState<'ALL' | 'IN' | 'OUT'>('ALL');
  const [search, setSearch] = useState('');
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // 1-year boundary
  const minDate = new Date(Date.now() - 365 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
  const maxDate = new Date().toISOString().split('T')[0];

  useEffect(() => {
    apiClient.get<ApiResponse<Gate[]>>('/admin/gates').then((res) => {
      setGates(res.data.data);
    }).catch(() => {});
  }, []);

  const fetchMovements = async () => {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const params = new URLSearchParams();
      if (startDate) params.append('start_date', startDate);
      if (endDate) params.append('end_date', endDate);
      if (gateId) params.append('gate_id', gateId);
      if (type !== 'ALL') params.append('type', type);
      if (search.trim()) params.append('search', search.trim());

      const res = await apiClient.get<ApiResponse<{ data: Movement[] }>>(
        `/admin/movements?${params.toString()}`
      );
      setMovements(res.data.data.data || []);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchMovements();
  }, [type, gateId]);

  const handleExportCsv = () => {
    const token = localStorage.getItem('smartgate_token');
    window.open(`${API_BASE_URL}/admin/movements/export?token=${token}`, '_blank');
  };

  return (
    <div className="space-y-6 text-left max-w-7xl mx-auto">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-black text-slate-900 tracking-tight">University Gate Movement Logs</h2>
          <p className="text-xs text-slate-500 mt-1">Authoritative movement records (up to 1 year historical access)</p>
        </div>
        <Button variant="outline" onClick={handleExportCsv} className="text-xs font-bold">
          <Download className="h-4 w-4 mr-1.5 text-blue-600" /> Export CSV Report
        </Button>
      </div>

      {errorMessage && <Alert type="error" message={errorMessage} />}

      {/* Filter Bar */}
      <Card className="p-4 border-slate-200">
        <form
          onSubmit={(e) => {
            e.preventDefault();
            fetchMovements();
          }}
          className="space-y-3"
        >
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <div>
              <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">From Date</label>
              <input
                type="date"
                min={minDate}
                max={maxDate}
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className="w-full p-2 border border-slate-200 rounded-xl text-xs bg-white"
              />
            </div>

            <div>
              <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">To Date</label>
              <input
                type="date"
                min={minDate}
                max={maxDate}
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                className="w-full p-2 border border-slate-200 rounded-xl text-xs bg-white"
              />
            </div>

            <div>
              <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">Filter Gate</label>
              <select
                value={gateId}
                onChange={(e) => setGateId(e.target.value)}
                className="w-full p-2 border border-slate-200 rounded-xl text-xs bg-white"
              >
                <option value="">All Gates</option>
                {gates.map((g) => (
                  <option key={g.id} value={g.id}>
                    {g.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">Movement Type</label>
              <select
                value={type}
                onChange={(e) => setType(e.target.value as any)}
                className="w-full p-2 border border-slate-200 rounded-xl text-xs bg-white"
              >
                <option value="ALL">All (IN & OUT)</option>
                <option value="IN">IN (Entry)</option>
                <option value="OUT">OUT (Exit)</option>
              </select>
            </div>

            <div>
              <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">Search</label>
              <Input
                placeholder="Roll, Name, Code..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="text-xs py-1.5"
              />
            </div>
          </div>

          <div className="flex justify-end gap-2 pt-1">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => {
                setStartDate('');
                setEndDate('');
                setGateId('');
                setType('ALL');
                setSearch('');
              }}
              className="text-xs"
            >
              Reset Filters
            </Button>
            <Button type="submit" size="sm" className="text-xs px-5 font-bold" isLoading={isLoading}>
              Apply Filters
            </Button>
          </div>
        </form>
      </Card>

      {/* Movements Table */}
      <Card className="p-0 border-slate-200 overflow-hidden shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full text-xs text-left">
            <thead className="text-[10px] text-slate-400 uppercase bg-slate-50 border-b border-slate-200">
              <tr>
                <th className="py-3 px-4">Verification ID</th>
                <th className="py-3 px-4">Student</th>
                <th className="py-3 px-4">Gate</th>
                <th className="py-3 px-4">Type</th>
                <th className="py-3 px-4">Destination & Purpose</th>
                <th className="py-3 px-4">Vehicle</th>
                <th className="py-3 px-4 text-right">Server Timestamp (IST)</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {movements.length === 0 && !isLoading ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-slate-400 font-medium">
                    Zero movement records match the current filters.
                  </td>
                </tr>
              ) : (
                movements.map((m) => (
                  <tr key={m.id} className="hover:bg-slate-50">
                    <td className="py-3 px-4 font-mono font-bold text-blue-600">{m.verification_code}</td>
                    <td className="py-3 px-4">
                      <span className="font-bold text-slate-900 block">{m.student?.name || 'Student'}</span>
                      <span className="font-mono text-slate-500 text-[11px]">{m.student?.roll_number}</span>
                    </td>
                    <td className="py-3 px-4 font-semibold text-slate-800">{m.gate?.name}</td>
                    <td className="py-3 px-4">
                      <span
                        className={`px-2.5 py-1 rounded-full font-black text-[10px] ${
                          m.type === 'IN' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                        }`}
                      >
                        {m.type === 'IN' ? '🟢 IN' : '🔴 OUT'}
                      </span>
                    </td>
                    <td className="py-3 px-4">
                      {m.type === 'OUT' ? (
                        <div>
                          <span className="font-bold text-slate-800">{m.destination || '-'}</span>
                          <span className="text-slate-400 block text-[11px]">{m.purpose || '-'}</span>
                        </div>
                      ) : (
                        <span className="text-slate-400">-</span>
                      )}
                    </td>
                    <td className="py-3 px-4 font-mono">
                      {m.vehicle_present ? (
                        <span className="px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-bold text-[10px]">
                          {m.vehicle_number || 'VEHICLE'}
                        </span>
                      ) : (
                        <span className="text-slate-400">NO</span>
                      )}
                    </td>
                    <td className="py-3 px-4 text-right font-mono text-slate-500">
                      {new Date(m.server_timestamp).toLocaleString([], {
                        year: 'numeric',
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
