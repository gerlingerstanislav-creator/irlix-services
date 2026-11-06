// Real Chromium wheel/trackpad regression for a synthetic Specialists profile.
// Auth, employee names, history and projects are generated locally; no live API.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
  const browser = await chromium.launch({
    headless: true, executablePath: process.env.CHROMIUM_EXECUTABLE,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  });
  try {
    const page = await browser.newPage({ viewport: { width: 1366, height: 600 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const base = process.env.SPECIALISTS_PREVIEW || 'http://127.0.0.1:5197';
    const issuer = base + '/keycloak/auth/realms/irlix';
    const employee = {
      id: 11, full_name: 'Демо Специалист', position: 'Инженер',
      department_name: 'Демо-направление', employment_status: 'Работает',
      grade: 'Middle', technologies: [{ id: 1, name: 'JavaScript', kind: 'primary', grade: 'Middle' }],
    };
    const mainTechnologies = Array.from({ length: 22 }, (_, i) => ({
      id: i + 1, name: 'Технология ' + String(i + 1).padStart(2, '0'), active: true,
    }));
    const additional = Array.from({ length: 28 }, (_, i) => ({ id: i + 1, name: 'Дополнительная ' + (i + 1), active: true }));
    const professional = {
      technologies: Array.from({ length: 12 }, (_, i) => ({
        id: i + 1, name: mainTechnologies[i].name, kind: 'primary', grade: 'Middle',
      })),
      additional_technologies: additional.slice(0, 19),
    };
    const history = Array.from({ length: 48 }, (_, i) => ({
      id: 'event-' + (i + 1), type: 'professional', title: 'Демо-событие ' + (i + 1),
      text: 'Изменение компетенции, подтверждённое руководителем ' + (i + 1),
      created_at: new Date(Date.UTC(2026, 0, 1 + i)).toISOString(),
    }));
    const projects = Array.from({ length: 45 }, (_, i) => ({
      project_id: i + 1, project_name: 'Демо-проект ' + (i + 1), client_name: 'Демо-клиент',
      valid_from: '2026-01-01', valid_to: null,
    }));
    await page.addInitScript(iss => sessionStorage.setItem('irlix.platform.auth.tokens', JSON.stringify({
      access_token: 'e30.' + btoa(JSON.stringify({ iss, exp: Math.floor(Date.now() / 1000) + 3600, name: 'Synthetic User' })) + '.synthetic',
    })), issuer);
    await page.route('**/.well-known/openid-configuration', route => route.fulfill({
      json: { issuer, authorization_endpoint: issuer + '/auth', token_endpoint: issuer + '/token' },
    }));
    await page.route('**/api/**', route => {
      const path = new URL(route.request().url()).pathname;
      let data;
      if (path.endsWith('/employees/access/me')) data = { services: { specialists: true }, roles: ['platform-admin'] };
      else if (path.endsWith('/specialists/permissions')) data = {
        profiles_view: true, profiles_manage: true, catalog_view: true, catalog_manage: true,
      };
      else if (path.endsWith('/specialists/role-permissions')) data = { can_manage: false };
      else if (path.endsWith('/specialists/workspace')) data = {
        people: [employee], departments: [], actor: { id: 1 }, scope: 'all', managed_department_ids: [],
        catalog: { technologies: mainTechnologies, additional_technologies: additional, competencies: [] },
      };
      else if (path.endsWith('/specialists/people/11')) data = { employee, professional };
      else if (path.endsWith('/specialists/people/11/timeline')) data = history;
      else if (path.endsWith('/employees/specialists-history/11')) data = history.map(e => ({ ...e, id: 'employees-' + e.id }));
      else if (path.endsWith('/clients/specialists/people/11/history')) data = history.map(e => ({ ...e, id: 'clients-' + e.id }));
      else if (path.endsWith('/clients/specialists/people/11/projects')) data = projects;
      else data = {};
      return route.fulfill({ json: { data } });
    });

    await page.goto(base + '/specialists/people/11');
    const scroll = page.locator('.specialist-profile-scroll');
    const root = page.locator('.irlix-drawer-body');
    const identity = page.locator('.specialists-profile-identity');
    const tabs = page.locator('.specialist-profile-tabs');
    await scroll.waitFor({ state: 'visible' });
    await page.waitForFunction(() => document.querySelectorAll('.irlix-history__event').length >= 48);

    async function geometry() {
      return page.evaluate(() => {
        const box = selector => {
          const r = document.querySelector(selector).getBoundingClientRect();
          return { top: r.top, bottom: r.bottom, height: r.height };
        };
        const area = document.querySelector('.specialist-profile-scroll');
        const outer = document.querySelector('.irlix-drawer-body');
        return {
          identity: box('.specialists-profile-identity'),
          tabs: box('.specialist-profile-tabs'),
          area: box('.specialist-profile-scroll'),
          scrollTop: area.scrollTop,
          scrollHeight: area.scrollHeight,
          clientHeight: area.clientHeight,
          outerScrollTop: outer.scrollTop,
          outerOverflow: getComputedStyle(outer).overflowY,
          feedOverflow: getComputedStyle(document.querySelector('.irlix-history__feed')).overflowY,
        };
      });
    }

    const historyBefore = await geometry();
    assert.equal(historyBefore.outerOverflow, 'hidden', 'outer drawer must not own scrolling');
    assert.equal(historyBefore.feedOverflow, 'visible', 'history feed must not intercept the wheel');
    assert.ok(historyBefore.scrollHeight > historyBefore.clientHeight + 50, 'history needs real overflow');
    assert.ok(historyBefore.area.top >= historyBefore.tabs.bottom - 1, 'scroll begins below tabs');
    assert.ok(historyBefore.tabs.top > historyBefore.identity.top, 'identity remains above tabs');
    // The wheel targets a timeline event, not the scrollbar (as on a trackpad).
    const event = page.locator('.irlix-history__event').first();
    await event.scrollIntoViewIfNeeded();
    const firstEventBox = await event.boundingBox();
    await page.mouse.move(firstEventBox.x + 30, firstEventBox.y + 20);
    for (let i = 0; i < 7; i++) await page.mouse.wheel(0, 33); // small trackpad-like deltas
    await page.waitForFunction(() => document.querySelector('.specialist-profile-scroll')?.scrollTop > 30);
    const historyAfter = await geometry();
    assert.ok(historyAfter.scrollTop > historyBefore.scrollTop, 'trackpad wheel must advance history scroll');
    assert.ok(Math.abs(historyAfter.identity.top - historyBefore.identity.top) < 2, 'identity does not move');
    assert.ok(Math.abs(historyAfter.tabs.top - historyBefore.tabs.top) < 2, 'tabs do not move');
    assert.equal(historyAfter.outerScrollTop, 0, 'outer drawer stays at top');

    await page.getByRole('tab', { name: 'О специалисте' }).click();
    await page.waitForFunction(() => document.querySelectorAll('.specialists-projects > div').length === 45);
    await page.waitForFunction(() => document.querySelector('.specialist-profile-scroll').scrollTop === 0);
    const aboutBefore = await page.evaluate(() => ({
      scrollTop: document.querySelector('.specialist-profile-scroll').scrollTop,
      scrollHeight: document.querySelector('.specialist-profile-scroll').scrollHeight,
      clientHeight: document.querySelector('.specialist-profile-scroll').clientHeight,
      identityY: document.querySelector('.specialists-profile-identity').getBoundingClientRect().top,
      tabsY: document.querySelector('.specialist-profile-tabs').getBoundingClientRect().top,
    }));
    assert.ok(aboutBefore.scrollHeight > aboutBefore.clientHeight + 50, 'about content overflows');
    const primary = page.locator('.specialists-profile-section').first();
    const primaryBox = await primary.boundingBox();
    await page.mouse.move(primaryBox.x + 40, primaryBox.y + 50);
    await page.mouse.wheel(0, 300);
    await page.waitForFunction(() => document.querySelector('.specialist-profile-scroll').scrollTop > 50);
    const aboutAfter = await page.evaluate(() => ({
      scrollTop: document.querySelector('.specialist-profile-scroll').scrollTop,
      identityY: document.querySelector('.specialists-profile-identity').getBoundingClientRect().top,
      tabsY: document.querySelector('.specialist-profile-tabs').getBoundingClientRect().top,
    }));
    assert.ok(aboutAfter.scrollTop > 50, 'mouse wheel scrolls about content');
    assert.ok(Math.abs(aboutAfter.identityY - aboutBefore.identityY) < 2);
    assert.ok(Math.abs(aboutAfter.tabsY - aboutBefore.tabsY) < 2);

    // Short mobile viewport must keep identity/tabs fixed while touchpad deltas work.
    await page.setViewportSize({ width: 390, height: 580 });
    const mobileBefore = await page.evaluate(() => ({
      tabsY: document.querySelector('.specialist-profile-tabs').getBoundingClientRect().top,
      identityY: document.querySelector('.specialists-profile-identity').getBoundingClientRect().top,
    }));
    await scroll.evaluate(el => { el.scrollTop = 0; });
    const mobileScroll = await scroll.boundingBox();
    await page.mouse.move(mobileScroll.x + 40, mobileScroll.y + 50);
    for (let i = 0; i < 5; i++) await page.mouse.wheel(0, 26);
    await page.waitForFunction(() => document.querySelector('.specialist-profile-scroll').scrollTop > 30);
    const mobileAfter = await page.evaluate(() => ({
      tabsY: document.querySelector('.specialist-profile-tabs').getBoundingClientRect().top,
      identityY: document.querySelector('.specialists-profile-identity').getBoundingClientRect().top,
    }));
    assert.ok(Math.abs(mobileAfter.tabsY - mobileBefore.tabsY) < 2);
    assert.ok(Math.abs(mobileAfter.identityY - mobileBefore.identityY) < 2);
    assert.deepEqual(errors, [], 'browser must not report runtime errors');
    console.log('Specialists drawer scroll PASS: fixed identity/tabs, independent overflow, trackpad wheel on history and about, desktop and mobile');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
