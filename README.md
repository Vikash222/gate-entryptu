# SmartGate — University Gate Entry & Exit Management System

Backend: Laravel 10
PHP: 8.2
Database: MySQL / MariaDB
Frontend: React + Vite
Mobile: Flutter / Dart
API: Laravel REST API
Authentication: Laravel Sanctum
2FA: TOTP / Microsoft Authenticator

SmartGate is a university-grade, high-security gate entry, exit, and attendance management platform built specifically for university campus perimeters. It provides server-authoritative role-based access control, cryptographic 30-second dynamic QR code movements, security manual entry with photo verification, automated late entry and day scholar after-hours classification, scoped real-time alerts, and comprehensive analytical reporting.

---

## Table of Contents

1. [Architecture & Technology Stack](#architecture--technology-stack)
2. [User Roles & Permissions (RBAC)](#user-roles--permissions-rbac)
3. [Administrator Management: How to Add & Change Admin Credentials](#administrator-management-how-to-add--change-admin-credentials)
   - [Method 1: Using Laravel Artisan Command (Recommended)](#method-1-using-laravel-artisan-command-recommended)
   - [Method 2: Using Laravel Tinker CLI](#method-2-using-laravel-tinker-cli)
   - [Method 3: Changing via the Seeder File](#method-3-changing-via-the-seeder-file)
   - [Method 4: Direct Database SQL Update](#method-4-direct-database-sql-update)
4. [How to Add Security Guards & Gates](#how-to-add-security-guards--gates)
5. [How Student Registration & Approval Works](#how-student-registration--approval-works)
6. [Core Business Rules & Security Enforcements](#core-business-rules--security-enforcements)
   - [8-Hour Absolute Session Policy](#8-hour-absolute-session-policy)
   - [4-Guard Active Duty Limit & Admin OTP](#4-guard-active-duty-limit--admin-otp)
   - [Late Entry Policy (9:00 PM – 4:00 AM)](#late-entry-policy-900-pm--400-am)
   - [Day Scholar After-Hours Policy (5:00 PM – 5:00 AM)](#day-scholar-after-hours-policy-500-pm--500-am)
   - [Profile Photo Security & Private Storage](#profile-photo-security--private-storage)
   - [Role- and Gate-Scoped Notifications](#role--and-gate-scoped-notifications)
7. [Installation & Local Setup](#installation--local-setup)
   - [Prerequisites](#prerequisites)
   - [Backend Setup (Laravel)](#backend-setup-laravel)
   - [Frontend Setup (React + Vite)](#frontend-setup-react--vite)
8. [Automated Testing & Build Verification](#automated-testing--build-verification)
9. [Production Deployment Checklist](#production-deployment-checklist)

---

## Architecture & Technology Stack

- **Backend**: Laravel 13 / PHP 8.3 / Laravel Sanctum
- **Frontend**: React 19 / TypeScript / Vite 8 / Tailwind CSS / Lucide Icons
- **Database**: MySQL / SQLite (ACID row-level pessimistic locking via `lockForUpdate()`)
- **QR Security**: Cryptographic HMAC-SHA256 tokens rotated every 30 seconds
- **Real-Time Delivery**: Server-Sent Events (SSE) & Gate-Scoped notifications
- **Excel Engine**: Streaming OpenXML ZIP archive generator (`.xlsx`) & UTF-8 `.csv`

---

## User Roles & Permissions (RBAC)

SmartGate enforces strict server-side authorization across three distinct roles:

| Feature / Action | Admin (`ADMIN`) | Security Guard (`SECURITY`) | Student (`STUDENT`) |
|---|:---:|:---:|:---:|
| **Authentication Session** | Unrestricted / 2FA Ready | Fixed 8-Hour Absolute Session | Fixed 8-Hour Absolute Session |
| **System Dashboard** | Full Campus Metrics | Gate Roster & Today's Feed | Personal Status & Identity |
| **Student Management** | Approve / Reject / Suspend / Edit | Search & View Photo ID Card | Self-Register / Update Profile |
| **Security Duty Roster** | View all guards / Force-end duty | Activate via OTP / End Duty | None |
| **Duty Activation OTP** | Generate 6-digit OTP | Redeem Admin OTP | None |
| **Gate QR Code** | View gates & codes | Generate 30s rotating QR | Scan & Verify via Camera |
| **Gate Movement** | View all / Audit logs | Record Manual IN & OUT | Submit Digital QR IN & OUT |
| **Movement History** | 1-Year Full History & Filters | 15-Day Gate-Scoped History | Restricted (Current State only) |
| **Export Data** | Genuine `.xlsx` & `.csv` Export | HTTP 403 Forbidden | HTTP 403 Forbidden |
| **Audit Logs** | Full Immutable Audit Trail | HTTP 403 Forbidden | HTTP 403 Forbidden |

---

## Administrator Management: How to Add & Change Admin Credentials

> [!IMPORTANT]
> **Admin Password & Email Location**:  
> In production, admin credentials are stored securely in the `users` database table (with passwords hashed using Bcrypt).  
> In local development/testing, initial seed values can be found in:  
> `backend/database/seeders/DevelopmentDatabaseSeeder.php`

### Method 1: Using Laravel Artisan Command (Recommended)

To create a new Administrator from the command line:

```bash
cd backend
php artisan smartgate:install-admin
```
The interactive wizard will prompt you:
```text
=== SmartGate Administrator Setup ===
Enter Administrator Name: Main Admin
Enter Administrator Email: admin@ptu.ac.in
Enter Secure Password (minimum 8 characters): ••••••••••••
✓ Administrator [Main Admin] (admin@ptu.ac.in) successfully created!
```

You can also pass arguments directly in non-interactive mode:
```bash
php artisan smartgate:install-admin --name="Campus Admin" --email="admin@ptu.ac.in" --password="YourSecurePassword123!"
```

---

### Method 2: Using Laravel Tinker CLI

To **change an existing Admin's email or password** at any time:

1. Open Tinker:
   ```bash
   cd backend
   php artisan tinker
   ```
2. Run the following PHP commands inside Tinker:
   ```php
   // 1. Find the admin user by current email or role
   $admin = App\Models\User::where('role', 'ADMIN')->first();

   // 2. Change email (optional)
   $admin->email = 'newadmin@ptu.ac.in';

   // 3. Change password (hashed with Bcrypt)
   $admin->password = Hash::make('NewPassword123!');

   // 4. Save changes
   $admin->save();

   // 5. Exit tinker
   exit
   ```
*(You can now log in immediately with the new email and password.)*

---

### Method 3: Changing via the Seeder File

If you are setting up or re-seeding a development database, you can customize the admin credentials directly in the code:

- **File Path**: `backend/database/seeders/DevelopmentDatabaseSeeder.php`
- **Lines 24–33**:
  ```php
  // 1. Synthetic Admin for dev testing
  $adminEmail = 'admin.dev@test.local'; // <-- Change default email here
  $admin = User::firstOrCreate(
      ['email' => $adminEmail],
      [
          'name' => 'Development Administrator',
          'password' => Hash::make('DevAdminPassword123!'), // <-- Change default password here
          'role' => User::ROLE_ADMIN,
          'status' => User::STATUS_ACTIVE,
      ]
  );
  ```
To run the seeder:
```bash
cd backend
php artisan db:seed --class=DevelopmentDatabaseSeeder
```

---

### Method 4: Direct Database SQL Update

If you have direct access to your database GUI (phpMyAdmin, TablePlus, DBeaver, or MySQL CLI):

```sql
-- Update password to 'NewPassword123!' for the admin
UPDATE users 
SET password = '$2y$12$e8Y5qY7gC6/q8a21uAovG.bQo8f1lC0vFhDkP3gY9q8j7f4e9zGae'
WHERE role = 'ADMIN' AND email = 'admin@ptu.ac.in';
```
*(Note: Passwords must be hashed with Bcrypt before storing in MySQL. Use Method 1 or Method 2 for automatic hashing.)*

---

## How to Add Security Guards & Gates

### Adding a Security Guard
1. Log in as **Admin** at `/login`.
2. Navigate to **Security Guards** (`/admin/security`).
3. Click **Add Guard**.
4. Enter the Guard's Full Name, University Email (`guard@ptu.ac.in`), and Initial Password.
5. The guard account is instantly created in `ACTIVE` status with `role = SECURITY`.

### Generating Duty Activation OTP (Admin)
For a guard to start their operational shift at a gate:
1. In **Admin Panel** $\rightarrow$ **Security Guards**, click **Generate Duty OTP**.
2. Optionally restrict to a specific Gate or Guard, and choose TTL (default 15 minutes).
3. The Admin communicates the 6-digit OTP to the officer.

### Activating Guard Duty (Security Officer)
1. Guard logs in at `/login`.
2. Operational gate features are locked until duty is activated.
3. Guard clicks **Start Duty**, selects their assigned gate, and inputs the 6-digit Admin OTP.
4. The system transactionally verifies and burns the OTP, unlocks gate tools, and starts the duty session.

---

## How Student Registration & Approval Works

1. **Student Self-Registration**:
   - New students navigate to `/register`.
   - Fill in: Full Name, Roll Number, Year, Program/Branch, Department, Email, Phone Number, Password, **Student Type** (`HOSTELLER` or `DAY_SCHOLAR`), and **Profile Photo** ($\le 100$ KB).
   - Account is created in `PENDING` status.

2. **Pending State Lock**:
   - A `PENDING` student can log in to view their submission status, but **cannot record gate movements or verify QR codes** (receives HTTP 403).

3. **Admin Review & Approval**:
   - Admin opens **Students** (`/admin/students`) $\rightarrow$ **Pending Approvals** tab.
   - Admin reviews details and verified photo.
   - Admin clicks **Approve** (transitions to `ACTIVE` $\rightarrow$ gate access enabled) or **Reject** (account marked `REJECTED`).

---

## Core Business Rules & Security Enforcements

### 8-Hour Absolute Session Policy
- **Applies to**: `STUDENT` and `SECURITY` roles.
- **Server-Authoritative**: Valid for exactly 8 hours from login (`created_at + 8 hours`).
- **No Silent Extensions**: Page refresh, tab switching, or token refresh calls **never** extend the 8-hour window.
- **Guard Duty Invalidation**: When a guard's 8-hour session expires, their active duty session is automatically terminated (`status = ENDED`).
- **Expiry Notification**: Frontend redirects to `/login` and alerts:  
  `"Your 8-hour session has expired. Please sign in again."`

### 4-Guard Active Duty Limit & Admin OTP
- System-wide limit: **Maximum 4 concurrent active duty sessions**.
- Protected with ACID row-level locking (`lockForUpdate()`).
- The 5th concurrent guard is rejected with HTTP 422 until an active guard ends duty or is force-ended by Admin.

### Late Entry Policy (9:00 PM – 4:00 AM)
- Calculated strictly on the server in `Asia/Kolkata`:
  - `20:59:59` $\rightarrow$ Normal
  - `21:00:00` to `03:59:59` $\rightarrow$ `is_late = true`
  - `04:00:00` $\rightarrow$ Normal
- **Overnight Late Date Cohort**: Movements after midnight (`00:00:00`–`03:59:59`) are grouped with the previous calendar day's `late_window_date`.

### Day Scholar After-Hours Policy (5:00 PM – 5:00 AM)
- **Non-Blocking Principle**: **Day Scholar entry and exit are NEVER blocked after 5:00 PM.**
- Movements are classified with `day_scholar_after_hours = true` and displayed with a prominent RED warning banner: `"DAY SCHOLAR — AFTER HOURS"`.
- Hostellers are not subject to after-hours warnings.

### Profile Photo Security & Private Storage
- **Max File Size**: 100 KB (102,400 bytes) hard upload and final stored limit.
- **Allowed Formats**: JPEG, PNG, WebP (GIF, SVG, and executables rejected via true MIME inspection).
- **Private Storage**: Stored outside `public/` web roots in `storage/app/private/profile_photos/`.
- **Media Endpoint**: Served via `GET /api/v1/media/students/{id}/photo` with session auth checks. Bearer tokens are never exposed in URL query strings.

### Role- and Gate-Scoped Notifications
- **Gate 1 Movement**: Notifies the moving student, active guards at Gate 1, and Admin. Guards at Gate 2 or Gate 3 do **not** receive it.
- **Gate 2 Movement**: Notifies Gate 2 guards only.
- **SSE Stream**: `GET /security/live-stream` binds strictly to the guard's server-derived active gate session.

---

## Installation & Local Setup

### Prerequisites
- PHP 8.2 or 8.3 (with `gd`, `zip`, `pdo_sqlite` or `pdo_mysql`, `mbstring`, `fileinfo`)
- Composer 2.x
- Node.js 18+ and npm

---

### Backend Setup (Laravel)

```bash
# 1. Navigate to backend
cd backend

# 2. Install PHP dependencies
composer install

# 3. Configure environment file
cp .env.example .env
php artisan key:generate

# 4. Run database migrations
php artisan migrate

# 5. Link storage directory
php artisan storage:link

# 6. Bootstrap Initial Administrator
php artisan smartgate:install-admin

# 7. (Optional) Seed base gates and movement options
php artisan db:seed --class=DatabaseSeeder

# 8. Start Laravel development server
php artisan serve
```
*(Backend runs on `http://127.0.0.1:8000`)*

---

### Frontend Setup (React + Vite)

```bash
# 1. Navigate to frontend
cd frontend

# 2. Install NPM dependencies
npm install

# 3. Create frontend environment configuration
cat <<EOF > .env
VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1
EOF

# 4. Start Vite development server
npm run dev
```
*(Frontend runs on `http://localhost:5173`)*

---

## Automated Testing & Build Verification

### Backend PHPUnit Tests
To run all 174 automated unit, feature, and concurrency tests:
```bash
cd backend
php artisan test
```
```text
  Tests:    174 passed (794 assertions)
  Duration: 2.42s
```

To run individual feature suites:
```bash
# Test 8-Hour Session Policy
php artisan test tests/Feature/SessionPolicy8HourTest.php

# Test Security Duty & OTP Roster
php artisan test tests/Feature/SecurityDutyOtpTest.php

# Test Day Scholar After-Hours Rules
php artisan test tests/Feature/DayScholarAfterHoursTest.php

# Test High Concurrency & Idempotency
php artisan test tests/Feature/MovementConcurrencyLoadTest.php

# Test Role- & Gate-Scoped Notifications
php artisan test tests/Feature/NotificationScopingTest.php
```

### Frontend Production Build & Linter
```bash
cd frontend

# Run TypeScript compilation and Vite production build
npm run build

# Run Oxlint static analysis
npm run lint
```
*(Production build completes in $\sim 650$ms with 0 errors across 46 files).*

---

## Production Deployment Checklist

When deploying to a live server (Ubuntu/Nginx, AWS, DigitalOcean, or Hostinger VPS):

1. **Environment Config (`.env`)**:
   ```env
   APP_NAME="SmartGate"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://smartgate.youruniversity.edu
   SANCTUM_STATEFUL_DOMAINS=smartgate.youruniversity.edu

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=smartgate_production
   DB_USERNAME=smartgate_user
   DB_PASSWORD=SecureProductionPassword
   ```

2. **Optimization Commands**:
   ```bash
   cd backend
   php artisan migrate --force
   php artisan storage:link
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

3. **Frontend Build**:
   ```bash
   cd frontend
   npm run build
   ```
   *Serve the generated `frontend/dist/` directory via Nginx or reverse proxy.*

4. **Directory Permissions**:
   ```bash
   sudo chown -R www-data:www-data backend/storage backend/bootstrap/cache
   sudo chmod -R 775 backend/storage backend/bootstrap/cache
   ```
