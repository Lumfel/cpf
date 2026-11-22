# Circle of Peer Facilitators (CPF) — First-Boot Setup, Club Adviser Role Handover & Events Guide

Operational reference for the **Blank First-Boot Environment ("Enter as Club Adviser")**, **Two-Step Club Adviser Role Handover**, and **Campus Events & Activities** workflows.

---

## 1. Blank First-Boot Environment ("Enter as Club Adviser")

Use the blank environment whenever deploying a brand-new chapter or demonstrating first-boot initialization without pre-seeded demo accounts.

### How It Works
- **Blank Seeder (`database/seeders/BlankSystemSeeder.php`)**:
  - Seeds only the 4 CHMSU campus shells (`ALIJIS` active with `adviser_name = null`; `TALISAY`, `FORTUNE_TOWNE`, and `BINALBAGAN` inactive) and each campus's single `TESTING_ROOM` (`capacity = 6`).
  - Seeds **zero users, zero memberships, zero Club Adviser accounts, zero events, zero elections, zero quotes, and zero messages**.
- **Automatic Pop-Out Trigger**:
  - On every Inertia request, `FirstBootSetupController::needsAdviserSetup()` checks whether zero approved `adviser` rows exist in `campus_memberships` and shares `systemBootstrap.needsAdviserSetup` via `HandleInertiaRequests`.
  - When `true`, `FirstBootAdviserModal.vue` pops open automatically on `/` (`Landing.vue`) and `/login` (`Login.vue`) as the **"Enter as Club Adviser"** onboarding modal *(with an optional **Preview Page Behind** toggle pill)*.
- **Atomic First-Boot Provisioning (`POST /first-boot/setup-adviser`, middleware `guest`, `throttle:10,1`)**:
  1. Updates the chosen active campus (`adviser_name`, `current_academic_year`, `gc_group_name`, `is_active = true`).
  2. Ensures the campus's single `CampusRoom` (`TESTING_ROOM`).
  3. Provisions the 3 default `CampusCounselor` records (`CampusCounselor::ensureDefaultCounselorsForCampus()`).
  4. Creates the active `OfficerGcChannel` for the chosen academic year.
  5. Inserts a default approved calming `PeerQuote` if none exists yet.
  6. Creates the verified founding `User` (`must_change_password = false`) + approved `CampusMembership` (`role = 'adviser'`), signs the Adviser straight into `/dashboard`, and **permanently locks** `POST /first-boot/setup-adviser` (`403 Forbidden` for any future call).

### Switching Between Blank First-Boot & Demo Seed Environments
> Both reset commands refuse execution when `APP_ENV=production` (`routes/console.php`). Make sure **Laragon MySQL (`127.0.0.1:3306`)** is running first.

```powershell
# Switch to BLANK first-boot state (pops out "Enter as Club Adviser" at / and /login)
php artisan cpf:reset-blank

# Switch back to full CHMSU Alijis DEMO seeded accounts & sample data
php artisan cpf:reset-demo
```

### First-Boot Browser Walkthrough
1. Ensure Laragon MySQL is started, then run `php artisan cpf:reset-blank`.
2. Open `http://localhost:8000/` or `http://localhost:8000/login`.
3. Confirm the **First-Boot System Initialization — Enter as Club Adviser** modal pops out automatically.
4. Fill in Chapter Display Name, Academic Year (`2026-2027`), Adviser Name, Email, Sex, and Password (minimum 8 characters), then click **Initialize System & Enter as Club Adviser**.
5. You land directly on `/dashboard` signed in as **Club Adviser**, with `/membership`, `/campus-hub`, `/events-activities`, `/community-hub`, and `/officers-chat` ready to operate immediately.

---

## 2. Club Adviser Role Handover & Lockout Protection

Because CPF intentionally has **no Admin role**, losing the sole Club Adviser would deadlock Adviser-only governance. Two layers prevent accidental lockout while enabling safe turnover to a new Adviser:

### Lockout Guards
- **`CampusMembershipPolicy::assignRole()`** (`app/Policies/CampusMembershipPolicy.php`): Non-Advisers (including President and Vice President) receive `403 Forbidden` if attempting to modify a Club Adviser's role.
- **`CampusMembershipController::updateRole()`** (`app/Http/Controllers/Membership/CampusMembershipController.php`): Even the Club Adviser receives `422 Unprocessable Entity` if attempting to demote the sole approved Adviser on a campus directly via `/membership/{membership}/role`, or if attempting to promote any student member/officer directly to `adviser`.
- **`OfficerAddMemberRequest`** (`app/Http/Requests/Membership/OfficerAddMemberRequest.php`) & `/membership` UI: `availableRoles` excludes `adviser`, direct member creation rejects `role = 'adviser'` (`422`), and Adviser rows on `/membership` render a read-only `Club Adviser • Use Handover Tab` pill instead of a role dropdown.

