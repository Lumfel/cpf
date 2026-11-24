# CPF Sample Accounts & Login / Registration Guide

Quick reference for testing the **Circle of Peer Facilitators (CPF)** system locally without needing to backread.

---

## 1. Starting the Local Server

1. Open **Laragon** and click **Start All** (starts MySQL on `127.0.0.1:3306`).
2. In PowerShell inside `c:\laragon\www\cpf`:
   ```powershell
   php artisan serve --host=localhost --port=8000
   ```
3. Open **`http://localhost:8000`** in your browser.
   > **Note:** Always use `localhost:8000` (not `127.0.0.1:8000`) so browser WebAuthn Passkeys work. If you accidentally open `127.0.0.1:8000`, Laravel automatically redirects you to `localhost:8000`.

### Resetting Sample Accounts Anytime
If you want to reset all 11 seeded accounts back to their original roles, statuses, and `password`, or switch to the blank first-boot Adviser onboarding flow:
```powershell
# Re-seed demo accounts in place
php artisan db:seed --force

# Or reset cleanly into Blank First-Boot ("Enter as Club Adviser") vs Demo environments
php artisan cpf:reset-blank
php artisan cpf:reset-demo
```

---

## 2. Sample Accounts (CHMSU Alijis Campus — Active Now • 12 Seeded Users)

> **Active Campus Lock:** The portal is currently active for **CHMSU Alijis Campus**. Binalbagan, Talisay, and Fortune Towne chapters are currently on hold (**Coming Soon**).

**Default password for all 11 pre-approved seeded accounts:** `password`  
**Pending applicant #12:** receives a locked **12-character Passkey Code** (`XXXX-XXXX-XXXX`) upon approval so they can sign in and set their own personal password (no temporary password step).

| # | Role (CPF Constitution) | Name | Email | Course / Year / Section | Governance Access & Key Responsibilities |
| :--- | :--- | :--- | :--- | :--- | :--- |
| 1 | **Club Adviser** (Guidance Counselor) | Montemayor, Elena V. | `adviser@cpf.chmsu.edu.ph` | Guidance Services / Adviser | Top authority (zero Admin role; account deletion disabled): approve/add members, invite campus Guidance Counselors via 24h Signed Link + 6-digit OTP, initiate **Adviser Handover** (existing Counselor dropdown or new invite), confirm GC & Dues, triage encrypted referrals, publish templated announcements, preside over elections, **Officers & Counselors Private GCs** |
| 2 | **Guidance Counselor** *(Non-Adviser • Coordination Only)* | Delos Santos, Clarissa R. | `counselor@cpf.chmsu.edu.ph` | Guidance Services / Guidance Counselor | **Coordination-only** (`canCoordinateGuidance = true`, `isOfficerOrAdviser = false`): access **ONLY** to **Counselors Private GC**, **Testing Room** schedule/review, and confidential **Guidance Referrals** (`pending` / `ongoing` / `completed`). Blocked from Member Approvals, Dues, Officers GC, Elections/Voting, and Club Events; selectable in **Adviser Handover** dropdown |
| 3 | **President** | Mercado, Sofia R. | `president@cpf.chmsu.edu.ph` | BS Psychology • Yr 4 • Sec A | Approve/add members, co-sign **Dues Checklist**, propose events, confirm Messenger GC, issue Passkey Codes, review Testing Room requests, **Officers Private GC** |
| 4 | **Vice President** | Bautista, Miguel A. | `vice_president@cpf.chmsu.edu.ph` | BSIT • Yr 4 • Sec B | Assumes President duties in absence; approve/add members, co-sign **Dues Checklist**, propose events, confirm Messenger GC, issue Passkey Codes, **Officers Private GC** |
| 5 | **Secretary** | Alvarez, Hannah G. | `secretary@cpf.chmsu.edu.ph` | BSIS • Yr 3 • Sec A | Maintain records/minutes; publish **Templated Campus Announcements**, view pending queue, confirm Messenger GC, issue Passkey Codes, **Officers Private GC** |
| 6 | **Treasurer** | Delgado, Liam C. | `treasurer@cpf.chmsu.edu.ph` | BSIT • Yr 3 • Sec B | Custody of funds & liquidation; confirm A–Z **Membership Dues Checklist** on `/dashboard` & `/membership`, **Officers Private GC** |
| 7 | **Auditor** | Castillo, Marco D. | `auditor@cpf.chmsu.edu.ph` | BSA • Yr 4 • Sec A | Audit incoming/outgoing funds and Treasurer reports; **read-only Audit View** of Campus Dues Checklist on `/dashboard`, **Officers Private GC** |
| 8 | **Business Manager** | Soriano, Patricia E. | `business@cpf.chmsu.edu.ph` | BSBA • Yr 3 • Sec A | Lead business projects & fundraising proposals, view campus roster, **Officers Private GC** |
| 9 | **Public Information Officer (PIO)** | Navarro, Bianca F. | `pio@cpf.chmsu.edu.ph` | BS Psychology • Yr 2 • Sec B | Publish **Templated Campus Announcements** (*General Assembly*, *Workshop Reminder*, *Dues Notice*, *Wellness Check-in*), confirm Messenger GC, **Officers Private GC** |
| 10 | **Sergeant-at-Arms** | Mendoza, Rafael J. | `saa@cpf.chmsu.edu.ph` | BSED • Yr 3 • Sec A | Maintain peace, calm, and order during meetings/events, view campus roster, **Officers Private GC** |
| 11 | **Verified Peer Member** | Reyes, Katrina M. | `katrina@cpf.chmsu.edu.ph` | BSIT • Yr 4 • Sec A | Approved peer facilitator (`is_in_messenger_gc = false`, `has_paid_dues = false`), Attendance QR ID, personal Passkey Code, privacy-safe Peer Referral submission with free-slot scheduling, seeded Blockchain Credentials |
| 12 | **Pending Applicant** | Tibo-oc, Paul Felippe G. | `paulfelippegtibooc@gmail.com` | BSIT • Yr 4 • Sec A | `status = pending` — appears in the Pending Membership Applications queue on `/membership` for Adviser/President/VP confirmation |

