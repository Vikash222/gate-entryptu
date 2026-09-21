import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ShieldCheck, Lock, Mail, ArrowRight } from 'lucide-react';
import { apiClient, getErrorMessage } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import type { ApiResponse, LoginResponseData } from '../../types';

export const LoginPage: React.FC = () => {
  const navigate = useNavigate();
  const { login } = useAuth();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [sessionExpiredMessage, setSessionExpiredMessage] = useState<string | null>(() => {
    return sessionStorage.getItem('smartgate_session_expired_message');
  });

  React.useEffect(() => {
    if (sessionExpiredMessage) {
      sessionStorage.removeItem('smartgate_session_expired_message');
    }
  }, [sessionExpiredMessage]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setSessionExpiredMessage(null);
    setIsLoading(true);

    try {
      const response = await apiClient.post<ApiResponse<LoginResponseData>>('/auth/login', {
        email: email.trim(),
        password,
      });

      const data = response.data.data;

      if (data.requires_2fa && data.challenge_token) {
        // Redirect to 2FA challenge page
        navigate('/auth/2fa', {
          state: {
            challenge_token: data.challenge_token,
            email: email.trim(),
            userName: data.user?.name,
          },
        });
        return;
      }

      if (data.token && data.user) {
        login(data.token, data.user, data.session_expires_at);

        // Role-based routing
        if (data.user.role === 'STUDENT') {
          navigate('/student');
        } else if (data.user.role === 'SECURITY') {
          navigate('/security');
        } else if (data.user.role === 'ADMIN') {
          navigate('/admin');
        } else {
          navigate('/');
        }
      }
    } catch (error) {
      setErrorMessage(getErrorMessage(error));
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-900 via-blue-950 to-slate-900 flex flex-col justify-center items-center px-4 py-8">
      {/* Container */}
      <div className="w-full max-w-md">
        {/* Brand Header */}
        <div className="text-center mb-8">
          <div className="inline-flex items-center justify-center p-3.5 bg-blue-600/20 border border-blue-500/30 rounded-2xl mb-4 backdrop-blur-md shadow-lg shadow-blue-500/10">
            <ShieldCheck className="h-9 w-9 text-blue-400" />
          </div>
          <h1 className="text-2xl font-black tracking-tight text-white sm:text-3xl">SMARTGATE</h1>
          <p className="text-sm font-medium text-blue-200/80 mt-1">University Gate Entry & Exit Management System</p>
        </div>

        {/* Form Card */}
        <div className="bg-white rounded-3xl shadow-2xl p-6 sm:p-8 border border-slate-100">
          <div className="mb-6">
            <h2 className="text-xl font-bold text-slate-900">Sign In</h2>
            <p className="text-xs text-slate-500 mt-1">Enter your university credentials to access the gate system</p>
          </div>

          {sessionExpiredMessage && (
            <div className="mb-6">
              <Alert type="warning" message={sessionExpiredMessage} />
            </div>
          )}

          {errorMessage && (
            <div className="mb-6">
              <Alert type="error" message={errorMessage} />
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <Input
              label="Email Address"
              type="email"
              required
              autoComplete="email"
              placeholder="e.g. yourname@university.edu"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              leftIcon={<Mail className="h-5 w-5" />}
            />

            <Input
              label="Password"
              type="password"
              required
              autoComplete="current-password"
              placeholder="••••••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              leftIcon={<Lock className="h-5 w-5" />}
            />

            <Button
              type="submit"
              size="lg"
              className="w-full mt-2 font-semibold shadow-md shadow-blue-600/30"
              isLoading={isLoading}
            >
              Sign In to SmartGate <ArrowRight className="ml-2 h-4 w-4" />
            </Button>
          </form>

          {/* Student Self-Registration Link */}
          <div className="mt-6 pt-5 border-t border-slate-100 text-center">
            <p className="text-xs text-slate-600">
              New University Student?{' '}
              <button
                type="button"
                onClick={() => navigate('/register')}
                className="font-bold text-blue-600 hover:text-blue-700 underline focus:outline-none"
              >
                Register SmartGate Account
              </button>
            </p>
          </div>

          {/* Security Notice Footer */}
          <div className="mt-4 pt-4 border-t border-slate-100 text-center">
            <div className="flex items-center justify-center gap-1.5 text-xs text-slate-400 font-medium">
              <Lock className="h-3.5 w-3.5 text-emerald-500" />
              <span>TLS Encrypted &bull; 2FA Ready &bull; Server Authoritative</span>
            </div>
          </div>
        </div>

        {/* System Info */}
        <p className="text-center text-xs text-slate-400/80 mt-6">
          Authorized personnel and students only. All access attempts are logged.
        </p>
      </div>
    </div>
  );
};
