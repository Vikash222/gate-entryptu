export type UserRole = 'ADMIN' | 'SECURITY' | 'STUDENT';
export type UserStatus = 'ACTIVE' | 'PENDING' | 'REJECTED' | 'SUSPENDED' | 'INACTIVE';
export type StudentAccountStatus = 'ACTIVE' | 'PENDING' | 'REJECTED' | 'SUSPENDED';
export type StudentStatus = 'INSIDE' | 'OUTSIDE';
export type StudentCategory = 'HOSTELER' | 'DAY_SCHOLAR';
export type StudentType = 'HOSTELLER' | 'DAY_SCHOLAR';
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
  category?: StudentCategory;
  student_type?: StudentType | StudentCategory;
  is_day_scholar?: boolean;
  day_scholar_after_hours?: boolean;
  day_scholar_warning?: string | null;
  year?: number;
  email?: string;
  phone_number?: string;
  program?: string;
  department?: string;
  semester?: number;
  batch?: string;
  profile_photo?: string | null;
  profile_photo_url?: string | null;
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
  movement_source?: 'QR' | 'SECURITY_MANUAL';
  is_late?: boolean;
  late_window_date?: string | null;
  day_scholar_after_hours?: boolean;
  DAY_SCHOLAR_AFTER_HOURS?: boolean;
  day_scholar_warning?: string | null;
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

export interface MovementAnalytics {
  summary: {
    total_movements: number;
    in_count: number;
    out_count: number;
    late_count: number;
    normal_count: number;
    late_in_count: number;
    late_out_count: number;
    total_after_hours?: number;
    after_hours_count?: number;
    unique_students: number;
    unique_guards: number;
  };
  gate_stats: Array<{
    gate_id: number;
    gate_name: string;
    gate_code: string;
    total: number;
    late: number;
    in: number;
    out: number;
  }>;
  source_stats: Array<{
    movement_source: string;
    total: number;
    late: number;
  }>;
  daily_trends: Array<{
    date: string;
    total: number;
    in_count: number;
    out_count: number;
    late_count: number;
    normal_count: number;
  }>;
  late_window_trends: Array<{
    late_window_date: string;
    total_late: number;
    late_in: number;
    late_out: number;
    unique_students: number;
  }>;
}

export interface StudentMovementReport {
  student: StudentProfile;
  total_movements: number;
  late_movements_count: number;
  normal_movements_count: number;
  in_count: number;
  out_count: number;
  late_rate_percentage: number;
  movements: Movement[];
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
  session_started_at?: string;
  session_expires_at?: string;
  session_duration_seconds?: number | null;
}

export interface GateVerificationReceipt {
  verification_code: string;
  movement_type: MovementType;
  student_name: string;
  roll_number: string;
  category?: StudentCategory;
  is_day_scholar?: boolean;
  day_scholar_after_hours?: boolean;
  DAY_SCHOLAR_AFTER_HOURS?: boolean;
  day_scholar_warning?: string | null;
  gate_name: string;
  server_timestamp: string;
  destination?: string | null;
  purpose?: string | null;
  vehicle_present?: boolean;
  vehicle_number?: string | null;
  movement_source?: 'QR' | 'SECURITY_MANUAL';
  is_late?: boolean;
  late_window_date?: string | null;
  current_status?: StudentStatus;
  profile_photo_url?: string | null;
}

export interface AppNotification {
  id: string;
  type: string;
  notifiable_id?: number;
  data: {
    type: 'STUDENT_MOVEMENT' | 'SECURITY_DUTY' | 'STUDENT_REGISTRATION' | 'GATE_ACTIVITY_ALERT' | string;
    title: string;
    message: string;
    event?: string;
    movement_id?: number;
    movement_uuid?: string;
    student_name?: string;
    roll_number?: string;
    movement_type?: MovementType;
    gate_name?: string;
    gate_id?: number;
    destination?: string | null;
    purpose?: string | null;
    vehicle_present?: boolean;
    vehicle_number?: string | null;
    verification_code?: string;
    guard_name?: string;
    alert_type?: string;
    server_timestamp: string;
    [key: string]: any;
  };
  read_at: string | null;
  created_at: string;
}

