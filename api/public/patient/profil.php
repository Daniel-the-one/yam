<?php
/**
 * KondjiPro — Espace patient : Mon profil
 * Fiche patient modifiable : nom, téléphone, date de naissance, adresse, sexe,
 * groupe sanguin, allergies, assurance, contact d'urgence, bio, photo profil.
 */
$page_title = 'Mon profil';
require_once __DIR__ . '/_init.php';

// S'assurer que le schéma patient est à jour (colonnes profil)
require_once __DIR__ . '/../api/patient.php';
ensure_patient_schema();

$pdo = db_connect();
if (!$pdo || !$patient) {
    // Mode démo : on affiche quand même le formulaire
}

// Recharger la fiche patient avec les nouvelles colonnes
if ($patientId > 0 && $pdo) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM patients WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $patientId]);
        $patient = $stmt->fetch();
    } catch (Exception $e) {}
}

include __DIR__ . '/../includes/header.php';

// Valeurs du formulaire
$prenom_nom  = $patient['nom'] ?? '';
$telephone   = $patient['telephone'] ?? '';
$dob         = $patient['date_naissance'] ?? '';
$adresse     = $patient['adresse'] ?? '';
$sexe        = $patient['sexe'] ?? '';
$groupe_sang = $patient['groupe_sanguin'] ?? '';
$allergies   = $patient['allergies'] ?? '';
$assurance   = $patient['assurance'] ?? '';
$contact_urg = $patient['contact_urgence'] ?? '';
$bio_patient = $patient['bio'] ?? '';
$photo_profil = $patient['photo_profil'] ?? null;
$has_photo = ($photo_profil !== null && $photo_profil !== '');
?>

<div class="page-head">
  <div>
    <h1>Mon profil</h1>
    <div class="sub">Gérez vos informations personnelles et médicales.</div>
  </div>
</div>

<!-- ── Photo profil + infos de base ── -->
<div class="card" style="padding:0; overflow:hidden; margin-bottom:20px;">
  <div style="height:80px; background:linear-gradient(135deg, var(--accent-2) 0%, #047857 100%);"></div>
  <div style="padding:0 24px 24px; margin-top:-40px;">
    <div style="display:flex; align-items:flex-end; gap:16px; margin-bottom:20px;">
      <div id="photo-preview" style="width:80px; height:80px; border-radius:50%; border:3px solid #fff;
           box-shadow:0 2px 12px rgba(0,0,0,.15); overflow:hidden; flex-shrink:0;
           display:flex; align-items:center; justify-content:center; background:rgba(16,185,129,.14); color:var(--accent-2);
           font-weight:700; font-size:28px; cursor:pointer; position:relative;"
           title="Cliquer pour changer la photo">
        <?php if ($has_photo): ?>
          <img src="<?= htmlspecialchars($photo_profil) ?>" alt="Photo"
               style="width:100%; height:100%; object-fit:cover;">
        <?php else: ?>
          <?php
            $init = '';
            foreach (preg_split('/\s+/', $prenom_nom) as $w) {
                $init .= mb_strtoupper(mb_substr($w, 0, 1));
                if (mb_strlen($init) >= 2) break;
            }
            if ($init === '') $init = '??';
          ?>
          <span><?= htmlspecialchars($init) ?></span>
        <?php endif; ?>
        <input type="file" id="photo-input" accept="image/*" hidden>
      </div>
      <div>
        <div style="font-weight:700; font-size:18px;"><?= htmlspecialchars($prenom_nom ?: 'Patient') ?></div>
        <div class="meta" style="color:var(--muted);">
          <?php if ($groupe_sang !== ''): ?>
            <span style="background:rgba(239,68,68,.1); color:#dc2626; padding:1px 8px; border-radius:8px; font-weight:600; font-size:12px;">
              <?= htmlspecialchars($groupe_sang) ?>
            </span>
          <?php endif; ?>
          <?php if ($assurance !== ''): ?>
            <span style="margin-left:6px;"><i class="bi bi-shield-check"></i> <?= htmlspecialchars($assurance) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Formulaire de modification ── -->
