<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSig — CRM</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .sync-block {
            display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 8px; padding: 14px 18px; margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        .sync-stat { font-size: 0.82rem; color: var(--text3); }
        .sync-stat strong { color: var(--text); font-size: 0.95rem; }
        .sync-sep { width: 1px; height: 28px; background: var(--border); }
        .progress-bar-wrap { background: var(--surface2); border-radius: 4px; height: 6px; width: 200px; overflow: hidden; display: none; }
        .progress-bar { height: 100%; background: var(--blue-light); border-radius: 4px; width: 0%; transition: width .3s; }

        .tab-bar { display: flex; gap: 4px; border-bottom: 2px solid var(--border); margin-bottom: 18px; }
        .tab { padding: 8px 16px; font-size: 0.85rem; font-weight: 600; cursor: pointer;
               color: var(--text3); border: none; background: none; border-bottom: 2px solid transparent;
               margin-bottom: -2px; transition: color .15s; }
        .tab.active { color: var(--blue); border-bottom-color: var(--blue); }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        .badge-count { background: var(--blue-hover); color: var(--blue); padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 700; margin-left: 6px; }
    </style>
    <script>
        const _P={carte:'/',donnees:'/donnees',requetes:'/requetes',crm:'/crm'};
        function navTo(p){window.parent?.showPage?window.parent.showPage(p):location.href=_P[p]||'/';}
        // Masque la nav immédiatement si on est dans un iframe (avant tout rendu)
        if(window.self!==window.top){document.write('<style>nav{display:none!important}body{padding-top:0!important}</style>');}
    </script>
</head>
<body>

<nav>
    <span class="nav-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="white" stroke-width="1.5"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10A15.3 15.3 0 0 1 8 12 15.3 15.3 0 0 1 12 2z" stroke="white" stroke-width="1.5"/></svg>
        RSig
    </span>
    <a href="#" onclick="navTo('carte');return false">Carte</a>
    <a href="#" onclick="navTo('donnees');return false">Données</a>
    <a href="#" onclick="navTo('requetes');return false">Requêtes</a>
    <a href="#" class="active">CRM</a>
</nav>

<div class="page-content" style="max-width:1200px">
    <h1>CRM Dynamics 365 — Miroir local</h1>

    <!-- ── Bloc sync ──────────────────────────────────────── -->
    <div class="sync-block">
        <div class="sync-stat">
            Sites en BDD<br><strong id="stat-sites">—</strong>
        </div>
        <div class="sync-sep"></div>
        <div class="sync-stat">
            Dossiers en BDD<br><strong id="stat-dossiers">—</strong>
        </div>
        <div class="sync-sep"></div>
        <div class="sync-stat">
            Dernière sync<br><strong id="stat-date">—</strong>
        </div>
        <div class="sync-sep"></div>
        <div class="sync-stat">
            Statut<br><span id="stat-status" class="badge badge-pending">—</span>
        </div>
        <span style="flex:1"></span>
        <div class="progress-bar-wrap" id="progress-wrap">
            <div class="progress-bar" id="progress-bar"></div>
        </div>
        <button class="btn" id="btn-sync">
            ↻ Synchroniser depuis Dynamics
        </button>
    </div>

    <div class="alert alert-info" id="sync-info" style="display:none"></div>
    <div class="alert alert-error" id="sync-error" style="display:none"></div>

    <!-- ── Onglets ────────────────────────────────────────── -->
    <div class="tab-bar">
        <button class="tab active" data-tab="dossiers">Dossiers <span class="badge-count" id="tab-count-d"></span></button>
        <button class="tab" data-tab="sites">Sites <span class="badge-count" id="tab-count-s"></span></button>
        <button class="tab" data-tab="odata">Requête OData directe</button>
    </div>

    <!-- ── Dossiers ───────────────────────────────────────── -->
    <div class="tab-panel active" id="tab-dossiers">
        <div class="card" style="padding:12px 14px; margin-bottom:12px">
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
                <input type="search" id="search-dossier" placeholder="Filtrer par numéro, référence, ville…"
                       style="max-width:320px; padding:7px 12px; border-radius:var(--radius); border:1px solid var(--border)">
                <button class="btn" id="load-dossiers">Charger</button>
            </div>
        </div>
        <div class="table-wrap" id="dossiers-table-wrap" style="display:none">
            <table id="dossiers-table">
                <thead><tr>
                    <th>Numéro</th><th>Client</th><th>Référence client</th><th>Ville</th>
                    <th>Adresse site</th><th>Code INSEE</th>
                    <th>Taxe foncière</th><th>Date demande</th><th>Date remise</th>
                </tr></thead>
                <tbody id="dossiers-body"></tbody>
            </table>
        </div>
        <p class="row-count" id="dossiers-count" style="display:none"></p>
        <div class="alert alert-info" id="dossiers-empty" style="display:none">Aucun dossier trouvé.</div>
    </div>

    <!-- ── Sites ──────────────────────────────────────────── -->
    <div class="tab-panel" id="tab-sites">
        <div class="card" style="padding:12px 14px; margin-bottom:12px">
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
                <input type="search" id="search-site" placeholder="Filtrer par adresse, ville, INSEE…"
                       style="max-width:320px; padding:7px 12px; border-radius:var(--radius); border:1px solid var(--border)">
                <button class="btn" id="load-sites">Charger</button>
            </div>
        </div>
        <div class="table-wrap" id="sites-table-wrap" style="display:none">
            <table id="sites-table">
                <thead><tr>
                    <th>Nom</th><th>Adresse</th><th>Ville</th><th>CP</th>
                    <th>INSEE</th><th>Section</th><th>Parcelle</th><th>Taxe foncière</th><th>Géocodé</th>
                </tr></thead>
                <tbody id="sites-body"></tbody>
            </table>
        </div>
        <p class="row-count" id="sites-count" style="display:none"></p>
        <div class="alert alert-info" id="sites-empty" style="display:none">Aucun site trouvé.</div>
    </div>

    <!-- ── OData direct ───────────────────────────────────── -->
    <div class="tab-panel" id="tab-odata">
        <div class="card">
            <h2>Requête OData directe (Dynamics 365)</h2>
            <p class="hint">Accès en lecture seule à l'API Dataverse. Résultat brut JSON.</p>
            <div style="margin-bottom:10px">
                <label style="font-size:12px;color:var(--text3);font-weight:600">Entité</label>
                <select id="odata-entity" style="max-width:200px;margin-left:8px">
                    <option value="apo_dossiers">apo_dossiers</option>
                    <option value="apo_sites">apo_sites</option>
                    <option value="apo_communes">apo_communes</option>
                </select>
            </div>
            <div style="margin-bottom:10px">
                <label style="font-size:12px;color:var(--text3);font-weight:600">Filtre OData ($filter)</label>
                <input type="text" id="odata-filter" placeholder="ex: apo_ville eq 'Paris'" style="margin-left:8px;max-width:400px">
            </div>
            <div style="margin-bottom:10px">
                <label style="font-size:12px;color:var(--text3);font-weight:600">Top ($top)</label>
                <input type="number" id="odata-top" value="10" min="1" max="100" style="max-width:80px;margin-left:8px">
            </div>
            <button class="btn" id="btn-odata">Exécuter</button>
        </div>
        <div class="card" id="odata-result-card" style="display:none">
            <h2>Résultat brut</h2>
            <pre id="odata-result" style="font-size:0.75rem;overflow-x:auto;max-height:500px;white-space:pre-wrap;word-break:break-all"></pre>
        </div>
        <div class="alert alert-error" id="odata-error" style="display:none"></div>
    </div>

</div>

<script>
// ── Onglets ───────────────────────────────────────────────
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.tab, .tab-panel').forEach(el => el.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
    });
});

