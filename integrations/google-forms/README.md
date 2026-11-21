# CPF Google Forms API Integration

Automatically forwards new Google Form membership responses to the Circle of Peer Facilitators (CPF) system so they enter the **Pending Review** queue for the Club Adviser and President.

## 1. Environment Configuration (`.env`)

Make sure your CPF `.env` defines:

```env
GOOGLE_FORMS_WEBHOOK_SECRET=cpf-local-forms-secret
```

Use a long random string in production.

## 2. API Endpoint Details

- **Method & Path:** `POST /api/google-forms/membership`
- **Headers:**
  - `Content-Type: application/json`
  - `Accept: application/json`
  - `X-CPF-Forms-Secret: <your GOOGLE_FORMS_WEBHOOK_SECRET>`
- **Required JSON Fields:**
  - `first_name`, `last_name` (and optional `middle_name`)
  - `course`, `year_level`, `section` (separate fields)
  - `sex` (`Male` or `Female`), optional `gender_identity`
  - `birthdate` (`YYYY-MM-DD`)
  - `active_email`
  - `facebook_profile_url` (must start with `https://` and point to `facebook.com` or `fb.com`)
  - `cellphone_number`
  - Optional `campus_id` or `campus_code` (defaults to `TALISAY` / first active campus)

Accepted applications are stored in `campus_memberships` with:
- `status = 'pending'`
- `enrollment_source = 'google_forms_api'`

## 3. Google Form Setup

1. Create a Google Form with separate questions for:
   - **Campus** (e.g., `TALISAY`, `FORTUNE_TOWNE`, `ALIJIS`, `BINALBAGAN`)
   - **First Name**, **Middle Name**, **Last Name**
   - **Course** (e.g., `BSIT`), **Year Level** (e.g., `4`), **Section** (e.g., `A`)
   - **Sex** (`Male` / `Female`) and **Gender Identity**
   - **Birthdate** (`Date`)
   - **Active Email**
   - **Facebook Profile Link** (URL validation matching `facebook.com` or `fb.com`)
   - **Cellphone Number**
2. Open **Extensions -> Apps Script** on your Google Form.
3. Paste the contents of [`cpf_google_forms_webhook.gs`](cpf_google_forms_webhook.gs).
4. Set **Script Properties**:
   - `CPF_API_URL`: `https://<your-domain>/api/google-forms/membership`
   - `CPF_WEBHOOK_SECRET`: same secret configured in `.env`
5. Add an **Installable Trigger**: `onFormSubmit` -> Event source: `From form` -> Event type: `On form submit`.

## 4. Officer & Adviser Review Flow

1. Every Google Form response immediately appears in the **Pending Online / Google Form Applications** queue on `/membership` with the applicant's full name, course, year level, section, and clickable Facebook profile link.
2. When the Club Adviser (or President/VP) clicks **Approve & Confirm**, the system issues an easy-to-remember temporary password (`Cpf-<Surname>-<4digits>`), marks `must_change_password = true`, and displays the credentials so the officer can share them securely.
3. Upon first login, the member is required to set their own personal password (`/force-password-change`), after which `must_change_password` becomes `false` and their Attendance QR Code unlocks on `/dashboard`.
