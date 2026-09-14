// Claim-page E2E: the other half of the matrix.
//
// When the library's egift_number is a bare redemption code rather than a URL, the module mints its
// own claim link, mails that, and the participant lands on DisplayReward.php -- where the reward is
// shown, the card is marked Claimed, and the participant can ask for the codes by email. That last
// step is the ONLY place reward_email_addr used to be written, so it is the path a fix to that field
// must not regress.
//
//   docker exec <web> php .../scripts/e2e-giftcard-fixture.php 265 setup plain
//   GCR_EMAIL=<from setup> node e2e/claim-page.js
//   docker exec <web> php .../scripts/e2e-giftcard-fixture.php 265 verify plain
//   docker exec <web> php .../scripts/e2e-giftcard-fixture.php 265 teardown
//
// The claim page is a public, no-auth page a participant opens on a phone from their inbox, so it is
// checked at iPhone 13 width as well as desktop.
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
const EMAIL   = process.env.GCR_EMAIL   || '';
const CODE    = process.env.GCR_CODE    || 'E2E-PLAIN-CODE-9137';

const FORM_URL = `${BASE}/${RCVER}/DataEntry/index.php?pid=${PID}&id=${RECORD}&event_id=${EVENT}&page=master_list`;
const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

function decodeMime(s) {
  return String(s)
    .replace(/\?=\s+=\?[^?]+\?[QqBb]\?/g, '')
    .replace(/=\?[^?]+\?[Qq]\?([^?]*)\?=/g, (_, t) => t.replace(/_/g, ' ').replace(/=([0-9A-Fa-f]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16))))
    .replace(/=\?[^?]+\?[Bb]\?([^?]*)\?=/g, (_, t) => Buffer.from(t, 'base64').toString('utf8'));
}

async function mail() {
  const r = await fetch(`${MAILHOG}/api/v2/messages?limit=50`);
  if (!r.ok) return [];
  const j = await r.json();
  return (j.items || []).map(m => ({
    to: (m.To || []).map(t => `${t.Mailbox}@${t.Domain}`).join(','),
    subject: decodeMime((m.Content?.Headers?.Subject || [''])[0]),
    // quoted-printable soft line breaks split the claim URL across lines; unfold before matching
    body: String(m.Content?.Body || '').replace(/=\r?\n/g, ''),
  }));
}

async function newPage(browser, mobile) {
  const ctx = await browser.newContext(
    mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } },
  );
  const p = await ctx.newPage();
  p._errs = [];
  p.on('pageerror', e => p._errs.push(String(e.message).slice(0, 160)));
  return { ctx, p };
}

(async () => {
  console.log('\nGift Card Reward — claim-page E2E (plain redemption code)\n');
  const before = (await mail()).length;

  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });

  // ---- coordinator awards the card
  const { ctx, p } = await newPage(browser, false);
  await p.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' });
  if (await p.locator('input[name="username"]').count()) {
    await p.locator('input[name="username"]').first().fill(USER);
    await p.locator('input[name="password"]').first().fill(PASS);
    await p.locator('#login_btn').first().click();
    await p.waitForLoadState('domcontentloaded');
  }
  check('C1  signed in as the throwaway coordinator', !(await p.locator('input[name="password"]').count()));

  await p.goto(FORM_URL, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1000);
  await p.locator('#id-__chk__remote_screen_payment_RC_1').first().check();
  await p.locator('#submit-btn-saverecord').first().click();
  await p.waitForLoadState('domcontentloaded');
  await p.waitForTimeout(4000);
  check('C2  the record saved', /successfully|saved/i.test(await p.locator('body').innerText()));
  await ctx.close();

  // ---- the participant's verification email
  const after = await mail();
  const fresh = after.slice(0, Math.max(0, after.length - before));
  const gift = fresh.find(m => m.to === EMAIL) || fresh.find(m => /gift ?card/i.test(m.subject));
  check('C3  a verification email left REDCap', !!gift, gift ? `to ${gift.to}` : `${fresh.length} new message(s)`);
  if (!gift) { await browser.close(); console.log(`\n  ${pass} passed, ${fail} failed\n`); process.exit(1); }

  const m = gift.body.match(/https?:\/\/[^\s"'<>]*reward_token=[A-Za-z0-9]+/);
  check('C4  it carries a module claim link, not a vendor URL', !!m, m ? m[0].slice(-46) : 'no reward_token= link found');
  if (!m) { await browser.close(); console.log(`\n  ${pass} passed, ${fail} failed\n`); process.exit(1); }
  const claimUrl = m[0];

  // ---- the participant opens it, on a phone, signed in to nothing
  const { ctx: mctx, p: mp } = await newPage(browser, true);
  await mp.goto(claimUrl, { waitUntil: 'domcontentloaded' });
  await mp.waitForTimeout(1500);
  const mtext = await mp.locator('body').innerText();
  check('C5  the claim page renders the reward without a login', mtext.includes(CODE), `code ${CODE}`);
  check('C6  it did not fall through to the "problem locating your reward" state', !/problem locating/i.test(mtext));

  // Mobile pickiness: a page a participant opens from their phone must not scroll sideways, and the
  // send button must be a real tap target.
  const overflow = await mp.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('C7  no horizontal overflow at iPhone 13 width', overflow <= 1, `${overflow}px`);
  const btn = mp.locator('#button');
  const bb = (await btn.count()) ? await btn.boundingBox() : null;
  check('C8  the "send by email" control is present and tappable', !!bb && bb.height >= 24, bb ? `${Math.round(bb.width)}×${Math.round(bb.height)}px` : 'missing');
  await mp.screenshot({ path: `${SHOTS}/10-claim-page-mobile.png`, fullPage: true });

  // ---- and asks for the codes by email
  //
  // Read the PREFILLED value first. The page echoes the address from the project record into a
  // value attribute, now through $module->escape(); htmlspecialchars must round-trip an ordinary
  // address unchanged rather than leave entities visible in the box.
  const prefilled = await mp.locator('#emailAddress').inputValue();
  check('C8b the prefilled address is the record\'s, not HTML-entity-encoded', prefilled === EMAIL,
    `got "${prefilled}"`);

  const typed = EMAIL.replace('@', '+claimed@');
  await mp.locator('#emailAddress').fill(typed);
  await btn.click();
  await mp.waitForTimeout(3500);
  const sentShown = await mp.locator('#sent').isVisible().catch(() => false);
  check('C9  the page confirms the email was sent', sentShown);
  await mp.screenshot({ path: `${SHOTS}/11-claim-page-after-send.png`, fullPage: true });

  const after2 = await mail();
  const reward = after2.find(x => x.to === typed);
  check('C10 the reward email reached the address the participant typed', !!reward, typed);
  if (reward) check('C11 it contains the redemption code', reward.body.includes(CODE));

  if (mp._errs.length) console.log('  page errors:', mp._errs.slice(0, 5));
  await mctx.close();

  // ---- desktop rendering of the same page
  const { ctx: dctx, p: dp } = await newPage(browser, false);
  await dp.goto(claimUrl, { waitUntil: 'domcontentloaded' });
  await dp.waitForTimeout(1200);
  check('C12 the claim page still renders on desktop', (await dp.locator('body').innerText()).includes(CODE));
  await dp.screenshot({ path: `${SHOTS}/12-claim-page-desktop.png`, fullPage: true });
  await dctx.close();

  await browser.close();
  console.log(`\n  ${pass} passed, ${fail} failed`);
  console.log(`  screenshots in ${SHOTS}\n`);
  process.exit(fail ? 1 : 0);
})();
