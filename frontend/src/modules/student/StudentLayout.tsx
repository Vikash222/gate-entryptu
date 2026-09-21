import React, { useState, useEffect, useRef } from 'react';
import { Outlet } from 'react-router-dom';
import { Shield, LogOut, WifiOff, Camera, Trash2, Loader2, AlertCircle } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { Modal } from '../../components/ui/Modal';
import { Button } from '../../components/ui/Button';
import { Avatar, clearPhotoCache } from '../../components/ui/Avatar';
import { NotificationBell } from '../../components/shared/NotificationBell';
import { apiClient, getErrorMessage } from '../../api/client';

export const StudentLayout: React.FC = () => {
  const { user, logout, refreshUser } = useAuth();
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [showProfile, setShowProfile] = useState(false);
  const [isUploadingPhoto, setIsUploadingPhoto] = useState(false);
  const [isDeletingPhoto, setIsDeletingPhoto] = useState(false);
  const [photoError, setPhotoError] = useState<string | null>(null);

  const fileInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  const student = user?.student;

  const handlePhotoSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    // Strict 100 KB hard limit check on client before transmitting
    if (file.size > 102400) {
      setPhotoError(`Profile photo must be 100 KB or smaller. (Selected file: ${(file.size / 1024).toFixed(1)} KB)`);
      if (fileInputRef.current) fileInputRef.current.value = '';
      return;
    }

    const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!validTypes.includes(file.type)) {
      setPhotoError('Invalid image format. Only JPEG, PNG, or WebP are allowed.');
      if (fileInputRef.current) fileInputRef.current.value = '';
      return;
    }

    setIsUploadingPhoto(true);
    setPhotoError(null);

    const formData = new FormData();
    formData.append('profile_photo', file);

    try {
      await apiClient.post('/student/profile/photo', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      clearPhotoCache(student?.profile_photo_url || undefined);
      await refreshUser();
    } catch (err) {
      setPhotoError(getErrorMessage(err));
    } finally {
      setIsUploadingPhoto(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  };

  const handleDeletePhoto = async () => {
    setIsDeletingPhoto(true);
    setPhotoError(null);
    try {
      await apiClient.delete('/student/profile/photo');
      clearPhotoCache(student?.profile_photo_url || undefined);
      await refreshUser();
    } catch (err) {
      setPhotoError(getErrorMessage(err));
    } finally {
      setIsDeletingPhoto(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-100 flex flex-col items-center">
      {/* Offline Alert Banner */}
      {!isOnline && (
        <div className="w-full bg-amber-500 text-slate-900 px-4 py-2.5 text-xs font-bold flex items-center justify-center gap-2 shadow-sm sticky top-0 z-50 animate-pulse">
          <WifiOff className="h-4 w-4" />
          <span>Offline. Gate verification unavailable. Please reconnect.</span>
        </div>
      )}

      {/* Main Mobile App Shell (Max width 480px for native app aesthetic) */}
      <div className="w-full max-w-md min-h-screen bg-white flex flex-col shadow-xl border-x border-slate-200">
        {/* App Header */}
        <header className="px-5 py-4 bg-slate-900 text-white flex items-center justify-between sticky top-0 z-40 shadow-sm">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-blue-600 rounded-xl shadow-sm">
              <Shield className="h-5 w-5 text-white" />
            </div>
            <div>
              <span className="text-xs font-black tracking-widest text-blue-400 block uppercase">University</span>
              <h1 className="text-base font-extrabold tracking-tight">SMARTGATE</h1>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <NotificationBell variant="dark" />
            <button
              onClick={() => {
                setPhotoError(null);
                setShowProfile(true);
              }}
              className="p-1 text-slate-300 hover:text-white hover:bg-slate-800 rounded-full transition flex items-center"
              title="Student Profile"
            >
              <Avatar
                src={student?.profile_photo_url}
                name={student?.name || user?.name}
                size="sm"
                className="ring-2 ring-blue-500/50"
              />
            </button>
            <button
              onClick={logout}
              className="p-2.5 text-slate-300 hover:text-rose-400 hover:bg-slate-800 rounded-xl transition"
              title="Sign Out"
            >
              <LogOut className="h-5 w-5" />
            </button>
          </div>
        </header>

        {/* Content Outlet */}
        <main className="flex-1 p-5 flex flex-col">
          <Outlet context={{ setShowProfile }} />
        </main>
      </div>

      {/* Student Profile Modal */}
      <Modal
        isOpen={showProfile}
        onClose={() => setShowProfile(false)}
        title="Student Profile"
        description="Official university student identification details"
        size="md"
      >
        <div className="space-y-4 pt-2 text-left">
          {/* Photo & Identity Banner */}
          <div className="flex items-center gap-3.5 p-3.5 bg-slate-50 rounded-2xl border border-slate-200/80">
            <div className="relative group shrink-0">
              <Avatar
                src={student?.profile_photo_url}
                name={student?.name || user?.name}
                size="xl"
                shape="rounded"
                className="ring-2 ring-slate-200 shadow-sm"
              />
              {isUploadingPhoto && (
                <div className="absolute inset-0 bg-black/50 rounded-xl flex items-center justify-center text-white">
                  <Loader2 className="h-5 w-5 animate-spin" />
                </div>
              )}
            </div>

            <div className="min-w-0 flex-1 space-y-1">
              <div className="flex items-center gap-1.5 flex-wrap">
                <h4 className="font-extrabold text-slate-900 text-sm leading-tight truncate">
                  {student?.name || user?.name}
                </h4>
                <span
                  className={`px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider ${
                    student?.category === 'DAY_SCHOLAR'
                      ? 'bg-amber-100 text-amber-800 border border-amber-200'
                      : 'bg-indigo-100 text-indigo-800 border border-indigo-200'
                  }`}
                >
                  {student?.category === 'DAY_SCHOLAR' ? 'Day Scholar' : 'Hosteler'}
                </span>
              </div>
              <p className="text-xs text-slate-500 font-mono">Roll: {student?.roll_number || 'N/A'}</p>

              {/* Photo Actions */}
              <div className="flex items-center gap-2 pt-1">
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  className="hidden"
                  onChange={handlePhotoSelect}
                />
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={isUploadingPhoto || isDeletingPhoto}
                  onClick={() => fileInputRef.current?.click()}
                  className="text-[11px] h-7 px-2.5 flex items-center gap-1"
                >
                  <Camera className="h-3.5 w-3.5" />
                  <span>{student?.profile_photo_url ? 'Change Photo' : 'Upload Photo'}</span>
                </Button>

                {student?.profile_photo_url && (
                  <button
                    type="button"
                    disabled={isUploadingPhoto || isDeletingPhoto}
                    onClick={handleDeletePhoto}
                    className="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                    title="Remove Photo"
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                  </button>
                )}
              </div>
              <p className="text-[10px] text-slate-400">JPG, PNG or WebP &bull; Max 100 KB</p>
            </div>
          </div>

          {/* Photo Error Banner */}
          {photoError && (
            <div className="p-2.5 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700 flex items-start gap-1.5">
              <AlertCircle className="h-4 w-4 text-rose-500 shrink-0 mt-0.5" />
              <span>{photoError}</span>
            </div>
          )}

          {/* Academic & Identity Details */}
          <div className="grid grid-cols-2 gap-3 text-xs">
            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Student ID</span>
              <span className="font-semibold text-slate-800 font-mono">{student?.student_id || 'N/A'}</span>
            </div>

            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Category</span>
              <span className="font-bold text-slate-800">
                {student?.category === 'DAY_SCHOLAR' ? 'Day Scholar' : 'Hosteler'}
              </span>
            </div>

            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Program</span>
              <span className="font-semibold text-slate-800">{student?.program || 'N/A'}</span>
            </div>

            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Department</span>
              <span className="font-semibold text-slate-800">{student?.department || 'N/A'}</span>
            </div>

            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Semester / Year</span>
              <span className="font-semibold text-slate-800">
                {student?.semester ? `Sem ${student.semester}` : ''} {student?.year ? `(Yr ${student.year})` : 'N/A'}
              </span>
            </div>

            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Gate Status</span>
              <span className="font-bold text-slate-800">
                {student?.current_status === 'INSIDE' ? 'Inside Campus' : 'Outside Campus'}
              </span>
            </div>

            <div className="col-span-2 p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block uppercase tracking-wider text-[10px] font-semibold">Official Email</span>
              <span className="font-semibold text-slate-800 break-all">{student?.email || user?.email}</span>
            </div>
          </div>

          <Button onClick={() => setShowProfile(false)} variant="outline" className="w-full">
            Close Profile
          </Button>
        </div>
      </Modal>
    </div>
  );
};
