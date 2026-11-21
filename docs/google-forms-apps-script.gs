/**
 * Circle of Peer Facilitators (CPF) — Google Forms Auto-Builder & Webhook Connector
 *
 * Paste this entire file into https://script.google.com
 * 1) Update WEBHOOK_BASE_URL to your live domain or free tunnel URL (e.g. https://xxxx.lhr.life)
 * 2) Run testWebhookConnection() to confirm your portal responds 200 OK
 * 3) Run createCpfMembershipForm() ONCE to generate the official Google Form and bind its trigger
 */

const WEBHOOK_BASE_URL = 'https://YOUR-TUNNEL-URL.lhr.life';
const WEBHOOK_SECRET = 'cpf-local-forms-secret';
const CAMPUS_CODE = 'ALIJIS';

/**
 * 1. Check webhook health (GET /api/google-forms/health)
 */
function testWebhookConnection() {
  const url = `${WEBHOOK_BASE_URL.replace(/\/$/, '')}/api/google-forms/health`;
  const response = UrlFetchApp.fetch(url, {
    method: 'get',
    headers: {
      'X-CPF-Forms-Secret': WEBHOOK_SECRET,
    },
    muteHttpExceptions: true,
  });

  Logger.log(`Health HTTP ${response.getResponseCode()}: ${response.getContentText()}`);
}

/**
 * 2. One-click auto-generator: creates the official CPF Google Form and attaches the submit trigger.
 */
function createCpfMembershipForm() {
  const form = FormApp.create('CPF Membership Application Form — CHMSU Alijis Campus');
  form.setDescription(
    'Official Circle of Peer Facilitators (CPF) Membership Application. ' +
      'Your response helps us respect and recognize your identity. All information is kept confidential.'
  );
  form.setCollectEmail(true);
  form.setAllowResponseEdits(false);

  form.addTextItem().setTitle('First Name').setRequired(true);
  form.addTextItem().setTitle('Middle Name / M.I.').setRequired(false);
  form.addTextItem().setTitle('Last Name').setRequired(true);
  form.addTextItem().setTitle('Course / Program').setHelpText('Example: BSIT, BS Psychology, BSED').setRequired(true);

  const yearItem = form.addListItem().setTitle('Year Level').setRequired(true);
  yearItem.setChoiceValues(['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year']);

  form.addTextItem().setTitle('Section').setHelpText('Example: A, B, C').setRequired(true);

  const sexItem = form.addMultipleChoiceItem().setTitle('Sex (for legal/medical records)').setRequired(true);
  sexItem.setChoiceValues(['Male', 'Female']);

  const genderItem = form.addMultipleChoiceItem().setTitle('Gender Identity (how you identify yourself)').setRequired(false);
  genderItem.setChoiceValues(['Man', 'Woman', 'Non-binary', 'Prefer not to say']);
  genderItem.showOtherOption(true);

  form.addDateItem().setTitle('Birthdate').setRequired(true);
  form.addTextItem().setTitle('Active Email Address').setRequired(true);
  form
    .addTextItem()
    .setTitle('Facebook Profile Link')
    .setHelpText('Paste your full profile URL, e.g. https://www.facebook.com/your.username')
    .setRequired(true);
  form.addTextItem().setTitle('Active FB Account Name / Photo Note').setRequired(false);
  form.addTextItem().setTitle('Active Cellphone No.').setRequired(true);

  // Remove existing duplicate triggers pointing to onFormSubmit on this form
  ScriptApp.getProjectTriggers().forEach((trigger) => {
    if (trigger.getHandlerFunction() === 'onFormSubmit') {
      ScriptApp.deleteTrigger(trigger);
    }
  });

  ScriptApp.newTrigger('onFormSubmit').forForm(form).onFormSubmit().create();

  Logger.log('=== CPF GOOGLE FORM CREATED & TRIGGER BOUND ===');
  Logger.log('Edit Form URL: ' + form.getEditUrl());
  Logger.log('Shareable Student Form URL: ' + form.getPublishedUrl());
}

