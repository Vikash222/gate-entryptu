import React, { useState, useEffect } from 'react';
import {
  BarChart3,
  Moon,
  Users,
  Search,
  FileSpreadsheet,
  AlertTriangle,
} from 'lucide-react';
import { Modal } from '../../components/ui/Modal';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Badge } from '../../components/ui/Badge';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse, MovementAnalytics, StudentMovementReport } from '../../types';

interface AdminAnalyticsModalProps {
  isOpen: boolean;
  onClose: () => void;
  activeFilterQuery: string; // The URL query string representing current filters (dates, gate, etc.)
  onExportExcel: () => void;
}

export const AdminAnalyticsModal: React.FC<AdminAnalyticsModalProps> = ({
  isOpen,
  onClose,
  activeFilterQuery,
  onExportExcel,
}) => {
  const [analytics, setAnalytics] = useState<MovementAnalytics | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Student Drilldown
  const [studentSearch, setStudentSearch] = useState('');
  const [searchingStudent, setSearchingStudent] = useState(false);
  const [studentReport, setStudentReport] = useState<StudentMovementReport | null>(null);
  const [studentError, setStudentError] = useState<string | null>(null);

  useEffect(() => {
    if (!isOpen) return;

    const fetchAnalytics = async () => {
      setIsLoading(true);
      setErrorMessage(null);
      try {
        const res = await apiClient.get<ApiResponse<MovementAnalytics>>(
          `/admin/reports/movements?${activeFilterQuery}`
        );
        setAnalytics(res.data.data);
      } catch (err) {
        setErrorMessage(getErrorMessage(err));
      } finally {
        setIsLoading(false);
      }
    };

    fetchAnalytics();
  }, [isOpen, activeFilterQuery]);

  const handleStudentSearch = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!studentSearch.trim()) return;

    setSearchingStudent(true);
    setStudentError(null);
    setStudentReport(null);

    try {
      // First look up student by roll number
      const searchRes = await apiClient.get<ApiResponse<{ data: any[] }>>(
        `/admin/students?search=${encodeURIComponent(studentSearch.trim())}`
      );
      const student = searchRes.data.data.data?.[0];
      if (!student) {
        setStudentError(`No student found matching "${studentSearch.trim()}".`);
        return;
      }

      // Fetch student movement report
      const repRes = await apiClient.get<ApiResponse<StudentMovementReport>>(
        `/admin/reports/students/${student.id}?${activeFilterQuery}`
      );
      setStudentReport(repRes.data.data);
    } catch (err) {
      setStudentError(getErrorMessage(err));
    } finally {
      setSearchingStudent(false);
    }
  };

  const summary = analytics?.summary;
  const lateRate = summary && summary.total_movements > 0
    ? Math.round((summary.late_count / summary.total_movements) * 100)
    : 0;

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={
        <div className="flex items-center gap-2">
          <BarChart3 className="h-5 w-5 text-blue-600" />
          <span className="text-base font-black text-slate-900">
            University Movement & Late Entry Analytics
          </span>
        </div>
      }
      description="Aggregated campus gate flow metrics, overnight late-window insights, and student drill-down"
      size="full"
    >
      <div className="space-y-6 text-left">
        {/* Top Actions Bar */}
        <div className="flex flex-wrap items-center justify-between gap-3 bg-slate-50 p-3 rounded-xl border border-slate-200">
          <div className="text-xs text-slate-600 font-medium">
            Active Dataset Filters: <span className="font-mono text-blue-600">{activeFilterQuery || 'All Default Timeframes'}</span>
          </div>
          <Button
            size="sm"
            onClick={onExportExcel}
            className="text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white flex items-center gap-1.5"
          >
            <FileSpreadsheet className="h-4 w-4" />
            Export Filtered Excel (.xlsx)
          </Button>
        </div>

        {errorMessage && <Alert type="error" message={errorMessage} />}

        {isLoading ? (
          <div className="py-16 text-center text-slate-400">
            <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mb-2" />
            <p className="text-xs font-medium">Computing movement aggregations & overnight trends...</p>
          </div>
        ) : summary ? (
          <>
            {/* KPI Summary Grid */}
            <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
              <Card className="p-3 border-slate-200">
                <span className="text-[10px] uppercase font-bold text-slate-400 block mb-1">Total Movements</span>
                <span className="text-2xl font-black text-slate-900">{summary.total_movements.toLocaleString()}</span>
                <div className="flex items-center gap-2 mt-2 text-[10px] font-semibold text-slate-500">
                  <span className="text-emerald-700 font-bold">🟢 {summary.in_count} IN</span>
                  <span>•</span>
                  <span className="text-rose-700 font-bold">🔴 {summary.out_count} OUT</span>
                </div>
              </Card>

              <Card className="p-3 border-amber-200 bg-amber-50/40">
                <div className="flex items-center justify-between mb-1">
                  <span className="text-[10px] uppercase font-bold text-amber-800">Late Movements</span>
                  <Moon className="h-3.5 w-3.5 text-amber-600" />
                </div>
                <span className="text-2xl font-black text-amber-900">{summary.late_count.toLocaleString()}</span>
                <div className="flex items-center gap-2 mt-2 text-[10px] font-semibold text-amber-700">
                  <span>{lateRate}% of total</span>
                  <span>•</span>
                  <span>{summary.late_in_count} IN / {summary.late_out_count} OUT</span>
                </div>
              </Card>

              <Card className="p-3 border-rose-200 bg-rose-50/40">
                <div className="flex items-center justify-between mb-1">
                  <span className="text-[10px] uppercase font-bold text-rose-800">Day Scholar After Hours</span>
                  <AlertTriangle className="h-3.5 w-3.5 text-rose-600" />
                </div>
                <span className="text-2xl font-black text-rose-900">
                  {(summary.total_after_hours ?? summary.after_hours_count ?? 0).toLocaleString()}
                </span>
                <span className="text-[10px] text-rose-700 block mt-2 font-medium">
                  After 5:00 PM (Allowed)
                </span>
              </Card>

              <Card className="p-3 border-slate-200">
                <span className="text-[10px] uppercase font-bold text-slate-400 block mb-1">Normal Movements</span>
                <span className="text-2xl font-black text-slate-800">{summary.normal_count.toLocaleString()}</span>
                <span className="text-[10px] text-slate-400 block mt-2">
                  {summary.total_movements > 0 ? (100 - lateRate) : 0}% regular hours
                </span>
              </Card>

              <Card className="p-3 border-slate-200">
                <div className="flex items-center justify-between mb-1">
                  <span className="text-[10px] uppercase font-bold text-slate-400">Active Participants</span>
                  <Users className="h-3.5 w-3.5 text-slate-500" />
                </div>
                <span className="text-2xl font-black text-slate-900">{summary.unique_students.toLocaleString()}</span>
                <span className="text-[10px] text-slate-500 block mt-2">
                  {summary.unique_guards} Guard{summary.unique_guards === 1 ? '' : 's'} on duty
                </span>
              </Card>
            </div>

            {/* Visual Ratio Bar: Late vs Normal */}
            <div className="p-4 bg-white border border-slate-200 rounded-2xl shadow-xs">
              <div className="flex justify-between items-center mb-2">
                <span className="text-xs font-bold text-slate-800">Late Movement Ratio (9:00 PM – 4:00 AM)</span>
                <span className="text-xs font-mono font-black text-amber-700">{lateRate}% Late</span>
              </div>
              <div className="w-full bg-slate-100 rounded-full h-3 flex overflow-hidden">
                <div
                  className="bg-amber-500 h-full transition-all duration-500"
                  style={{ width: `${lateRate}%` }}
                  title={`${summary.late_count} Late Movements (${lateRate}%)`}
                />
                <div
                  className="bg-emerald-500 h-full transition-all duration-500"
                  style={{ width: `${100 - lateRate}%` }}
                  title={`${summary.normal_count} Normal Movements`}
                />
              </div>
              <div className="flex justify-between items-center mt-2 text-[10px] text-slate-500 font-medium">
                <span className="flex items-center gap-1.5">
                  <span className="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block" />
                  Late ({summary.late_count})
                </span>
                <span className="flex items-center gap-1.5">
                  <span className="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block" />
                  Normal ({summary.normal_count})
                </span>
              </div>
            </div>

            {/* Gate Breakdown & Source Breakdown */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {/* Gate Stats */}
              <div className="p-4 bg-white border border-slate-200 rounded-2xl shadow-xs">
                <h4 className="text-xs font-bold text-slate-800 uppercase tracking-wider mb-3">
                  Gate Movement Distribution
                </h4>
                <div className="space-y-2.5">
                  {analytics.gate_stats.length === 0 ? (
                    <div className="text-xs text-slate-400 py-4 text-center">No gate movement records</div>
                  ) : (
                    analytics.gate_stats.map((g) => {
                      const pct = summary.total_movements > 0 ? Math.round((g.total / summary.total_movements) * 100) : 0;
                      return (
                        <div key={g.gate_id} className="text-xs space-y-1">
                          <div className="flex justify-between font-medium">
                            <span className="text-slate-800 font-bold">{g.gate_name}</span>
                            <span className="font-mono text-slate-500">
                              {g.total} ({pct}%) • <span className="text-amber-700 font-bold">{g.late} late</span>
                            </span>
                          </div>
                          <div className="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                            <div className="bg-blue-600 h-full rounded-full" style={{ width: `${pct}%` }} />
                          </div>
                        </div>
                      );
                    })
                  )}
                </div>
              </div>

              {/* Source Stats */}
              <div className="p-4 bg-white border border-slate-200 rounded-2xl shadow-xs">
                <h4 className="text-xs font-bold text-slate-800 uppercase tracking-wider mb-3">
                  Movement Source Breakdown
                </h4>
                <div className="space-y-3">
                  {analytics.source_stats.length === 0 ? (
                    <div className="text-xs text-slate-400 py-4 text-center">No source records</div>
                  ) : (
                    analytics.source_stats.map((s) => {
                      const pct = summary.total_movements > 0 ? Math.round((s.total / summary.total_movements) * 100) : 0;
                      const isManual = s.movement_source === 'SECURITY_MANUAL';
                      return (
                        <div key={s.movement_source} className="p-3 bg-slate-50 border border-slate-200 rounded-xl">
                          <div className="flex justify-between items-center mb-1">
                            <span className="font-bold text-slate-800 text-xs">
                              {isManual ? 'Security-Assisted Manual Entry' : 'Student Self-Service QR Scan'}
                            </span>
                            <Badge variant={isManual ? 'primary' : 'neutral'} size="sm">
                              {s.movement_source}
                            </Badge>
                          </div>
                          <div className="flex justify-between text-xs text-slate-500 font-mono mt-2">
                            <span>Total: <strong className="text-slate-900">{s.total}</strong> ({pct}%)</span>
                            <span>Late Movements: <strong className="text-amber-700">{s.late}</strong></span>
                          </div>
                        </div>
                      );
                    })
                  )}
                </div>
              </div>
            </div>

            {/* Daily Trends & Overnight Windows */}
            <div className="p-4 bg-white border border-slate-200 rounded-2xl shadow-xs">
              <h4 className="text-xs font-bold text-slate-800 uppercase tracking-wider mb-3">
                Daily Timeline & Overnight Late Windows
              </h4>
              <div className="overflow-x-auto">
                <table className="w-full text-xs text-left">
                  <thead className="text-[10px] text-slate-400 uppercase bg-slate-50 border-b border-slate-200">
                    <tr>
                      <th className="py-2.5 px-3">Date</th>
                      <th className="py-2.5 px-3">Total</th>
                      <th className="py-2.5 px-3">IN</th>
                      <th className="py-2.5 px-3">OUT</th>
                      <th className="py-2.5 px-3">Late Movements</th>
                      <th className="py-2.5 px-3">Normal Movements</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 font-mono">
                    {analytics.daily_trends.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-6 text-center text-slate-400">No trend data available</td>
                      </tr>
                    ) : (
                      analytics.daily_trends.map((d) => (
                        <tr key={d.date} className="hover:bg-slate-50">
                          <td className="py-2.5 px-3 font-bold text-slate-800">{d.date}</td>
                          <td className="py-2.5 px-3 font-bold text-slate-900">{d.total}</td>
                          <td className="py-2.5 px-3 text-emerald-700 font-semibold">{d.in_count}</td>
                          <td className="py-2.5 px-3 text-rose-700 font-semibold">{d.out_count}</td>
                          <td className="py-2.5 px-3">
                            {d.late_count > 0 ? (
                              <span className="px-2 py-0.5 rounded bg-amber-100 text-amber-800 font-bold">
                                🌙 {d.late_count}
                              </span>
                            ) : (
                              <span className="text-slate-400">0</span>
                            )}
                          </td>
                          <td className="py-2.5 px-3 text-slate-600">{d.normal_count}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Student Individual Drill-down */}
            <div className="p-4 bg-white border border-slate-200 rounded-2xl shadow-xs space-y-4">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-3">
                <div>
                  <h4 className="text-xs font-bold text-slate-900 uppercase tracking-wider">
                    Student Late-Entry Drill-down
                  </h4>
                  <p className="text-[11px] text-slate-500">
                    Analyze a specific student's movement record and late entry frequency
                  </p>
                </div>

                <form onSubmit={handleStudentSearch} className="flex items-center gap-2">
                  <Input
                    placeholder="Enter Roll Number..."
                    value={studentSearch}
                    onChange={(e) => setStudentSearch(e.target.value)}
                    className="text-xs py-1 px-2.5 w-44"
                  />
                  <Button
                    type="submit"
                    size="sm"
                    className="text-xs font-bold"
                    isLoading={searchingStudent}
                  >
                    <Search className="h-3.5 w-3.5 mr-1" /> Inspect
                  </Button>
                </form>
              </div>

              {studentError && <Alert type="error" message={studentError} />}

              {studentReport && (
                <div className="space-y-3 bg-slate-50 p-4 rounded-xl border border-slate-200">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      <h5 className="font-black text-slate-900 text-sm">
                        {studentReport.student.name}
                      </h5>
                      <div className="text-xs text-slate-500 font-mono">
                        Roll: <strong className="text-slate-800">{studentReport.student.roll_number}</strong> • ID: {studentReport.student.student_id}
                      </div>
                    </div>
                    <div className="flex items-center gap-3">
                      <div className="text-right">
                        <span className="text-[10px] uppercase font-bold text-slate-400 block">Late Rate</span>
                        <span className="text-base font-black text-amber-700">
                          {studentReport.late_rate_percentage}%
                        </span>
                      </div>
                      <Badge variant={studentReport.late_movements_count > 0 ? 'warning' : 'success'} size="md">
                        {studentReport.late_movements_count} Late Movement{studentReport.late_movements_count === 1 ? '' : 's'}
                      </Badge>
                    </div>
                  </div>

                  {/* Summary row */}
                  <div className="grid grid-cols-4 gap-2 text-center text-xs font-mono pt-2 border-t border-slate-200">
                    <div className="bg-white p-2 rounded-lg border border-slate-200">
                      <span className="text-[9px] text-slate-400 block uppercase font-sans">Total</span>
                      <strong>{studentReport.total_movements}</strong>
                    </div>
                    <div className="bg-white p-2 rounded-lg border border-slate-200">
                      <span className="text-[9px] text-slate-400 block uppercase font-sans">IN</span>
                      <strong className="text-emerald-700">{studentReport.in_count}</strong>
                    </div>
                    <div className="bg-white p-2 rounded-lg border border-slate-200">
                      <span className="text-[9px] text-slate-400 block uppercase font-sans">OUT</span>
                      <strong className="text-rose-700">{studentReport.out_count}</strong>
                    </div>
                    <div className="bg-white p-2 rounded-lg border border-amber-200 bg-amber-50/50">
                      <span className="text-[9px] text-amber-700 block uppercase font-sans">Late</span>
                      <strong className="text-amber-900">{studentReport.late_movements_count}</strong>
                    </div>
                  </div>

                  {/* Student Recent Movements List */}
                  {studentReport.movements.length > 0 && (
                    <div className="pt-2">
                      <span className="text-[10px] font-bold text-slate-500 uppercase block mb-1">
                        Recent Movements in Filtered Period:
                      </span>
                      <div className="space-y-1.5 max-h-48 overflow-y-auto pr-1">
                        {studentReport.movements.map((m) => (
                          <div
                            key={m.id}
                            className="flex items-center justify-between p-2 bg-white rounded-lg border border-slate-200 text-[11px]"
                          >
                            <div className="flex items-center gap-2">
                              <Badge variant={m.type === 'IN' ? 'success' : 'danger'} size="sm">
                                {m.type}
                              </Badge>
                              <span className="font-semibold text-slate-800">{m.gate?.name}</span>
                              <span className="font-mono text-slate-400 text-[10px]">[{m.verification_code}]</span>
                            </div>
                            <div className="flex items-center gap-2">
                              {m.is_late ? (
                                <span className="px-2 py-0.5 rounded bg-amber-100 text-amber-800 font-bold text-[10px]">
                                  🌙 LATE
                                </span>
                              ) : (
                                <span className="text-slate-400 text-[10px]">Normal</span>
                              )}
                              <span className="font-mono text-slate-500 text-[10px]">
                                {new Date(m.server_timestamp).toLocaleString('en-IN', {
                                  timeZone: 'Asia/Kolkata',
                                  day: '2-digit',
                                  month: 'short',
                                  hour: '2-digit',
                                  minute: '2-digit',
                                  hour12: true,
                                })}
                              </span>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              )}
            </div>
          </>
        ) : null}
      </div>
    </Modal>
  );
};
