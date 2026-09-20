import React, { useState, useEffect } from 'react';
import { Calendar, RefreshCw } from 'lucide-react';
import { Modal } from '../../components/ui/Modal';
import { Button } from '../../components/ui/Button';
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
          <Calendar className="h-5 w-5 text-blue-600" />
          <span>Today's Movements</span>
        </div>
      }
      description="Newest gate activity recorded today across university gates"
      size="lg"
    >
      <div className="space-y-4 pt-1 text-left">
        <div className="flex justify-between items-center">
          <span className="text-xs font-bold text-slate-500">{movements.length} Total Today</span>
          <button
            type="button"
            onClick={fetchToday}
            disabled={isLoading}
            className="p-1.5 text-slate-500 hover:text-blue-600 rounded-lg hover:bg-slate-100 transition"
            title="Refresh"
          >
            <RefreshCw className={`h-4 w-4 ${isLoading ? 'animate-spin text-blue-600' : ''}`} />
          </button>
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
                <div>
                  <h5 className="font-bold text-slate-900">{m.student?.name || 'Student'}</h5>
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
