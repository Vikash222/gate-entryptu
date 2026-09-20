import React, { useState, useEffect } from 'react';
import { ShieldCheck, KeyRound, AlertCircle } from 'lucide-react';
import { Modal } from '../../components/ui/Modal';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse, Gate } from '../../types';

interface DutyActivationModalProps {
  isOpen: boolean;
  onClose: () => void;
  onDutyActivated: (dutyData: any) => void;
}

export const DutyActivationModal: React.FC<DutyActivationModalProps> = ({
  isOpen,
  onClose,
  onDutyActivated,
}) => {
  const [gates, setGates] = useState<Gate[]>([]);
  const [selectedGateId, setSelectedGateId] = useState<number | null>(null);
  const [adminOtp, setAdminOtp] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    if (isOpen) {
      setErrorMessage(null);
      setAdminOtp('');
      apiClient
        .get<ApiResponse<Gate[]>>('/gates')
        .then((res) => {
          setGates(res.data.data);
          if (res.data.data.length > 0) {
            setSelectedGateId(res.data.data[0].id);
          }
        })
        .catch((err) => {
          setErrorMessage(getErrorMessage(err));
        });
    }
  }, [isOpen]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedGateId) {
      setErrorMessage('Please select an assigned university gate.');
      return;
    }

    const trimmedOtp = adminOtp.trim();
    if (trimmedOtp.length !== 6 || !/^\d{6}$/.test(trimmedOtp)) {
      setErrorMessage('Please enter a valid 6-digit numeric Admin Authorization OTP.');
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    try {
      const response = await apiClient.post<ApiResponse<any>>('/security/duty/activate', {
        gate_id: selectedGateId,
        admin_otp: trimmedOtp,
      });

      onDutyActivated(response.data.data);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  const isFormValid = selectedGateId !== null && adminOtp.trim().length === 6 && /^\d{6}$/.test(adminOtp.trim());

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={
        <div className="flex items-center gap-2">
          <ShieldCheck className="h-5 w-5 text-blue-600" />
          <span>Authorize Gate Duty Session</span>
        </div>
      }
      description="Two-stage security: Admin OTP authorization is required to activate operational access."
      size="md"
    >
      <form onSubmit={handleSubmit} className="space-y-4 pt-2 text-left">
        {errorMessage && <Alert type="error" message={errorMessage} />}

        <div className="p-3 bg-amber-50 border border-amber-200 rounded-xl flex items-start gap-2.5 text-xs text-amber-900">
          <AlertCircle className="h-4 w-4 text-amber-600 shrink-0 mt-0.5" />
          <span>
            <strong>Mandatory Authorization:</strong> Request the University Administrator to generate a 6-digit Duty OTP from the Admin Console. A valid login alone cannot operate the gate.
          </span>
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-2">
            1. Select Assigned University Gate
          </label>
          <div className="grid grid-cols-1 gap-2">
            {gates.map((g) => (
              <button
                key={g.id}
                type="button"
                onClick={() => setSelectedGateId(g.id)}
                className={`p-3 rounded-xl border text-left flex items-center justify-between transition ${
                  selectedGateId === g.id
                    ? 'bg-blue-50/70 border-blue-600 ring-2 ring-blue-500/20'
                    : 'bg-white border-slate-200 hover:bg-slate-50'
                }`}
              >
                <div>
                  <h4 className="font-bold text-slate-900 text-sm">{g.name}</h4>
                  <p className="text-xs text-slate-500 font-mono">{g.code} &bull; {g.location || 'University Campus'}</p>
                </div>
                <span
                  className={`h-4 w-4 rounded-full border-2 flex items-center justify-center ${
                    selectedGateId === g.id ? 'border-blue-600 bg-blue-600' : 'border-slate-300'
                  }`}
                >
                  {selectedGateId === g.id && <span className="h-1.5 w-1.5 rounded-full bg-white" />}
                </span>
              </button>
            ))}
          </div>
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">
            2. Enter Admin Authorization OTP <span className="text-rose-500">*</span>
          </label>
          <Input
            type="text"
            inputMode="numeric"
            maxLength={6}
            placeholder="6-digit OTP (e.g. 849201)"
            value={adminOtp}
            onChange={(e) => setAdminOtp(e.target.value.replace(/\D/g, '').slice(0, 6))}
            leftIcon={<KeyRound className="h-4 w-4 text-slate-400" />}
            className="text-center font-mono text-lg font-bold tracking-widest"
            helperText="Single-use, short-lived code generated by Admin"
          />
        </div>

        <Button
          type="submit"
          size="xl"
          variant="primary"
          className="w-full font-bold mt-2"
          isLoading={isLoading}
          disabled={!isFormValid || isLoading}
        >
          Verify OTP & Start Duty
        </Button>
      </form>
    </Modal>
  );
};
