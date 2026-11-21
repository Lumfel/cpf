/**
 * CPF Membership Google Forms -> Laravel API Connector
 *
 * Attach this script to your CPF Membership Google Form (Extensions -> Apps Script)
 * and set an "On form submit" installable trigger pointing to `onFormSubmit`.
 *
 * Script Properties required (Project Settings -> Script Properties):
 *   CPF_API_URL          e.g. https://cpf.example.edu.ph/api/google-forms/membership
 *   CPF_WEBHOOK_SECRET   Matches GOOGLE_FORMS_WEBHOOK_SECRET in your CPF .env file
 */
function onFormSubmit(e) {
  const props = PropertiesService.getScriptProperties();
  const endpointUrl = props.getProperty('CPF_API_URL') || 'http://127.0.0.1:8000/api/google-forms/membership';
  const webhookSecret = props.getProperty('CPF_WEBHOOK_SECRET') || 'cpf-local-forms-secret';

  const named = (e && e.namedValues) ? e.namedValues : {};
  const firstAnswer = (keys) => {
    for (let i = 0; i < keys.length; i++) {
      const value = named[keys[i]];
      if (value && value.length > 0 && String(value[0]).trim() !== '') {
        return String(value[0]).trim();
      }
    }
    return '';
  };

  const payload = {
    campus_code: firstAnswer(['Campus', 'Campus Code']) || 'TALISAY',
    first_name: firstAnswer(['First Name']),
    middle_name: firstAnswer(['Middle Name', 'Middle Initial']) || null,
    last_name: firstAnswer(['Last Name']),
    course: firstAnswer(['Course', 'Program']),
    year_level: firstAnswer(['Year Level', 'Year']),
    section: firstAnswer(['Section']),
    sex: firstAnswer(['Sex']),
    gender_identity: firstAnswer(['Gender Identity']) || 'Prefer not to say',
    gender_identity_other: firstAnswer(['Gender Identity (Other)']) || null,
    birthdate: firstAnswer(['Birthdate', 'Date of Birth']),
    active_email: firstAnswer(['Active Email', 'Email Address']),
    recorded_email: (e && e.response && typeof e.response.getRespondentEmail === 'function')
      ? (e.response.getRespondentEmail() || firstAnswer(['Email Address', 'Active Email']))
      : firstAnswer(['Email Address', 'Active Email']),
    facebook_profile_url: firstAnswer(['Facebook Profile Link', 'Facebook Profile URL', 'Facebook Link']),
    active_fb_account: firstAnswer(['Facebook Account Name / Description', 'Active FB Account']) || null,
    cellphone_number: firstAnswer(['Cellphone Number', 'Mobile Number', 'Contact Number']),
  };

  const response = UrlFetchApp.fetch(endpointUrl, {
    method: 'post',
    contentType: 'application/json',
    headers: {
      'Accept': 'application/json',
      'X-CPF-Forms-Secret': webhookSecret,
    },
    payload: JSON.stringify(payload),
    muteHttpExceptions: true,
  });

  const code = response.getResponseCode();
  const body = response.getContentText();
  Logger.log('CPF Webhook Status: ' + code + ' Body: ' + body);

  if (code < 200 || code >= 300) {
    throw new Error('Failed to sync CPF Google Form response (' + code + '): ' + body);
  }
}