// ── Statut sync ───────────────────────────────────────────
function loadSyncStatus() {
    fetch('/api/crm/sync/status').then(r=>r.json()).then(d=>{
        document.getElementById('stat-sites').textContent    = d.sites_in_db.toLocaleString('fr-FR');
        document.getElementById('stat-dossiers').textContent = d.dossiers_in_db.toLocaleString('fr-FR');
        document.getElementById('tab-count-d').textContent   = d.dossiers_in_db;
        document.getElementById('tab-count-s').textContent   = d.sites_in_db;

        if (d.last_sync) {
            const dt = new Date(d.last_sync.finished_at || d.last_sync.started_at);
            document.getElementById('stat-date').textContent = isNaN(dt) ? '—' : dt.toLocaleString('fr-FR');
            const st = document.getElementById('stat-status');
            if (d.last_sync.status === 'ok') { st.textContent = 'OK'; st.className = 'badge badge-ok'; }
            else if (d.last_sync.status === 'error') { st.textContent = 'Erreur'; st.className = 'badge badge-warn'; }
            else { st.textContent = 'En cours…'; st.className = 'badge badge-pending'; }
        }
    }).catch(()=>{});
}
loadSyncStatus();

// ── Sync (asynchrone — worker arrière-plan) ───────────────
let pollInterval = null;

