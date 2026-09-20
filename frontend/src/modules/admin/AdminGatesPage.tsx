import React, { useState, useEffect } from 'react';
import { DoorClosed, Plus, MapPin } from 'lucide-react';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Modal } from '../../components/ui/Modal';
import { Alert } from '../../components/ui/Alert';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse, Gate } from '../../types';

export const AdminGatesPage: React.FC = () => {
  const [gates, setGates] = useState<Gate[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [isModalOpen, setIsModalOpen] = useState(false);
  const [formData, setFormData] = useState({
    name: '',
    code: '',
    location: '',
    description: '',
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [modalError, setModalError] = useState<string | null>(null);

  const fetchGates = async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const res = await apiClient.get<ApiResponse<Gate[]>>('/admin/gates');
      setGates(res.data.data);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchGates();
  }, []);

  const handleCreateGate = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setModalError(null);

    try {
      await apiClient.post('/admin/gates', formData);
      setIsModalOpen(false);
      setFormData({ name: '', code: '', location: '', description: '' });
      fetchGates();
    } catch (err) {
      setModalError(getErrorMessage(err));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="space-y-6 text-left max-w-7xl mx-auto">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-black text-slate-900 tracking-tight">University Gates Configuration</h2>
          <p className="text-xs text-slate-500 mt-1">Database-driven gate management (extensible beyond initial Gate 1 & 2)</p>
        </div>
        <Button onClick={() => setIsModalOpen(true)} className="text-xs font-bold">
          <Plus className="h-4 w-4 mr-1.5" /> Add New Gate
        </Button>
      </div>

      {errorMessage && <Alert type="error" message={errorMessage} />}

      {isLoading && (
        <div className="text-center py-8 text-xs text-slate-400">Loading university gates...</div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        {gates.map((g) => (
          <Card key={g.id} className="p-5 border-slate-200 shadow-sm flex flex-col justify-between">
            <div>
              <div className="flex items-center justify-between">
                <div className="p-2.5 bg-blue-50 text-blue-600 rounded-xl">
                  <DoorClosed className="h-6 w-6" />
                </div>
                <span className="px-2 py-0.5 rounded text-[10px] font-black bg-emerald-100 text-emerald-800 border border-emerald-200">
                  ACTIVE
                </span>
              </div>

              <h4 className="text-lg font-black text-slate-900 mt-3">{g.name}</h4>
              <p className="text-xs font-mono font-bold text-slate-500">Code: {g.code}</p>

              {g.location && (
                <p className="text-xs text-slate-600 mt-2 flex items-center gap-1.5">
                  <MapPin className="h-3.5 w-3.5 text-slate-400" />
                  <span>{g.location}</span>
                </p>
              )}
            </div>

            <div className="mt-4 pt-3 border-t border-slate-100 text-[11px] text-slate-400 font-mono">
              Database ID: #{g.id}
            </div>
          </Card>
        ))}
      </div>

      <Modal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        title="Add University Gate"
        description="Provision a new physical gate without code modifications"
        size="md"
      >
        <form onSubmit={handleCreateGate} className="space-y-4 pt-2 text-left">
          {modalError && <Alert type="error" message={modalError} />}

          <Input
            label="Gate Name"
            required
            placeholder="e.g. South Gate"
            value={formData.name}
            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
          />

          <Input
            label="Gate Code"
            required
            placeholder="e.g. GATE-3"
            value={formData.code}
            onChange={(e) => setFormData({ ...formData, code: e.target.value.toUpperCase() })}
          />

          <Input
            label="Location Description"
            placeholder="e.g. Near Science Block"
            value={formData.location}
            onChange={(e) => setFormData({ ...formData, location: e.target.value })}
          />

          <Button type="submit" size="lg" className="w-full font-bold mt-2" isLoading={isSubmitting}>
            Create Gate
          </Button>
        </form>
      </Modal>
    </div>
  );
};