/**
 * 3. Send a sample test application directly (POST /api/google-forms/membership)
 */
function sendSampleTestApplication() {
  const payload = {
    campus_code: CAMPUS_CODE,
    recorded_email: 'sample.alijis.student@gmail.com',
    first_name: 'Maria Clara',
    middle_name: 'D.',
    last_name: 'Santos',
    course: 'BSIT',
    year_level: '3rd Year',
    section: 'A',
    sex: 'Female',
    gender_identity: 'Woman',
    birthdate: '2005-04-18',
    active_email: 'sample.alijis.student@gmail.com',
    facebook_profile_url: 'https://www.facebook.com/maria.clara.santos.cpf',
    active_fb_account: 'Maria Clara Santos (CHMSU Alijis)',
    cellphone_number: '0917-555-0188',
  };

  postToCpfWebhook_(payload);
}

/**
 * 4. Trigger handler: runs automatically whenever a student submits the Google Form
 *    Supports both direct Google Form submit triggers AND linked Google Sheet triggers.
 */
function onFormSubmit(e) {
  if (!e) {
    throw new Error('onFormSubmit must be invoked by a Google Form submit trigger.');
  }

  const answers = {};
  let recordedEmail = '';

  if (e.response && typeof e.response.getItemResponses === 'function') {
    recordedEmail = (e.response.getRespondentEmail && e.response.getRespondentEmail()) || '';
    e.response.getItemResponses().forEach((itemResponse) => {
      const title = itemResponse.getItem().getTitle().trim().toLowerCase();
      answers[title] = String(itemResponse.getResponse() || '').trim();
    });
  } else if (e.namedValues) {
    Object.keys(e.namedValues).forEach((key) => {
      const normalizedKey = key.trim().toLowerCase();
      const val = Array.isArray(e.namedValues[key]) ? e.namedValues[key][0] : e.namedValues[key];
      answers[normalizedKey] = String(val || '').trim();
    });
    recordedEmail = answers['email address'] || answers['email'] || '';
  }

  const pick = (...candidates) => {
    for (const candidate of candidates) {
      if (answers[candidate]) {
        return answers[candidate];
      }
      const fuzzyKey = Object.keys(answers).find((k) => k.includes(candidate));
      if (fuzzyKey && answers[fuzzyKey]) {
        return answers[fuzzyKey];
      }
    }
    return '';
  };

  const activeEmail = pick('active email address', 'active email', 'email address') || recordedEmail;

  const payload = {
    campus_code: CAMPUS_CODE,
    recorded_email: recordedEmail || activeEmail,
    first_name: pick('first name'),
    middle_name: pick('middle name / m.i.', 'middle name'),
    last_name: pick('last name'),
    course: pick('course / program', 'course'),
    year_level: pick('year level'),
    section: pick('section'),
    sex: pick('sex (for legal/medical records)', 'sex'),
    gender_identity: pick('gender identity (how you identify yourself)', 'gender identity'),
    birthdate: pick('birthdate'),
    active_email: activeEmail,
    facebook_profile_url: pick('facebook profile link', 'facebook'),
    active_fb_account: pick('active fb account name / photo note', 'active fb account'),
    cellphone_number: pick('active cellphone no.', 'cellphone'),
  };

  postToCpfWebhook_(payload);
}

function postToCpfWebhook_(payload) {
  const url = `${WEBHOOK_BASE_URL.replace(/\/$/, '')}/api/google-forms/membership`;
  const response = UrlFetchApp.fetch(url, {
    method: 'post',
    contentType: 'application/json',
    headers: {
      'X-CPF-Forms-Secret': WEBHOOK_SECRET,
    },
    payload: JSON.stringify(payload),
    muteHttpExceptions: true,
  });

  const code = response.getResponseCode();
  const body = response.getContentText();
  Logger.log(`Webhook HTTP ${code}: ${body}`);

  if (code < 200 || code >= 300) {
    throw new Error(`CPF Webhook returned HTTP ${code}: ${body}`);
  }
}