function startPolling() {
    if (pollInterval) return;
    pollInterval = setInterval(() => {
        fetch('/api/crm/sync/status').then(r=>r.json()).then(d => {
            const status = d.last_sync?.status;
            if (status === 'ok' || status === 'error') {
                clearInterval(pollInterval); pollInterval = null;
                const btn = document.getElementById('btn-sync');
                btn.disabled = false; btn.textContent = '↻ Synchroniser depuis Dynamics';
                document.getElementById('progress-wrap').style.display = 'none';
                document.getElementById('progress-bar').style.width = '0%';
                if (status === 'ok') {
                    const info = document.getElementById('sync-info');
                    info.textContent = `Sync terminée — ${d.last_sync.sites_count} sites, ${d.last_sync.dossiers_count} dossiers, ${d.last_sync.geocoded} géocodés.`;
                    info.style.display = 'block';
                } else {
                    const err = document.getElementById('sync-error');
                    err.textContent = 'Erreur : ' + (d.last_sync.message || 'inconnue');
                    err.style.display = 'block';
                }
                loadSyncStatus();
            }
        });
    }, 4000);
}

// Auto-démarre le polling si sync déjà en cours au chargement de la page
fetch('/api/crm/sync/status').then(r=>r.json()).then(d=>{
    if (d.last_sync?.status === 'running') {
        const btn = document.getElementById('btn-sync');
        btn.disabled = true; btn.textContent = '↻ Sync en cours…';
        document.getElementById('progress-wrap').style.display = 'block';
        let pct = 0;
        const anim = setInterval(() => { pct = Math.min(pct + 0.5, 85); document.getElementById('progress-bar').style.width = pct + '%'; }, 1000);
        setTimeout(() => clearInterval(anim), 300000);
        startPolling();
    }
}).catch(()=>{});

document.getElementById('btn-sync').addEventListener('click', () => {
    const btn  = document.getElementById('btn-sync');
    const info = document.getElementById('sync-info');
    const err  = document.getElementById('sync-error');
    const prog = document.getElementById('progress-wrap');
    const bar  = document.getElementById('progress-bar');

    btn.disabled = true; btn.textContent = '↻ Démarrage…';
    info.style.display = 'none'; err.style.display = 'none';

    fetch('/api/crm/sync', { method: 'POST' })
    .then(r => r.json())
    .then(d => {
        if (d.error) {
            err.textContent = 'Erreur : ' + d.error; err.style.display = 'block';
            btn.disabled = false; btn.textContent = '↻ Synchroniser depuis Dynamics';
            return;
        }
        if (d.status === 'already_running') {
            info.textContent = 'Une synchronisation est déjà en cours…';
            info.style.display = 'block';
        } else {
            btn.textContent = '↻ Sync en cours…';
            info.textContent = 'Synchronisation lancée en arrière-plan. Cela peut prendre quelques minutes (géocodage des adresses).';
            info.style.display = 'block';
        }
        // Animation barre + polling
        prog.style.display = 'block';
        let pct = 0;
        const anim = setInterval(() => { pct = Math.min(pct + 0.5, 85); bar.style.width = pct + '%'; }, 1000);
        setTimeout(() => clearInterval(anim), 300000);
        startPolling();
    })
    .catch(e => {
        err.textContent = 'Erreur réseau : ' + e.message; err.style.display = 'block';
        btn.disabled = false; btn.textContent = '↻ Synchroniser depuis Dynamics';
    });
});

// ── Tableau dossiers (depuis miroir local) ────────────────
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('fr-FR') : '—'; }
function fmtEur(v)  { return v ? (+v).toLocaleString('fr-FR') + ' €' : '—'; }

function runQuery(sql, onData, onEmpty, onError) {
    fetch('/api/query', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({sql}) })
    .then(r=>r.json())
    .then(d => { if (d.error) onError(d.error); else if (!d.rows?.length) onEmpty(); else onData(d); })
    .catch(e => onError(e.message));
}

