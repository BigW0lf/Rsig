<?php // Archivé — ancienne page Mise à jour BDD ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSig — Mise à jour BDD (archivé)</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<nav>
    <span class="nav-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="white" stroke-width="1.5"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10A15.3 15.3 0 0 1 8 12 15.3 15.3 0 0 1 12 2z" stroke="white" stroke-width="1.5"/></svg>
        RSig
    </span>
    <a href="/">Carte</a>
    <a href="/donnees">Données</a>
    <a href="/requetes" class="active">Requêtes</a>
    <a href="/crm">CRM</a>
    <span class="nav-spacer"></span>
    <a href="https://localhost:8443/nifi" target="_blank" class="nifi-link">NiFi&nbsp;<span class="dot dot-pending"></span></a>
</nav>

<div class="page-content">
    <h1>Mise à jour de la base de données</h1>

    <div class="status-bar">
        <span>PostgreSQL :
            <?php if ($connected): ?>
            <span class="badge badge-ok">Connectée</span>
            <?php else: ?>
            <span class="badge badge-warn">Non disponible</span>
            <?php endif; ?>
        </span>
        <span>NiFi : <span id="nifi-badge" class="badge badge-pending">…</span>
            <a href="https://localhost:8443/nifi" target="_blank" class="btn-sm">Ouvrir NiFi</a>
        </span>
    </div>

    <?php if (!$connected): ?>
    <div class="alert alert-warn">La base PostgreSQL n'est pas encore disponible.</div>
    <?php endif; ?>

    <section class="card">
        <h2>Import CSV via NiFi</h2>
        <p>Dépose un fichier CSV dans le dossier d'entrée NiFi ou déclenche un pipeline existant.</p>
        <div class="form-row">
            <input type="file" id="csv-file" accept=".csv" <?= !$connected ? 'disabled' : '' ?>>
            <button id="btn-upload-csv" class="btn" <?= !$connected ? 'disabled' : '' ?>>Envoyer vers NiFi</button>
        </div>
        <div id="upload-status" class="alert" style="display:none"></div>
    </section>

    <section class="card">
        <h2>Exécuter une requête SQL</h2>
        <p class="hint">Uniquement INSERT / UPDATE / CREATE TABLE. DROP et DELETE sont bloqués.</p>
        <textarea id="sql-query" rows="6" placeholder="INSERT INTO ma_table (col) VALUES ('val');" <?= !$connected ? 'disabled' : '' ?>></textarea>
        <div class="form-row">
            <button id="btn-run-sql" class="btn" <?= !$connected ? 'disabled' : '' ?>>Exécuter</button>
        </div>
        <div id="sql-result" class="alert" style="display:none"></div>
    </section>

    <section class="card">
        <h2>Lien rapide NiFi</h2>
        <a href="https://localhost:8443/nifi" target="_blank" class="btn">Ouvrir Apache NiFi</a>
    </section>
</div>

<script>
fetch('/api/nifi/status').then(r=>r.json()).then(d=>{
    const b=document.getElementById('nifi-badge');
    b.textContent=d.status==='up'?'En ligne':'Hors ligne';
    b.className='badge '+(d.status==='up'?'badge-ok':'badge-warn');
}).catch(()=>{const b=document.getElementById('nifi-badge');b.textContent='Hors ligne';b.className='badge badge-warn';});

document.getElementById('btn-run-sql')?.addEventListener('click',()=>{
    const sql=document.getElementById('sql-query').value.trim();
    if(!sql)return;
    const res=document.getElementById('sql-result');
    res.style.display='block';res.className='alert alert-info';res.textContent='Exécution…';
    fetch('/api/sql',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({sql})})
    .then(r=>r.json()).then(d=>{res.className='alert '+(d.error?'alert-error':'alert-ok');res.textContent=d.error?'Erreur : '+d.error:d.message;})
    .catch(()=>{res.className='alert alert-error';res.textContent='Erreur serveur.';});
});

document.getElementById('btn-upload-csv')?.addEventListener('click',()=>{
    const file=document.getElementById('csv-file').files[0];
    if(!file){alert('Sélectionne un fichier CSV.');return;}
    const status=document.getElementById('upload-status');
    status.style.display='block';status.className='alert alert-info';status.textContent='Envoi…';
    const fd=new FormData();fd.append('file',file);
    fetch('/api/upload-csv',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        status.className='alert '+(d.error?'alert-error':'alert-ok');
        status.textContent=d.error?'Erreur : '+d.error:d.message;
    }).catch(()=>{status.className='alert alert-error';status.textContent='Erreur.';});
});
</script>
</body>
</html>
