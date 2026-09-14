// Control Center page E2E: "Gift Cards: Backfill Reward Email Address".
//
//   docker exec <web> php .../scripts/e2e-backfill-fixture.php setup
//   node e2e/backfill-page.js
//   docker exec <web> php .../scripts/e2e-backfill-fixture.php teardown
//
// Covers the overview, the orphaned-reward_pid panel (the restored-copy tell), the per-project
// scan, an actual Apply, and that a non-admin cannot reach the page at all — a Control Center link
// is hidden from non-admins by REDCap, and hiding a link is not access control.
const { chromium, devices } = require('playwright');
const fs = require('fs');

const HOST_RULES = process.env.GCR_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const BASE   = process.env.GCR_BASE  || 'http://redcap.local';
const RCVER  = process.env.GCR_RCVER || 'redcap_v17.2.3';
const ADMIN  = process.env.GCR_ADMIN_USER || 'e2e_gcr_admin';
const ADMINP = process.env.GCR_ADMIN_PASS || 'E2eGcAdmin!2026';
// A second admin, used only for the forged-CSRF case — see the comment at that block.
const ADMIN2  = process.env.GCR_ADMIN2_USER || 'e2e_gcr_admin2';
const ADMIN2P = process.env.GCR_ADMIN2_PASS || 'E2eGcAdmin2!2026';
const USER   = process.env.GCR_USER  || 'e2e_gcr_tester';
const PASS   = process.env.GCR_PASS  || 'E2eGiftCard!2026';
const PID    = process.env.GCR_PID   || '265';
const REWARD_PID = process.env.GCR_REWARD_PID || '33646';

const PAGE = `${BASE}/${RCVER}/ExternalModules/?prefix=giftcard_reward&page=pages%2FBackfillEmailAddr`;
const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

async function newPage(browser, mobile = false) {
  const ctx = await browser.newContext(
    mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } });
  const p = await ctx.newPage();
  p._errs = [];
  p.on('pageerror', e => p._errs.push(String(e.message).slice(0, 160)));
  return { ctx, p };
}

async function login(p, u, pw) {
  await p.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' });
  if (await p.locator('input[name="username"]').count()) {
    await p.locator('input[name="username"]').first().fill(u);
    await p.locator('input[name="password"]').first().fill(pw);
    await p.locator('#login_btn').first().click();
    await p.waitForLoadState('domcontentloaded');
  }
  return !(await p.locator('input[name="password"]').count());
}

