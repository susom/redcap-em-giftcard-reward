// Reservation E2E: a coordinator ticks the payment checkbox on the SPARCLE master_list form and
// saves. That save is the only thing that fires redcap_save_record, which is where the whole
// gift-card reservation happens -- so it is the only honest way to reproduce the reported bug.
//
//   docker exec <web> php .../scripts/e2e-giftcard-fixture.php 265 setup
//   node e2e/reserve-reward.js
//   docker exec <web> php .../scripts/e2e-giftcard-fixture.php 265 report
//   docker exec <web> php .../scripts/e2e-giftcard-fixture.php 265 teardown
//
// Requires playwright resolvable by node (NODE_PATH=... if it is not a local dependency) and a
// MailHog listening on the web container's smarthost, so REDCap::email() can actually succeed --
// reserveReward() only writes status=Reserved when the verification email went out.
//
// REDCap builds absolute asset URLs from its CONFIGURED base URL (http://redcap.local/), so the
// browser has to be on that hostname. Resolve it in-browser rather than requiring /etc/hosts.
const { chromium, devices } = require('playwright');
const fs = require('fs');

const HOST_RULES = process.env.GCR_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const BASE    = process.env.GCR_BASE    || 'http://redcap.local';
const MAILHOG = process.env.GCR_MAILHOG || 'http://127.0.0.1:8025';
const USER    = process.env.GCR_USER    || 'e2e_gcr_tester';
const PASS    = process.env.GCR_PASS    || 'E2eGiftCard!2026';
const PID     = process.env.GCR_PID     || '265';
const RECORD  = process.env.GCR_RECORD  || '90001';
const EVENT   = process.env.GCR_EVENT   || '1058';
const RCVER   = process.env.GCR_RCVER   || 'redcap_v17.2.3';
// The fixture prints EMAIL= on setup; pass it in so the mail assertion targets this run's message.
const EMAIL   = process.env.GCR_EMAIL   || '';

const FORM_URL = `${BASE}/${RCVER}/DataEntry/index.php?pid=${PID}&id=${RECORD}&event_id=${EVENT}&page=master_list`;

const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

async function newCtx(browser, mobile = false) {
  const ctx = await browser.newContext(
    mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } },
  );
  const p = await ctx.newPage();
  p._errs = [];
  p.on('pageerror', e => p._errs.push(String(e.message).slice(0, 160)));
  return { ctx, p };
}

async function login(p) {
  await p.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' });
  const u = p.locator('input[name="username"]').first();
  if (!(await u.count())) return true;               // already signed in
  await u.fill(USER);
  await p.locator('input[name="password"]').first().fill(PASS);
  await p.locator('#login_btn').first().click();
  await p.waitForLoadState('domcontentloaded');
  await p.waitForTimeout(1500);
  return !(await p.locator('input[name="password"]').count());
}

// Everything MailHog is holding, newest first.
async function mail() {
  const r = await fetch(`${MAILHOG}/api/v2/messages?limit=50`);
  if (!r.ok) return [];
  const j = await r.json();
  return (j.items || []).map(m => ({
    to: (m.To || []).map(t => `${t.Mailbox}@${t.Domain}`).join(','),
    subject: decodeMime((m.Content?.Headers?.Subject || [''])[0]),
    body: m.Content?.Body || '',
  }));
}

// REDCap sends the subject RFC 2047 encoded ("=?us-ascii?Q?SPARCLE_Study:_Amazon_eGift...?="), in
// several chunks. Matching /gift ?card/ against the raw header silently never hits.
function decodeMime(s) {
  return String(s)
    .replace(/\?=\s+=\?[^?]+\?[QqBb]\?/g, '')
    .replace(/=\?[^?]+\?[Qq]\?([^?]*)\?=/g, (_, t) => t.replace(/_/g, ' ').replace(/=([0-9A-Fa-f]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16))))
    .replace(/=\?[^?]+\?[Bb]\?([^?]*)\?=/g, (_, t) => Buffer.from(t, 'base64').toString('utf8'));
}

