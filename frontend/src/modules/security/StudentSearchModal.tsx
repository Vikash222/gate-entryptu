import React, { useState } from 'react';
import { Search, MapPin, Compass, Car, ChevronRight } from 'lucide-react';
import { Modal } from '../../components/ui/Modal';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse, StudentProfile, Movement } from '../../types';

interface StudentSearchModalProps {
  isOpen: boolean;
  onClose: () => void;
}

export const StudentSearchModal: React.FC<StudentSearchModalProps> = ({ isOpen, onClose }) => {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<StudentProfile[]>([]);
  const [selectedStudent, setSelectedStudent] = useState<StudentProfile | null>(null);
  const [studentDetails, setStudentDetails] = useState<any | null>(null);
  const [studentHistory, setStudentHistory] = useState<Movement[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const handleSearch = async (e: React.FormEvent) => {
    e.preventDefault();
    if (query.trim().length < 2) return;

    setIsLoading(true);
    setErrorMessage(null);
    setSelectedStudent(null);
    setStudentDetails(null);

    try {
      const response = await apiClient.get<ApiResponse<StudentProfile[]>>(
        `/security/students/search?query=${encodeURIComponent(query.trim())}`
      );
      setResults(response.data.data);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  const selectStudent = async (student: StudentProfile) => {
    setSelectedStudent(student);
    setIsLoading(true);
    try {
      const [detailRes, histRes] = await Promise.all([
        apiClient.get<ApiResponse<any>>(`/security/students/${student.id}`),
        apiClient.get<ApiResponse<{ movements: Movement[] }>>(`/security/students/${student.id}/history`),
      ]);
      setStudentDetails(detailRes.data.data);
      setStudentHistory(histRes.data.data.movements || []);
    } catch (err) {
      setErrorMessage(getErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={
        <div className="flex items-center gap-2">
          <Search className="h-5 w-5 text-blue-600" />
          <span>Student Gate Lookup</span>
        </div>
      }
      description="Search by Name, Roll Number, Student ID, or Phone Number"
      size="lg"
    >
      <div className="space-y-4 pt-1 text-left">
        {/* Search Bar */}
        <form onSubmit={handleSearch} className="flex gap-2">
          <Input
            placeholder="Search Roll No, Name, Student ID..."
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            className="text-sm"
            autoFocus
          />
          <Button type="submit" isLoading={isLoading} className="text-xs font-bold px-4">
            Search
          </Button>
        </form>

        {errorMessage && <p className="text-xs text-rose-600">{errorMessage}</p>}

        {/* Search Results List */}
        {!selectedStudent && (
          <div className="divide-y divide-slate-100 max-h-[50vh] overflow-y-auto">
            {results.length === 0 && !isLoading ? (
              <div className="py-8 text-center text-slate-400 text-xs font-medium">
                {query.length >= 2 ? 'No matching students found.' : 'Type at least 2 characters to search.'}
              </div>
            ) : (
              results.map((s) => (
                <button
                  key={s.id}
                  type="button"
                  onClick={() => selectStudent(s)}
                  className="w-full py-3 px-2 flex items-center justify-between text-left hover:bg-slate-50 transition rounded-xl"
                >
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center font-bold text-xs">
                      {s.name.charAt(0)}
                    </div>
                    <div>
                      <h5 className="font-bold text-slate-900 text-sm">{s.name}</h5>
                      <p className="text-xs text-slate-500 font-mono">
                        {s.roll_number} &bull; {s.program || 'B.Tech'}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    <span
                      className={`px-2.5 py-1 rounded-full font-black text-[10px] ${
                        s.current_status === 'INSIDE'
                          ? 'bg-emerald-100 text-emerald-800'
                          : 'bg-rose-100 text-rose-800'
                      }`}
                    >
                      {s.current_status === 'INSIDE' ? '🟢 INSIDE' : '🔴 OUTSIDE'}
                    </span>
                    <ChevronRight className="h-4 w-4 text-slate-400" />
                  </div>
                </button>
              ))
            )}
          </div>
        )}

        {/* Selected Student Details View */}
        {selectedStudent && (
          <div className="space-y-4 max-h-[60vh] overflow-y-auto pr-1 animate-fadeIn">
            <button
              type="button"
              onClick={() => setSelectedStudent(null)}
              className="text-xs font-bold text-blue-600 hover:text-blue-800 transition"
            >
              ← Back to Search Results
            </button>

            {/* Student Header Card */}
            <div className="p-4 bg-slate-900 text-white rounded-2xl flex items-center justify-between">
              <div>
                <h4 className="font-extrabold text-base">{selectedStudent.name}</h4>
                <p className="text-xs text-slate-300 font-mono">Roll: {selectedStudent.roll_number}</p>
                <p className="text-[11px] text-slate-400 mt-0.5">
                  {selectedStudent.program} &bull; {selectedStudent.department}
                </p>
              </div>

              <div
                className={`px-3 py-1.5 rounded-xl font-black text-xs ${
                  selectedStudent.current_status === 'INSIDE' ? 'bg-emerald-500' : 'bg-rose-500'
                }`}
              >
                {selectedStudent.current_status === 'INSIDE' ? '🟢 INSIDE' : '🔴 OUTSIDE'}
              </div>
            </div>

            {/* Latest Gate Movement (if OUT, shows destination/purpose/vehicle) */}
            {studentDetails?.latest_movement && (
              <div className="p-3.5 bg-slate-50 rounded-xl border border-slate-200 text-xs space-y-2">
                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Latest Recorded Movement
                </span>
                <div className="flex justify-between items-center">
                  <span className="font-bold text-slate-800">
                    Gate: {studentDetails.latest_movement.gate_name}
                  </span>
                  <span className="font-mono text-slate-500">
                    {studentDetails.latest_movement.display_time}
                  </span>
                </div>

                {studentDetails.latest_movement.type === 'OUT' && (
                  <div className="pt-2 border-t border-slate-200 grid grid-cols-2 gap-2 text-[11px]">
                    <div className="flex items-center gap-1.5">
                      <MapPin className="h-3.5 w-3.5 text-slate-400" />
                      <span>Dest: <strong>{studentDetails.latest_movement.destination}</strong></span>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <Compass className="h-3.5 w-3.5 text-slate-400" />
                      <span>Purpose: <strong>{studentDetails.latest_movement.purpose}</strong></span>
                    </div>
                    {studentDetails.latest_movement.vehicle_present && (
                      <div className="col-span-2 flex items-center gap-1.5 font-mono text-blue-600 font-bold">
                        <Car className="h-3.5 w-3.5 text-blue-500" />
                        <span>Vehicle: {studentDetails.latest_movement.vehicle_number}</span>
                      </div>
                    )}
                  </div>
                )}
              </div>
            )}

            {/* 15-Day Movement History for this student */}
            <div>
              <span className="text-xs font-bold text-slate-600 block mb-2">
                Previous 15 Days History (Max 15 Days Access)
              </span>
              <div className="divide-y divide-slate-100 max-h-48 overflow-y-auto border border-slate-100 rounded-xl bg-white p-2">
                {studentHistory.length === 0 ? (
                  <div className="py-4 text-center text-xs text-slate-400">
                    No movements recorded in the last 15 days.
                  </div>
                ) : (
                  studentHistory.map((m) => (
                    <div key={m.id} className="py-2 flex items-center justify-between text-xs">
                      <div>
                        <span className="font-semibold text-slate-800">
                          {new Date(m.server_timestamp).toLocaleDateString([], { month: 'short', day: 'numeric' })}
                        </span>
                        <span className="text-[11px] text-slate-500 ml-2 font-mono">
                          {new Date(m.server_timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                        </span>
                      </div>
                      <span
                        className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                          m.type === 'IN' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                        }`}
                      >
                        {m.type === 'IN' ? 'IN' : 'OUT'}
                      </span>
                    </div>
                  ))
                )}
              </div>
            </div>
          </div>
        )}
      </div>
    </Modal>
  );
};
