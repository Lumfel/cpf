# Circle of Peer Facilitators (CPF) — Complete System Setup & Operations Guide

A single, comprehensive reference covering everything configured, built, and tested for the **CPF Portal (CHMSU Alijis Campus)**.

---

## 1. Daily Startup & Maintenance Commands

All commands run in **PowerShell** inside `c:\laragon\www\cpf` (with **Laragon** running MySQL on `127.0.0.1:3306`):

```powershell
# 1. Start the local Laravel server (always use localhost:8000, not 127.0.0.1)
php artisan serve --host=localhost --port=8000

# 2. Reload .env changes anytime you edit mail/webhook settings
php artisan config:clear

# 3. Reset all 11 sample accounts cleanly (resets Paul to Pending & clears his old passkeys)
php artisan db:seed --force

# 4. Apply any new database migrations
php artisan migrate --force

# 5. Rebuild frontend assets & run TypeScript check after UI edits
npm.cmd run types:check
npm.cmd run build
```

---

## 2. Active Campus Configuration (`CHMSU Alijis Campus`)

- **Active Now (`is_active = true`):** **CHMSU Alijis Campus** (`ALIJIS`)
- **On Hold / Coming Soon (`is_active = false`):** CHMSU Binalbagan, CHMSU Talisay (Main Campus), and CHMSU Fortune Towne
- Online membership forms, landing page applications, and the Google Forms webhook only accept active campuses (`CHMSU Alijis Campus`).

---

## 3. Role-Based Governance (CPF Constitution — 10 Roles, Zero Admin Role)

**Default password for all 10 pre-approved seeded accounts:** `password`  
**Pending applicant #11:** receives a **locked 12-character Passkey Code** (`XXXX-XXXX-XXXX`) upon approval and creates their own personal password (no temporary password step).

| # | Role | Sample Name | Email | Course / Yr / Sec | Core Authority |
| :--- | :--- | :--- | :--- | :--- | :--- |
| 1 | **Club Adviser** | Montemayor, Elena V. | `adviser@cpf.chmsu.edu.ph` | Guidance Services / Adviser | Top authority: approve/add members, assign all roles, confirm GC & Dues, issue passkeys, **exclusively approve/decline Member Info Change Requests**, triage encrypted referrals, manage elections & ZIP archives, **Officers Chat** |
| 2 | **President** | Mercado, Sofia R. | `president@cpf.chmsu.edu.ph` | BS Psychology • 4A | Approve/add members, co-sign Dues Checklist, confirm Messenger GC, issue passkeys, review Testing Room requests, **Officers Chat** |
| 3 | **Vice President** | Bautista, Miguel A. | `vice_president@cpf.chmsu.edu.ph` | BSIT • 4B | Assumes President duties in absence; approve/add members, co-sign Dues Checklist, confirm Messenger GC, issue passkeys, **Officers Chat** |
| 4 | **Secretary** | Alvarez, Hannah G. | `secretary@cpf.chmsu.edu.ph` | BSIS • 3A | View pending queue, confirm Messenger GC, issue passkeys, **Officers Chat** |
| 5 | **Treasurer** | Delgado, Liam C. | `treasurer@cpf.chmsu.edu.ph` | BSIT • 3B | Confirm A–Z Membership Dues Checklist on `/dashboard` & `/membership`, **Officers Chat** |
| 6 | **Auditor** | Castillo, Marco D. | `auditor@cpf.chmsu.edu.ph` | BSA • 4A | **Read-only Audit View** of Campus Dues Checklist on `/dashboard`, **Officers Chat** |
| 7 | **Business Manager** | Soriano, Patricia E. | `business@cpf.chmsu.edu.ph` | BSBA • 3A | Campus roster & organizational projects, **Officers Chat** |
| 8 | **Public Information Officer (PIO)** | Navarro, Bianca F. | `pio@cpf.chmsu.edu.ph` | BS Psychology • 2B | Confirm Messenger GC, issue passkeys, announcements, **Officers Chat** |
| 9 | **Sergeant-at-Arms** | Mendoza, Rafael J. | `saa@cpf.chmsu.edu.ph` | BSED • 3A | Peace & order oversight, campus roster, **Officers Chat** |
| 10 | **Verified Member** | Reyes, Katrina M. | `katrina@cpf.chmsu.edu.ph` | BSIT • 4A | Attendance QR ID, personal locked Passkey Code, Blockchain Credentials, Official Profile & Change Requests |
| 11 | **Pending Applicant** | Tibo-oc, Paul Felippe G. | `paulfelippegtibooc@gmail.com` | BSIT • 4A | Starts in **Pending Membership Applications** on `/membership` for approval testing |

