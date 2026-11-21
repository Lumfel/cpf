# Google Forms Webhook Setup Guide (CHMSU Alijis Campus)

Connect a real Google Form to the CPF Portal so every student submission lands immediately in **Membership & GC Checklist -> Pending Membership Applications** (`enrollment_source = google_forms_api`).

---

## Option A — Live Real Google Form (Using Free Windows SSH Tunnel)

Google Forms runs in Google's cloud, so it needs a temporary public `https://...` URL pointing to your local `localhost:8000` server (when deployed online, use your real domain instead).

### Step 1 — Start Laravel Locally
In PowerShell inside `c:\laragon\www\cpf`:
```powershell
php artisan serve --host=localhost --port=8000
```

### Step 2 — Start a Free Zero-Install HTTPS Tunnel
Open a **second PowerShell window** and run (built right into Windows):
```powershell
ssh -R 80:localhost:8000 nokey@localhost.run
```
- Type `yes` if asked to trust the host key.
- Copy the printed HTTPS URL that looks like `https://xxxxxxxx.lhr.life`.
- Leave both PowerShell windows open while testing.

### Step 3 — Paste the Google Apps Script
1. Open [Google Apps Script](https://script.google.com) and click **New project**.
2. Delete the empty placeholder code and paste the full contents of [google-forms-apps-script.gs](file:///c:/laragon/www/cpf/docs/google-forms-apps-script.gs).
3. On line 9, replace `https://YOUR-TUNNEL-URL.lhr.life` with your actual `https://xxxxxxxx.lhr.life` URL from Step 2.
4. Click **Save** (`Ctrl + S`).

### Step 4 — Verify Connectivity & Auto-Generate the Google Form
1. In the Apps Script toolbar dropdown (top), choose **`testWebhookConnection`** and click **Run**.
   - Grant Google permissions when prompted (**Advanced -> Go to Untitled project -> Allow**).
   - Check the **Execution log** — you should see `Health HTTP 200: {"ok":true,...,"active_campus_code":"ALIJIS",...}`.
2. Next, switch the function dropdown to **`createCpfMembershipForm`** and click **Run**.
   - Look in the **Execution log** for the **Shareable Student Form URL**.
   - It automatically creates every matching CPF field (First/Middle/Last Name, Course, Year Level, Section, Sex, Gender Identity with "Other", Birthdate, Active Email, Facebook Profile Link, FB Note, Cellphone No.) and wires the automatic **`onFormSubmit`** trigger for you!

### Step 5 — Submit & Approve
1. Open the generated Google Form URL, fill out a student response, and submit.
2. Open `http://localhost:8000/membership` logged in as **Club Adviser**, **President**, or **Vice President**.
3. The applicant appears under **Pending Membership Applications** with a **`Google Forms API`** badge.
4. Click **Confirm & Issue Passkey Code** — their locked Passkey Code is issued and delivered via email (one time only).

---

## Option B — Instant Local PowerShell Webhook Test (No Tunnel Required)

If you want to verify the endpoint locally before setting up the Google Form:

```powershell
$headers = @{
    "X-CPF-Forms-Secret" = "cpf-local-forms-secret"
    "Accept" = "application/json"
    "Content-Type" = "application/json"
}

Invoke-RestMethod -Uri "http://localhost:8000/api/google-forms/health" -Headers $headers -Method Get

$body = @{
    campus_code = "ALIJIS"
    first_name = "Maria Clara"
    middle_name = "D."
    last_name = "Santos"
    course = "BSIT"
    year_level = "3rd Year"
    section = "A"
    sex = "Female"
    gender_identity = "Woman"
    birthdate = "2005-04-18"
    active_email = "maria.clara.alijis@gmail.com"
    facebook_profile_url = "https://www.facebook.com/maria.clara.santos.cpf"
    active_fb_account = "Maria Clara Santos (CHMSU Alijis)"
    cellphone_number = "0917-555-0188"
} | ConvertTo-Json

Invoke-RestMethod -Uri "http://localhost:8000/api/google-forms/membership" -Headers $headers -Method Post -Body $body
```
