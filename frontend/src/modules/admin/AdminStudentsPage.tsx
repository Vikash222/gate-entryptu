import React, { useState, useEffect, useRef } from 'react';
import {
  Plus,
  Search,
  CheckCircle2,
  XCircle,
  AlertTriangle,
  RotateCcw,
  Eye,
  Clock,
  GraduationCap,
  Shield,
  Phone,
  Mail,
  Calendar,
  Camera,
  Trash2,
  Loader2,
  UserCheck,
} from 'lucide-react';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Modal } from '../../components/ui/Modal';
import { Alert } from '../../components/ui/Alert';
import { Badge } from '../../components/ui/Badge';
import { Avatar, clearPhotoCache } from '../../components/ui/Avatar';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse, StudentProfile, StudentAccountStatus } from '../../types';

type FilterTab = 'ALL' | 'PENDING' | 'ACTIVE' | 'SUSPENDED' | 'REJECTED';

export const AdminStudentsPage: React.FC = () => {
  const [students, setStudents] = useState<StudentProfile[]>([]);
  const [search, setSearch] = useState('');
  const [activeTab, setActiveTab] = useState<FilterTab>('ALL');
  const [categoryFilter, setCategoryFilter] = useState<'ALL' | 'HOSTELER' | 'DAY_SCHOLAR'>('ALL');
  const [pendingCount, setPendingCount] = useState<number>(0);
  const [isLoading, setIsLoading] = useState(true);
  const [actionLoadingId, setActionLoadingId] = useState<number | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Selected Student for Full Verification Modal
  const [selectedStudent, setSelectedStudent] = useState<StudentProfile | null>(null);
  const [adminPhotoUploading, setAdminPhotoUploading] = useState(false);
  const [adminPhotoError, setAdminPhotoError] = useState<string | null>(null);
  const adminFileInputRef = useRef<HTMLInputElement>(null);

  // Manual Add Student Modal Form
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    password: '',
    student_id: '',
    roll_number: '',
    category: 'HOSTELER' as 'HOSTELER' | 'DAY_SCHOLAR',
    phone_number: '',
    program: 'B.Tech',
    department: 'Computer Science',
    year: '1',
    semester: '1',
    batch: '2024-2028',
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [modalError, setModalError] = useState<string | null>(null);

  const fetchStudents = async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const params = new URLSearchParams();
      if (search.trim()) params.append('search', search.trim());
      if (activeTab !== 'ALL') params.append('status', activeTab);
      if (categoryFilter !== 'ALL') params.append('category', categoryFilter);

      const [res, pendingRes] = await Promise.all([
        apiClient.get<ApiResponse<{ data: StudentProfile[]; total?: number }>>(
          `/admin/students?${params.toString()}`
        ),
        apiClient.get<ApiResponse<{ total: number }>>('/admin/students?status=PENDING'),
      ]);
      setStudents(res.data.data.data || []);
      setPendingCount(pendingRes.data.data.total ?? 0);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchStudents();
  }, [activeTab, categoryFilter]);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    fetchStudents();
  };

  const handleAdminPhotoUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    if (!selectedStudent) return;
    const file = e.target.files?.[0];
    if (!file) return;

    if (file.size > 102400) {
      setAdminPhotoError(`Profile photo must be 100 KB or smaller. (Selected file: ${(file.size / 1024).toFixed(1)} KB)`);
      if (adminFileInputRef.current) adminFileInputRef.current.value = '';
      return;
    }

    setAdminPhotoUploading(true);
    setAdminPhotoError(null);

    const data = new FormData();
    data.append('profile_photo', file);

    try {
      const res = await apiClient.post<ApiResponse<{ student: StudentProfile }>>(
        `/admin/students/${selectedStudent.id}/photo`,
        data,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      );
      setSelectedStudent(res.data.data.student);
      clearPhotoCache(selectedStudent.profile_photo_url || undefined);
      setSuccessMessage('Student profile photo updated successfully.');
      fetchStudents();
    } catch (err) {
      setAdminPhotoError(getErrorMessage(err));
    } finally {
      setAdminPhotoUploading(false);
      if (adminFileInputRef.current) adminFileInputRef.current.value = '';
    }
  };

  const handleAdminPhotoDelete = async () => {
    if (!selectedStudent) return;
    setAdminPhotoUploading(true);
    setAdminPhotoError(null);
    try {
      const res = await apiClient.delete<ApiResponse<{ student: StudentProfile }>>(
        `/admin/students/${selectedStudent.id}/photo`
      );
      clearPhotoCache(selectedStudent.profile_photo_url || undefined);
      setSelectedStudent(res.data.data.student);
      setSuccessMessage('Student profile photo removed successfully.');
      fetchStudents();
    } catch (err) {
      setAdminPhotoError(getErrorMessage(err));
    } finally {
      setAdminPhotoUploading(false);
    }
  };

  // Student Lifecycle Actions
  const handleApprove = async (studentId: number) => {
    setActionLoadingId(studentId);
    setErrorMessage(null);
    setSuccessMessage(null);
    try {
      const res = await apiClient.post<ApiResponse<StudentProfile>>(`/admin/students/${studentId}/approve`);
      setSuccessMessage(res.data.message || 'Student registration approved successfully. Gate access enabled.');
      if (selectedStudent?.id === studentId) {
        setSelectedStudent(res.data.data);
      }
      fetchStudents();
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setActionLoadingId(null);
    }
  };

  const handleReject = async (studentId: number) => {
    if (!window.confirm('Are you sure you want to reject this student registration? Gate access will remain locked.')) {
      return;
    }
    setActionLoadingId(studentId);
    setErrorMessage(null);
    setSuccessMessage(null);
    try {
      const res = await apiClient.post<ApiResponse<StudentProfile>>(`/admin/students/${studentId}/reject`);
      setSuccessMessage(res.data.message || 'Student registration rejected.');
      if (selectedStudent?.id === studentId) {
        setSelectedStudent(res.data.data);
      }
      fetchStudents();
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setActionLoadingId(null);
    }
  };

  const handleSuspend = async (studentId: number) => {
    if (!window.confirm('Suspend student gate access? Active gate entry tokens will be immediately revoked.')) {
      return;
    }
    setActionLoadingId(studentId);
    setErrorMessage(null);
    setSuccessMessage(null);
    try {
      const res = await apiClient.post<ApiResponse<StudentProfile>>(`/admin/students/${studentId}/suspend`);
      setSuccessMessage(res.data.message || 'Student gate entry access suspended.');
      if (selectedStudent?.id === studentId) {
        setSelectedStudent(res.data.data);
      }
      fetchStudents();
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setActionLoadingId(null);
    }
  };

  const handleReactivate = async (studentId: number) => {
    setActionLoadingId(studentId);
    setErrorMessage(null);
    setSuccessMessage(null);
    try {
      const res = await apiClient.post<ApiResponse<StudentProfile>>(`/admin/students/${studentId}/reactivate`);
      setSuccessMessage(res.data.message || 'Student account reactivated.');
      if (selectedStudent?.id === studentId) {
        setSelectedStudent(res.data.data);
      }
      fetchStudents();
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setActionLoadingId(null);
    }
  };

  const handleCreateStudent = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setModalError(null);

    try {
      await apiClient.post('/admin/students', {
        ...formData,
        year: parseInt(formData.year) || 1,
        semester: parseInt(formData.semester) || 1,
      });

      setIsModalOpen(false);
      setSuccessMessage('Student registered successfully.');
      setFormData({
        name: '',
        email: '',
        password: '',
        student_id: '',
        roll_number: '',
        category: 'HOSTELER',
        phone_number: '',
        program: 'B.Tech',
        department: 'Computer Science',
        year: '1',
        semester: '1',
        batch: '2024-2028',
      });
      fetchStudents();
    } catch (err) {
      setModalError(getErrorMessage(err));
    } finally {
      setIsSubmitting(false);
    }
  };

  const renderStatusBadge = (status?: StudentAccountStatus) => {
    switch (status) {
      case 'PENDING':
        return (
          <Badge variant="warning" size="sm" className="gap-1 font-bold">
            <Clock className="h-3 w-3" /> PENDING
          </Badge>
        );
      case 'ACTIVE':
        return (
          <Badge variant="success" size="sm" className="gap-1 font-bold">
            <CheckCircle2 className="h-3 w-3" /> ACTIVE
          </Badge>
        );
      case 'SUSPENDED':
        return (
          <Badge variant="danger" size="sm" className="gap-1 font-bold bg-rose-50 text-rose-700 border-rose-200">
            <AlertTriangle className="h-3 w-3" /> SUSPENDED
          </Badge>
        );
      case 'REJECTED':
        return (
          <Badge variant="danger" size="sm" className="gap-1 font-bold">
            <XCircle className="h-3 w-3" /> REJECTED
          </Badge>
        );
      default:
        return (
          <Badge variant="neutral" size="sm">
            {status || 'UNKNOWN'}
          </Badge>
        );
    }
  };

  return (
    <div className="space-y-6 text-left max-w-7xl mx-auto">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-black text-slate-900 tracking-tight">Student Accounts & Approvals</h2>
          <p className="text-xs text-slate-500 mt-1">
            Review self-registrations, verify enrollments, and manage gate entry authorization lifecycle
          </p>
        </div>
        <Button onClick={() => setIsModalOpen(true)} className="text-xs font-bold self-start sm:self-auto">
          <Plus className="h-4 w-4 mr-1.5" /> Manual Enrollment
        </Button>
      </div>

      {errorMessage && <Alert type="error" message={errorMessage} />}
      {successMessage && <Alert type="success" message={successMessage} />}

      {/* Pending Applications Notice */}
      {pendingCount > 0 && activeTab !== 'PENDING' && (
        <div className="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-amber-100 rounded-xl text-amber-700">
              <Clock className="h-5 w-5" />
            </div>
            <div>
              <p className="text-xs font-bold text-amber-900">
                {pendingCount} Student Self-Registration{pendingCount > 1 ? 's' : ''} Awaiting Approval
              </p>
              <p className="text-[11px] text-amber-700 mt-0.5">
                Newly registered students cannot use Gate Entry until approved by University Administration.
              </p>
            </div>
          </div>
          <Button
            size="sm"
            className="text-xs font-bold whitespace-nowrap bg-amber-600 hover:bg-amber-700 text-white"
            onClick={() => setActiveTab('PENDING')}
          >
            Review Pending
          </Button>
        </div>
      )}

      {/* Filter Tabs & Search */}
      <Card className="p-4 border-slate-200 space-y-4">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
          {/* Tabs */}
          <div className="flex flex-wrap items-center gap-1.5 bg-slate-100 p-1.5 rounded-xl text-xs font-bold">
            <button
              type="button"
              onClick={() => setActiveTab('ALL')}
              className={`px-3 py-1.5 rounded-lg transition-all ${
                activeTab === 'ALL' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'
              }`}
            >
              All Students
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('PENDING')}
              className={`px-3 py-1.5 rounded-lg transition-all flex items-center gap-1.5 ${
                activeTab === 'PENDING'
                  ? 'bg-amber-500 text-white shadow-sm'
                  : 'text-amber-700 hover:text-amber-800'
              }`}
            >
              <span>Pending Approval</span>
              {pendingCount > 0 && (
                <span className={`px-1.5 py-0.2 rounded-full text-[10px] font-black ${
                  activeTab === 'PENDING' ? 'bg-white text-amber-700' : 'bg-amber-200 text-amber-800'
                }`}>
                  {pendingCount}
                </span>
              )}
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('ACTIVE')}
              className={`px-3 py-1.5 rounded-lg transition-all ${
                activeTab === 'ACTIVE' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'
              }`}
            >
              Active
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('SUSPENDED')}
              className={`px-3 py-1.5 rounded-lg transition-all ${
                activeTab === 'SUSPENDED' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'
              }`}
            >
              Suspended
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('REJECTED')}
              className={`px-3 py-1.5 rounded-lg transition-all ${
                activeTab === 'REJECTED' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'
              }`}
            >
              Rejected
            </button>
          </div>

          {/* Category Filter & Search */}
          <div className="flex flex-wrap items-center gap-2">
            <select
              value={categoryFilter}
              onChange={(e) => setCategoryFilter(e.target.value as any)}
              className="text-xs font-semibold px-2.5 py-2 rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="ALL">All Categories</option>
              <option value="HOSTELER">Hostelers Only</option>
              <option value="DAY_SCHOLAR">Day Scholars Only</option>
            </select>

            <form onSubmit={handleSearchSubmit} className="flex gap-2">
              <Input
                placeholder="Search Roll No, Name, Phone..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                leftIcon={<Search className="h-4 w-4" />}
                className="text-xs w-60"
              />
              <Button type="submit" size="md" className="text-xs font-bold px-4">
                Filter
              </Button>
            </form>
          </div>
        </div>
      </Card>

      {/* Students Data Table */}
      <Card className="p-0 border-slate-200 overflow-hidden shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full text-xs text-left">
            <thead className="text-[10px] text-slate-400 uppercase bg-slate-50 border-b border-slate-200">
              <tr>
                <th className="py-3 px-4">Roll Number</th>
                <th className="py-3 px-4">Student Name</th>
                <th className="py-3 px-4">Program / Branch</th>
                <th className="py-3 px-4">Account Status</th>
                <th className="py-3 px-4">Gate Presence</th>
                <th className="py-3 px-4">Contact</th>
                <th className="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {students.length === 0 && !isLoading ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-slate-400 font-medium">
                    No students found matching current filters.
                  </td>
                </tr>
              ) : (
                students.map((s) => {
                  const isActionLoading = actionLoadingId === s.id;
                  return (
                    <tr key={s.id} className="hover:bg-slate-50/80 transition-colors">
                      <td className="py-3 px-4 font-mono font-bold text-slate-900">
                        {s.roll_number}
                        {s.student_id && s.student_id !== s.roll_number && (
                          <span className="block text-[10px] font-normal text-slate-400">{s.student_id}</span>
                        )}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-2.5">
                          <Avatar
                            src={s.profile_photo_url}
                            name={s.name}
                            size="sm"
                            shape="rounded"
                          />
                          <div>
                            <div className="font-bold text-slate-800 flex items-center gap-1.5 flex-wrap">
                              <span>{s.name}</span>
                              <span
                                className={`px-1.5 py-0.2 rounded text-[9px] font-black uppercase tracking-wider ${
                                  s.category === 'DAY_SCHOLAR'
                                    ? 'bg-amber-100 text-amber-800'
                                    : 'bg-indigo-100 text-indigo-800'
                                }`}
                              >
                                {s.category === 'DAY_SCHOLAR' ? 'Day Scholar' : 'Hosteler'}
                              </span>
                            </div>
                            {s.year && <div className="text-[10px] text-slate-400">Year {s.year}</div>}
                          </div>
                        </div>
                      </td>
                      <td className="py-3 px-4 text-slate-600">
                        <div>{s.program || 'General'}</div>
                        {s.department && <div className="text-[10px] text-slate-400">{s.department}</div>}
                      </td>
                      <td className="py-3 px-4">
                        {renderStatusBadge(s.status)}
                      </td>
                      <td className="py-3 px-4">
                        <span
                          className={`px-2 py-0.5 rounded-full font-black text-[10px] ${
                            s.current_status === 'INSIDE'
                              ? 'bg-emerald-100 text-emerald-800'
                              : 'bg-rose-100 text-rose-800'
                          }`}
                        >
                          {s.current_status === 'INSIDE' ? '🟢 INSIDE' : '🔴 OUTSIDE'}
                        </span>
                      </td>
                      <td className="py-3 px-4 text-slate-500 font-mono text-[11px]">
                        <div>{s.email}</div>
                        {s.phone_number && <div className="text-[10px] text-slate-400">{s.phone_number}</div>}
                      </td>
                      <td className="py-3 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          {/* View Full Details */}
                          <button
                            type="button"
                            title="View Full Details"
                            onClick={() => setSelectedStudent(s)}
                            className="p-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition"
                          >
                            <Eye className="h-3.5 w-3.5" />
                          </button>

                          {/* Lifecycle Action Buttons */}
                          {s.status === 'PENDING' && (
                            <>
                              <button
                                type="button"
                                disabled={isActionLoading}
                                onClick={() => handleApprove(s.id)}
                                title="Approve Student Gate Access"
                                className="px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[11px] shadow-sm flex items-center gap-1 transition disabled:opacity-50"
                              >
                                <CheckCircle2 className="h-3 w-3" /> Approve
                              </button>
                              <button
                                type="button"
                                disabled={isActionLoading}
                                onClick={() => handleReject(s.id)}
                                title="Reject Registration"
                                className="px-2.5 py-1 rounded-lg bg-rose-600 hover:bg-rose-700 text-white font-bold text-[11px] shadow-sm flex items-center gap-1 transition disabled:opacity-50"
                              >
                                <XCircle className="h-3 w-3" /> Reject
                              </button>
                            </>
                          )}

                          {s.status === 'ACTIVE' && (
                            <button
                              type="button"
                              disabled={isActionLoading}
                              onClick={() => handleSuspend(s.id)}
                              title="Suspend Gate Access"
                              className="px-2.5 py-1 rounded-lg border border-rose-200 text-rose-700 hover:bg-rose-50 font-bold text-[11px] transition disabled:opacity-50"
                            >
                              Suspend
                            </button>
                          )}

                          {(s.status === 'SUSPENDED' || s.status === 'REJECTED') && (
                            <button
                              type="button"
                              disabled={isActionLoading}
                              onClick={() => handleReactivate(s.id)}
                              title="Reactivate Account"
                              className="px-2.5 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-bold text-[11px] shadow-sm flex items-center gap-1 transition disabled:opacity-50"
                            >
                              <RotateCcw className="h-3 w-3" /> Reactivate
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </Card>

      {/* Student Details & Verification Modal */}
      {selectedStudent && (
        <Modal
          isOpen={!!selectedStudent}
          onClose={() => setSelectedStudent(null)}
          title="Student Profile & Gate Authorization"
          description="Official enrollment verification and lifecycle status controls"
          size="lg"
        >
          <div className="space-y-4 pt-2 text-left">
            <div className="flex items-center justify-between p-4 bg-slate-50 rounded-2xl border border-slate-200/80">
              <div className="flex items-center gap-3.5">
                <div className="relative group shrink-0">
                  <Avatar
                    src={selectedStudent.profile_photo_url}
                    name={selectedStudent.name}
                    size="xl"
                    shape="rounded"
                    className="ring-2 ring-slate-200 shadow-sm"
                  />
                  {adminPhotoUploading && (
                    <div className="absolute inset-0 bg-black/50 rounded-xl flex items-center justify-center text-white">
                      <Loader2 className="h-5 w-5 animate-spin" />
                    </div>
                  )}
                </div>

                <div className="space-y-1">
                  <div className="flex items-center gap-2 flex-wrap">
                    <h3 className="text-base font-bold text-slate-900">{selectedStudent.name}</h3>
                    <span
                      className={`px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider ${
                        selectedStudent.category === 'DAY_SCHOLAR'
                          ? 'bg-amber-100 text-amber-800 border border-amber-200'
                          : 'bg-indigo-100 text-indigo-800 border border-indigo-200'
                      }`}
                    >
                      {selectedStudent.category === 'DAY_SCHOLAR' ? 'Day Scholar' : 'Hosteler'}
                    </span>
                  </div>
                  <p className="text-xs font-mono text-slate-500 font-semibold">
                    Roll: {selectedStudent.roll_number}
                  </p>

                  {/* Admin Photo Controls */}
                  <div className="flex items-center gap-2 pt-1">
                    <input
                      ref={adminFileInputRef}
                      type="file"
                      accept="image/jpeg,image/png,image/webp"
                      className="hidden"
                      onChange={handleAdminPhotoUpload}
                    />
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={adminPhotoUploading}
                      onClick={() => adminFileInputRef.current?.click()}
                      className="text-[11px] h-7 px-2.5 flex items-center gap-1"
                    >
                      <Camera className="h-3.5 w-3.5" />
                      <span>{selectedStudent.profile_photo_url ? 'Replace Photo' : 'Upload Photo'}</span>
                    </Button>
                    {selectedStudent.profile_photo_url && (
                      <button
                        type="button"
                        disabled={adminPhotoUploading}
                        onClick={handleAdminPhotoDelete}
                        className="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                        title="Remove Photo"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                      </button>
                    )}
                    <span className="text-[10px] text-slate-400">Max 100 KB</span>
                  </div>
                  {adminPhotoError && (
                    <p className="text-[11px] text-rose-600 font-medium">{adminPhotoError}</p>
                  )}
                </div>
              </div>
              <div>{renderStatusBadge(selectedStudent.status)}</div>
            </div>

            {/* Profile Grid */}
            <div className="grid grid-cols-2 gap-3 text-xs">
              <div className="p-3 bg-slate-50/70 rounded-xl border border-slate-200/60 space-y-1">
                <span className="text-[10px] uppercase font-bold text-slate-400 flex items-center gap-1">
                  <UserCheck className="h-3 w-3" /> Category
                </span>
                <p className="font-semibold text-slate-800">
                  {selectedStudent.category === 'DAY_SCHOLAR' ? 'Day Scholar (Commuter)' : 'Hosteler (Campus Resident)'}
                </p>
              </div>

              <div className="p-3 bg-slate-50/70 rounded-xl border border-slate-200/60 space-y-1">
                <span className="text-[10px] uppercase font-bold text-slate-400 flex items-center gap-1">
                  <GraduationCap className="h-3 w-3" /> Program & Year
                </span>
                <p className="font-semibold text-slate-800">
                  {selectedStudent.program || 'Not specified'} (Year {selectedStudent.year || 1})
                </p>
              </div>

              <div className="p-3 bg-slate-50/70 rounded-xl border border-slate-200/60 space-y-1">
                <span className="text-[10px] uppercase font-bold text-slate-400 flex items-center gap-1">
                  <Shield className="h-3 w-3" /> Department
                </span>
                <p className="font-semibold text-slate-800">
                  {selectedStudent.department || 'Not specified'}
                </p>
              </div>

              <div className="p-3 bg-slate-50/70 rounded-xl border border-slate-200/60 space-y-1">
                <span className="text-[10px] uppercase font-bold text-slate-400 flex items-center gap-1">
                  <Mail className="h-3 w-3" /> Official Email
                </span>
                <p className="font-semibold text-slate-800 font-mono truncate">
                  {selectedStudent.email}
                </p>
              </div>

              <div className="p-3 bg-slate-50/70 rounded-xl border border-slate-200/60 space-y-1">
                <span className="text-[10px] uppercase font-bold text-slate-400 flex items-center gap-1">
                  <Phone className="h-3 w-3" /> Contact Phone
                </span>
                <p className="font-semibold text-slate-800 font-mono">
                  {selectedStudent.phone_number || 'Not registered'}
                </p>
              </div>

              <div className="p-3 bg-slate-50/70 rounded-xl border border-slate-200/60 space-y-1">
                <span className="text-[10px] uppercase font-bold text-slate-400 flex items-center gap-1">
                  <Calendar className="h-3 w-3" /> Campus Presence
                </span>
                <p className="font-semibold text-slate-800">
                  {selectedStudent.current_status === 'INSIDE' ? '🟢 Present on campus (INSIDE)' : '🔴 Outside campus (OUTSIDE)'}
                </p>
              </div>

              <div className="col-span-2 p-3 bg-slate-50/70 rounded-xl border border-slate-200/60 space-y-1">
                <span className="text-[10px] uppercase font-bold text-slate-400">Gate Entry Access</span>
                <p className={`font-semibold ${selectedStudent.status === 'ACTIVE' ? 'text-emerald-700' : 'text-amber-700'}`}>
                  {selectedStudent.status === 'ACTIVE' ? 'Authorized & Active' : 'Locked / Not Authorized'}
                </p>
              </div>
            </div>

            {/* Modal Quick Actions */}
            <div className="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
              {selectedStudent.status === 'PENDING' && (
                <>
                  <Button
                    variant="danger"
                    size="sm"
                    className="text-xs font-bold"
                    onClick={() => handleReject(selectedStudent.id)}
                  >
                    Reject Application
                  </Button>
                  <Button
                    variant="success"
                    size="sm"
                    className="text-xs font-bold"
                    onClick={() => handleApprove(selectedStudent.id)}
                  >
                    Approve & Grant Gate Access
                  </Button>
                </>
              )}

              {selectedStudent.status === 'ACTIVE' && (
                <Button
                  variant="danger"
                  size="sm"
                  className="text-xs font-bold"
                  onClick={() => handleSuspend(selectedStudent.id)}
                >
                  Suspend Gate Access
                </Button>
              )}

              {(selectedStudent.status === 'SUSPENDED' || selectedStudent.status === 'REJECTED') && (
                <Button
                  variant="primary"
                  size="sm"
                  className="text-xs font-bold"
                  onClick={() => handleReactivate(selectedStudent.id)}
                >
                  Reactivate Student Account
                </Button>
              )}
            </div>
          </div>
        </Modal>
      )}

      {/* Manual Register Student Modal */}
      <Modal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        title="Manual Student Enrollment"
        description="Enrolls verified student with direct active gate entry credentials"
        size="lg"
      >
        <form onSubmit={handleCreateStudent} className="space-y-4 pt-2 text-left">
          {modalError && <Alert type="error" message={modalError} />}

          <div className="grid grid-cols-2 gap-3">
            <Input
              label="Full Name"
              required
              placeholder="e.g. Student Name"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
            />
            <Input
              label="Official Email"
              type="email"
              required
              placeholder="student@university.edu"
              value={formData.email}
              onChange={(e) => setFormData({ ...formData, email: e.target.value })}
            />
            <Input
              label="Roll Number"
              required
              placeholder="e.g. 24CSE101"
              value={formData.roll_number}
              onChange={(e) => setFormData({ ...formData, roll_number: e.target.value.toUpperCase() })}
            />
            <Input
              label="Student ID"
              required
              placeholder="e.g. STU1001"
              value={formData.student_id}
              onChange={(e) => setFormData({ ...formData, student_id: e.target.value.toUpperCase() })}
            />
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Student Category <span className="text-rose-500">*</span>
              </label>
              <select
                className="w-full h-10 px-3 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                value={formData.category}
                onChange={(e) => setFormData({ ...formData, category: e.target.value as 'HOSTELER' | 'DAY_SCHOLAR' })}
              >
                <option value="HOSTELER">Hosteler</option>
                <option value="DAY_SCHOLAR">Day Scholar</option>
              </select>
            </div>
            <Input
              label="Login Password"
              type="password"
              required
              placeholder="Minimum 8 characters"
              value={formData.password}
              onChange={(e) => setFormData({ ...formData, password: e.target.value })}
            />
            <Input
              label="Phone Number"
              type="tel"
              placeholder="+91 XXXXXXXXXX"
              value={formData.phone_number}
              onChange={(e) => setFormData({ ...formData, phone_number: e.target.value })}
            />
            <Input
              label="Program"
              placeholder="e.g. B.Tech"
              value={formData.program}
              onChange={(e) => setFormData({ ...formData, program: e.target.value })}
            />
            <Input
              label="Department"
              placeholder="e.g. Computer Science"
              value={formData.department}
              onChange={(e) => setFormData({ ...formData, department: e.target.value })}
            />
            <Input
              label="Year (1-6)"
              type="number"
              min={1}
              max={6}
              value={formData.year}
              onChange={(e) => setFormData({ ...formData, year: e.target.value })}
            />
            <Input
              label="Batch"
              placeholder="e.g. 2024-2028"
              value={formData.batch}
              onChange={(e) => setFormData({ ...formData, batch: e.target.value })}
            />
          </div>

          <Button type="submit" size="lg" className="w-full font-bold mt-4" isLoading={isSubmitting}>
            Enroll Active Student
          </Button>
        </form>
      </Modal>
    </div>
  );
};
