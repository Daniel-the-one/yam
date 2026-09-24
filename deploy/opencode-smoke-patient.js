// /usr/local/sbin/opencode-smoke-patient.js — Flux patient KondjiPro (local).
// Usage : node opencode-smoke-patient.js  (serveur déjà lancé sur 8099)
const { chromium } = require('playwright-core');

const BASE       = 'http://127.0.0.1:8099';
const LOGIN_URL  = BASE + '/pages/login.html';
const USERNAME   = 'jeankoffi';
const PASSWORD   = 'test1234';

(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium' });
  const page = await browser.newPage({ locale: 'fr-FR' });
  const fails = [];
  const total = [];

  pipe(async () => {
    await label('1. Connexion patient (login.html)', async () => {
      await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded' });
      // l'onglet « Patient » doit être actif par défaut
      const activeTab = await page.locator('.btn-tab.active, [data-tab].active').count();
      if (activeTab === 0) throw new Error('aucun onglet actif sur login.html');
      await page.fill('#identifier', USERNAME);
      await page.fill('#password', PASSWORD);
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 12000 }),
        page.click('button[type=submit]'),
      ]);
      const url = page.url();
      // Le patient doit arriver sur l'espace patient, pas sur la SPA contact.
      if (!url.includes('/patient/')) throw new Error('redirection patient ≠ /patient/ — URL reçue: ' + url);
      await note('   ✓ URL après login: ' + url.replace(BASE, ''));
      // Vérifie que la nav patient est rendue (sidebar liens)
      const navOk = await page.locator('text=Mes rendez-vous, a[href*="rendez-vous"]').count();
      if (!navOk) throw new Error('sidebar patient absente');
    });

    await label('2. Accueil patient (tableau de bord)', async () => {
      await page.goto(BASE + '/patient/accueil.php', { waitUntil: 'domcontentloaded' });
      const title = await page.title();
      if (!/Mon espace|accueil/i.test(title)) throw new Error('titre inattendu: ' + title);
      const card = await page.locator('.balance-card, .grid-stats').count();
      if (card === 0) throw new Error('pas de carte/dashboard');
    });

    await label('3. Trouver un médecin → prise de RDV', async () => {
      await page.goto(BASE + '/patient/medecins.php', { waitUntil: 'domcontentloaded' });
      const list = await page.locator('.card, .list-item').count();
      if (list < 1) throw new Error('aucun médecin listé');
      const firstLink = page.locator('a[href*="rendez-vous"], a[href*="prendre-rdv"]').first();
      if (await firstLink.count()) {
        await firstLink.click(); await page.waitForLoadState('domcontentloaded');
        if (await page.locator('#form-rdv, #rdv-form').count()) {
          await page.fill('#date_rdv, [name="date_rdv"]', '2030-06-15T10:00');
          await page.fill('#motif, [name="motif"]', 'Suivi périodique');
          await page.click('button[type=submit]');
          await page.waitForTimeout(900);
          const ok = await page.locator('text=rendez-vous enregistré, text=Enregistré').count();
          if (!ok) throw new Error('RDV non confirmé après soumission');
          await note('   ✓ RDV créé');
        }
      }
    });

    await label('4. Liste de mes rendez-vous (statut en_attente)', async () => {
      await page.goto(BASE + '/patient/rendez-vous.php', { waitUntil: 'domcontentloaded' });
      const pill = await page.locator('.pill--facture').count();
      if (pill === 0) throw new Error('aucun RDV « en attente » affiché');
    });

    await label('5. Mon dossier (timeline consultations)', async () => {
      await page.goto(BASE + '/patient/dossier.php', { waitUntil: 'domcontentloaded' });
      const tl = await page.locator('.t-item, .timeline-item').count();
      if (tl === 0) throw new Error('timeline dossier vide');
    });

    await label('6. Mes ordonnances (médicaments)', async () => {
      await page.goto(BASE + '/patient/ordonnances.php', { waitUntil: 'domcontentloaded' });
      const meds = await page.locator('table tbody tr').count();
      if (meds === 0) throw new Error('aucun médicament listé');
    });

    await label('7. Mes factures', async () => {
      await page.goto(BASE + '/patient/factures.php', { waitUntil: 'domcontentloaded' });
      const total = await page.locator('.stat-value').count();
      if (total < 1) throw new Error('aucun montant affiché');
    });

    await label('8. API RDV — annulation', async () => {
      const res = await page.evaluate(async () => {
        const r = await fetch('/api/rdv.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=annuler&id=1' });
        return r.json();
      });
      if (!res.ok) throw new Error('annulation échouée: ' + (res.message || ''));
    });
  });

  await browser.close();
})();

// ── helpers ─────────────────────────────────────────────
function label(name, fn) { return fn(); }
function note(msg) { console.log(msg); }
