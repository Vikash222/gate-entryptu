import React, { useState, useEffect } from 'react';
import { LogIn, LogOut, Car } from 'lucide-react';
import { Modal } from '../../components/ui/Modal';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse, GateVerificationReceipt } from '../../types';

interface MovementModalProps {
  isOpen: boolean;
  onClose: () => void;
  movementType: 'IN' | 'OUT';
  gateSessionToken: string;
  gateName: string;
  onSuccess: (receipt: GateVerificationReceipt) => void;
}

export const MovementModal: React.FC<MovementModalProps> = ({
  isOpen,
  onClose,
  movementType,
  gateSessionToken,
  gateName,
  onSuccess,
}) => {
  const [vehiclePresent, setVehiclePresent] = useState(false);
  const [vehicleNumber, setVehicleNumber] = useState('');

  const [destinationOptions, setDestinationOptions] = useState<string[]>([
    'Jalandhar',
    'Kapurthala',
    'Kheere Shop',
    'Home',
    'Other',
  ]);
  const [destination, setDestination] = useState('Jalandhar');
  const [destinationOther, setDestinationOther] = useState('');

  const [purposeOptions, setPurposeOptions] = useState<string[]>([
    'Personal',
    'Food',
    'Shopping',
    'Academic',
    'Medical',
    'Home Visit',
    'Other',
  ]);
  const [purpose, setPurpose] = useState('Personal');
  const [purposeOther, setPurposeOther] = useState('');

  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Load configurable options from server
  useEffect(() => {
    if (isOpen && movementType === 'OUT') {
      apiClient
        .get<ApiResponse<{ destinations: string[]; purposes: string[] }>>('/gate-entry/options')
        .then((res) => {
          if (res.data.data.destinations?.length > 0) {
            setDestinationOptions(res.data.data.destinations);
            setDestination(res.data.data.destinations[0]);
          }
          if (res.data.data.purposes?.length > 0) {
            setPurposeOptions(res.data.data.purposes);
            setPurpose(res.data.data.purposes[0]);
          }
        })
        .catch(() => {});
    }
  }, [isOpen, movementType]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    // Client request ID for idempotency
    const clientRequestId = `req_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;

    try {
      if (movementType === 'IN') {
        const response = await apiClient.post<ApiResponse<{ receipt: GateVerificationReceipt }>>(
          '/gate-entry/in',
          {
            gate_session_token: gateSessionToken,
            client_request_id: clientRequestId,
          }
        );
        onSuccess(response.data.data.receipt);
      } else {
        if (vehiclePresent && !vehicleNumber.trim()) {
          setErrorMessage('Vehicle number is required when exiting in a vehicle.');
          setIsLoading(false);
          return;
        }

        const response = await apiClient.post<ApiResponse<{ receipt: GateVerificationReceipt }>>(
          '/gate-entry/out',
          {
            gate_session_token: gateSessionToken,
            destination,
            destination_other: destination === 'Other' ? destinationOther.trim() : null,
            purpose,
            purpose_other: purpose === 'Other' ? purposeOther.trim() : null,
            vehicle_present: vehiclePresent,
            vehicle_number: vehiclePresent ? vehicleNumber.trim().toUpperCase() : null,
            client_request_id: clientRequestId,
          }
        );
        onSuccess(response.data.data.receipt);
      }
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  const isOut = movementType === 'OUT';

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={
        <div className="flex items-center gap-2">
          {isOut ? <LogOut className="h-5 w-5 text-rose-600" /> : <LogIn className="h-5 w-5 text-emerald-600" />}
          <span>{isOut ? 'Confirm Campus Exit' : 'Confirm Campus Entry'}</span>
        </div>
      }
      description={`Gate Verified: ${gateName}`}
      size="md"
    >
      <form onSubmit={handleSubmit} className="space-y-5 pt-2 text-left">
        {errorMessage && <Alert type="error" message={errorMessage} />}

        {!isOut ? (
          // Fast IN flow: One-tap confirmation
          <div className="space-y-4 py-2">
            <div className="p-4 bg-emerald-50 rounded-2xl border border-emerald-200 text-center">
              <span className="text-xs font-semibold uppercase tracking-wider text-emerald-600 block">
                Recording Entry At
              </span>
              <h3 className="text-xl font-black text-slate-900 mt-1">{gateName}</h3>
              <p className="text-xs text-slate-500 mt-1">Authoritative server timestamp will be applied.</p>
            </div>

            <Button
              type="submit"
              variant="success"
              size="xl"
              className="w-full text-base font-bold shadow-md shadow-emerald-600/20"
              isLoading={isLoading}
            >
              Confirm Entry Now
            </Button>
          </div>
        ) : (
          // OUT flow: Destination, Purpose, Vehicle
          <div className="space-y-4">
            {/* Vehicle Question */}
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-2">
                Are you in a vehicle?
              </label>
              <div className="grid grid-cols-2 gap-2">
                <button
                  type="button"
                  onClick={() => setVehiclePresent(false)}
                  className={`py-2.5 px-4 rounded-xl border text-sm font-bold transition ${
                    !vehiclePresent
                      ? 'bg-slate-900 text-white border-slate-900 shadow-sm'
                      : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                  }`}
                >
                  NO
                </button>
                <button
                  type="button"
                  onClick={() => setVehiclePresent(true)}
                  className={`py-2.5 px-4 rounded-xl border text-sm font-bold transition flex items-center justify-center gap-1.5 ${
                    vehiclePresent
                      ? 'bg-blue-600 text-white border-blue-600 shadow-sm'
                      : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                  }`}
                >
                  <Car className="h-4 w-4" /> YES
                </button>
              </div>
            </div>

            {/* Vehicle Number (if YES) */}
            {vehiclePresent && (
              <Input
                label="Vehicle Registration Number"
                type="text"
                required
                placeholder="e.g. PB10AB1234"
                value={vehicleNumber}
                onChange={(e) => setVehicleNumber(e.target.value)}
                autoFocus
              />
            )}

            {/* Destination Selection */}
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-2">
                Where are you going?
              </label>
              <div className="flex flex-wrap gap-2 mb-2">
                {destinationOptions.map((opt) => (
                  <button
                    key={opt}
                    type="button"
                    onClick={() => setDestination(opt)}
                    className={`px-3 py-1.5 rounded-lg text-xs font-bold transition ${
                      destination === opt
                        ? 'bg-blue-600 text-white shadow-sm'
                        : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                    }`}
                  >
                    {opt}
                  </button>
                ))}
              </div>
              {destination === 'Other' && (
                <Input
                  placeholder="Specify destination"
                  value={destinationOther}
                  onChange={(e) => setDestinationOther(e.target.value)}
                  required
                />
              )}
            </div>

            {/* Purpose Selection */}
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-2">
                Purpose of Visit
              </label>
              <div className="flex flex-wrap gap-2 mb-2">
                {purposeOptions.map((opt) => (
                  <button
                    key={opt}
                    type="button"
                    onClick={() => setPurpose(opt)}
                    className={`px-3 py-1.5 rounded-lg text-xs font-bold transition ${
                      purpose === opt
                        ? 'bg-blue-600 text-white shadow-sm'
                        : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                    }`}
                  >
                    {opt}
                  </button>
                ))}
              </div>
              {purpose === 'Other' && (
                <Input
                  placeholder="Specify purpose"
                  value={purposeOther}
                  onChange={(e) => setPurposeOther(e.target.value)}
                  required
                />
              )}
            </div>

            <Button
              type="submit"
              variant="danger"
              size="xl"
              className="w-full text-base font-bold shadow-md shadow-rose-600/20"
              isLoading={isLoading}
            >
              Confirm Exit Now
            </Button>
          </div>
        )}
      </form>
    </Modal>
  );
};