(async () => {
  console.log(`\nGift Card Reward — reservation E2E\n  ${FORM_URL}\n`);

  const before = (await mail()).length;

  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const { ctx, p } = await newCtx(browser);

  check('R1  signed in as the throwaway coordinator', await login(p));

  await p.goto(FORM_URL, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1200);

  // REDCap's checkbox inputs are NOT named after the field. The visible box is
  // `__chkn__<field>` with id `id-__chk__<field>_RC_<code>`, and the value actually posted lives in
  // a sibling hidden `__chk__<field>_RC_<code>`. A `[name="remote_screen_payment___1"]` selector --
  // the export/import spelling -- matches nothing on the page.
  const box = p.locator('#id-__chk__remote_screen_payment_RC_1').first();
  check('R2  the master_list form and its payment checkbox are reachable', await box.count() > 0);
  await p.screenshot({ path: `${SHOTS}/01-form-before-save.png`, fullPage: false });

  await box.check();
  check('R3  "Award $25 remote screen gift card" is ticked', await box.isChecked());

  const save = p.locator('#submit-btn-saverecord, button[name="submit-btn-saverecord"]').first();
  check('R4  the save button is present', await save.count() > 0);
  await save.click();
  await p.waitForLoadState('domcontentloaded');
  await p.waitForTimeout(4000);

  const bodyText = await p.locator('body').innerText();
  check('R5  REDCap reported the record was saved', /successfully|saved/i.test(bodyText));
  await p.screenshot({ path: `${SHOTS}/02-after-save.png`, fullPage: false });

  // Back to the form: read back what the module wrote into the project record.
  await p.goto(FORM_URL, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1200);
  const gcId = await p.locator('input[name="remote_screen_gift_card_id"]').first().inputValue().catch(() => '');
  const gcSt = await p.locator('input[name="remote_screen_gift_card_status"]').first().inputValue().catch(() => '');
  check('R6  a library card was reserved into the project record', gcId !== '', `id="${gcId}" status="${gcSt}"`);
  check('R7  the project status field reads Reserved', gcSt === 'Reserved', `got "${gcSt}"`);
  await p.screenshot({ path: `${SHOTS}/03-form-after-save.png`, fullPage: false });

  // The verification email is the half the customer said WAS working.
  const after = await mail();
  const fresh = after.slice(0, Math.max(0, after.length - before));
  // Match on the recipient, not the subject: the seeded address is unique per run, while the
  // subject is study copy that the team can reword at any time.
  const gift = fresh.find(m => m.to === EMAIL) || fresh.find(m => /gift ?card/i.test(m.subject));
  check('R8  a verification email left REDCap', !!gift, gift ? `to ${gift.to} — "${gift.subject}"` : `${fresh.length} new message(s)`);
  if (gift) {
    const body = gift.body.replace(/=\r?\n/g, '');
    const direct = /egift\.activationspot\.com|https?:\/\/[^\s"'<>]*activationspot/i.test(body);
    const modLink = /ExternalModules[^\s"'<>]*DisplayReward|reward_token=/i.test(body);
    check('R9  the email carries the gift card link', direct || modLink,
      direct ? 'direct vendor URL (no module claim page)' : 'module DisplayReward claim link');
    console.log(`\n      link style: ${direct ? 'DIRECT vendor URL' : modLink ? 'module claim link' : 'neither'}\n`);
    fs.writeFileSync(`${SHOTS}/verification-email.html`, gift.body);
  }

  if (p._errs.length) console.log('  page errors:', p._errs.slice(0, 5));

  await ctx.close();
  await browser.close();

  console.log(`\n  ${pass} passed, ${fail} failed`);
  console.log(`  screenshots in ${SHOTS}\n`);
  console.log('  Now run the fixture in "report" mode to see what landed in the LIBRARY row.\n');
  process.exit(fail ? 1 : 0);
})();