<form id="form-profil" class="card" style="padding:20px;">

  <!-- Section : Informations personnelles -->
  <h3 style="margin-bottom:16px; font-size:16px;">
    <i class="bi bi-person"></i> Informations personnelles
  </h3>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:16px;">
    <div class="form-row">
      <label for="p-nom">Nom complet</label>
      <input id="p-nom" name="nom" class="input" value="<?= htmlspecialchars($prenom_nom) ?>" required>
    </div>
    <div class="form-row">
      <label for="p-tel">Téléphone</label>
      <input id="p-tel" name="telephone" class="input" type="tel" value="<?= htmlspecialchars($telephone) ?>" required>
    </div>
    <div class="form-row">
      <label for="p-dob">Date de naissance</label>
      <input id="p-dob" name="date_naissance" class="input" type="date" value="<?= htmlspecialchars($dob) ?>">
    </div>
    <div class="form-row">
      <label for="p-sexe">Sexe</label>
      <select id="p-sexe" name="sexe" class="input">
        <option value="">— Non précisé —</option>
        <option value="M" <?= $sexe === 'M' ? 'selected' : '' ?>>Masculin</option>
        <option value="F" <?= $sexe === 'F' ? 'selected' : '' ?>>Féminin</option>
      </select>
    </div>
    <div class="form-row" style="grid-column:1/-1;">
      <label for="p-adr">Adresse</label>
      <input id="p-adr" name="adresse" class="input" value="<?= htmlspecialchars($adresse) ?>" placeholder="Ville, quartier...">
    </div>
  </div>

  <!-- Section : Informations médicales -->
  <h3 style="margin:24px 0 16px; font-size:16px;">
    <i class="bi bi-heart-pulse"></i> Informations médicales
  </h3>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:16px;">
    <div class="form-row">
      <label for="p-gs">Groupe sanguin</label>
      <select id="p-gs" name="groupe_sanguin" class="input">
        <option value="">— Non précisé —</option>
        <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $gs): ?>
          <option value="<?= $gs ?>" <?= $groupe_sang === $gs ? 'selected' : '' ?>><?= $gs ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row" style="grid-column:1/-1;">
      <label for="p-allergies">Allergies connues</label>
      <textarea id="p-allergies" name="allergies" class="input" rows="2"
                placeholder="Ex : Pénicilline, arachides,latex…"><?= htmlspecialchars($allergies) ?></textarea>
    </div>
  </div>

  <!-- Section : Assurance & urgence -->
  <h3 style="margin:24px 0 16px; font-size:16px;">
    <i class="bi bi-shield-check"></i> Assurance & contact d'urgence
  </h3>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:16px;">
    <div class="form-row">
      <label for="p-assurance">Assurance maladie</label>
      <input id="p-assurance" name="assurance" class="input" value="<?= htmlspecialchars($assurance) ?>"
             placeholder="Ex : CNAM, NSIA, SNNI…">
    </div>
    <div class="form-row">
      <label for="p-cu">Contact d'urgence</label>
      <input id="p-cu" name="contact_urgence" class="input" value="<?= htmlspecialchars($contact_urg) ?>"
             placeholder="Nom + Téléphone" type="text">
    </div>
  </div>

  <!-- Section : Bio / À propos -->
  <h3 style="margin:24px 0 16px; font-size:16px;">
    <i class="bi bi-chat-dots"></i> À propos de vous
  </h3>

  <div class="form-row">
    <label for="p-bio">Notes personnelles (visibles uniquement par vous)</label>
    <textarea id="p-bio" name="bio" class="input" rows="3"
              placeholder=" informations utiles pour votre médecin…"><?= htmlspecialchars($bio_patient) ?></textarea>
  </div>

  <!-- Boutons -->
  <div style="display:flex; gap:12px; margin-top:24px; padding-top:16px; border-top:1px solid var(--line);">
    <button type="submit" class="btn btn--primary" id="btn-save">
      <i class="bi bi-check-lg"></i> Enregistrer
    </button>
    <button type="button" class="btn btn--ghost" onclick="window.location.reload();">
      <i class="bi bi-arrow-counterclockwise"></i> Annuler
    </button>
  </div>

  <div id="form-msg" style="margin-top:12px; display:none;"></div>
</form>

<!-- ── JS : soumission AJAX + upload photo ── -->
<script>
(function(){
  var form = document.getElementById('form-profil');
  var msg  = document.getElementById('form-msg');
  var photoInput = document.getElementById('photo-input');
  var photoPreview = document.getElementById('photo-preview');
  var photoData = null;
  var photoExt  = null;

  // Clic sur la photo → ouvrir le sélecteur de fichier
  photoPreview.addEventListener('click', function(){ photoInput.click(); });

  // Changement de fichier → convertir en base64
  photoInput.addEventListener('change', function(){
    var file = this.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { alert('Photo trop volumineuse (max 5 Mo).'); return; }
    var reader = new FileReader();
    reader.onload = function(e){
      var dataUrl = e.target.result;
      // Afficher l'aperçu
      photoPreview.innerHTML = '<img src="' + dataUrl + '" alt="Photo" style="width:100%; height:100%; object-fit:cover;">';
      // Extraire base64 et extension
      var parts = dataUrl.split(',');
      photoData = parts[1];
      photoExt = file.name.split('.').pop().toLowerCase();
    };
    reader.readAsDataURL(file);
  });

  // Soumission
  form.addEventListener('submit', function(e){
    e.preventDefault();
    var btn = document.getElementById('btn-save');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Enregistrement…';
    msg.style.display = 'none';

    var body = new FormData(form);
    body.append('patient_id', '<?= (int)$patientId ?>');
    if (photoData) {
      body.append('photo_data', photoData);
      body.append('photo_ext', photoExt);
    }

    fetch('/api/patient_save', { method: 'POST', body: body })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.ok) {
          msg.innerHTML = '<span style="color:#047857;"><i class="bi bi-check-circle-fill"></i> ' + (data.message || 'Profil mis à jour.') + '</span>';
          msg.style.display = '';
          // Recharger après 1.5s pour afficher la nouvelle photo
          setTimeout(function(){ window.location.reload(); }, 1500);
        } else {
          msg.innerHTML = '<span style="color:#dc2626;"><i class="bi bi-exclamation-circle-fill"></i> ' + (data.message || 'Erreur.') + '</span>';
          msg.style.display = '';
        }
      })
      .catch(function(){
        msg.innerHTML = '<span style="color:#dc2626;"><i class="bi bi-exclamation-circle-fill"></i> Erreur réseau.</span>';
        msg.style.display = '';
      })
      .finally(function(){
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Enregistrer';
      });
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