### Data & Account Safety Rules
- **No Admin Role:** Governance is led by the **Club Adviser** (Guidance Counselor) and elected student officers per the CPF Constitution & By-Laws.
- **Account Deletion Disabled:** `DELETE /settings/profile` returns HTTP `403` for all users (including the Club Adviser) and the delete UI is removed so student records cannot be accidentally wiped.

---

## 4. Passkey-First Onboarding, Login & Official Member Profiles

### A. Registration & One-Time Passkey Delivery
1. A student submits an application via the **Landing Page Apply Modal** (`/`), `/membership`, or the **Google Forms Webhook**.
2. An authorized officer (**Adviser, President, or Vice President**) opens `/membership`, checks the applicant's Facebook profile link, and clicks **Confirm & Issue Passkey Code**.
3. The system:
   - Marks the membership **Approved** and sets `must_change_password = true`.
   - Generates and **locks** a 12-character Passkey Code (`XXXX-XXXX-XXXX`).
   - Sends the **One-Time Passkey Code Email** (`MembershipApprovedPasskeyNotification`) and stamps `passkey_issuances.emailed_at` so the approval email is delivered **one time only**.
   - Displays the Passkey Code and delivery status badge (**`One-Time Email Delivered ✓`**, **`Already Sent Previously`**, **`Logged Locally`**, or **`Delivery Failed`** with exact mail error) in the banner.

### B. First-Time Member Login (No Temporary Password)
1. Go to `http://localhost:8000/login` -> click the **Passkey Code** tab.
2. Enter your **Account Email** and type (or paste) your 12-character Passkey Code into the **12 individual boxes** (dark mode & light mode supported).
3. You are automatically taken to `/force-password-change` to **create your own personal password** (no temporary password input required).
4. Once saved, your **Attendance QR Code** unlocks immediately on `/dashboard`, and you can sign in anytime using **Email & Password**, **12-Box Passkey Code**, or a registered **Device Passkey (WebAuthn)**.
5. **Locking & Regeneration:** Passkey codes remain locked so accidental clicks never change them; both officers (`/membership`) and members (`/dashboard`) can click **Generate New Passkey** to revoke the old code and lock a new one in real time.

### C. Official Member Profile & Adviser-Only Information Change Requests
- Once a user is an **Official Approved Member**, the **Online Membership Form** tab is hidden (and `POST /membership/apply` is blocked server-side) so they never submit another membership form.
- Instead, `/membership` shows **My Official Profile & Change Request**, where they view their verified record and can submit an **Information Change Request** (modified fields + reason).
- Pending applicants see a clean **Application Submitted • Pending Officer Verification** card instead of a duplicate form.
- **Club Adviser Exclusive Approval:** Only the same-campus **Club Adviser** sees the **Member Information Change Requests** panel on `/membership` with a side-by-side **Old -> New** diff and **Approve Changes** / **Decline** buttons (`403 Forbidden` for all other roles).

---

## 5. Officers Private Group Chat (Messenger-Style UI)

- **Dedicated Sidebar Navigation:** Located directly under **Dashboard** as **Officers Chat** (`/officers-chat`), visible only to verified Officers and the Club Adviser (`403 Forbidden` for ordinary members).
- Also embedded on `/dashboard` (with a **Full Officers Chat** link) and `/campus-hub`.
- **Messenger Split-Pane Layout:**
  - **Left Sidebar (`Officer Chats`):** Search bar, active **Main Officers GC — CHMSU Alijis Campus** thread, and **Other Campus Groups (Coming Soon)** on-hold entries for **Binalbagan**, **Talisay**, and **Fortune Towne**.
  - **Right Conversation Window:** Right-aligned blue bubbles for your own messages, left-aligned bubbles with initials/role badges for fellow officers, auto-scroll, pill `Aa` composer, and the Club Adviser's **Archive ZIP & Reset** (`SHA-256` archive) button.

---

## 6. Email Setup (Mailtrap Sandbox & Real Gmail SMTP)

### Current Local Setup — Mailtrap Sandbox (Active in `.env`)
- Configured in `.env` (`MAIL_HOST=sandbox.smtp.mailtrap.io`, `MAIL_PORT=2525`, `MAIL_SCHEME=smtp`, `MAIL_FROM_ADDRESS="noreply@cpf.chmsu.edu.ph"`).
- **How Mailtrap Sandbox works:** It safely **traps** all outgoing emails (Approval Passkey emails & Forgot Password reset links) inside your **Mailtrap -> Sandboxes -> My Sandbox** inbox so you can inspect and test them without emailing real student accounts.
- You can use the **Manual Forward** tab inside Mailtrap if you want to forward any trapped email to a real Gmail address.

