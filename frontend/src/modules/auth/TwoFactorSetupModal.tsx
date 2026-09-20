import React, { useState } from 'react';
import QRCode from 'qrcode';
import { ShieldCheck, Copy, Check, Download } from 'lucide-react';
import { Modal } from '../../components/ui/Modal';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse } from '../../types';

interface TwoFactorSetupModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
}

export const TwoFactorSetupModal: React.FC<TwoFactorSetupModalProps> = ({
  isOpen,
  onClose,
  onSuccess,
}) => {
  const [step, setStep] = useState<'initial' | 'scan' | 'recovery'>('initial');
  const [secret, setSecret] = useState('');
  const [qrDataUrl, setQrDataUrl] = useState('');
  const [testCode, setTestCode] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [copied, setCopied] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const startSetup = async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const response = await apiClient.post<ApiResponse<{ secret: string; otpauth_url: string }>>('/auth/2fa/setup');
      const data = response.data.data;
      setSecret(data.secret);

      const url = await QRCode.toDataURL(data.otpauth_url, {
        width: 256,
        margin: 2,
        color: {
          dark: '#0f172a',
          light: '#ffffff',
        },
      });
      setQrDataUrl(url);
      setStep('scan');
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  const verifyAndEnable = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const response = await apiClient.post<ApiResponse<{ recovery_codes: string[] }>>('/auth/2fa/enable', {
        code: testCode.trim(),
      });

      setRecoveryCodes(response.data.data.recovery_codes);
      setStep('recovery');
      onSuccess();
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  const copySecret = () => {
    navigator.clipboard.writeText(secret);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const downloadRecoveryCodes = () => {
    const text = `SMARTGATE 2FA RECOVERY CODES\nGenerated: ${new Date().toISOString()}\n\nKeep these codes in a safe place. Each code can be used once:\n\n` +
      recoveryCodes.map((c, i) => `${i + 1}. ${c}`).join('\n');
    const blob = new Blob([text], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'smartgate-recovery-codes.txt';
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Two-Factor Authentication (2FA)"
      description="Secure your account using Microsoft Authenticator or Google Authenticator."
      size="md"
    >
      <div className="space-y-4 pt-2">
        {errorMessage && <Alert type="error" message={errorMessage} />}

        {step === 'initial' && (
          <div className="text-center py-4 space-y-4">
            <div className="inline-flex p-4 bg-blue-50 text-blue-600 rounded-full">
              <ShieldCheck className="h-10 w-10" />
            </div>
            <p className="text-sm text-slate-600">
              Two-factor authentication adds an extra layer of physical security. Whenever you sign in, you will be required to provide a 6-digit code from Microsoft Authenticator.
            </p>
            <Button onClick={startSetup} isLoading={isLoading} className="w-full">
              Begin 2FA Setup
            </Button>
          </div>
        )}

        {step === 'scan' && (
          <div className="space-y-4">
            <div className="bg-slate-50 p-4 rounded-2xl flex flex-col items-center border border-slate-200/80">
              {qrDataUrl && (
                <img src={qrDataUrl} alt="2FA QR Code" className="w-52 h-52 rounded-xl shadow-sm bg-white p-2" />
              )}
              <p className="text-xs text-slate-500 mt-3 text-center font-medium">
                Scan with <strong>Microsoft Authenticator</strong> or any TOTP app
              </p>
            </div>

            <div className="text-xs text-slate-500 bg-slate-100 p-3 rounded-xl flex items-center justify-between">
              <div>
                <span className="font-semibold block text-slate-700">Manual Entry Key:</span>
                <span className="font-mono text-slate-800 break-all">{secret}</span>
              </div>
              <button
                type="button"
                onClick={copySecret}
                className="p-2 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 transition ml-2 flex-shrink-0"
              >
                {copied ? <Check className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
              </button>
            </div>

            <form onSubmit={verifyAndEnable} className="space-y-3 pt-2">
              <Input
                label="Enter 6-digit code from authenticator app"
                type="text"
                required
                maxLength={6}
                inputMode="numeric"
                placeholder="000 000"
                value={testCode}
                onChange={(e) => setTestCode(e.target.value.replace(/\D/g, ''))}
              />
              <Button type="submit" isLoading={isLoading} className="w-full">
                Verify & Activate 2FA
              </Button>
            </form>
          </div>
        )}

        {step === 'recovery' && (
          <div className="space-y-4">
            <Alert
              type="warning"
              title="Save Your Emergency Recovery Codes"
              message="If you lose access to your authenticator phone, these recovery codes are the only way to regain access to your account."
            />

            <div className="grid grid-cols-2 gap-2 bg-slate-50 p-4 rounded-2xl border border-slate-200">
              {recoveryCodes.map((code, idx) => (
                <div key={idx} className="font-mono text-xs font-bold text-slate-800 bg-white p-2 rounded-lg border border-slate-200 text-center">
                  {code}
                </div>
              ))}
            </div>

            <div className="flex gap-2">
              <Button variant="outline" onClick={downloadRecoveryCodes} className="flex-1">
                <Download className="h-4 w-4 mr-1.5" /> Download Codes
              </Button>
              <Button onClick={onClose} className="flex-1">
                Finish Setup
              </Button>
            </div>
          </div>
        )}
      </div>
    </Modal>
  );
};
