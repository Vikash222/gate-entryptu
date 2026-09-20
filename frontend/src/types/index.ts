export type UserRole = 'ADMIN' | 'SECURITY' | 'STUDENT';
export type UserStatus = 'ACTIVE' | 'PENDING' | 'REJECTED' | 'SUSPENDED' | 'INACTIVE';
export type StudentAccountStatus = 'ACTIVE' | 'PENDING' | 'REJECTED' | 'SUSPENDED';
export type StudentStatus = 'INSIDE' | 'OUTSIDE';
export type MovementType = 'IN' | 'OUT';
export type DutySessionStatus = 'ACTIVE' | 'ENDED';

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  status: UserStatus;
  totp_enabled: boolean;
  student?: StudentProfile | null;
}

export interface StudentProfile {
  id: number;
  student_id: string;
  roll_number: string;
  name: string;
  year?: number;
  email?: string;
  phone_number?: string;
  program?: string;
  department?: string;
  semester?: number;
  batch?: string;
  profile_photo?: string | null;
  status?: StudentAccountStatus;
  current_status: StudentStatus;
  last_movement_at?: string | null;
}

export interface Gate {
  id: number;
  name: string;
  code: string;
  status: 'ACTIVE' | 'INACTIVE';
  location?: string | null;
  description?: string | null;
}

export interface SecurityDutySession {
  id: number;
  user_id: number;
  gate_id: number;
  started_at: string;
  ended_at?: string | null;
  status: DutySessionStatus;
  device_identifier?: string | null;
  gate?: Gate;
  user?: {
    id: number;
    name: string;
    email: string;
  };
}

export interface Movement {
  id: number;
  movement_uuid: string;
  verification_code: string;
  student_id: number;
  gate_id: number;
  security_user_id?: number | null;
  type: MovementType;
  vehicle_present: boolean;
  vehicle_number?: string | null;
  destination?: string | null;
  destination_other?: string | null;
  purpose?: string | null;
  purpose_other?: string | null;
  server_timestamp: string;
  created_at: string;
  student?: StudentProfile;
  gate?: Gate;
  security_user?: {
    id: number;
    name: string;
  } | null;
}

export interface MovementOption {
  id: number;
  type: 'DESTINATION' | 'PURPOSE';
  name: string;
  sort_order: number;
  is_active: boolean;
}

export interface AuditLog {
  id: number;
  user_id?: number | null;
  action: string;
  module: string;
  entity_type?: string | null;
  entity_id?: string | null;
  ip_address?: string | null;
  user_agent?: string | null;
  status: 'SUCCESS' | 'FAILED' | 'WARNING';
  metadata?: Record<string, any> | null;
  server_timestamp: string;
  created_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
    role: UserRole;
  } | null;
}

export interface ApiResponse<T = any> {
  success: boolean;
  message: string;
  data: T;
  errors?: Record<string, string[]>;
}

export interface LoginResponseData {
  requires_2fa: boolean;
  token?: string;
  challenge_token?: string;
  user?: User;
}

export interface GateVerificationReceipt {
  verification_code: string;
  movement_type: MovementType;
  student_name: string;
  roll_number: string;
  gate_name: string;
  server_timestamp: string;
  destination?: string | null;
  purpose?: string | null;
  vehicle_present?: boolean;
  vehicle_number?: string | null;
}