(async () => {
  console.log('\nGift Card Reward — Control Center backfill page\n');
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });

  // ---- a non-admin must be refused, not merely un-linked
  {
    const { ctx, p } = await newPage(browser);
    await login(p, USER, PASS);
    const r = await p.goto(PAGE, { waitUntil: 'domcontentloaded' });
    const body = await p.locator('body').innerText();
    // Two layers can refuse, and in practice REDCap's own control-center gate gets there first with
    // "You do not have permission to access this page." (HTTP 200 — it renders an error page rather
    // than setting a status). The page's isSuperUser() guard sits behind it as the backstop; accept
    // either, because which one fires is REDCap's business, and assert separately that no data leaks.
    check('B1  a non-admin is refused the page',
      /do not have permission|restricted to REDCap administrators/i.test(body),
      `HTTP ${r.status()} — "${body.trim().slice(0, 60)}"`);
    check('B2  and sees no project data', !/Projects with this module enabled/i.test(body));
    await ctx.close();
  }

  // ---- admin: the overview
  const { ctx, p } = await newPage(browser);
  check('B3  signed in as the throwaway admin', await login(p, ADMIN, ADMINP));

  await p.goto(PAGE, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(800);
  let body = await p.locator('body').innerText();
  check('B4  the overview lists projects with the module enabled', /Projects with this module enabled/i.test(body));
  check('B5  it reaches this project and its library', /\b265\b/.test(body) && /\b266\b/.test(body));
  check('B6  a stale library pid is named as missing, not as a missing field',
    /Library project 31624 does not exist/i.test(body));
  check('B7  the restored-copy panel calls out the orphaned reward_pid',
    /not on this server/i.test(body) && new RegExp(`\\b${REWARD_PID}\\b`).test(body));
  await p.screenshot({ path: `${SHOTS}/20-backfill-overview.png`, fullPage: true });

  // ---- admin: the per-project scan
  await p.goto(`${PAGE}&gc_pid=${PID}`, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(600);
  body = await p.locator('body').innerText();
  check('B8  the scan view offers the reward_pid actually on the rows',
    new RegExp(`\\b${REWARD_PID}\\b`).test(body) && /not a project here/i.test(body));
  await p.screenshot({ path: `${SHOTS}/21-backfill-scan-default.png`, fullPage: true });

  await p.goto(`${PAGE}&gc_pid=${PID}&reward_pid=${REWARD_PID}`, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(600);
  body = await p.locator('body').innerText();
  check('B9  selecting it produces the same plan as the CLI (69 / 92 / 23)',
    /69<?\s*<?\/?strong>?\s*address|69\s*address/i.test(body) || body.includes('69'),
    body.match(/(\d+)\s*address\(es\) and\s*(\d+)\s*url\(s\)[\s\S]{0,40}?(\d+)\s*skipped/)?.[0]?.replace(/\s+/g, ' '));
  const summary = body.match(/(\d+)\s*address\(es\) and\s*(\d+)\s*url\(s\) would be filled,\s*(\d+)\s*skipped/);
  check('B10 the plan numbers match exactly', !!summary && summary[1] === '69' && summary[2] === '92' && summary[3] === '23',
    summary ? `${summary[1]}/${summary[2]}/${summary[3]}` : 'summary line not found');
  check('B11 the addresses and the skip reasons are both shown',
    /Addresses to be written/i.test(body) && /Skipped \(23\)/i.test(body));
  await p.screenshot({ path: `${SHOTS}/22-backfill-scan-selected.png`, fullPage: true });

  // ---- CSRF
  //
  // The page mints its own nonce because neither surrounding layer protects this write: core
  // unsets `redcap_csrf_token` before module code runs, and a forged
  // `redcap_external_module_csrf_token` was measured to be accepted by the framework on 17.2.3.
  // This is therefore the only control standing between a cross-site POST and a data write, which
  // is exactly why it gets a test rather than a comment.
  //
  // As a SECOND admin, in its own context. Reusing the main account breaks the run two ways:
  // signing the same user in again invalidates the first session, and a failed
  // forceCsrfTokenCheck() poisons the session it happened in, so the next legitimate POST is
  // refused too. Both make the real Apply below fail for reasons unrelated to the code.
  {
    const { ctx: cctx, p: cp } = await newPage(browser);
    cp.on('dialog', d => d.accept());
    await login(cp, ADMIN2, ADMIN2P);
    await cp.goto(`${PAGE}&gc_pid=${PID}&reward_pid=${REWARD_PID}`, { waitUntil: 'domcontentloaded' });
    await cp.waitForTimeout(600);
    check('B11b there is a plan to submit, so the forgery is a real attempt',
      await cp.locator('button[type=submit]').count() > 0);
    // Forge `gcb_token`, the page's own nonce, leaving the framework's token valid so this
    // exercises the control that actually protects the write. The other two fields are the wrong
    // target: core unsets `redcap_csrf_token` from $_POST before module code runs, and a forged
    // `redcap_external_module_csrf_token` was measured to be ACCEPTED on 17.2.3.
    await cp.evaluate(() => {
      const forge = () => document.querySelectorAll('input[name="gcb_token"]')
        .forEach(i => { i.value = 'forged-0000'; });
      forge();
      document.querySelectorAll('form').forEach(f => f.addEventListener('submit', forge, true));
    });
    await cp.locator('button[type=submit]').first().click();
    await cp.waitForLoadState('domcontentloaded');
    await cp.waitForTimeout(1500);
    const forged = await cp.locator('body').innerText();
    check('B11c a forged page nonce is refused and writes nothing',
      !/Wrote\s*\d+\s*library record/.test(forged) && /session token was not valid/i.test(forged),
      (forged.match(/Your session token was not valid[^\n]*/) || ['no refusal notice shown'])[0].slice(0, 80));
    await cctx.close();
  }

  // ---- apply
  p.on('dialog', d => d.accept());
  await p.locator('button[type=submit]').first().click();
  await p.waitForLoadState('domcontentloaded');
  await p.waitForTimeout(2500);
  body = await p.locator('body').innerText();
  const wrote = body.match(/Wrote\s*(\d+)\s*library record/);
  // 92, not 69: saveData returns record ids, and every url row is in the union of rows touched.
  // A bare `> 0` would let a regression that writes only the addresses through.
  check('B12 Apply wrote all 92 touched rows', !!wrote && wrote[1] === '92',
    wrote ? `${wrote[1]} records` : 'no confirmation :: ' + body.split('\n').map(x=>x.trim()).filter(Boolean).filter(l=>/Multiple tabs|expired|permission|Wrote|would be filled|backfill/i.test(l)).slice(0,3).join(' | ').slice(0,200));
  const after = body.match(/(\d+)\s*address\(es\) and\s*(\d+)\s*url\(s\) would be filled,\s*(\d+)\s*skipped/);
  check('B13 re-scanning after the write finds nothing left to fill',
    !!after && after[1] === '0' && after[2] === '0',
    after ? `${after[1]}/${after[2]}/${after[3]}` : 'summary line not found');
  await p.screenshot({ path: `${SHOTS}/23-backfill-applied.png`, fullPage: true });

  if (p._errs.length) console.log('  page errors:', p._errs.slice(0, 5));
  await ctx.close();

  // ---- mobile rendering of the overview
  const { ctx: mctx, p: mp } = await newPage(browser, true);
  await login(mp, ADMIN, ADMINP);
  await mp.goto(PAGE, { waitUntil: 'domcontentloaded' });
  await mp.waitForTimeout(800);
  const overflow = await mp.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('B14 the overview does not scroll sideways on a phone', overflow <= 1, `${overflow}px`);
  await mp.screenshot({ path: `${SHOTS}/24-backfill-overview-mobile.png`, fullPage: true });
  await mctx.close();

  await browser.close();
  console.log(`\n  ${pass} passed, ${fail} failed`);
  console.log(`  screenshots in ${SHOTS}\n`);
  process.exit(fail ? 1 : 0);
})();
