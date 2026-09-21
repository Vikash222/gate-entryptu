import React from 'react';
import {
  MapPin,
  Car,
  Moon,
  AlertTriangle,
} from 'lucide-react';
import { Modal } from '../../components/ui/Modal';
import { Badge } from '../../components/ui/Badge';
import { Avatar } from '../../components/ui/Avatar';
import type { Movement } from '../../types';

interface MovementDetailModalProps {
  isOpen: boolean;
  onClose: () => void;
  movement: Movement | null;
}

export const MovementDetailModal: React.FC<MovementDetailModalProps> = ({
  isOpen,
  onClose,
  movement,
}) => {
  if (!movement) return null;

  const istDateStr = movement.server_timestamp
    ? new Date(movement.server_timestamp).toLocaleString('en-IN', {
        timeZone: 'Asia/Kolkata',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
      })
    : '-';

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={
        <div className="flex items-center gap-2">
          <span className="text-base font-black text-slate-900">Movement Audit Record</span>
          <span className="font-mono text-xs font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">
            {movement.verification_code}
          </span>
        </div>
      }
      description="Authoritative campus gate movement timestamp and security audit trail"
      size="lg"
    >
      <div className="space-y-5 text-left text-xs">
        {/* Prominent Day Scholar After-Hours Banner */}
        {movement.day_scholar_after_hours && (
          <div className="p-3 bg-rose-50 border-2 border-rose-300 rounded-xl flex items-center justify-between">
            <div className="flex items-center gap-2.5">
              <div className="w-8 h-8 rounded-full bg-rose-100 flex items-center justify-center shrink-0">
                <AlertTriangle className="h-4 w-4 text-rose-600" />
              </div>
              <div>
                <span className="font-black text-rose-900 text-xs tracking-wide block">
                  DAY SCHOLAR — AFTER HOURS
                </span>
                <span className="text-[11px] text-rose-700">
                  Day Scholar movement recorded past standard campus hours (after 5:00 PM IST).
                </span>
              </div>
            </div>
            <span className="px-2 py-0.5 rounded text-[10px] font-black bg-emerald-100 text-emerald-800 border border-emerald-300">
              ALLOWED
            </span>
          </div>
        )}

        {/* Status Highlights */}
        <div className="flex flex-wrap gap-2 p-3 bg-slate-50 border border-slate-200 rounded-xl items-center justify-between">
          <div className="flex items-center gap-2">
            <span className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Type:</span>
            <Badge
              variant={movement.type === 'IN' ? 'success' : 'danger'}
              size="md"
              className="font-black"
            >
              {movement.type === 'IN' ? '🟢 IN (Entry)' : '🔴 OUT (Exit)'}
            </Badge>
          </div>

          <div className="flex items-center gap-2">
            <span className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Classification:</span>
            {movement.day_scholar_after_hours && (
              <Badge variant="danger" size="md" className="font-black bg-rose-100 text-rose-800 border-rose-300">
                DAY SCHOLAR — AFTER HOURS
              </Badge>
            )}
            {movement.is_late ? (
              <Badge variant="warning" size="md" className="font-black flex items-center gap-1">
                <Moon className="h-3 w-3 text-amber-600" />
                LATE ENTRY (9 PM – 4 AM)
              </Badge>
            ) : !movement.day_scholar_after_hours ? (
              <Badge variant="neutral" size="md" className="font-semibold text-slate-600">
                NORMAL HOURS
              </Badge>
            ) : null}
          </div>

          <div className="flex items-center gap-2">
            <span className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Source:</span>
            <Badge variant="neutral" size="sm" className="font-mono">
              {movement.movement_source === 'SECURITY_MANUAL' ? 'SECURITY MANUAL' : 'QR SCAN'}
            </Badge>
          </div>
        </div>

        {/* Student Information Card */}
        <div className="border border-slate-200 rounded-xl p-4 bg-white shadow-xs">
          <div className="flex items-center justify-between pb-3 mb-3 border-b border-slate-100">
            <div className="flex items-center gap-3">
              <Avatar
                src={movement.student?.profile_photo_url}
                name={movement.student?.name || 'Student'}
                size="lg"
                shape="rounded"
              />
              <div>
                <h4 className="font-bold text-slate-900 text-sm">{movement.student?.name || 'Unknown'}</h4>
                <div className="flex items-center gap-2 mt-0.5">
                  <span className="font-mono text-xs text-slate-500">{movement.student?.roll_number}</span>
                  <span
                    className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${
                      movement.student?.category === 'DAY_SCHOLAR'
                        ? 'bg-purple-100 text-purple-800 border border-purple-200'
                        : 'bg-blue-100 text-blue-800 border border-blue-200'
                    }`}
                  >
                    {movement.student?.category === 'DAY_SCHOLAR' ? 'Day Scholar' : 'Hosteler'}
                  </span>
                </div>
              </div>
            </div>
            {movement.student?.current_status && (
              <Badge
                variant={movement.student?.current_status === 'INSIDE' ? 'success' : 'neutral'}
                size="sm"
                className="font-bold"
              >
                {movement.student?.current_status}
              </Badge>
            )}
          </div>

          <div className="grid grid-cols-2 gap-3 text-xs">
            <div>
              <span className="text-slate-400 block text-[10px] uppercase font-semibold">Student ID</span>
              <span className="font-mono text-slate-700">{movement.student?.student_id || '-'}</span>
            </div>
            <div>
              <span className="text-slate-400 block text-[10px] uppercase font-semibold">Academic Program</span>
              <span className="text-slate-800 font-medium">
                {movement.student?.program || '-'} {movement.student?.department ? `(${movement.student.department})` : ''}
              </span>
            </div>
            <div>
              <span className="text-slate-400 block text-[10px] uppercase font-semibold">Year & Batch</span>
              <span className="text-slate-700">
                Year {movement.student?.year || '-'} • Batch {movement.student?.batch || '-'}
              </span>
            </div>
            <div>
              <span className="text-slate-400 block text-[10px] uppercase font-semibold">Student Category</span>
              <span className="font-bold text-slate-800">
                {movement.student?.category === 'DAY_SCHOLAR' ? 'Day Scholar' : 'Hosteler'}
              </span>
            </div>
          </div>
        </div>

        {/* Movement Details Card */}
        <div className="border border-slate-200 rounded-xl p-4 bg-white shadow-xs">
          <div className="flex items-center gap-2 pb-2 mb-3 border-b border-slate-100">
            <MapPin className="h-4 w-4 text-emerald-600" />
            <h4 className="font-bold text-slate-900 text-sm">Gate & Timestamp Audit</h4>
          </div>

          <div className="grid grid-cols-2 gap-3 text-xs">
            <div>
              <span className="text-slate-400 block text-[10px] uppercase font-semibold">Gate Location</span>
              <span className="font-bold text-slate-800">
                {movement.gate?.name || 'Gate'} ({movement.gate?.code || '-'})
              </span>
            </div>
            <div>
              <span className="text-slate-400 block text-[10px] uppercase font-semibold">Authoritative Server Time (IST)</span>
              <span className="font-mono font-bold text-slate-900">{istDateStr}</span>
            </div>
            {movement.is_late && (
              <div>
                <span className="text-slate-400 block text-[10px] uppercase font-semibold">Late Overnight Window</span>
                <span className="font-mono text-amber-700 font-bold bg-amber-50 px-2 py-0.5 rounded inline-block">
                  {movement.late_window_date || 'N/A'} (Night shift)
                </span>
              </div>
            )}
            <div>
              <span className="text-slate-400 block text-[10px] uppercase font-semibold">Security Guard / Operator</span>
              <span className="text-slate-700">
                {movement.security_user?.name ? (
                  <span className="font-semibold text-slate-800">
                    {movement.security_user.name} (ID #{movement.security_user.id})
                  </span>
                ) : (
                  <span className="text-slate-500 italic">Self-service (Gate QR Scan)</span>
                )}
              </span>
            </div>
          </div>
        </div>

        {/* Transit & Vehicle Card */}
        <div className="border border-slate-200 rounded-xl p-4 bg-white shadow-xs">
          <div className="flex items-center gap-2 pb-2 mb-3 border-b border-slate-100">
            <Car className="h-4 w-4 text-blue-600" />
            <h4 className="font-bold text-slate-900 text-sm">Transit & Vehicle Data</h4>
          </div>

          <div className="grid grid-cols-2 gap-3 text-xs">
            <div>
              <span className="text-slate-400 block text-[10px] uppercase font-semibold">Destination</span>
              <span className="font-medium text-slate-800">
                {movement.destination || movement.destination_other || '—'}
              </span>
            </div>
            <div>
              <span className="text-slate-400 block text-[10px] uppercase font-semibold">Declared Purpose</span>
              <span className="font-medium text-slate-800">
                {movement.purpose || movement.purpose_other || '—'}
              </span>
            </div>
            <div>
              <span className="text-slate-400 block text-[10px] uppercase font-semibold">Vehicle Present</span>
              <span className="font-bold text-slate-800">
                {movement.vehicle_present ? 'YES' : 'NO'}
              </span>
            </div>
            <div>
              <span className="text-slate-400 block text-[10px] uppercase font-semibold">Vehicle Plate / Registration</span>
              <span className="font-mono font-bold text-blue-700">
                {movement.vehicle_number || '—'}
              </span>
            </div>
          </div>
        </div>

        {/* Technical Traceability */}
        <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl text-[11px] font-mono text-slate-500 space-y-1">
          <div className="flex justify-between">
            <span>Movement UUID:</span>
            <span className="font-bold text-slate-700 select-all">{movement.movement_uuid}</span>
          </div>
          <div className="flex justify-between">
            <span>Verification Code:</span>
            <span className="font-bold text-blue-700 select-all">{movement.verification_code}</span>
          </div>
        </div>
      </div>
    </Modal>
  );
};
