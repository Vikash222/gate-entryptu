import React, { useState, useEffect } from 'react';
import { History, Search } from 'lucide-react';
import { Modal } from '../../components/ui/Modal';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse, Movement } from '../../types';

interface SecurityHistoryModalProps {
  isOpen: boolean;
  onClose: () => void;
}

export const SecurityHistoryModal: React.FC<SecurityHistoryModalProps> = ({ isOpen, onClose }) => {
  const [movements, setMovements] = useState<Movement[]>([]);
  const [dateFilter, setDateFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState<'ALL' | 'IN' | 'OUT'>('ALL');
  const [search, setSearch] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Calculate 15 days ago boundary in YYYY-MM-DD for html5 date input min attribute
  const minDate = new Date(Date.now() - 15 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
  const maxDate = new Date().toISOString().split('T')[0];

  const fetchHistory = async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const params = new URLSearchParams();
      if (dateFilter) params.append('date', dateFilter);
      if (typeFilter !== 'ALL') params.append('type', typeFilter);
      if (search.trim()) params.append('search', search.trim());

      const response = await apiClient.get<ApiResponse<{ data: Movement[] }>>(
        `/security/history?${params.toString()}`
      );
      setMovements(response.data.data.data || []);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    if (isOpen) {
      fetchHistory();
    }
  }, [isOpen, dateFilter, typeFilter]);

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={
        <div className="flex items-center gap-2">
          <History className="h-5 w-5 text-blue-600" />
          <span>15-Day Movement History</span>
        </div>
      }
      description="Access strictly restricted to previous 15 days of gate records"
      size="lg"
    >
      <div className="space-y-4 pt-1 text-left">
        {/* Filters */}
        <div className="grid grid-cols-2 gap-2 text-xs">
          <div>
            <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">Date</label>
            <input
              type="date"
              min={minDate}
              max={maxDate}
              value={dateFilter}
              onChange={(e) => setDateFilter(e.target.value)}
              className="w-full p-2 border border-slate-200 rounded-xl bg-white text-xs"
            />
          </div>

          <div>
            <label className="block text-[10px] font-bold text-slate-500 uppercase mb-1">Movement Type</label>
            <div className="grid grid-cols-3 gap-1">
              {(['ALL', 'IN', 'OUT'] as const).map((t) => (
                <button
                  key={t}
                  type="button"
                  onClick={() => setTypeFilter(t)}
                  className={`py-2 rounded-lg font-bold text-xs transition ${
                    typeFilter === t ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                  }`}
                >
                  {t}
                </button>
              ))}
            </div>
          </div>
        </div>

        {/* Search */}
        <div className="flex gap-2">
          <Input
            placeholder="Search student roll no or name..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            leftIcon={<Search className="h-4 w-4" />}
            className="text-xs py-2"
          />
          <Button onClick={fetchHistory} className="text-xs px-3 font-bold" isLoading={isLoading}>
            Filter
          </Button>
        </div>

        {errorMessage && <p className="text-xs text-rose-600">{errorMessage}</p>}

        {/* Records list */}
        <div className="divide-y divide-slate-100 max-h-[50vh] overflow-y-auto">
          {movements.length === 0 && !isLoading ? (
            <div className="py-8 text-center text-slate-400 text-xs font-medium">
              No movement records found within the 15-day window.
            </div>
          ) : (
            movements.map((m) => (
              <div key={m.id} className="py-2.5 flex items-center justify-between text-xs">
                <div>
                  <h5 className="font-bold text-slate-900">{m.student?.name}</h5>
                  <p className="text-[11px] text-slate-500 font-mono">
                    {m.student?.roll_number} &bull; {m.gate?.name}
                  </p>
                  <p className="text-[10px] text-slate-400">
                    {new Date(m.server_timestamp).toLocaleDateString([], {
                      month: 'short',
                      day: 'numeric',
                      year: 'numeric',
                    })}{' '}
                    at{' '}
                    {new Date(m.server_timestamp).toLocaleTimeString([], {
                      hour: '2-digit',
                      minute: '2-digit',
                    })}
                  </p>
                </div>

                <div className="text-right">
                  <span
                    className={`px-2 py-0.5 rounded font-black text-[10px] ${
                      m.type === 'IN' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                    }`}
                  >
                    {m.type === 'IN' ? '🟢 IN' : '🔴 OUT'}
                  </span>
                  {m.destination && (
                    <p className="text-[10px] text-slate-500 mt-1 max-w-[120px] truncate">{m.destination}</p>
                  )}
                </div>
              </div>
            ))
          )}
        </div>

        <Button variant="outline" onClick={onClose} className="w-full text-xs">
          Close History
        </Button>
      </div>
    </Modal>
  );
};
