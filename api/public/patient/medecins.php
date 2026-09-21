<?php
/**
 * KondjiPro — Espace patient : Trouver un médecin
 * Recherche par nom OU par numéro de téléphone,
 * fiche enrichie (photo, spécialité), appel direct, prise de RDV.
 */
$page_title = 'Trouver un médecin';
require_once __DIR__ . '/_init.php';

$pdo = db_connect();
$medecins = [];
if ($pdo) {
    try {
        // Migration idempotente : colonnes bio, horaires, accepte_rdv
        require_once __DIR__ . '/../api/patient.php';
        ensure_patient_schema();
        $medecins = $pdo->query(
            "SELECT id, prenom, nom, telephone, specialite, photo, bio, horaires, accepte_rdv
             FROM medecins ORDER BY prenom, nom"
        )->fetchAll();
    } catch (Exception $e) {}
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Trouver un médecin</h1>
    <div class="sub">Recherchez par nom ou par numéro de téléphone, consultez le profil, appelez directement.</div>
  </div>
</div>

<!-- ── Barre de recherche ── -->
<div class="card" style="margin-bottom:20px;">
  <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
    <div style="flex:1; min-width:200px; position:relative;">
      <i class="bi bi-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--muted);"></i>
      <input type="text" id="search-medecin" class="input" placeholder="Nom ou numéro de téléphone..."
             style="padding-left:38px;" autocomplete="off">
    </div>
    <button type="button" class="btn btn--ghost" id="btn-clear-search" style="display:none;">
      <i class="bi bi-x-circle"></i> Effacer
    </button>
  </div>
</div>

<!-- ── Résultat recherche par téléphone (caché par défaut) ── -->
<div id="phone-result" class="card" style="display:none; margin-bottom:20px; border:2px solid var(--accent);">
  <div id="phone-result-content"></div>
</div>

<!-- ── Liste des médecins ── -->
<div id="medecins-grid" class="grid" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));">
<?php if (!$medecins): ?>
  <div class="card" style="grid-column: 1 / -1;">
    <div class="empty" style="padding:40px;">
      <i class="bi bi-person-badge" style="font-size:34px; opacity:.4; display:block; margin-bottom:10px;"></i>
      Aucun médecin n'est encore enregistré dans le cabinet.
    </div>
  </div>
<?php else: foreach ($medecins as $m):
    $nom_complet = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
    if ($nom_complet === '') $nom_complet = 'Médecin';
    $init = '';
    foreach (preg_split('/\s+/', $nom_complet) as $w) {
        $init .= mb_strtoupper(mb_substr($w, 0, 1));
        if (mb_strlen($init) >= 2) break;
    }
    if ($init === '') $init = 'MD';

    $photo = $m['photo'] ?? null;
    // Normaliser : les anciens chemins relatifs → absolu
    if ($photo !== null && $photo !== '' && !str_starts_with($photo, '/')) {
        $photo = '/' . $photo;
    }
    $has_photo = ($photo !== null && $photo !== '');
    $bio_short = $m['bio'] ?? '';
    if (mb_strlen($bio_short) > 80) $bio_short = mb_substr($bio_short, 0, 80) . '…';
?>
  <div class="card medecin-card" data-search="<?= htmlspecialchars(strtolower($nom_complet . ' ' . ($m['telephone'] ?? '') . ' ' . ($m['specialite'] ?? ''))) ?>"
       style="display:flex; flex-direction:column; gap:12px; cursor:pointer; transition:box-shadow .2s;"
       onclick="window.location.href='/patient/medecin?id=<?= (int)$m['id'] ?>'">

    <!-- Photo + Nom -->
    <div style="display:flex; align-items:center; gap:14px;">
      <?php if ($has_photo): ?>
        <img src="<?= htmlspecialchars($photo) ?>" alt="" class="avatar-img"
             style="width:56px; height:56px; border-radius:50%; object-fit:cover;"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <span class="avatar-letters"
              style="display:none; width:56px; height:56px; border-radius:50%; align-items:center; justify-content:center;
                     background:rgba(16,185,129,.14); color:var(--accent-2); font-weight:700; font-size:18px; flex-shrink:0;">
          <?= htmlspecialchars($init) ?>
        </span>
      <?php else: ?>
        <span class="avatar-letters"
              style="width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center;
                     background:rgba(16,185,129,.14); color:var(--accent-2); font-weight:700; font-size:18px; flex-shrink:0;">
          <?= htmlspecialchars($init) ?>
        </span>
      <?php endif; ?>
      <div style="min-width:0;">
        <div style="font-weight:700; font-size:16px;"><?= htmlspecialchars($nom_complet) ?></div>
        <div class="meta" style="color:var(--muted);">
          <i class="bi bi-briefcase"></i> <?= htmlspecialchars($m['specialite'] ?? 'Médecin généraliste') ?>
        </div>
        <?php if ($bio_short !== ''): ?>
          <div class="meta" style="color:var(--muted); margin-top:2px; font-size:12px;">
            <?= htmlspecialchars($bio_short) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Téléphone -->
    <div class="kv" style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-top:1px solid var(--line);">
      <span style="color:var(--muted)"><i class="bi bi-telephone"></i> Téléphone</span>
      <strong style="font-size:14px;"><?= htmlspecialchars($m['telephone'] ?? '—') ?></strong>
    </div>

    <!-- Boutons d'action -->
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="btn btn--primary btn--sm" href="rendez-vous?medecin=<?= (int)$m['id'] ?>"
         onclick="event.stopPropagation();" style="flex:1; text-align:center;">
        <i class="bi bi-calendar-plus"></i> RDV
      </a>
      <?php if (!empty($m['telephone'])): ?>
        <a class="btn btn--ghost btn--sm" href="tel:<?= htmlspecialchars(preg_replace('/[^\d+]/', '', $m['telephone'])) ?>"
           onclick="event.stopPropagation();" style="flex:1; text-align:center;">
          <i class="bi bi-telephone-fill"></i> Appeler
        </a>
      <?php endif; ?>
      <a class="btn btn--ghost btn--sm" href="/patient/medecin?id=<?= (int)$m['id'] ?>"
         onclick="event.stopPropagation();" style="flex:1; text-align:center;">
        <i class="bi bi-person-lines-fill"></i> Profil
      </a>
    </div>
  </div>