> **Zero Admin & Data Safety:** Per Constitution governance, there is no generic Admin role, direct student/officer promotion to `adviser` is blocked (Adviser authority transfers strictly to incoming Guidance Counselors via `/membership` -> **Adviser Handover**), directly added/approved users automatically receive verified email timestamps (`email_verified_at`), and **account deletion is permanently disabled** (`DELETE /settings/profile` returns `403`).

---

## 3. How Registration & Passkey-First Onboarding Work (No Temporary Password)

### Path A — Official CPF Membership Application (Recommended Student Flow)
1. Visit **`http://localhost:8000`** and click **Apply Now** (or submit via Google Forms API `POST /api/google-forms/membership` with header `X-CPF-Forms-Secret: cpf-local-forms-secret`).
2. Fill in all required details:
   - First Name, Middle Name (optional), Last Name, Birthday
   - **Separate** Course / Program, Year Level, and Section
   - Active Email, Cellphone Number, Sex, Gender Identity
   - Required **Facebook Profile Link** (`https://www.facebook.com/...` or `https://fb.com/...`)
3. Submitting creates a pending account (`status = pending`) visible on `/membership`.
4. **Officer Confirmation:**
   - Log in as `adviser@cpf.chmsu.edu.ph`, `president@cpf.chmsu.edu.ph`, or `vice_president@cpf.chmsu.edu.ph` (`password`).
   - Open **`http://localhost:8000/membership`**.
   - Click **Open Facebook Profile** to verify the applicant, then click **Confirm & Issue Passkey Code**.
5. **Copy the Issued Passkey Code:**
   - A banner at the top of `/membership` displays the member's email and **locked 12-character Passkey Code** (`XXXX-XXXX-XXXX`) inside 12 crisp boxes.
   - Share this Passkey Code (and their registered email) with the newly confirmed member — no temporary password is generated or required.

### Path B — Direct Officer Add (`/membership` -> `+ Add Member`)
1. Log in as **Club Adviser, President, or Vice President**.
2. Open **`/membership`** and click **+ Add Member (Adviser / Pres / VP)**.
3. Fill in the member's details (separate Course, Year Level, Section + Facebook Profile Link) and submit.
4. The member is immediately verified and their **locked 12-character Passkey Code** (`XXXX-XXXX-XXXX`) appears in the top banner on `/membership`.

