import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { ShieldCheck, UserPlus, Lock, Mail, Phone, BookOpen, GraduationCap, CheckCircle2, ArrowRight, ArrowLeft } from 'lucide-react';
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
    name: string;
    email: string;
    status: string;
  };
}

export const RegisterStudentPage: React.FC = () => {
  const navigate = useNavigate();

  const [formData, setFormData] = useState({
    name: '',
    roll_number: '',
    student_id: '',
    year: '1',
    program: '',
    department: '',
    email: '',
    phone_number: '',
    password: '',
    password_confirmation: '',
  });

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

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setFieldErrors({});

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
      const payload = {
        name: formData.name.trim(),
        roll_number: formData.roll_number.trim(),
        student_id: formData.student_id.trim() ? formData.student_id.trim() : formData.roll_number.trim(),
        year: parseInt(formData.year, 10),
        program: formData.program.trim(),
        department: formData.department.trim() || undefined,
        email: formData.email.trim(),
        phone_number: formData.phone_number.trim(),
        password: formData.password,
        password_confirmation: formData.password_confirmation,
      };

      const response = await apiClient.post<ApiResponse<RegisterSuccessData>>('/auth/register-student', payload);
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
                <p className="text-sm text-slate-500 mt-1">Your SmartGate account has been created.</p>
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
                <div className="flex justify-between py-1 border-b border-slate-200/50">
                  <span className="text-slate-500">Full Name:</span>
                  <span className="font-semibold text-slate-800">{registeredStudent.name}</span>
                </div>
                <div className="flex justify-between py-1 border-b border-slate-200/50">
                  <span className="text-slate-500">Roll Number:</span>
                  <span className="font-semibold text-slate-800 font-mono">{registeredStudent.roll_number}</span>
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