### Switching to Real Gmail Delivery Later (Free, No Custom Domain Needed)
When ready to deliver straight to real student Gmail inboxes:
1. Create a dedicated organization Gmail (e.g., `cpf.chmsu.alijis@gmail.com`) and turn on **2-Step Verification**.
2. Generate a 16-character **Google App Password** at `https://myaccount.google.com/apppasswords`.
3. Update `.env`:
   ```env
   MAIL_MAILER=smtp
   MAIL_SCHEME=smtps
   MAIL_HOST=smtp.gmail.com
   MAIL_PORT=465
   MAIL_USERNAME=cpf.chmsu.alijis@gmail.com
   MAIL_PASSWORD=your16charapppassword
   MAIL_FROM_ADDRESS="cpf.chmsu.alijis@gmail.com"
   MAIL_FROM_NAME="${APP_NAME}"
   ```
4. Run `php artisan config:clear` and restart `php artisan serve --host=localhost --port=8000`.

---

## 7. Google Forms Live Webhook Integration

### Endpoints & Secret
- **Health Check:** `GET /api/google-forms/health`
- **Submit Application:** `POST /api/google-forms/membership`
- **Required Header:** `X-CPF-Forms-Secret: cpf-local-forms-secret` (configured in `.env` as `GOOGLE_FORMS_WEBHOOK_SECRET`)
- **Automatic Payload Normalization:** Converts `"4th Year"` -> `"4"`, normalizes case on `sex`, maps custom Google Forms `"Other:"` gender answers into `gender_identity = "Others"` + `gender_identity_other`, prepends `https://` to bare `facebook.com/...` links, and parses standard date formats (`MM/DD/YYYY` or `YYYY-MM-DD`).

### Running the Live Google Form Tunnel (Keepalive)
1. **PowerShell Window 1:**
   ```powershell
   php artisan serve --host=localhost --port=8000
   ```
2. **PowerShell Window 2** (30-second keepalive prevents idle timeouts):
   ```powershell
   ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -R 80:localhost:8000 nokey@localhost.run
   ```
3. Copy the printed `https://xxxxxxxx.lhr.life` URL and paste it onto **line 10** of your Google Apps Script project (source in `docs/google-forms-apps-script.gs`), then press **`Ctrl + S`**.
4. Run **`testWebhookConnection`** -> confirm `Health HTTP 200` with `"active_campus_code": "ALIJIS"`.
5. Run **`createCpfMembershipForm`** **once** to auto-generate the 13-question Google Form and attach its automatic `onFormSubmit` trigger. *(If the tunnel restarts later, only update line 10 with the new URL and save — never recreate the form; the same student form link keeps working.)*

### Checking New Submissions
- **CPF Portal:** Refresh `/membership` -> **Pending Membership Applications** (`Google Forms API` badge).
- **Terminal:** Live `POST /api/google-forms/membership` log line.
- **Google Forms:** Open the Edit URL -> **Responses** tab (enable `⋮ -> Get email notifications for new responses` for instant alerts).
- **Google Apps Script:** **Executions** tab on the left sidebar shows every `onFormSubmit` result (`Webhook HTTP 201` or validation error details).

---

## 8. Quick URL Reference & Automated Tests

| Page | URL |
| :--- | :--- |
| Public Landing & Apply Modal | `http://localhost:8000/` |
| Login (Email, 12-Box Passkey Code, Device Passkey) | `http://localhost:8000/login` |
| Direct Account Registration | `http://localhost:8000/register` |
| Peer Dashboard (Attendance QR, Passkey Code, Dues Checklist) | `http://localhost:8000/dashboard` |
| Officers Chat (Dedicated Messenger-Style Officers GC) | `http://localhost:8000/officers-chat` |
| Membership, Pending Approvals, Change Requests & A–Z GC Checklist | `http://localhost:8000/membership` |
| Campus Hub (Testing Room, Encrypted Referrals, Officers GC) | `http://localhost:8000/campus-hub` |
| Community Hub (Elections, Time Capsules, Archives) | `http://localhost:8000/community-hub` |
| My Blockchain Achievements, Certificates & Awards | `http://localhost:8000/my-credentials` |
| Account Security (Password, 2FA, Device Passkeys) | `http://localhost:8000/settings/security` |
| Isolated Prototype Sandbox | `http://localhost:8000/prototype` |

### Running the Automated Test Suite
```powershell
php artisan test tests/Feature/CpfFunctionalRequirementsTest.php tests/Feature/DashboardTest.php tests/Feature/ExampleTest.php
```
*(20 tests / 384 assertions covering Constitution role governance, Passkey-First onboarding, one-time approval emails, Adviser-only Member Information Change Requests, disabled account deletion, Officers Chat access, and Google Forms webhook normalization.)*