### Path C — Self-Serve Account Registration (`/register`)
1. On `/login`, click **Create an account** (`http://localhost:8000/register`).
2. Enter Name, Email, Password, and Confirm Password to create an **Ordinary Account** (no verified campus role yet).
   - With `MAIL_MAILER=log` in `.env`, the email verification link is written to `storage/logs/laravel.log`.
3. After verifying and logging in, the user can fill out the **Online Membership Form** tab on `/membership` to request campus verification.

---

## 4. How Login & Creating Your Personal Password Work (`http://localhost:8000/login`)

### Method 1 — 12-Box Passkey Code (Recommended for Newly Confirmed Members)
1. On **`http://localhost:8000/login`**, click the **Passkey Code** tab.
2. Enter your **Account Email** and type (or paste) your 12-character code (`XXXX-XXXX-XXXX`) into the **12 individual boxes**.
3. Click **Sign in with Passkey Code**.
4. **First-Time Onboarding (`/force-password-change`):**
   - If you have not created your personal password yet (`must_change_password = true`), the system automatically sends you to **`/force-password-change`**.
   - **No temporary password field is shown** — simply enter and confirm your **New Personal Password** (`password` + `password_confirmation`) and click **Save Personal Password & Unlock Attendance QR**.
   - Your **Attendance QR ID** unlocks immediately on `/dashboard`!
5. **Locking & Real-Time Regeneration:**
   - **By Officers (`/membership`):** Authorized officers (`Adviser, President, VP, Secretary, PIO`) click **Issue Passkey** on any verified member. The code stays **locked** (`is_locked = true`) so it cannot be accidentally overwritten. Clicking **Generate New Passkey** immediately revokes the old code and locks a new one in real time.
   - **By Members (`/dashboard`):** Scroll to **My Passkey Login Code** to view/copy your locked 12-box code, or click **Generate New Passkey** anytime to replace it in real time.

### Method 2 — Email & Personal Password
1. On `/login`, keep the **Email & Password** tab selected.
2. Enter your **Email address** and your **Personal Password** (or `password` for pre-seeded sample accounts) and click **Sign in to CPF Portal**.

### Method 3 — Device Passkey (`Sign in with a passkey` — WebAuthn)
1. Sign in first using **Email & Password** or **Passkey Code** on **`http://localhost:8000/login`**.
2. Go to **Settings -> Security** (`http://localhost:8000/settings/security`) and register a device passkey using Windows Hello, fingerprint, Face ID, or phone QR.
3. Next time you visit `http://localhost:8000/login`, click **Sign in with a passkey** to authenticate via the Windows Security prompt.

---

## 5. Quick URL Reference

| Page | URL |
| :--- | :--- |
| Public Landing & Apply Modal | `http://localhost:8000/` |
| Login (Email, 12-Box Passkey Code, Device Passkey) | `http://localhost:8000/login` |
| Direct Account Registration | `http://localhost:8000/register` |
| Peer Dashboard (Attendance QR, Officers Private GC, Passkey Code, Events & Dues) | `http://localhost:8000/dashboard` |
| Events & Activities (Calendar, Officer QR Log-In/Log-Out Scanner, Checklist & Reflections) | `http://localhost:8000/events-activities` |
| Officers Chat (Dedicated Messenger-Style Officers & Counselors GC) | `http://localhost:8000/officers-chat` |
| Membership, Pending Approvals, Change Requests, Adviser Handover & A–Z GC Checklist | `http://localhost:8000/membership` |
| Campus Operations (Testing Room, Referrals, Officers GC & ZIP Archive) | `http://localhost:8000/campus-hub` |
| Community Hub (Dynamic General Assembly Elections, Time Capsules, Archives) | `http://localhost:8000/community-hub` |
| Policy & By-Laws (CHMSU Alijis CPF Constitution & By-Laws) | `http://localhost:8000/policy-and-bylaws` |
| My Blockchain Achievements, Certificates & Awards *(public verification only when signed out)* | `http://localhost:8000/my-credentials` |
| Public Blockchain Verification (Sample Code) | `http://localhost:8000/credentials/verify/CPF-ACH-2026-KTRN0001` |
| Account Security (Password, 2FA, Device Passkeys) | `http://localhost:8000/settings/security` |
| Isolated Prototype Sandbox | `http://localhost:8000/prototype` |
