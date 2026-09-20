import React, { useEffect, useState } from 'react';
import { CheckCircle2, Clock, MapPin, Compass, Car, ShieldCheck } from 'lucide-react';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import type { GateVerificationReceipt } from '../../types';

interface ReceiptModalProps {
  receipt: GateVerificationReceipt | null;
  onClose: () => void;
}

export const ReceiptModal: React.FC<ReceiptModalProps> = ({ receipt, onClose }) => {
  const [countdown, setCountdown] = useState(60);

  useEffect(() => {
    if (!receipt) {
      setCountdown(60);
      return;
    }

    setCountdown(60);
    const interval = setInterval(() => {
      setCountdown((prev) => {
        if (prev <= 1) {
          clearInterval(interval);
          onClose();
          return 0;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(interval);
  }, [receipt, onClose]);

  if (!receipt) return null;

  const isOut = receipt.movement_type === 'OUT';

  return (
    <Modal
      isOpen={!!receipt}
      onClose={onClose}
      title={
        <div className="flex items-center gap-2 text-emerald-600">
          <CheckCircle2 className="h-6 w-6" />
          <span>MOVEMENT RECORDED</span>
        </div>
      }
      description={`Auto-closing in ${countdown}s`}
      size="md"
      showCloseButton={false}
    >
      <div className="space-y-4 pt-1 text-left">
        {/* Verification Status Pill */}
        <div className="p-4 bg-slate-900 text-white rounded-2xl flex items-center justify-between shadow-lg">
          <div>
            <h4 className="text-lg font-black tracking-tight">{receipt.student_name}</h4>
            <p className="text-xs text-slate-300 font-mono">Roll: {receipt.roll_number}</p>
          </div>
          <div
            className={`px-3 py-1.5 rounded-xl font-black text-sm tracking-wider flex items-center gap-1.5 ${
              isOut ? 'bg-rose-500 text-white' : 'bg-emerald-500 text-white'
            }`}
          >
            <span>{isOut ? '🔴 OUT' : '🟢 IN'}</span>
          </div>
        </div>

        {/* Details Grid */}
        <div className="grid grid-cols-2 gap-3 text-xs">
          <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
            <span className="text-slate-400 block font-semibold text-[10px] uppercase">Verified Gate</span>
            <span className="font-bold text-slate-800 text-sm">{receipt.gate_name}</span>
          </div>

          <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
            <span className="text-slate-400 block font-semibold text-[10px] uppercase flex items-center gap-1">
              <Clock className="h-3 w-3" /> Server Time (IST)
            </span>
            <span className="font-bold text-slate-800 text-sm">
              {new Date(receipt.server_timestamp).toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
              })}
            </span>
          </div>

          {isOut && receipt.destination && (
            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block font-semibold text-[10px] uppercase flex items-center gap-1">
                <MapPin className="h-3 w-3" /> Destination
              </span>
              <span className="font-bold text-slate-800">{receipt.destination}</span>
            </div>
          )}

          {isOut && receipt.purpose && (
            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
              <span className="text-slate-400 block font-semibold text-[10px] uppercase flex items-center gap-1">
                <Compass className="h-3 w-3" /> Purpose
              </span>
              <span className="font-bold text-slate-800">{receipt.purpose}</span>
            </div>
          )}

          {isOut && receipt.vehicle_present && (
            <div className="col-span-2 p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between">
              <div>
                <span className="text-slate-400 block font-semibold text-[10px] uppercase flex items-center gap-1">
                  <Car className="h-3 w-3" /> Vehicle Registration
                </span>
                <span className="font-bold text-slate-800 text-sm font-mono">{receipt.vehicle_number}</span>
              </div>
              <span className="px-2 py-0.5 bg-blue-100 text-blue-800 rounded font-bold text-[10px]">VEHICLE</span>
            </div>
          )}
        </div>

        {/* Verification Code Box */}
        <div className="p-3.5 bg-blue-50/80 rounded-2xl border border-blue-200 flex items-center justify-between">
          <div>
            <span className="text-[10px] font-bold tracking-wider text-blue-600 block uppercase">
              Official Verification Code
            </span>
            <span className="font-mono text-base font-black text-slate-900 tracking-wider">
              {receipt.verification_code}
            </span>
          </div>
          <div className="flex items-center gap-1 text-xs text-blue-700 font-semibold">
            <ShieldCheck className="h-5 w-5 text-blue-600" />
            <span>Server Verified</span>
          </div>
        </div>

        {/* Close Button with live countdown */}
        <Button onClick={onClose} variant="primary" size="lg" className="w-full font-bold">
          Done ({countdown}s)
        </Button>
      </div>
    </Modal>
  );
};
