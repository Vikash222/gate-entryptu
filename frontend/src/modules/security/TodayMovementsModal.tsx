import React, { useState, useEffect } from 'react';
import { Calendar, RefreshCw, AlertTriangle } from 'lucide-react';
import { Modal } from '../../components/ui/Modal';
import { Button } from '../../components/ui/Button';
import { Avatar } from '../../components/ui/Avatar';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse, Movement } from '../../types';

interface TodayMovementsModalProps {
  isOpen: boolean;
  onClose: () => void;
  gateId?: number;
}

export const TodayMovementsModal: React.FC<TodayMovementsModalProps> = ({
  isOpen,
  onClose,
  gateId,
}) => {
  const [movements, setMovements] = useState<Movement[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const fetchToday = async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const url = gateId ? `/security/today?gate_id=${gateId}` : '/security/today';
      const response = await apiClient.get<ApiResponse<Movement[]>>(url);
      setMovements(response.data.data);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    if (isOpen) {
      fetchToday();
    }
  }, [isOpen, gateId]);

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={
        <div className="flex items-center gap-2">
          <Calendar className="h-5 w-5 text-emerald-600" />
          <span className="text-base font-black text-slate-900">Today's Gate Movements</span>
        </div>
      }
      description="Live ledger of movements recorded at this gate location today"
      size="lg"
    >
      <div className="space-y-4 pt-1 text-left">
        <div className="flex justify-between items-center text-xs">
          <span className="text-slate-500 font-mono">
            Total records: <strong className="text-slate-900">{movements.length}</strong>
          </span>
          <Button
            variant="ghost"
            size="sm"
            onClick={fetchToday}
            isLoading={isLoading}
            className="text-xs text-blue-600"
          >
            <RefreshCw className="h-3 w-3 mr-1" /> Refresh
          </Button>
        </div>

        {errorMessage && <p className="text-xs text-rose-600">{errorMessage}</p>}

        <div className="divide-y divide-slate-100 max-h-[60vh] overflow-y-auto">
          {movements.length === 0 && !isLoading ? (
            <div className="py-8 text-center text-slate-400 text-xs font-medium">
              Zero movements recorded yet today.
            </div>
          ) : (
            movements.map((m) => (
              <div key={m.id} className="py-3 flex items-center justify-between text-xs">
                <div className="flex items-center gap-2.5">
                  <Avatar
                    src={m.student?.profile_photo_url}
                    name={m.student?.name || 'Student'}
                    size="md"
                    shape="rounded"
                  />
                  <div>
                    <div className="flex items-center gap-1.5">
                      <h5 className="font-bold text-slate-900">{m.student?.name || 'Student'}</h5>
                      {m.student?.category === 'DAY_SCHOLAR' && (
                        <span className="px-1.5 py-0.2 rounded text-[9px] font-bold bg-purple-100 text-purple-700 border border-purple-200">
                          Day Scholar
                        </span>
                      )}
                    </div>
                    <p className="text-[11px] text-slate-500 font-mono">
                      {m.student?.roll_number || 'N/A'} &bull; {m.gate?.name}
                    </p>
                    {m.type === 'OUT' && (
                      <p className="text-[10px] text-slate-400 mt-0.5">
                        To: <span className="text-slate-600 font-medium">{m.destination || '-'}</span>
                        {m.vehicle_present && (
                          <span className="ml-1.5 font-mono text-blue-600">[{m.vehicle_number}]</span>
                        )}
                      </p>
                    )}
                  </div>
                </div>

                <div className="text-right flex flex-col items-end gap-1">
                  <span
                    className={`px-2 py-0.5 rounded font-black text-[11px] ${
                      m.type === 'IN'
                        ? 'bg-emerald-100 text-emerald-800'
                        : 'bg-rose-100 text-rose-800'
                    }`}
                  >
                    {m.type === 'IN' ? '🟢 IN' : '🔴 OUT'}
                  </span>
                  {m.day_scholar_after_hours && (
                    <span className="inline-flex items-center gap-1 px-1.5 py-0.2 rounded bg-rose-100 text-rose-800 font-bold text-[9px] border border-rose-300">
                      <AlertTriangle className="h-2.5 w-2.5 text-rose-600" />
                      AFTER HOURS
                    </span>
                  )}
                  <span className="text-[10px] text-slate-400 font-mono">
                    {new Date(m.server_timestamp).toLocaleTimeString([], {
                      hour: '2-digit',
                      minute: '2-digit',
                    })}
                  </span>
                </div>
              </div>
            ))
          )}
        </div>

        <Button variant="outline" onClick={onClose} className="w-full text-xs mt-2">
          Close Table
        </Button>
      </div>
    </Modal>
  );
};
