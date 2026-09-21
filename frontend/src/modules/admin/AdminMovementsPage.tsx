import React, { useState, useEffect, useMemo } from 'react';
import {
  Download,
  FileSpreadsheet,
  BarChart3,
  Filter,
  Calendar,
  Moon,
  ChevronLeft,
  ChevronRight,
  RotateCcw,
  Eye,
  AlertTriangle,
} from 'lucide-react';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import { Avatar } from '../../components/ui/Avatar';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse, Movement, Gate } from '../../types';
import { MovementDetailModal } from './MovementDetailModal';
import { AdminAnalyticsModal } from './AdminAnalyticsModal';

type FilterTab = 'ALL' | 'IN' | 'OUT' | 'LATE' | 'AFTER_HOURS';
type DatePreset = 'TODAY' | 'YESTERDAY' | 'THIS_WEEK' | 'THIS_MONTH' | 'CUSTOM';

export const AdminMovementsPage: React.FC = () => {
  const [movements, setMovements] = useState<Movement[]>([]);
  const [gates, setGates] = useState<Gate[]>([]);
  const [activeTab, setActiveTab] = useState<FilterTab>('ALL');
  const [datePreset, setDatePreset] = useState<DatePreset>('CUSTOM');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [lateWindowDate, setLateWindowDate] = useState('');
  const [gateId, setGateId] = useState('');
  const [source, setSource] = useState<'' | 'QR' | 'SECURITY_MANUAL'>('');
  const [categoryFilter, setCategoryFilter] = useState<'' | 'HOSTELER' | 'DAY_SCHOLAR'>('');
  const [afterHoursFilter, setAfterHoursFilter] = useState<'' | 'true' | 'false'>('');
  const [search, setSearch] = useState('');

  // Pagination state
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [totalRecords, setTotalRecords] = useState(0);

  // Modals & Export state
  const [selectedMovement, setSelectedMovement] = useState<Movement | null>(null);
  const [isAnalyticsModalOpen, setIsAnalyticsModalOpen] = useState(false);
  const [isExportingExcel, setIsExportingExcel] = useState(false);
  const [isExportingCsv, setIsExportingCsv] = useState(false);

  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // 1-year boundary
  const minDate = useMemo(() => {
    const d = new Date();
    d.setFullYear(d.getFullYear() - 1);
    return d.toISOString().split('T')[0];
  }, []);
  const maxDate = useMemo(() => new Date().toISOString().split('T')[0], []);

  useEffect(() => {
    apiClient
      .get<ApiResponse<Gate[]>>('/admin/gates')
      .then((res) => {
        setGates(res.data.data || []);
      })
      .catch(() => {});
  }, []);

  // Quick Date Preset Handler
  const handlePresetSelect = (preset: DatePreset) => {
    setDatePreset(preset);
    const today = new Date();

    if (preset === 'TODAY') {
      const todayStr = today.toISOString().split('T')[0];
      setStartDate(todayStr);
      setEndDate(todayStr);
    } else if (preset === 'YESTERDAY') {
      const y = new Date(today);
      y.setDate(today.getDate() - 1);
      const yStr = y.toISOString().split('T')[0];
      setStartDate(yStr);
      setEndDate(yStr);
    } else if (preset === 'THIS_WEEK') {
      const day = today.getDay();
      const diff = today.getDate() - day + (day === 0 ? -6 : 1); // Monday
      const monday = new Date(today.setDate(diff));
      setStartDate(monday.toISOString().split('T')[0]);
      setEndDate(new Date().toISOString().split('T')[0]);
    } else if (preset === 'THIS_MONTH') {
      const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
      setStartDate(startOfMonth.toISOString().split('T')[0]);
      setEndDate(new Date().toISOString().split('T')[0]);
    } else if (preset === 'CUSTOM') {
      setStartDate('');
      setEndDate('');
    }
  };

  // Build filter query params
  const buildFilterParams = () => {
    const params = new URLSearchParams();

    if (startDate) params.append('start_date', startDate);
    if (endDate) params.append('end_date', endDate);
    if (lateWindowDate) params.append('late_window_date', lateWindowDate);
    if (gateId) params.append('gate_id', gateId);
    if (source) params.append('movement_source', source);
    if (categoryFilter) params.append('category', categoryFilter);
    if (afterHoursFilter) params.append('day_scholar_after_hours', afterHoursFilter);
    if (search.trim()) params.append('search', search.trim());

    if (activeTab === 'IN') {
      params.append('type', 'IN');
    } else if (activeTab === 'OUT') {
      params.append('type', 'OUT');
    } else if (activeTab === 'LATE') {
      params.append('late', 'true');
    } else if (activeTab === 'AFTER_HOURS') {
      params.append('day_scholar_after_hours', 'true');
    }

    return params;
  };

  const fetchMovements = async (targetPage = page) => {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const params = buildFilterParams();
      params.append('page', targetPage.toString());
      params.append('per_page', '30');

      const res = await apiClient.get<ApiResponse<{
        data: Movement[];
        current_page: number;
        last_page: number;
        total: number;
      }>>(`/admin/movements?${params.toString()}`);

      const paginatedData = res.data.data;
      setMovements(paginatedData.data || []);
      setPage(paginatedData.current_page || 1);
      setLastPage(paginatedData.last_page || 1);
      setTotalRecords(paginatedData.total || 0);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchMovements(1);
  }, [activeTab, gateId, source, categoryFilter, afterHoursFilter, startDate, endDate, lateWindowDate]);

  // Export handlers with authenticated blob download
  const handleExport = async (format: 'xlsx' | 'csv') => {
    if (format === 'xlsx') setIsExportingExcel(true);
    else setIsExportingCsv(true);

    try {
      const params = buildFilterParams();
      params.append('format', format);

      const res = await apiClient.get(`/admin/movements/export?${params.toString()}`, {
        responseType: 'blob',
      });

      const disposition = res.headers['content-disposition'];
      let filename = `smartgate_movements_${new Date().toISOString().split('T')[0]}.${format}`;
      if (disposition && disposition.includes('filename=')) {
        const matches = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(disposition);
        if (matches != null && matches[1]) {
          filename = matches[1].replace(/['"]/g, '');
        }
      }

      const blob = new Blob([res.data], {
        type:
          format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv',
      });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', filename);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      setErrorMessage(`Export failed: ${getErrorMessage(err)}`);
    } finally {
      if (format === 'xlsx') setIsExportingExcel(false);
      else setIsExportingCsv(false);
    }
  };

  const handleResetFilters = () => {
    setActiveTab('ALL');
    setDatePreset('CUSTOM');
    setStartDate('');
    setEndDate('');
    setLateWindowDate('');
    setGateId('');
    setSource('');
    setCategoryFilter('');
    setAfterHoursFilter('');
    setSearch('');
  };

  return (
    <div className="space-y-6 text-left max-w-7xl mx-auto">
      {/* Header & Export Actions */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-black text-slate-900 tracking-tight">
            University Gate Movement Logs
          </h2>
          <p className="text-xs text-slate-500 mt-1">
            Authoritative movement audit trail (9 PM–4 AM Late Window automatic classification)
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => setIsAnalyticsModalOpen(true)}
            className="text-xs font-bold border-blue-200 text-blue-700 hover:bg-blue-50"
          >
            <BarChart3 className="h-4 w-4 mr-1.5 text-blue-600" />
            Generate Report
          </Button>

          <Button
            size="sm"
            onClick={() => handleExport('xlsx')}
            isLoading={isExportingExcel}
            className="text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white"
          >
            <FileSpreadsheet className="h-4 w-4 mr-1.5" />
            Export Excel
          </Button>

          <Button
            variant="outline"
            size="sm"
            onClick={() => handleExport('csv')}
            isLoading={isExportingCsv}
            className="text-xs font-bold"
          >
            <Download className="h-4 w-4 mr-1.5 text-slate-600" />
            CSV
          </Button>
        </div>
      </div>

      {errorMessage && <Alert type="error" message={errorMessage} />}

      {/* Primary Movement Tabs */}
      <div className="flex items-center gap-1.5 border-b border-slate-200 pb-2 overflow-x-auto">
        <button
          type="button"
          onClick={() => setActiveTab('ALL')}
          className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
            activeTab === 'ALL'
              ? 'bg-slate-900 text-white shadow-xs'
              : 'text-slate-600 hover:bg-slate-100'
          }`}
        >
          ALL MOVEMENTS
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('IN')}
          className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
            activeTab === 'IN'
              ? 'bg-emerald-600 text-white shadow-xs'
              : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-700'
          }`}
        >
          <span className="w-2 h-2 rounded-full bg-emerald-400 inline-block" />
          IN (ENTRIES)
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('OUT')}
          className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
            activeTab === 'OUT'
              ? 'bg-rose-600 text-white shadow-xs'
              : 'text-slate-600 hover:bg-rose-50 hover:text-rose-700'
          }`}
        >
          <span className="w-2 h-2 rounded-full bg-rose-400 inline-block" />
          OUT (EXITS)
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('LATE')}
          className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
            activeTab === 'LATE'
              ? 'bg-amber-600 text-white shadow-xs'
              : 'text-amber-800 bg-amber-50 hover:bg-amber-100 border border-amber-200'
          }`}
        >
          <Moon className="h-3.5 w-3.5 text-amber-500" />
          LATE ENTRIES (9 PM – 4 AM)
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('AFTER_HOURS')}
          className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
            activeTab === 'AFTER_HOURS'
              ? 'bg-rose-700 text-white shadow-xs'
              : 'text-rose-800 bg-rose-50 hover:bg-rose-100 border border-rose-200'
          }`}
        >
          <AlertTriangle className="h-3.5 w-3.5 text-rose-600" />
          DAY SCHOLAR — AFTER HOURS
        </button>
      </div>

      {/* Filter Bar */}
      <Card className="p-4 border-slate-200 space-y-3">
        {/* Quick Date Presets */}
        <div className="flex flex-wrap items-center gap-2 pb-2 border-b border-slate-100">
          <span className="text-[10px] font-bold uppercase text-slate-400 mr-1 flex items-center gap-1">
            <Calendar className="h-3 w-3" /> Presets:
          </span>
          {(['TODAY', 'YESTERDAY', 'THIS_WEEK', 'THIS_MONTH', 'CUSTOM'] as DatePreset[]).map((p) => (
            <button
              key={p}
              type="button"
              onClick={() => handlePresetSelect(p)}
              className={`px-2.5 py-1 rounded-lg text-[11px] font-semibold transition ${
                datePreset === p
                  ? 'bg-blue-600 text-white shadow-xs'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              {p === 'TODAY'
                ? 'Today'
                : p === 'YESTERDAY'
                ? 'Yesterday'
                : p === 'THIS_WEEK'
                ? 'This Week'
                : p === 'THIS_MONTH'
                ? 'This Month'
                : 'Custom Range'}
            </button>
          ))}
        </div>

        <form
          onSubmit={(e) => {
            e.preventDefault();
            fetchMovements(1);
          }}
          className="space-y-3"
        >
          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">
            <div>
              <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">
                From Date
              </label>
              <input
                type="date"
                min={minDate}
                max={maxDate}
                value={startDate}
                onChange={(e) => {
                  setDatePreset('CUSTOM');
                  setStartDate(e.target.value);
                }}
                className="w-full p-2 border border-slate-200 rounded-xl text-xs bg-white focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div>
              <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">
                To Date
              </label>
              <input
                type="date"
                min={minDate}
                max={maxDate}
                value={endDate}
                onChange={(e) => {
                  setDatePreset('CUSTOM');
                  setEndDate(e.target.value);
                }}
                className="w-full p-2 border border-slate-200 rounded-xl text-xs bg-white focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div>
              <label className="block text-[10px] font-bold text-amber-700 uppercase mb-1 flex items-center gap-1">
                <Moon className="h-2.5 w-2.5" /> Late Window
              </label>
              <input
                type="date"
                title="Overnight Window (9 PM of date to 4 AM next day)"
                value={lateWindowDate}
                onChange={(e) => setLateWindowDate(e.target.value)}
                className="w-full p-2 border border-amber-200 bg-amber-50/40 rounded-xl text-xs focus:ring-2 focus:ring-amber-500"
              />
            </div>

            <div>
              <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">
                Gate Location
              </label>
              <select
                value={gateId}
                onChange={(e) => setGateId(e.target.value)}
                className="w-full p-2 border border-slate-200 rounded-xl text-xs bg-white focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All Gates</option>
                {gates.map((g) => (
                  <option key={g.id} value={g.id}>
                    {g.name} ({g.code})
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">
                Source
              </label>
              <select
                value={source}
                onChange={(e) => setSource(e.target.value as any)}
                className="w-full p-2 border border-slate-200 rounded-xl text-xs bg-white focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All Sources</option>
                <option value="QR">QR Scan</option>
                <option value="SECURITY_MANUAL">Manual</option>
              </select>
            </div>

            <div>
              <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">
                Category
              </label>
              <select
                value={categoryFilter}
                onChange={(e) => setCategoryFilter(e.target.value as any)}
                className="w-full p-2 border border-slate-200 rounded-xl text-xs bg-white focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All Categories</option>
                <option value="HOSTELER">Hostelers</option>
                <option value="DAY_SCHOLAR">Day Scholars</option>
              </select>
            </div>

            <div>
              <label className="block text-[10px] font-bold text-rose-700 uppercase mb-1 flex items-center gap-1">
                <AlertTriangle className="h-2.5 w-2.5" /> Timing
              </label>
              <select
                value={afterHoursFilter}
                onChange={(e) => setAfterHoursFilter(e.target.value as any)}
                className="w-full p-2 border border-rose-200 bg-rose-50/40 rounded-xl text-xs focus:ring-2 focus:ring-rose-500"
              >
                <option value="">All Hours</option>
                <option value="true">After Hours (5PM+)</option>
                <option value="false">Standard Hours</option>
              </select>
            </div>

            <div>
              <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">
                Search
              </label>
              <Input
                placeholder="Roll, Name..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="text-xs py-1.5"
              />
            </div>
          </div>

          <div className="flex items-center justify-between pt-1">
            <span className="text-xs text-slate-500 font-mono">
              Found <strong className="text-slate-900">{totalRecords.toLocaleString()}</strong> record
              {totalRecords === 1 ? '' : 's'}
            </span>

            <div className="flex gap-2">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={handleResetFilters}
                className="text-xs flex items-center gap-1"
              >
                <RotateCcw className="h-3 w-3" /> Reset
              </Button>
              <Button
                type="submit"
                size="sm"
                className="text-xs px-5 font-bold flex items-center gap-1.5"
                isLoading={isLoading}
              >
                <Filter className="h-3 w-3" /> Apply Filters
              </Button>
            </div>
          </div>
        </form>
      </Card>

      {/* Movements Table */}
      <Card className="p-0 border-slate-200 overflow-hidden shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full text-xs text-left">
            <thead className="text-[10px] text-slate-400 uppercase bg-slate-50 border-b border-slate-200 select-none">
              <tr>
                <th className="py-3 px-4">Verification ID</th>
                <th className="py-3 px-4">Student</th>
                <th className="py-3 px-4">Gate</th>
                <th className="py-3 px-4">Type</th>
                <th className="py-3 px-4">Source</th>
                <th className="py-3 px-4">Classification</th>
                <th className="py-3 px-4">Transit & Vehicle</th>
                <th className="py-3 px-4 text-right">Server Timestamp (IST)</th>
                <th className="py-3 px-4 text-center">Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {movements.length === 0 && !isLoading ? (
                <tr>
                  <td colSpan={9} className="py-12 text-center text-slate-400 font-medium">
                    Zero movement records match the current filters.
                  </td>
                </tr>
              ) : (
                movements.map((m) => (
                  <tr
                    key={m.id}
                    onClick={() => setSelectedMovement(m)}
                    className="hover:bg-slate-50/80 cursor-pointer transition"
                  >
                    <td className="py-3 px-4 font-mono font-bold text-blue-600">
                      {m.verification_code}
                    </td>
                    <td className="py-3 px-4">
                      <div className="flex items-center gap-2.5">
                        <Avatar
                          src={m.student?.profile_photo_url}
                          name={m.student?.name || 'Student'}
                          size="sm"
                          shape="rounded"
                        />
                        <div>
                          <div className="flex items-center gap-1">
                            <span className="font-bold text-slate-900 block leading-tight">
                              {m.student?.name || 'Student'}
                            </span>
                            {m.student?.category === 'DAY_SCHOLAR' && (
                              <span className="px-1 py-0.2 rounded text-[9px] font-bold bg-purple-50 text-purple-700 border border-purple-200">
                                Day
                              </span>
                            )}
                          </div>
                          <span className="font-mono text-slate-500 text-[11px]">
                            {m.student?.roll_number}
                          </span>
                        </div>
                      </div>
                    </td>
                    <td className="py-3 px-4 font-semibold text-slate-800">
                      {m.gate?.name}
                    </td>
                    <td className="py-3 px-4">
                      <span
                        className={`px-2.5 py-1 rounded-full font-black text-[10px] ${
                          m.type === 'IN'
                            ? 'bg-emerald-100 text-emerald-800'
                            : 'bg-rose-100 text-rose-800'
                        }`}
                      >
                        {m.type === 'IN' ? '🟢 IN' : '🔴 OUT'}
                      </span>
                    </td>
                    <td className="py-3 px-4">
                      <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-medium">
                        {m.movement_source === 'SECURITY_MANUAL' ? 'MANUAL' : 'QR SCAN'}
                      </span>
                    </td>
                    <td className="py-3 px-4">
                      <div className="flex flex-col gap-1 items-start">
                        {m.day_scholar_after_hours && (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-rose-100 text-rose-800 font-black text-[10px] border border-rose-300">
                            <AlertTriangle className="h-3 w-3 text-rose-600" />
                            DAY SCHOLAR — AFTER HOURS
                          </span>
                        )}
                        {m.is_late ? (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-900 font-black text-[10px] border border-amber-200">
                            <Moon className="h-3 w-3 text-amber-600" />
                            LATE
                          </span>
                        ) : !m.day_scholar_after_hours ? (
                          <span className="text-[10px] font-semibold text-slate-400 px-2 py-0.5 rounded bg-slate-50">
                            NORMAL
                          </span>
                        ) : null}
                      </div>
                    </td>
                    <td className="py-3 px-4">
                      {m.type === 'OUT' ? (
                        <div>
                          <span className="font-bold text-slate-800">{m.destination || '-'}</span>
                          <span className="text-slate-400 block text-[11px]">{m.purpose || '-'}</span>
                        </div>
                      ) : (
                        <span className="text-slate-400">Campus Entry</span>
                      )}
                      {m.vehicle_present && (
                        <span className="inline-block mt-0.5 text-[10px] font-mono font-bold text-blue-600 bg-blue-50 px-1.5 py-0.2 rounded">
                          🚗 {m.vehicle_number || 'VEHICLE'}
                        </span>
                      )}
                    </td>
                    <td className="py-3 px-4 text-right font-mono text-slate-500">
                      {new Date(m.server_timestamp).toLocaleString('en-IN', {
                        timeZone: 'Asia/Kolkata',
                        year: 'numeric',
                        month: 'short',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true,
                      })}
                    </td>
                    <td className="py-3 px-4 text-center">
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={(e) => {
                          e.stopPropagation();
                          setSelectedMovement(m);
                        }}
                        className="text-slate-400 hover:text-blue-600 p-1"
                        title="View Audit Details"
                      >
                        <Eye className="h-4 w-4" />
                      </Button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination Bar */}
        <div className="flex items-center justify-between p-3 border-t border-slate-200 bg-slate-50 text-xs">
          <span className="text-slate-500">
            Page <strong className="text-slate-800">{page}</strong> of{' '}
            <strong className="text-slate-800">{lastPage}</strong>
          </span>

          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={page <= 1 || isLoading}
              onClick={() => fetchMovements(page - 1)}
              className="text-xs py-1 px-2.5"
            >
              <ChevronLeft className="h-3.5 w-3.5 mr-1" /> Previous
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={page >= lastPage || isLoading}
              onClick={() => fetchMovements(page + 1)}
              className="text-xs py-1 px-2.5"
            >
              Next <ChevronRight className="h-3.5 w-3.5 ml-1" />
            </Button>
          </div>
        </div>
      </Card>

      {/* Movement Detail Audit Modal */}
      <MovementDetailModal
        isOpen={!!selectedMovement}
        onClose={() => setSelectedMovement(null)}
        movement={selectedMovement}
      />

      {/* Movement Analytics Modal */}
      <AdminAnalyticsModal
        isOpen={isAnalyticsModalOpen}
        onClose={() => setIsAnalyticsModalOpen(false)}
        activeFilterQuery={buildFilterParams().toString()}
        onExportExcel={() => handleExport('xlsx')}
      />
    </div>
  );
};
