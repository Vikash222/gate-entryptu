import React, { useState, useEffect, useRef } from 'react';
import {
  UserCheck,
  Search,
  LogIn,
  LogOut,
  Car,
  MapPin,
  Briefcase,
  AlertTriangle,
  CheckCircle2,
  X,
  ArrowRight,
  RotateCcw,
} from 'lucide-react';
import { Modal } from '../../components/ui/Modal';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Avatar } from '../../components/ui/Avatar';
import { apiClient, getErrorMessage } from '../../api/client';
import type { ApiResponse, StudentProfile, GateVerificationReceipt } from '../../types';

interface ManualEntryModalProps {
  isOpen: boolean;
  onClose: () => void;
  assignedGateName?: string;
}

const DESTINATION_OPTIONS = ['Jalandhar', 'Kapurthala', 'Kheere Shop', 'Home', 'Other'];
const PURPOSE_OPTIONS = ['Personal', 'Food', 'Shopping', 'Academic', 'Medical', 'Home Visit', 'Other'];

export const ManualEntryModal: React.FC<ManualEntryModalProps> = ({
  isOpen,
  onClose,
  assignedGateName = 'Assigned Gate',
}) => {
  // Search state
  const [searchQuery, setSearchQuery] = useState('');
  const [isSearching, setIsSearching] = useState(false);
  const [searchError, setSearchError] = useState<string | null>(null);
  const [searchResults, setSearchResults] = useState<StudentProfile[]>([]);
  const [selectedStudent, setSelectedStudent] = useState<StudentProfile | null>(null);

  // Form state
  const [movementType, setMovementType] = useState<'IN' | 'OUT'>('OUT');
  const [vehiclePresent, setVehiclePresent] = useState(false);
  const [vehicleNumber, setVehicleNumber] = useState('');
  const [destination, setDestination] = useState('Jalandhar');
  const [destinationOther, setDestinationOther] = useState('');
  const [purpose, setPurpose] = useState('Personal');
  const [purposeOther, setPurposeOther] = useState('');

  // Submission state
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [receipt, setReceipt] = useState<GateVerificationReceipt | null>(null);

  const searchInputRef = useRef<HTMLInputElement>(null);
  const searchAbortControllerRef = useRef<AbortController | null>(null);

  useEffect(() => {
    return () => {
      searchAbortControllerRef.current?.abort();
    };
  }, []);

  // Focus search input when modal opens or resets
  useEffect(() => {
    if (isOpen && !selectedStudent && !receipt) {
      setTimeout(() => {
        searchInputRef.current?.focus();
      }, 100);
    }
  }, [isOpen, selectedStudent, receipt]);

  // Reset form when student is selected
  const handleSelectStudent = (student: StudentProfile) => {
    setSelectedStudent(student);
    setFormError(null);
    setReceipt(null);
    // Auto-select valid movement type based on current status
    if (student.current_status === 'INSIDE') {
      setMovementType('OUT');
    } else {
      setMovementType('IN');
    }
    setVehiclePresent(false);
    setVehicleNumber('');
    setDestination('Jalandhar');
    setDestinationOther('');
    setPurpose('Personal');
    setPurposeOther('');
  };

  // Perform search
  const handleSearch = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    const q = searchQuery.trim();
    if (!q) {
      setSearchError('Please enter a Roll Number or Name.');
      return;
    }

    if (searchAbortControllerRef.current) {
      searchAbortControllerRef.current.abort();
    }
    const controller = new AbortController();
    searchAbortControllerRef.current = controller;

    setIsSearching(true);
    setSearchError(null);
    setSelectedStudent(null);
    setReceipt(null);

    try {
      const response = await apiClient.get<ApiResponse<StudentProfile[]>>(
        `/security/students/search?roll_number=${encodeURIComponent(q)}`,
        { signal: controller.signal }
      );
      const results = response.data?.data || [];
      setSearchResults(results);

      if (results.length === 0) {
        setSearchError('No student found matching "' + q + '". Please verify the Roll Number.');
      } else if (results.length === 1) {
        // Direct exact match — immediately select student
        handleSelectStudent(results[0]);
      }
    } catch (err: any) {
      if (err?.name === 'CanceledError' || err?.code === 'ERR_CANCELED') {
        return;
      }
      setSearchError(getErrorMessage(err));
    } finally {
      if (searchAbortControllerRef.current === controller) {
        setIsSearching(false);
      }
    }
  };

  // Submit manual movement
  const handleSubmitMovement = async () => {
    if (!selectedStudent) return;

    // Frontend validations
    if (movementType === 'OUT') {
      if (!destination || (destination === 'Other' && !destinationOther.trim())) {
        setFormError('Please select or specify a destination.');
        return;
      }
      if (!purpose || (purpose === 'Other' && !purposeOther.trim())) {
        setFormError('Please select or specify a purpose of visit.');
        return;
      }
      if (vehiclePresent && !vehicleNumber.trim()) {
        setFormError('Vehicle registration number is required when vehicle is YES.');
        return;
      }
    }

    setIsSubmitting(true);
    setFormError(null);

    // Client-side generated idempotency key
    const clientRequestId = `sec_man_${selectedStudent.id}_${Date.now()}_${Math.random().toString(36).substring(2, 8)}`;

    try {
      const payload: any = {
        student_id: selectedStudent.id,
        type: movementType,
        client_request_id: clientRequestId,
      };

      if (movementType === 'OUT') {
        payload.vehicle_present = vehiclePresent;
        payload.vehicle_number = vehiclePresent ? vehicleNumber.trim() : null;
        payload.destination = destination === 'Other' ? destinationOther.trim() : destination;
        payload.destination_other = destination === 'Other' ? destinationOther.trim() : null;
        payload.purpose = purpose === 'Other' ? purposeOther.trim() : purpose;
        payload.purpose_other = purpose === 'Other' ? purposeOther.trim() : null;
      }

      const response = await apiClient.post<ApiResponse<GateVerificationReceipt>>(
        '/security/manual-movement',
        payload
      );

      const data = response.data.data;
      setReceipt(data);

      // Trigger global event so live movements update immediately on SecurityDashboard
      window.dispatchEvent(new CustomEvent('smartgate:movement-recorded'));
      if ('vibrate' in navigator) {
        navigator.vibrate([60, 30, 60]);
      }
    } catch (err) {
      setFormError(getErrorMessage(err));
    } finally {
      setIsSubmitting(false);
    }
  };

  // Reset workflow for next student
  const handleResetForNextStudent = () => {
    setSearchQuery('');
    setSearchResults([]);
    setSelectedStudent(null);
    setReceipt(null);
    setFormError(null);
    setSearchError(null);
    setTimeout(() => {
      searchInputRef.current?.focus();
    }, 50);
  };

  const handleClose = () => {
    handleResetForNextStudent();
    onClose();
  };

  const formatReceiptTime = (isoString?: string) => {
    if (!isoString) return '';
    try {
      const d = new Date(isoString);
      return (
        d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) +
        ' · ' +
        d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
      );
    } catch {
      return isoString;
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={handleClose}
      title={
        <div className="flex items-center gap-2">
          <div className="p-1.5 bg-blue-600 rounded-xl text-white">
            <UserCheck className="h-5 w-5" />
          </div>
          <div>
            <h3 className="text-base font-extrabold text-slate-900 leading-tight">Security-Assisted Manual Entry</h3>
            <span className="text-[10px] font-bold text-blue-600 uppercase tracking-wider block">
              Gate: {assignedGateName}
            </span>
          </div>
        </div>
      }
      description="Record student entry or exit without student mobile QR scan."
      size="md"
    >
      <div className="space-y-4 pt-1 text-left">
        {/* =========================================================================
            RECEIPT VIEW: After Movement Successfully Recorded
           ========================================================================= */}
        {receipt ? (
          <div className="space-y-4 animate-in fade-in zoom-in-95 duration-200">
            <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl text-center space-y-2.5 shadow-sm">
              <div className="flex justify-center">
                <Avatar
                  src={receipt.profile_photo_url}
                  name={receipt.student_name}
                  size="xl"
                  shape="rounded"
                  className="ring-4 ring-emerald-400/60 shadow-lg"
                />
              </div>
              <div>
                <span className="text-[10px] font-black uppercase tracking-widest text-emerald-700 block">
                  Movement Recorded Successfully
                </span>
                <h4 className="text-lg font-black text-slate-900 tracking-tight">
                  {receipt.student_name}
                </h4>
                <div className="flex items-center justify-center gap-2 pt-0.5">
                  <span className="text-xs font-mono font-bold text-slate-600">
                    Roll: {receipt.roll_number}
                  </span>
                  {receipt.category && (
                    <span className="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase bg-slate-200 text-slate-700">
                      {receipt.category === 'DAY_SCHOLAR' ? 'Day Scholar' : 'Hosteler'}
                    </span>
                  )}
                </div>
              </div>

              {/* Prominent After-Hours Warning Banner in Receipt */}
              {receipt.day_scholar_after_hours && (
                <div className="py-1 px-3 bg-rose-600 text-white rounded-xl text-xs font-black uppercase tracking-wider inline-flex items-center gap-1.5 shadow-sm mx-auto">
                  <AlertTriangle className="h-3.5 w-3.5 text-amber-300" />
                  <span>DAY SCHOLAR — AFTER HOURS</span>
                </div>
              )}

              <div className="flex items-center justify-center gap-2 pt-1">
                <span
                  className={`px-3 py-1 rounded-xl text-xs font-black uppercase tracking-wider ${
                    receipt.movement_type === 'IN'
                      ? 'bg-emerald-600 text-white'
                      : 'bg-rose-600 text-white'
                  }`}
                >
                  {receipt.movement_type === 'IN' ? '↓ ENTRY (IN)' : '↑ EXIT (OUT)'}
                </span>
                <span className="px-2.5 py-1 rounded-xl bg-blue-100 text-blue-800 text-[10px] font-black uppercase">
                  Manual Entry
                </span>
                {receipt.is_late && (
                  <span className="px-2 py-1 rounded-xl bg-amber-500 text-white text-[10px] font-black uppercase">
                    Late Entry
                  </span>
                )}
              </div>
            </div>

            {/* Receipt Key-Value Details */}
            <div className="bg-slate-50 rounded-2xl border border-slate-200/80 p-3.5 space-y-2 text-xs">
              <div className="flex justify-between py-1 border-b border-slate-200/60">
                <span className="text-slate-500 font-medium">Verification Code</span>
                <span className="font-mono font-black text-slate-900 tracking-wider">
                  {receipt.verification_code}
                </span>
              </div>

              <div className="flex justify-between py-1 border-b border-slate-200/60">
                <span className="text-slate-500 font-medium">Assigned Gate</span>
                <span className="font-bold text-slate-900">{receipt.gate_name}</span>
              </div>

              <div className="flex justify-between py-1 border-b border-slate-200/60">
                <span className="text-slate-500 font-medium">Server Timestamp</span>
                <span className="font-mono text-slate-800 font-semibold">
                  {formatReceiptTime(receipt.server_timestamp)}
                </span>
              </div>

              {receipt.movement_type === 'OUT' && (
                <>
                  {receipt.destination && (
                    <div className="flex justify-between py-1 border-b border-slate-200/60">
                      <span className="text-slate-500 font-medium">Destination</span>
                      <span className="font-bold text-slate-900">{receipt.destination}</span>
                    </div>
                  )}
                  {receipt.purpose && (
                    <div className="flex justify-between py-1 border-b border-slate-200/60">
                      <span className="text-slate-500 font-medium">Purpose</span>
                      <span className="text-slate-900 font-medium">{receipt.purpose}</span>
                    </div>
                  )}
                  {receipt.vehicle_present && receipt.vehicle_number && (
                    <div className="flex justify-between py-1">
                      <span className="text-slate-500 font-medium">Vehicle Reg No.</span>
                      <span className="font-mono font-bold text-blue-700">
                        {receipt.vehicle_number}
                      </span>
                    </div>
                  )}
                </>
              )}
            </div>

            {/* Receipt Action Buttons */}
            <div className="flex gap-2 pt-1">
              <Button
                variant="primary"
                size="lg"
                onClick={handleResetForNextStudent}
                className="flex-1 font-bold shadow-md shadow-blue-600/20 text-xs flex items-center justify-center gap-1.5"
              >
                <RotateCcw className="h-4 w-4" />
                <span>Next Student</span>
              </Button>
              <Button
                variant="outline"
                size="lg"
                onClick={handleClose}
                className="px-5 text-xs font-bold text-slate-600"
              >
                Close
              </Button>
            </div>
          </div>
        ) : (
          /* =========================================================================
              SEARCH & ENTRY WORKFLOW
             ========================================================================= */
          <div className="space-y-4">
            {/* Step 1: Roll Number Search Bar */}
            {!selectedStudent && (
              <form onSubmit={handleSearch} className="space-y-2">
                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                  Enter Student Roll Number or Name
                </label>
                <div className="flex gap-2">
                  <Input
                    ref={searchInputRef}
                    placeholder="e.g. 23CSE101 or Rahul"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="text-base font-semibold tracking-wide"
                    autoComplete="off"
                  />
                  <Button
                    type="submit"
                    variant="primary"
                    isLoading={isSearching}
                    className="font-bold px-4 text-xs"
                  >
                    <Search className="h-4 w-4" />
                    <span>Search</span>
                  </Button>
                </div>

                {searchError && (
                  <p className="text-xs font-medium text-rose-600 pt-1 flex items-center gap-1">
                    <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                    <span>{searchError}</span>
                  </p>
                )}

                {/* Multiple search results dropdown if query was fuzzy */}
                {searchResults.length > 1 && !selectedStudent && (
                  <div className="mt-3 border border-slate-200 rounded-xl overflow-hidden divide-y divide-slate-100 max-h-56 overflow-y-auto bg-white shadow-sm">
                    <div className="px-3 py-1.5 bg-slate-100 text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                      Select matching student ({searchResults.length} found)
                    </div>
                    {searchResults.map((s) => (
                      <button
                        key={s.id}
                        type="button"
                        onClick={() => handleSelectStudent(s)}
                        className="w-full p-2.5 text-left flex items-center justify-between hover:bg-blue-50/70 transition"
                      >
                        <div className="flex items-center gap-2.5">
                          <Avatar
                            src={s.profile_photo_url}
                            name={s.name}
                            size="sm"
                            shape="rounded"
                          />
                          <div>
                            <div className="font-bold text-slate-900 text-xs flex items-center gap-1.5">
                              <span>{s.name}</span>
                              <span className="text-[9px] px-1.5 py-0.2 rounded font-bold uppercase bg-slate-100 text-slate-600">
                                {s.category === 'DAY_SCHOLAR' ? 'Day Scholar' : 'Hosteler'}
                              </span>
                            </div>
                            <div className="text-[11px] font-mono text-slate-500">
                              {s.roll_number} &bull; {s.program || 'Student'}
                            </div>
                          </div>
                        </div>
                        <span
                          className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                            s.current_status === 'INSIDE'
                              ? 'bg-emerald-100 text-emerald-700'
                              : 'bg-rose-100 text-rose-700'
                          }`}
                        >
                          {s.current_status}
                        </span>
                      </button>
                    ))}
                  </div>
                )}
              </form>
            )}

            {/* Step 2: Student Confirmation Card */}
            {selectedStudent && (
              <div className="space-y-4">
                <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl relative shadow-inner">
                  <button
                    type="button"
                    onClick={() => {
                      setSelectedStudent(null);
                      setFormError(null);
                    }}
                    className="absolute top-3 right-3 text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-200/60 transition z-10"
                    title="Change student"
                  >
                    <X className="h-4 w-4" />
                  </button>

                  <div className="flex items-center gap-3.5">
                    <Avatar
                      src={selectedStudent.profile_photo_url}
                      name={selectedStudent.name}
                      size="xl"
                      shape="rounded"
                      className="border-2 border-white shadow-md shrink-0 ring-2 ring-slate-200"
                    />
                    <div className="min-w-0 pr-6 space-y-0.5">
                      <div className="flex items-center gap-2 flex-wrap">
                        <h4 className="font-extrabold text-slate-900 text-base leading-tight truncate">
                          {selectedStudent.name}
                        </h4>
                        <span
                          className={`px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider ${
                            selectedStudent.category === 'DAY_SCHOLAR'
                              ? 'bg-amber-100 text-amber-800 border border-amber-200'
                              : 'bg-indigo-100 text-indigo-800 border border-indigo-200'
                          }`}
                        >
                          {selectedStudent.category === 'DAY_SCHOLAR' ? 'Day Scholar' : 'Hosteler'}
                        </span>
                      </div>
                      <p className="text-xs font-mono font-bold text-slate-600">
                        Roll: {selectedStudent.roll_number}
                      </p>
                      <p className="text-[11px] text-slate-500 truncate">
                        {selectedStudent.program || 'Student'} {selectedStudent.year ? `(Yr ${selectedStudent.year})` : ''}
                      </p>
                    </div>
                  </div>

                  {/* PROMINENT RED AFTER-HOURS WARNING */}
                  {selectedStudent.day_scholar_after_hours && (
                    <div className="mt-3 p-2.5 bg-rose-600 text-white rounded-xl flex items-center justify-between shadow-md shadow-rose-600/20">
                      <div className="flex items-center gap-2">
                        <AlertTriangle className="h-5 w-5 text-amber-300 shrink-0" />
                        <div>
                          <span className="text-xs font-black uppercase tracking-wider block">
                            DAY SCHOLAR — AFTER HOURS
                          </span>
                          <span className="text-[10px] text-rose-100 font-medium">
                            Movement is fully permitted. Warning recorded authoritatively.
                          </span>
                        </div>
                      </div>
                      <span className="px-2 py-0.5 bg-white/20 rounded text-[10px] font-black uppercase tracking-wider text-emerald-200">
                        ALLOWED
                      </span>
                    </div>
                  )}

                  {/* Current Status Badge Banner */}
                  <div className="mt-3 pt-2.5 border-t border-slate-200/80 flex items-center justify-between">
                    <span className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                      Current Gate State:
                    </span>
                    <span
                      className={`px-2.5 py-1 rounded-xl text-xs font-black uppercase tracking-wider flex items-center gap-1 ${
                        selectedStudent.current_status === 'INSIDE'
                          ? 'bg-emerald-100 text-emerald-800 border border-emerald-300/60'
                          : 'bg-rose-100 text-rose-800 border border-rose-300/60'
                      }`}
                    >
                      <span
                        className={`h-2 w-2 rounded-full ${
                          selectedStudent.current_status === 'INSIDE' ? 'bg-emerald-600' : 'bg-rose-600'
                        }`}
                      />
                      <span>{selectedStudent.current_status}</span>
                    </span>
                  </div>
                </div>

                {/* Account Status Lock: If not ACTIVE, reject movement */}
                {selectedStudent.status && selectedStudent.status !== 'ACTIVE' ? (
                  <div className="p-3.5 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-800 space-y-1">
                    <div className="font-bold flex items-center gap-1.5 text-rose-900">
                      <AlertTriangle className="h-4 w-4 text-rose-600 shrink-0" />
                      <span>Gate Movement Prohibited</span>
                    </div>
                    <p className="leading-relaxed">
                      Student account is currently <strong>{selectedStudent.status}</strong>. Gate entry or exit cannot be recorded until approved and active.
                    </p>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setSelectedStudent(null)}
                      className="mt-2 text-xs w-full"
                    >
                      Search Another Student
                    </Button>
                  </div>
                ) : (
                  /* Movement Configuration Form */
                  <div className="space-y-4">
                    {/* IN vs OUT Selection Tabs */}
                    <div>
                      <label className="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                        Movement Action <span className="text-rose-500">*</span>
                      </label>
                      <div className="grid grid-cols-2 gap-2">
                        {/* IN Button */}
                        <button
                          type="button"
                          onClick={() => setMovementType('IN')}
                          disabled={selectedStudent.current_status === 'INSIDE'}
                          className={`p-3 rounded-xl border flex flex-col items-center gap-1 font-bold text-xs transition ${
                            movementType === 'IN'
                              ? 'bg-emerald-600 text-white border-emerald-600 shadow-md shadow-emerald-600/20'
                              : selectedStudent.current_status === 'INSIDE'
                              ? 'bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed opacity-60'
                              : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                          }`}
                        >
                          <LogIn className="h-5 w-5" />
                          <span>ENTER (IN)</span>
                          {selectedStudent.current_status === 'INSIDE' && (
                            <span className="text-[9px] font-normal text-slate-400">(Already INSIDE)</span>
                          )}
                        </button>

                        {/* OUT Button */}
                        <button
                          type="button"
                          onClick={() => setMovementType('OUT')}
                          disabled={selectedStudent.current_status === 'OUTSIDE'}
                          className={`p-3 rounded-xl border flex flex-col items-center gap-1 font-bold text-xs transition ${
                            movementType === 'OUT'
                              ? 'bg-rose-600 text-white border-rose-600 shadow-md shadow-rose-600/20'
                              : selectedStudent.current_status === 'OUTSIDE'
                              ? 'bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed opacity-60'
                              : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                          }`}
                        >
                          <LogOut className="h-5 w-5" />
                          <span>EXIT (OUT)</span>
                          {selectedStudent.current_status === 'OUTSIDE' && (
                            <span className="text-[9px] font-normal text-slate-400">(Already OUTSIDE)</span>
                          )}
                        </button>
                      </div>
                    </div>

                    {/* OUT Movement Form (Vehicle, Destination, Purpose) */}
                    {movementType === 'OUT' ? (
                      <div className="space-y-3.5 bg-slate-50/70 p-3.5 rounded-2xl border border-slate-200/80">
                        {/* A. Vehicle Toggle */}
                        <div>
                          <label className="block text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            Traveling in a Vehicle?
                          </label>
                          <div className="grid grid-cols-2 gap-2">
                            <button
                              type="button"
                              onClick={() => setVehiclePresent(false)}
                              className={`py-2 px-3 rounded-xl text-xs font-bold transition border ${
                                !vehiclePresent
                                  ? 'bg-slate-900 text-white border-slate-900 shadow-sm'
                                  : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-100'
                              }`}
                            >
                              NO (On Foot)
                            </button>
                            <button
                              type="button"
                              onClick={() => setVehiclePresent(true)}
                              className={`py-2 px-3 rounded-xl text-xs font-bold transition border flex items-center justify-center gap-1.5 ${
                                vehiclePresent
                                  ? 'bg-blue-600 text-white border-blue-600 shadow-sm'
                                  : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-100'
                              }`}
                            >
                              <Car className="h-3.5 w-3.5" />
                              <span>YES (In Vehicle)</span>
                            </button>
                          </div>
                        </div>

                        {/* Vehicle Registration Number (Required if YES) */}
                        {vehiclePresent && (
                          <div className="animate-in fade-in duration-150">
                            <label className="block text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-1">
                              Vehicle Registration Number <span className="text-rose-500">*</span>
                            </label>
                            <Input
                              placeholder="e.g. PB08AB1234"
                              value={vehicleNumber}
                              onChange={(e) => setVehicleNumber(e.target.value.toUpperCase())}
                              className="font-mono font-bold text-sm tracking-widest"
                            />
                          </div>
                        )}

                        {/* B. Destination Quick Pills */}
                        <div>
                          <label className="block text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-1.5 flex items-center gap-1">
                            <MapPin className="h-3.5 w-3.5 text-rose-500" />
                            <span>Destination <span className="text-rose-500">*</span></span>
                          </label>
                          <div className="flex flex-wrap gap-1.5">
                            {DESTINATION_OPTIONS.map((dest) => (
                              <button
                                key={dest}
                                type="button"
                                onClick={() => setDestination(dest)}
                                className={`px-2.5 py-1.5 rounded-xl text-xs font-bold transition border ${
                                  destination === dest
                                    ? 'bg-rose-600 text-white border-rose-600 shadow-sm'
                                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-100'
                                }`}
                              >
                                {dest}
                              </button>
                            ))}
                          </div>

                          {destination === 'Other' && (
                            <div className="mt-2 animate-in fade-in duration-150">
                              <Input
                                placeholder="Specify custom destination..."
                                value={destinationOther}
                                onChange={(e) => setDestinationOther(e.target.value)}
                                className="text-xs"
                              />
                            </div>
                          )}
                        </div>

                        {/* C. Purpose Quick Pills */}
                        <div>
                          <label className="block text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-1.5 flex items-center gap-1">
                            <Briefcase className="h-3.5 w-3.5 text-slate-500" />
                            <span>Purpose of Visit <span className="text-rose-500">*</span></span>
                          </label>
                          <div className="flex flex-wrap gap-1.5">
                            {PURPOSE_OPTIONS.map((purp) => (
                              <button
                                key={purp}
                                type="button"
                                onClick={() => setPurpose(purp)}
                                className={`px-2.5 py-1.5 rounded-xl text-xs font-bold transition border ${
                                  purpose === purp
                                    ? 'bg-blue-600 text-white border-blue-600 shadow-sm'
                                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-100'
                                }`}
                              >
                                {purp}
                              </button>
                            ))}
                          </div>

                          {purpose === 'Other' && (
                            <div className="mt-2 animate-in fade-in duration-150">
                              <Input
                                placeholder="Specify custom purpose..."
                                value={purposeOther}
                                onChange={(e) => setPurposeOther(e.target.value)}
                                className="text-xs"
                              />
                            </div>
                          )}
                        </div>
                      </div>
                    ) : (
                      /* IN Movement Summary (Speed-optimized, no extra fields) */
                      <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-2xl text-xs text-emerald-900 space-y-1">
                        <div className="font-bold flex items-center gap-1 text-emerald-800">
                          <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0" />
                          <span>Fast Student IN Movement</span>
                        </div>
                        <p className="text-[11px] text-emerald-800 leading-relaxed">
                          Student {selectedStudent.name} ({selectedStudent.roll_number}) will be checked IN at {assignedGateName}. Authoritative server timestamp will be recorded.
                        </p>
                      </div>
                    )}

                    {/* Error message */}
                    {formError && (
                      <div className="p-2.5 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700 font-medium flex items-center gap-1.5">
                        <AlertTriangle className="h-4 w-4 shrink-0 text-rose-600" />
                        <span>{formError}</span>
                      </div>
                    )}

                    {/* Confirmation Button */}
                    <div className="pt-1">
                      <Button
                        type="button"
                        variant={movementType === 'IN' ? 'primary' : 'danger'}
                        size="xl"
                        isLoading={isSubmitting}
                        disabled={isSubmitting}
                        onClick={handleSubmitMovement}
                        className="w-full font-black text-sm uppercase tracking-wider shadow-lg flex items-center justify-center gap-2"
                      >
                        <span>
                          {movementType === 'IN' ? 'Confirm IN (Enter Campus)' : 'Confirm OUT (Exit Campus)'}
                        </span>
                        <ArrowRight className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
};