document.getElementById('load-dossiers').addEventListener('click', () => {
    const q   = document.getElementById('search-dossier').value.trim().replace(/'/g,"''");
    const where = q ? `WHERE d.numero ILIKE '%${q}%' OR d.reference_client ILIKE '%${q}%' OR d.client_name ILIKE '%${q}%' OR s.ville ILIKE '%${q}%'` : '';
    const sql = `SELECT d.numero, d.client_name, d.reference_client, s.ville, s.adresse,
                        s.code_insee, s.montant_tf, d.date_demande, d.date_remise
                 FROM crm_dossiers_mirror d
                 LEFT JOIN crm_sites_mirror s ON s.siteid = d.site_id
                 ${where} ORDER BY d.numero LIMIT 500`;

    runQuery(sql, data => {
        const tbody = document.getElementById('dossiers-body');
        tbody.innerHTML = data.rows.map(r => `<tr>
            <td>${r.numero??'—'}</td>
            <td>${r.client_name??'—'}</td>
            <td>${r.reference_client??'—'}</td>
            <td>${r.ville??'—'}</td>
            <td>${r.adresse??'—'}</td>
            <td>${r.code_insee??'—'}</td>
            <td>${fmtEur(r.montant_tf)}</td>
            <td>${fmtDate(r.date_demande)}</td>
            <td>${fmtDate(r.date_remise)}</td>
        </tr>`).join('');
        document.getElementById('dossiers-table-wrap').style.display = 'block';
        document.getElementById('dossiers-count').textContent = data.count + ' dossier(s)';
        document.getElementById('dossiers-count').style.display = 'block';
        document.getElementById('dossiers-empty').style.display = 'none';
    },
    () => {
        document.getElementById('dossiers-table-wrap').style.display = 'none';
        document.getElementById('dossiers-empty').style.display = 'block';
    },
    e => alert('Erreur : ' + e));
});

document.getElementById('load-sites').addEventListener('click', () => {
    const q     = document.getElementById('search-site').value.trim().replace(/'/g,"''");
    const where = q ? `WHERE adresse ILIKE '%${q}%' OR ville ILIKE '%${q}%' OR code_insee ILIKE '%${q}%'` : '';
    const sql = `SELECT nom, adresse, ville, code_postal, code_insee, section, parcelle, montant_tf,
                        CASE WHEN geom IS NOT NULL THEN 'Oui' ELSE 'Non' END AS geocode
                 FROM crm_sites_mirror ${where} ORDER BY ville, adresse LIMIT 500`;

    runQuery(sql, data => {
        const tbody = document.getElementById('sites-body');
        tbody.innerHTML = data.rows.map(r => `<tr>
            <td>${r.nom??'—'}</td>
            <td>${r.adresse??'—'}</td>
            <td>${r.ville??'—'}</td>
            <td>${r.code_postal??'—'}</td>
            <td>${r.code_insee??'—'}</td>
            <td>${r.section??'—'}</td>
            <td>${r.parcelle??'—'}</td>
            <td>${fmtEur(r.montant_tf)}</td>
            <td><span class="badge ${r.geocode==='Oui'?'badge-ok':'badge-warn'}">${r.geocode}</span></td>
        </tr>`).join('');
        document.getElementById('sites-table-wrap').style.display = 'block';
        document.getElementById('sites-count').textContent = data.count + ' site(s)';
        document.getElementById('sites-count').style.display = 'block';
        document.getElementById('sites-empty').style.display = 'none';
    },
    () => {
        document.getElementById('sites-table-wrap').style.display = 'none';
        document.getElementById('sites-empty').style.display = 'block';
    },
    e => alert('Erreur : ' + e));
});

// ── OData direct ─────────────────────────────────────────
document.getElementById('btn-odata').addEventListener('click', () => {
    const entity = document.getElementById('odata-entity').value;
    const filter = document.getElementById('odata-filter').value.trim();
    const top    = document.getElementById('odata-top').value || 10;

    let params = `$top=${top}`;
    if (filter) params += '&$filter=' + encodeURIComponent(filter);

    fetch(`/api/commune/odata?entity=${encodeURIComponent(entity)}&${params}`)
    .then(r => r.json())
    .then(d => {
        document.getElementById('odata-result').textContent = JSON.stringify(d, null, 2);
        document.getElementById('odata-result-card').style.display = 'block';
        document.getElementById('odata-error').style.display = 'none';
    })
    .catch(e => {
        document.getElementById('odata-error').textContent = 'Erreur : ' + e.message;
        document.getElementById('odata-error').style.display = 'block';
    });
});
</script>
</body>
</html>
