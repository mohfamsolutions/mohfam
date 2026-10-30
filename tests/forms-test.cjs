// Simulated form DOM and fetch: never opens a browser or makes a network request.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('twentytwentyfive.child_.theme_.-.jaimemasc.com_/assets/js/forms.js', 'utf8');

async function scenario({ submitResponse, sessionOk = true, repeat = false, turnstile = false, optedIn = false }) {
  const status = { textContent: '', className: '' };
  const button = { textContent: 'Submit Inquiry', disabled: false, setAttribute() {}, removeAttribute() {} };
  let handler, resets = 0;
  const typed = { name: 'Taylor Example', email: 'visitor@example.com', message: 'Keep these typed details.', ...(optedIn ? { marketing_opt_in: '1' } : {}) };
  const original = { ...typed };
  const requests = [];
  const form = {
    querySelector(selector) {
      if (selector === '.mohfam-form-status') return status;
      if (selector === '[type="submit"]') return button;
      return null;
    },
    reportValidity: () => true,
    addEventListener(event, callback) { handler = callback; },
    reset() { resets++; Object.keys(typed).forEach(key => { typed[key] = ''; }); },
  };
  class FormData {
    constructor() { this.fields = { ...typed, form_kind: 'inquiry', website: '' }; }
    append(key, value) { this.fields[key] = value; }
  }
  const fetch = async (url, options) => {
    requests.push(options);
    if (options.body instanceof URLSearchParams) {
      return { ok: sessionOk, json: async () => sessionOk
        ? { success: true, data: { nonce: 'fresh-nonce', form_token: 'signed-start-token' } }
        : { success: false, data: { message: 'unavailable' } } };
    }
    if (submitResponse instanceof Error) throw submitResponse;
    return submitResponse;
  };
  vm.runInNewContext(source, {
    document: { addEventListener(event, callback) { callback(); }, querySelectorAll: () => [form] },
    window: { location: { href: 'https://mohfam.co/contact/#inquiry' } },
    mohfamForms: { ajaxUrl: '/wp-admin/admin-ajax.php', turnstileEnabled: turnstile },
    FormData, URLSearchParams, fetch,
  });
  await handler({ preventDefault() {} });
  if (repeat) await handler({ preventDefault() {} });
  assert.equal(button.disabled, false);
  assert.equal(button.textContent, 'Submit Inquiry');
  const submits = requests.filter(request => request.body instanceof FormData);
  for (const request of submits) {
    assert.equal(request.body.fields.nonce, 'fresh-nonce');
    assert.equal(request.body.fields.form_token, 'signed-start-token');
    assert.equal(request.body.fields.action, 'mohfam_submit_form');
    assert.equal(request.body.fields.page_url, 'https://mohfam.co/contact/#inquiry');
    assert.equal(request.credentials, 'same-origin');
  }
  return { status, resets, typed, original, submits };
}

(async () => {
  const success = await scenario({ submitResponse: { ok: true, json: async () => ({ success: true, data: { message: 'Inquiry sent.' } }) } });
  assert.equal(success.resets, 1);
  assert.equal(success.status.className, 'mohfam-form-status is-success');
  const failures = [
    { ok: false, json: async () => ({ success: false, data: { message: 'We could not send your inquiry. Please call +1 (818) 818-1181.' } }) },
    { ok: true, json: async () => ({ success: false }) },
    { ok: false, json: async () => { throw new SyntaxError('Unexpected token <'); } },
    new TypeError('Failed to fetch'),
  ];
  for (const submitResponse of failures) {
    const result = await scenario({ submitResponse, repeat: true });
    assert.equal(result.resets, 0);
    assert.deepEqual(result.typed, result.original);
    assert.equal(result.status.className, 'mohfam-form-status is-error');
    assert.match(result.status.textContent, /\+1 \(818\) 818-1181/);
    assert.equal(result.submits.length, 2, 'A retry remains possible without discarding fields');
  }
  const unavailable = await scenario({ sessionOk: false });
  assert.equal(unavailable.submits.length, 0);
  assert.equal(unavailable.resets, 0);
  assert.deepEqual(unavailable.typed, unavailable.original);
  assert.match(unavailable.status.textContent, /818/);
  const captcha = await scenario({ turnstile: true });
  assert.equal(captcha.submits.length, 0);
  assert.equal(captcha.resets, 0);
  assert.match(captcha.status.textContent, /818/);
  const opted = await scenario({ optedIn: true, submitResponse: { ok: false, json: async () => ({ success: false }) } });
  assert.equal(opted.submits[0].body.fields.marketing_opt_in, '1');
  assert.deepEqual(opted.typed, opted.original, 'Opt-in selection remains on error');
  assert.equal(success.submits[0].body.fields.marketing_opt_in, undefined, 'Unchecked form omits opt-in');
  for (const template of ['front-page.html', 'page-contact.html']) {
    const html = fs.readFileSync(`twentytwentyfive.child_.theme_.-.jaimemasc.com_/templates/${template}`, 'utf8');
    const input = html.match(/<input\b[^>]*name="marketing_opt_in"[^>]*>/g);
    assert.equal(input?.length, 1, `${template}: one checkbox`);
    assert.match(input[0], /type="checkbox"/);
    assert.doesNotMatch(input[0], /\s(?:checked|required)(?:\s|=|>)/, 'Optional and unchecked by default');
    const id = input[0].match(/\bid="([^"]+)"/)[1];
    assert.ok(html.includes(`for="${id}"`), 'Accessible explicit label');
    assert.ok(html.indexOf(input[0]) > html.indexOf('</textarea>'), 'Opt-in follows Project Details');
    assert.ok(html.indexOf(input[0]) < html.indexOf('<div class="mohfam-turnstile"'), 'Opt-in precedes security verification');
    assert.ok(html.includes('Send me MohFam updates and offers (optional)'));
  }
  console.log('Passed 8 offline form scenarios and accessible, unchecked opt-in checks on both templates.');
})().catch(error => { console.error(error); process.exitCode = 1; });
