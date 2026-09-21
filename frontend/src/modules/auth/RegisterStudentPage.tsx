import React, { useState, useRef } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import {
  ShieldCheck,
  UserPlus,
  Lock,
  Mail,
  Phone,
  BookOpen,
  GraduationCap,
  CheckCircle2,
  ArrowRight,
  ArrowLeft,
  Camera,
  AlertTriangle,
} from 'lucide-react';
import { apiClient, getErrorMessage } from '../../api/client';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import { Badge } from '../../components/ui/Badge';
import type { ApiResponse } from '../../types';

interface RegisterSuccessData {
  student: {
    id: number;
    roll_number: string;
    student_id?: string;
    name: string;
    email: string;
    status: string;
    student_type?: string;
    category?: string;
    profile_photo?: string | null;
    profile_photo_url?: string | null;
  };
}

export const RegisterStudentPage: React.FC = () => {
  const navigate = useNavigate();

  const [formData, setFormData] = useState({
    name: '',
    roll_number: '',
    student_id: '',
    student_type: '' as '' | 'HOSTELLER' | 'DAY_SCHOLAR',
    year: '1',
    program: '',
    department: '',
    email: '',
    phone_number: '',
    password: '',
    password_confirmation: '',
  });

  const [photoFile, setPhotoFile] = useState<File | null>(null);
  const [photoPreview, setPhotoPreview] = useState<string | null>(null);
  const [photoError, setPhotoError] = useState<string | null>(null);
  const photoInputRef = useRef<HTMLInputElement>(null);

  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [isSuccess, setIsSuccess] = useState(false);
  const [registeredStudent, setRegisteredStudent] = useState<RegisterSuccessData['student'] | null>(null);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
    if (fieldErrors[name]) {
      setFieldErrors((prev) => {
        const copy = { ...prev };
        delete copy[name];
        return copy;
      });
    }
  };

  const handlePhotoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setPhotoError(null);
    if (fieldErrors['profile_photo']) {
      setFieldErrors((prev) => {
        const copy = { ...prev };
        delete copy['profile_photo'];
        return copy;
      });
    }

    // 1. File size check <= 100 KB (102,400 bytes)
    if (file.size > 102400) {
      setPhotoError('Profile photo must be 100 KB or smaller.');
      if (photoInputRef.current) photoInputRef.current.value = '';
      return;
    }

    // 2. Allowed extensions / MIME
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    const ext = file.name.split('.').pop()?.toLowerCase() || '';
    if (!allowedTypes.includes(file.type) || !['jpg', 'jpeg', 'png', 'webp'].includes(ext)) {
      setPhotoError('Only JPG, PNG and WebP images are allowed.');
      if (photoInputRef.current) photoInputRef.current.value = '';
      return;
    }

    // 3. Image readability & dimensions check <= 2000x2000
    const objectUrl = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      if (img.width > 2000 || img.height > 2000) {
        setPhotoError('Image dimensions are too large.');
        URL.revokeObjectURL(objectUrl);
        if (photoInputRef.current) photoInputRef.current.value = '';
        return;
      }
      setPhotoFile(file);
      setPhotoPreview(objectUrl);
      setPhotoError(null);
    };
    img.onerror = () => {
      setPhotoError('Invalid image file.');
      URL.revokeObjectURL(objectUrl);
      if (photoInputRef.current) photoInputRef.current.value = '';
    };
    img.src = objectUrl;
  };

  const handleRemovePhoto = () => {
    if (photoPreview) {
      URL.revokeObjectURL(photoPreview);
    }
    setPhotoFile(null);
    setPhotoPreview(null);
    setPhotoError(null);
    if (photoInputRef.current) {
      photoInputRef.current.value = '';
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setFieldErrors({});

    if (!formData.student_type) {
      setFieldErrors({
        student_type: ['Please select Hosteller or Day Scholar.'],
      });
      setErrorMessage('Please select Hosteller or Day Scholar.');
      return;
    }

    if (formData.password !== formData.password_confirmation) {
      setErrorMessage('Password and confirmation do not match.');
      return;
    }

    if (formData.password.length < 8) {
      setErrorMessage('Password must be at least 8 characters long.');
      return;
    }

    setIsLoading(true);

    try {
      const data = new FormData();
      data.append('name', formData.name.trim());
      data.append('roll_number', formData.roll_number.trim());
      data.append('student_id', formData.student_id.trim() ? formData.student_id.trim() : formData.roll_number.trim());
      data.append('student_type', formData.student_type);
      data.append('year', formData.year);
      data.append('program', formData.program.trim());
      if (formData.department.trim()) {
        data.append('department', formData.department.trim());
      }
      data.append('email', formData.email.trim());
      data.append('phone_number', formData.phone_number.trim());
      data.append('password', formData.password);
      data.append('password_confirmation', formData.password_confirmation);
      if (photoFile) {
        data.append('profile_photo', photoFile);
      }

      const response = await apiClient.post<ApiResponse<RegisterSuccessData>>('/auth/register-student', data, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      });

      setRegisteredStudent(response.data.data.student);
      setIsSuccess(true);
    } catch (error: any) {
      if (error?.response?.data?.errors) {
        setFieldErrors(error.response.data.errors);
      }
      setErrorMessage(getErrorMessage(error));
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-900 via-blue-950 to-slate-900 flex flex-col justify-center items-center px-4 py-8 sm:py-12">
      <div className="w-full max-w-xl">
        {/* Brand Header */}
        <div className="text-center mb-6">
          <div className="inline-flex items-center justify-center p-3.5 bg-blue-600/20 border border-blue-500/30 rounded-2xl mb-3 backdrop-blur-md shadow-lg shadow-blue-500/10">
            <ShieldCheck className="h-8 w-8 text-blue-400" />
          </div>
          <h1 className="text-2xl font-black tracking-tight text-white sm:text-3xl">SMARTGATE</h1>
          <p className="text-sm font-medium text-blue-200/80 mt-1">Student Account Self-Registration</p>
        </div>

        {/* Card */}
        <div className="bg-white rounded-3xl shadow-2xl p-6 sm:p-8 border border-slate-100">
          {isSuccess && registeredStudent ? (
            /* Registration Success Confirmation */
            <div className="text-center space-y-6 animate-fadeIn py-4">
              <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-amber-100 border border-amber-200 text-amber-600 mx-auto">
                <CheckCircle2 className="h-8 w-8" />
              </div>

              <div>
                <h2 className="text-2xl font-bold text-slate-900">Registration Submitted!</h2>
                <p className="text-sm text-slate-500 mt-1">Your SmartGate account application has been submitted.</p>
              </div>

              {/* Status Notice Banner */}
              <div className="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-left space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold uppercase tracking-wider text-amber-800">Account Status:</span>
                  <Badge variant="warning" size="md">
                    PENDING APPROVAL
                  </Badge>
                </div>
                <p className="text-xs text-amber-900 leading-relaxed">
                  For university security, all new self-registered student accounts start in <strong>PENDING</strong> status. 
                  An Administrator must verify your student details before temporary Gate QR verification and IN/OUT gate access are enabled.
                </p>
              </div>

              {/* Details Summary */}
              <div className="bg-slate-50 rounded-2xl p-4 text-left space-y-2 text-xs border border-slate-200/70">
                {photoPreview && (
                  <div className="flex items-center justify-center pb-3 border-b border-slate-200/50">
                    <img
                      src={photoPreview}
                      alt="Student Profile Photo"
                      className="w-16 h-16 rounded-2xl object-cover ring-2 ring-blue-500 shadow-md"
                    />
                  </div>
                )}
                <div className="flex justify-between py-1 border-b border-slate-200/50">
                  <span className="text-slate-500">Full Name:</span>
                  <span className="font-semibold text-slate-800">{registeredStudent.name}</span>
                </div>
                <div className="flex justify-between py-1 border-b border-slate-200/50">
                  <span className="text-slate-500">Roll Number:</span>
                  <span className="font-semibold text-slate-800 font-mono">{registeredStudent.roll_number}</span>
                </div>
                <div className="flex justify-between py-1 border-b border-slate-200/50">
                  <span className="text-slate-500">Student Type:</span>
                  <span
                    className={`px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider ${
                      (registeredStudent.student_type || registeredStudent.category) === 'DAY_SCHOLAR'
                        ? 'bg-amber-100 text-amber-800'
                        : 'bg-indigo-100 text-indigo-800'
                    }`}
                  >
                    {(registeredStudent.student_type || registeredStudent.category) === 'DAY_SCHOLAR' ? 'Day Scholar' : 'Hosteller'}
                  </span>
                </div>
                <div className="flex justify-between py-1 border-b border-slate-200/50">
                  <span className="text-slate-500">Email:</span>
                  <span className="font-semibold text-slate-800">{registeredStudent.email}</span>
                </div>
                <div className="flex justify-between py-1">
                  <span className="text-slate-500">Gate Entry Access:</span>
                  <span className="font-semibold text-amber-700">Locked until Admin Approval</span>
                </div>
              </div>

              <div className="pt-2">
                <Button
                  size="lg"
                  className="w-full font-semibold shadow-md shadow-blue-600/20"
                  onClick={() => navigate('/login')}
                >
                  Proceed to Sign In <ArrowRight className="ml-2 h-4 w-4" />
                </Button>
              </div>
            </div>
          ) : (
            /* Registration Form */
            <div>
              <div className="flex items-center justify-between mb-5">
                <div>
                  <h2 className="text-xl font-bold text-slate-900">Create Student Account</h2>
                  <p className="text-xs text-slate-500 mt-0.5">Enter your institutional details to register</p>
                </div>
                <Link
                  to="/login"
                  className="text-xs font-semibold text-blue-600 hover:text-blue-700 flex items-center gap-1"
                >
                  <ArrowLeft className="h-3.5 w-3.5" /> Back to Login
                </Link>
              </div>

              {errorMessage && (
                <div className="mb-5">
                  <Alert type="error" message={errorMessage} />
                </div>
              )}

              <form onSubmit={handleSubmit} className="space-y-4">
                {/* Profile Photo Picker & Preview */}
                <div className="p-4 bg-slate-50 border border-slate-200/80 rounded-2xl space-y-3">
                  <div className="flex items-center justify-between">
                    <div>
                      <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Profile Photo
                      </label>
                      <p className="text-[11px] text-slate-500 mt-0.5">
                        JPEG, PNG, or WebP &bull; 100 KB maximum &bull; Max 2000x2000 px
                      </p>
                    </div>
                    <input
                      ref={photoInputRef}
                      type="file"
                      accept="image/jpeg,image/png,image/webp"
                      className="hidden"
                      onChange={handlePhotoChange}
                    />
                  </div>

                  {/* Preview or Empty State */}
                  <div className="flex items-center gap-4">
                    {photoPreview ? (
                      <div className="relative group shrink-0">
                        <img
                          src={photoPreview}
                          alt="Profile Preview"
                          className="w-16 h-16 rounded-2xl object-cover ring-2 ring-blue-500 shadow-md"
                        />
                      </div>
                    ) : (
                      <div className="w-16 h-16 rounded-2xl bg-slate-200/80 border-2 border-dashed border-slate-300 flex items-center justify-center text-slate-400 shrink-0">
                        <Camera className="h-6 w-6" />
                      </div>
                    )}

                    <div className="space-y-1.5">
                      <div className="flex items-center gap-2">
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() => photoInputRef.current?.click()}
                          className="text-xs font-semibold px-3 py-1.5"
                        >
                          <Camera className="h-3.5 w-3.5 mr-1.5" />
                          {photoPreview ? 'Change Photo' : 'Choose Photo'}
                        </Button>

                        {photoPreview && (
                          <button
                            type="button"
                            onClick={handleRemovePhoto}
                            className="text-xs text-rose-600 hover:text-rose-700 font-semibold px-2 py-1 hover:bg-rose-50 rounded-lg transition"
                          >
                            Remove
                          </button>
                        )}
                      </div>

                      {photoFile && (
                        <p className="text-[11px] text-slate-600 font-medium">
                          {photoFile.name} ({(photoFile.size / 1024).toFixed(1)} KB)
                        </p>
                      )}
                    </div>
                  </div>

                  {photoError && (
                    <p className="text-xs text-rose-600 font-semibold flex items-center gap-1 mt-1">
                      <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                      {photoError}
                    </p>
                  )}
                  {fieldErrors['profile_photo']?.[0] && (
                    <p className="text-xs text-rose-600 font-semibold flex items-center gap-1 mt-1">
                      <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                      {fieldErrors['profile_photo'][0]}
                    </p>
                  )}
                </div>

                {/* Student Type Selector */}
                <div className="space-y-1.5 text-left">
                  <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    Student Type *
                  </label>
                  <div className="grid grid-cols-2 gap-3">
                    <button
                      type="button"
                      onClick={() => {
                        setFormData((prev) => ({ ...prev, student_type: 'HOSTELLER' }));
                        if (fieldErrors['student_type']) {
                          setFieldErrors((prev) => {
                            const copy = { ...prev };
                            delete copy['student_type'];
                            return copy;
                          });
                        }
                      }}
                      className={`p-3.5 rounded-2xl border-2 text-left transition flex items-center justify-between ${
                        formData.student_type === 'HOSTELLER'
                          ? 'border-blue-600 bg-blue-50/70 shadow-sm'
                          : 'border-slate-200 bg-white hover:border-slate-300'
                      }`}
                    >
                      <div className="flex items-center gap-2.5">
                        <div
                          className={`w-4 h-4 rounded-full border-2 flex items-center justify-center ${
                            formData.student_type === 'HOSTELLER'
                              ? 'border-blue-600 bg-blue-600'
                              : 'border-slate-300'
                          }`}
                        >
                          {formData.student_type === 'HOSTELLER' && (
                            <div className="w-1.5 h-1.5 rounded-full bg-white" />
                          )}
                        </div>
                        <div>
                          <span className="font-bold text-xs text-slate-800 block">Hosteller</span>
                          <span className="text-[10px] text-slate-500 block">Campus Resident</span>
                        </div>
                      </div>
                    </button>

                    <button
                      type="button"
                      onClick={() => {
                        setFormData((prev) => ({ ...prev, student_type: 'DAY_SCHOLAR' }));
                        if (fieldErrors['student_type']) {
                          setFieldErrors((prev) => {
                            const copy = { ...prev };
                            delete copy['student_type'];
                            return copy;
                          });
                        }
                      }}
                      className={`p-3.5 rounded-2xl border-2 text-left transition flex items-center justify-between ${
                        formData.student_type === 'DAY_SCHOLAR'
                          ? 'border-amber-600 bg-amber-50/70 shadow-sm'
                          : 'border-slate-200 bg-white hover:border-slate-300'
                      }`}
                    >
                      <div className="flex items-center gap-2.5">
                        <div
                          className={`w-4 h-4 rounded-full border-2 flex items-center justify-center ${
                            formData.student_type === 'DAY_SCHOLAR'
                              ? 'border-amber-600 bg-amber-600'
                              : 'border-slate-300'
                          }`}
                        >
                          {formData.student_type === 'DAY_SCHOLAR' && (
                            <div className="w-1.5 h-1.5 rounded-full bg-white" />
                          )}
                        </div>
                        <div>
                          <span className="font-bold text-xs text-slate-800 block">Day Scholar</span>
                          <span className="text-[10px] text-slate-500 block">Commuter</span>
                        </div>
                      </div>
                    </button>
                  </div>
                  {fieldErrors['student_type']?.[0] && (
                    <p className="text-xs text-rose-600 font-semibold mt-1">
                      {fieldErrors['student_type'][0]}
                    </p>
                  )}
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Full Name */}
                  <Input
                    label="Full Name *"
                    name="name"
                    required
                    placeholder="e.g. Rahul Sharma"
                    value={formData.name}
                    onChange={handleChange}
                    error={fieldErrors['name']?.[0]}
                  />

                  {/* Roll Number */}
                  <Input
                    label="Roll Number *"
                    name="roll_number"
                    required
                    placeholder="e.g. 2026/CS/042"
                    value={formData.roll_number}
                    onChange={handleChange}
                    error={fieldErrors['roll_number']?.[0]}
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Student ID (Optional if same as roll number) */}
                  <Input
                    label="Student ID (Optional)"
                    name="student_id"
                    placeholder="Defaults to Roll Number"
                    value={formData.student_id}
                    onChange={handleChange}
                    error={fieldErrors['student_id']?.[0]}
                  />

                  {/* Year of Study */}
                  <div className="w-full space-y-1.5 text-left">
                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600">
                      Year of Study *
                    </label>
                    <div className="relative rounded-xl shadow-sm">
                      <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                        <GraduationCap className="h-5 w-5" />
                      </div>
                      <select
                        name="year"
                        value={formData.year}
                        onChange={handleChange}
                        className="block w-full rounded-xl border border-slate-200 bg-white py-3 pl-11 pr-4 text-slate-900 text-base focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
                        required
                      >
                        <option value="1">1st Year</option>
                        <option value="2">2nd Year</option>
                        <option value="3">3rd Year</option>
                        <option value="4">4th Year</option>
                        <option value="5">5th Year</option>
                        <option value="6">6th Year</option>
                      </select>
                    </div>
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Program / Branch */}
                  <Input
                    label="Program / Branch *"
                    name="program"
                    required
                    placeholder="e.g. B.Tech Computer Science"
                    value={formData.program}
                    onChange={handleChange}
                    leftIcon={<BookOpen className="h-5 w-5" />}
                    error={fieldErrors['program']?.[0]}
                  />

                  {/* Department */}
                  <Input
                    label="Department"
                    name="department"
                    placeholder="e.g. CSE"
                    value={formData.department}
                    onChange={handleChange}
                    error={fieldErrors['department']?.[0]}
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Email */}
                  <Input
                    label="Institutional Email *"
                    name="email"
                    type="email"
                    required
                    placeholder="e.g. student@ptu.ac.in"
                    value={formData.email}
                    onChange={handleChange}
                    leftIcon={<Mail className="h-5 w-5" />}
                    error={fieldErrors['email']?.[0]}
                  />

                  {/* Phone Number */}
                  <Input
                    label="Phone Number *"
                    name="phone_number"
                    type="tel"
                    required
                    placeholder="e.g. +91 9876543210"
                    value={formData.phone_number}
                    onChange={handleChange}
                    leftIcon={<Phone className="h-5 w-5" />}
                    error={fieldErrors['phone_number']?.[0]}
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Password */}
                  <Input
                    label="Password (min 8 chars) *"
                    name="password"
                    type="password"
                    required
                    placeholder="••••••••••••"
                    value={formData.password}
                    onChange={handleChange}
                    leftIcon={<Lock className="h-5 w-5" />}
                    error={fieldErrors['password']?.[0]}
                  />

                  {/* Confirm Password */}
                  <Input
                    label="Confirm Password *"
                    name="password_confirmation"
                    type="password"
                    required
                    placeholder="••••••••••••"
                    value={formData.password_confirmation}
                    onChange={handleChange}
                    leftIcon={<Lock className="h-5 w-5" />}
                  />
                </div>

                {/* Live Registration Preview Banner */}
                {(photoPreview || formData.student_type) && (
                  <div className="p-3 bg-slate-100/80 border border-slate-200 rounded-xl flex items-center justify-between text-xs">
                    <div className="flex items-center gap-3">
                      {photoPreview ? (
                        <img
                          src={photoPreview}
                          alt="Preview"
                          className="w-10 h-10 rounded-xl object-cover ring-1 ring-slate-300 shadow-sm"
                        />
                      ) : (
                        <div className="w-10 h-10 rounded-xl bg-slate-200 flex items-center justify-center text-slate-400">
                          <Camera className="h-5 w-5" />
                        </div>
                      )}
                      <div>
                        <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">
                          Profile Preview
                        </span>
                        <span className="font-bold text-slate-800">
                          {formData.name.trim() || 'Student Name'}
                        </span>
                      </div>
                    </div>
                    <div>
                      <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block text-right">
                        Student Type
                      </span>
                      {formData.student_type ? (
                        <span
                          className={`px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider inline-block ${
                            formData.student_type === 'DAY_SCHOLAR'
                              ? 'bg-amber-100 text-amber-800 border border-amber-200'
                              : 'bg-indigo-100 text-indigo-800 border border-indigo-200'
                          }`}
                        >
                          ● {formData.student_type === 'DAY_SCHOLAR' ? 'DAY SCHOLAR' : 'HOSTELLER'}
                        </span>
                      ) : (
                        <span className="text-[10px] font-semibold text-rose-500">Not Selected</span>
                      )}
                    </div>
                  </div>
                )}

                <div className="bg-slate-50 border border-slate-200/70 rounded-xl p-3 text-xs text-slate-500">
                  <span className="font-semibold text-slate-700">Notice:</span> After registering, your account status will be set to <strong>PENDING</strong>. University Administration will verify your enrollment before enabling Gate Entry.
                </div>

                <Button
                  type="submit"
                  size="lg"
                  className="w-full mt-3 font-semibold shadow-md shadow-blue-600/30"
                  isLoading={isLoading}
                >
                  <UserPlus className="mr-2 h-4 w-4" /> Submit Registration Application
                </Button>
              </form>

              <div className="mt-5 pt-4 border-t border-slate-100 text-center text-xs text-slate-500">
                Already have an account?{' '}
                <Link to="/login" className="font-bold text-blue-600 hover:text-blue-700 underline">
                  Sign in here
                </Link>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