### Two-Step Handover Process (`adviser_handovers`)
Implemented via `AdviserHandoverController` and `AdviserHandoverPanel.vue` (shown on `/membership` -> **Adviser Handover** tab and as an alert banner on `/dashboard`):

1. **Step 1 — Current Club Adviser Initiates Handover (`POST /adviser-handover`)**:
   - Current Adviser enters their **current password** to authorize the transfer and enters the **incoming Guidance Counselor's** details (first/middle/last name, email, phone, and initial password). Handover is strictly limited to incoming Guidance Counselors — student members and officers cannot receive Club Adviser authority via handover.
   - The backend creates a verified incoming Guidance Counselor user + approved membership (`Guidance Services / Incoming Adviser`) and targets them for handover.
   - Any prior `pending` handover on the campus is cancelled cleanly.
   - The system creates an `AdviserHandover` record valid for **7 days** and displays a **6-character verification code** (uppercase alphanumeric `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`) to the current Adviser.
   - Until confirmation, the **current Club Adviser retains full authority** and can revoke the pending transfer anytime via **Cancel Pending Handover** (`DELETE /adviser-handover/{handover}`).
2. **Step 2 — Incoming Guidance Counselor Confirms (`POST /adviser-handover/{handover}/respond`)**:
   - Only `to_user_id` sees the **Pending Club Adviser Role Handover** banner on `/dashboard` and `/membership` (all other accounts get `403 Forbidden` on `/respond`; the 6-character `confirmation_code` is never exposed in the incoming user's Inertia payload).
   - Incoming Guidance Counselor enters the 6-character verification code and chooses:
     - **Accept & Assume Club Adviser Role** (`decision: 'accepted'`): Runs inside `DB::transaction()` to promote the incoming membership to `adviser` (`Guidance Services / Adviser`), transition the outgoing Adviser cleanly to `member` (`outgoing_role = 'member'`), update `campuses.adviser_name`, and stamp the handover `accepted`.
     - **Decline** (`decision: 'declined'`): Marks handover `declined`; the initiating Adviser keeps full authority.

### Handover Browser Walkthrough
1. Sign in as the current **Club Adviser** and go to `/membership` -> **Adviser Handover**.
2. Fill in the **Incoming Guidance Counselor's** details and initial password, enter your current Adviser password, add optional handover notes, and click **Initiate Adviser Handover & Generate Code**.
3. Copy the boxed **6-character code** displayed in the pending handover card.
4. Sign in as the incoming Guidance Counselor in another browser/Incognito window — the **Pending Club Adviser Role Handover** card appears automatically on `/dashboard` (and `/membership`).
5. Paste the 6-character code and click **Accept & Assume Club Adviser Role**. The incoming Guidance Counselor immediately gains Club Adviser authority and the previous Adviser steps down cleanly.

---

## 3. Events & Activities Module (`/events-activities`)

Dedicated campus workspace for proposing, approving, rescheduling, running live attendance QR scans, and collecting encrypted post-event reflections.

### Role Authority & Lifecycle Rules
- **Schedule / Propose Authority (`canProposeEvents()`)**: **Club Adviser, President, and Vice President** (`CpfRole.php`). Secretary, Treasurer, Auditor, Business Manager, PIO, Sergeant-at-Arms, and regular Members get `403 Forbidden` if attempting to create events.
  - Events created by the **Club Adviser** are **auto-approved** (`status = 'approved'`) and immediately notify all approved campus members.
  - Events created by **President / Vice President** start as **`pending_approval`** and notify the campus Adviser(s).
- **Strict 1-Day-Before Scheduling Cutoff**:
  - Creating (`POST /events-activities`), approving (`PATCH /approve`), or rescheduling (`PATCH /schedule`) is rejected with `422` if `event_date` is today or in the past (`< now()->addDay()->startOfDay()`).
  - Once an `approved` event enters within 1 day of its date (`within_one_day_cutoff`), schedule changes are locked (`422`).
- **Club Adviser Ultimate Schedule Controls (`403` for all non-Advisers)**:
  - **Approve** (`PATCH /events-activities/{event}/approve`): Promotes `pending_approval` -> `approved` and notifies members.
  - **Drag-and-Drop / Input Reschedule** (`PATCH /events-activities/{event}/schedule`): Adviser can drag event chips across dates on `EventCalendarGrid.vue` (date-only drop preserves `start_time`/`end_time`) or edit Title, Date, Start/End Time, Location, and Description via the editor form. Notifies members (`event_rescheduled`).
  - **Cancel** (`PATCH /events-activities/{event}/cancel`): Marks `cancelled` with optional reason and notifies members (`event_cancelled`).

### Automatic Activation, Officer QR Attendance Scanner & Checklist
- **Automatic Lifecycle Transitions**:
  - `CampusEvent::effectiveStatus()` dynamically evaluates `pending_approval`, `cancelled`, `scheduled` (before `start_time`), `active` (inclusive between `start_time` and `end_time`), and `completed` (after `end_time`).
  - `CampusEvent::syncLifecycleTimestamps()` runs automatically on page loads (`/events-activities` & `/dashboard`) and via the scheduled Artisan command `cpf:sync-campus-events` (`->everyMinute()` in `routes/console.php`), stamping `activated_at` / `completed_at` and notifying members once per transition.
- **Officer QR Scanner (`EventQrScanner.vue` -> `POST /events-activities/{event}/scan`)**:
  - Unlocks for any verified officer or Adviser (`isOfficerOrAdviser()`) **strictly while `effectiveStatus() === 'active'`** (`422` before/after; `403` for regular members).
  - Supports **Log-In (Activity Start)** and **Log-Out (Activity End)** via live device camera (`BarcodeDetector`), handheld scanner, manual input, or quick member ID selector.
  - Accepts both HMAC-signed Dashboard QR payloads (`CPF|campus:{CODE}|user:{ID}|role:{ROLE}|sig:{HMAC16}`) and campus Member ID codes (`CPF-{CODE}-{YYYY}-{ID}`). Cross-campus codes and tampered HMAC signatures return `422`.
  - Enforces **Log-In before Log-Out** (`422` if scanning `logout` before `login`).
- **Attendance Checklist (List View) & Dashboard Feed**:
  - Under `/events-activities` -> **QR Scanner & Checklist**, officers and members view the A–Z list of approved campus members with real-time `In` / `Out` timestamps, scanning officer names, and search/status filters (`All`, `Present In & Out`, `Logged In Only`, `Not Scanned Yet`).
  - On `/dashboard`, all campus members see a read-only **Campus Events & Activities Calendar** alongside their **Event Notifications** inbox (`Mark Read` / `Mark All Read`) and a **Copy Signed QR Payload** button under their Attendance QR ID.

### Post-Event Reflections -> Time Capsule
- Once `effectiveStatus() === 'completed'`, verified campus members can open **Reflections -> Time Capsule** on `/events-activities` and submit `POST /events-activities/{event}/reflection` (title, reflection message min 10 chars, and future Time Capsule `unlock_at`).
- Inside `DB::transaction()`, the reflection is AES-256 encrypted straight into a `TimeCapsule` record (`time_capsules`) and linked via `campus_event_reflections` (limited to 1 reflection per member per event; plaintext is never visible in checklists or to officers).

---

## 4. Technical Reference & Automated Verification

### Key Endpoints
| Route | Method | Allowed Roles / Middleware | Purpose |
| :--- | :--- | :--- | :--- |
| `/first-boot/setup-adviser` | `POST` | `guest`, `throttle:10,1` (only while 0 Advisers exist) | Initialize founding campus Adviser & resources |
| `/adviser-handover` | `POST` | Same-campus Club Adviser | Initiate password-verified handover (`6-char` code) |
| `/adviser-handover/{handover}/respond` | `POST` | Designated `to_user_id` | Accept (with 6-char code) or decline handover |
| `/adviser-handover/{handover}` | `DELETE` | Initiating Club Adviser | Cancel pending handover |
| `/events-activities` | `GET` | Approved/authenticated user | Events workspace, checklists, notifications |
| `/events-activities` | `POST` | Adviser, President, VP (`canProposeEvents`) | Create/propose event (`>= 1 day` ahead) |
| `/events-activities/{event}/approve` | `PATCH` | Same-campus Club Adviser | Approve pending event |
| `/events-activities/{event}/schedule` | `PATCH` | Same-campus Club Adviser | Drag/input reschedule or edit details |
| `/events-activities/{event}/cancel` | `PATCH` | Same-campus Club Adviser | Cancel non-completed event |
| `/events-activities/{event}/scan` | `POST` | Campus Officers & Adviser (`isOfficerOrAdviser`) | Record QR Log-In (`start`) or Log-Out (`end`) |
| `/events-activities/{event}/reflection` | `POST` | Verified campus member (when `completed`) | Seal reflection into encrypted `TimeCapsule` |
| `/events-activities/notifications/{notification}/read` | `PATCH` | Notification recipient | Mark single event notification read |
| `/events-activities/notifications/read-all` | `POST` | Authenticated user | Mark all unread event notifications read |

### New Database Tables
- `adviser_handovers` (`database/migrations/2026_11_22_000015_create_adviser_handovers_table.php`)
- `campus_events`, `campus_event_attendances`, `campus_event_reflections`, `campus_event_notifications` (`database/migrations/2026_11_22_000014_create_campus_events_and_activities_tables.php`)

### Automated PHPUnit Feature Verification
```powershell
php artisan test
```
- **Latest verified full suite result:** **77 passed, 855 assertions, 0 failures** *(covers First-Boot blank setup & Guidance-Counselor-only Adviser handover, Adviser promotion guards, Events & Activities QR attendance & reflections, election self-nomination/voting integrity, and full CPF functional requirements).*
