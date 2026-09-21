import React, { useState, useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { ShieldAlert, KeyRound, ArrowLeft } from 'lucide-react';
import { apiClient, getErrorMessage } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import type { ApiResponse, LoginResponseData } from '../../types';

export const TwoFactorChallengePage: React.FC = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const { login } = useAuth();

  const state = location.state as {
    challenge_token?: string;
    email?: string;
    userName?: string;
  } | null;

  const challengeToken = state?.challenge_token;

  const [code, setCode] = useState('');
  const [recoveryCode, setRecoveryCode] = useState('');
  const [useRecovery, setUseRecovery] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!challengeToken) {
      navigate('/login', { replace: true });
    }
  }, [challengeToken, navigate]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setIsLoading(true);

    try {
      const payload: Record<string, string> = {
        challenge_token: challengeToken!,
      };

      if (useRecovery) {
        payload.recovery_code = recoveryCode.trim();
      } else {
        payload.code = code.trim();
      }

      const response = await apiClient.post<ApiResponse<LoginResponseData>>('/auth/2fa/verify', payload);
      const data = response.data.data;

      if (data.token && data.user) {
        login(data.token, data.user, data.session_expires_at);

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
    <div className="min-h-screen bg-slate-900 flex flex-col justify-center items-center px-4 py-8">
      <div className="w-full max-w-md">
        {/* Header */}
        <div className="text-center mb-6">
          <div className="inline-flex items-center justify-center p-3 bg-blue-600/20 border border-blue-500/30 rounded-2xl mb-3">
            <ShieldAlert className="h-8 w-8 text-blue-400" />
          </div>
          <h1 className="text-2xl font-bold text-white">Two-Factor Authentication</h1>
          <p className="text-xs text-slate-400 mt-1">
            {useRecovery
              ? 'Enter one of your 10-character emergency recovery codes.'
              : 'Enter the 6-digit verification code from Microsoft Authenticator.'}
          </p>
        </div>

        {/* Card */}
        <div className="bg-white rounded-3xl shadow-2xl p-6 sm:p-8">
          {errorMessage && (
            <div className="mb-5">
              <Alert type="error" message={errorMessage} />
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-5">
            {!useRecovery ? (
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-2">
                  6-Digit Authenticator Code
                </label>
                <input
                  type="text"
                  required
                  maxLength={6}
                  autoFocus
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  placeholder="000 000"
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                  className="w-full text-center text-3xl font-mono tracking-widest py-3 border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none"
                />
              </div>
            ) : (
              <Input
                label="Emergency Recovery Code"
                type="text"
                required
                autoFocus
                placeholder="XXXXX-XXXXX"
                value={recoveryCode}
                onChange={(e) => setRecoveryCode(e.target.value)}
                leftIcon={<KeyRound className="h-5 w-5" />}
              />
            )}

            <Button
              type="submit"
              size="lg"
              className="w-full font-semibold shadow-md shadow-blue-600/20"
              isLoading={isLoading}
            >
              Verify & Proceed
            </Button>
          </form>

          <div className="mt-6 pt-5 border-t border-slate-100 flex flex-col gap-3 text-center">
            <button
              type="button"
              onClick={() => {
                setUseRecovery(!useRecovery);
                setErrorMessage(null);
              }}
              className="text-xs font-semibold text-blue-600 hover:text-blue-800 transition"
            >
              {useRecovery ? '← Use Authenticator app code' : 'Lost your phone? Use an emergency recovery code'}
            </button>

            <button
              type="button"
              onClick={() => navigate('/login')}
              className="inline-flex items-center justify-center text-xs text-slate-500 hover:text-slate-700 transition"
            >
              <ArrowLeft className="h-3.5 w-3.5 mr-1" /> Back to login
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};