<?php endforeach; endif; ?>
</div>

<!-- ── JS : recherche par nom + par téléphone ── -->
<script>
(function(){
  var input    = document.getElementById('search-medecin');
  var grid     = document.getElementById('medecins-grid');
  var cards    = grid ? grid.querySelectorAll('.medecin-card') : [];
  var btnClear = document.getElementById('btn-clear-search');
  var phoneBox = document.getElementById('phone-result');
  var phoneCnt = document.getElementById('phone-result-content');
  var debounce = null;

  // Recherche locale par nom/spécialité/téléphone
  input.addEventListener('input', function(){
    clearTimeout(debounce);
    var q = this.value.trim().toLowerCase();
    btnClear.style.display = q ? '' : 'none';
    phoneBox.style.display = 'none';

    // Si ça ressemble à un téléphone (chiffres, +), faire un appel API
    if (/^[\d+\s-]{6,}/.test(q.replace(/\s/g, ''))) {
      debounce = setTimeout(function(){ searchPhone(q); }, 400);
      cards.forEach(function(c){ c.style.display = 'none'; });
      return;
    }

    // Sinon : filtrage local par nom
    cards.forEach(function(c){
      var haystack = c.getAttribute('data-search') || '';
      c.style.display = (!q || haystack.indexOf(q) !== -1) ? '' : 'none';
    });
  });

  // Effacer
  btnClear.addEventListener('click', function(){
    input.value = '';
    btnClear.style.display = 'none';
    phoneBox.style.display = 'none';
    cards.forEach(function(c){ c.style.display = ''; });
  });

  // Recherche téléphone via API
  function searchPhone(q) {
    fetch('/api/medecins?phone=' + encodeURIComponent(q), {headers:{'Accept':'application/json'}})
      .then(function(r){ return r.json(); })
      .then(function(data){
        var results = data.results || [];
        if (results.length === 0) {
          phoneCnt.innerHTML = '<div style="padding:16px; text-align:center; color:var(--muted);"><i class="bi bi-search"></i> Aucun médecin trouvé pour ce numéro.</div>';
        } else {
          var html = '';
          results.forEach(function(m){
            var nom = ((m.prenom||'') + ' ' + (m.nom||'')).trim() || 'Médecin';
            var tel = m.telephone || '';
            var spec = m.specialite || 'Médecin généraliste';
            html += '<div style="display:flex; align-items:center; gap:14px; padding:14px 16px; border-bottom:1px solid var(--line);">';
            html += '  <div style="flex:1;">';
            html += '    <div style="font-weight:700; font-size:15px;">' + escHtml(nom) + '</div>';
            html += '    <div class="meta" style="color:var(--muted);"><i class="bi bi-briefcase"></i> ' + escHtml(spec) + '</div>';
            html += '    <div class="meta" style="color:var(--muted);"><i class="bi bi-telephone"></i> ' + escHtml(tel) + '</div>';
            html += '  </div>';
            html += '  <div style="display:flex; gap:6px; flex-shrink:0;">';
            if (tel) {
              html += '<a class="btn btn--ghost btn--sm" href="tel:' + escAttr(tel.replace(/[^\d+]/g,'')) + '"><i class="bi bi-telephone-fill"></i> Appeler</a>';
            }
            html += '    <a class="btn btn--primary btn--sm" href="/patient/medecin?id=' + m.id + '"><i class="bi bi-person-lines-fill"></i> Profil</a>';
            html += '  </div>';
            html += '</div>';
          });
          phoneCnt.innerHTML = html;
        }
        phoneBox.style.display = '';
      })
      .catch(function(){
        phoneCnt.innerHTML = '<div style="padding:16px; color:var(--muted); text-align:center;">Erreur de recherche.</div>';
        phoneBox.style.display = '';
      });
  }

  function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function escAttr(s) { return s.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
