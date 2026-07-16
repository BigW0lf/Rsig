<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSig — Mise à jour</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= ASSET_VER ?>">
    <style>
        /* ── Onglets principaux ── */
        .main-tabs { display:flex; gap:4px; border-bottom:3px solid var(--border); margin-bottom:24px; }
        .main-tab  { padding:10px 20px; font-size:0.88rem; font-weight:700; cursor:pointer; color:var(--text3);
                     border:none; background:none; border-bottom:3px solid transparent; margin-bottom:-3px; transition:color .15s; }
        .main-tab.active { color:var(--blue); border-bottom-color:var(--blue); }
        .main-panel { display:none; } .main-panel.active { display:block; }

        /* ── Update card ── */
        .update-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);
                       padding:16px 20px; margin-bottom:16px; box-shadow:var(--shadow-sm); }
        .update-card h3 { font-size:0.9rem; font-weight:700; margin-bottom:12px; color:var(--text); }
        .update-card p  { font-size:0.82rem; color:var(--text2); margin-bottom:12px; }

        /* ── Stats ── */
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; margin-bottom:14px; }
        .stat-box  { background:var(--surface2); border-radius:var(--radius); padding:10px 12px; text-align:center; }
        .stat-box .val { font-size:1.25rem; font-weight:700; color:var(--blue); }
        .stat-box .lbl { font-size:0.72rem; color:var(--text3); margin-top:2px; }

        /* ── Statut badges ── */
        .status-badge  { display:inline-block; padding:2px 9px; border-radius:10px; font-size:0.75rem; font-weight:700; }
        .status-running{ background:#fef9c3; color:#a16207; }
        .status-ok     { background:#dcfce7; color:#166534; }
        .status-error  { background:#fee2e2; color:#991b1b; }
        .status-idle   { background:var(--surface2); color:var(--text3); }

        /* ── CRM héritage ── */
        .sync-block { display:flex; align-items:center; gap:16px; flex-wrap:wrap;
                      background:var(--surface); border:1px solid var(--border);
                      border-radius:8px; padding:14px 18px; margin-bottom:20px; box-shadow:var(--shadow-sm); }
        .sync-stat  { font-size:0.82rem; color:var(--text3); }
        .sync-stat strong { color:var(--text); font-size:0.95rem; }
        .sync-sep   { width:1px; height:28px; background:var(--border); }
        .progress-bar-wrap { background:var(--surface2); border-radius:4px; height:6px; width:200px; overflow:hidden; display:none; }
        .progress-bar      { height:100%; background:var(--blue-light); border-radius:4px; width:0%; transition:width .3s; }

        /* ── BOFIP ── */
        .shortcut-list { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px; }
        .shortcut-btn  { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);
                         padding:5px 10px; font-size:0.78rem; color:var(--blue); cursor:pointer; transition:background .15s; }
        .shortcut-btn:hover { background:var(--blue-hover); }
        .result-block  { margin-bottom:20px; }
        .result-block h3 { font-size:0.88rem; font-weight:700; margin-bottom:8px; padding-bottom:6px; border-bottom:2px solid var(--border); }
        .result-table-wrap { overflow-x:auto; border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-sm); margin-bottom:10px; }
        .result-table-wrap table { font-size:0.8rem; width:100%; }
        .result-table-wrap th { background:var(--surface2); font-weight:700; padding:6px 10px; border-bottom:1px solid var(--border2); }
        .result-table-wrap td { padding:6px 10px; border-bottom:1px solid var(--border2); }
        .result-table-wrap tr:last-child td { border-bottom:none; }
        .result-table-wrap td:first-child { font-weight:500; }
        .result-table-wrap td:not(:first-child) { color:var(--blue); font-weight:600; text-align:right; }
        .communes-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:10px; }
        .commune-dep   { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:10px 12px; }
        .commune-dep-name { font-weight:700; font-size:0.82rem; color:var(--text); margin-bottom:6px; }
        .commune-dep-list { font-size:0.78rem; color:var(--text2); line-height:1.6; }
        .meta-bar { display:flex; gap:16px; align-items:center; margin-bottom:14px; background:var(--surface);
                    border:1px solid var(--border); border-radius:var(--radius); padding:8px 14px; font-size:0.82rem; }
        .meta-url { color:var(--text3); font-size:0.75rem; word-break:break-all; }
        #bofip-spinner { display:none; color:var(--text3); font-size:0.82rem; padding:4px 0; }

        /* ── TA ── */
        .log-output { background:#1a2332; color:#a5f3fc; font-family:monospace; font-size:0.75rem;
                      border-radius:var(--radius); padding:12px; max-height:180px; overflow-y:auto;
                      white-space:pre-wrap; word-break:break-all; margin-top:10px; }

        /* ── TSB ── */
        .tsb-stats-table { font-size:0.8rem; width:100%; }
        .tsb-stats-table th { background:var(--surface2); font-weight:700; text-align:center; padding:6px 8px; border-bottom:1px solid var(--border); }
        .tsb-stats-table td { padding:5px 8px; border-bottom:1px solid var(--border2); text-align:center; }
        .tsb-stats-table td:first-child { font-weight:700; color:var(--blue); text-align:left; }
        .badge-circ { display:inline-block; padding:1px 6px; border-radius:10px; font-size:0.75rem; font-weight:700; }
        .bc1{background:#fecaca;color:#991b1b} .bc2{background:#fed7aa;color:#c2410c}
        .bc3{background:#fef08a;color:#a16207} .bc4{background:#bbf7d0;color:#15803d}
    </style>
    <script>
        const _P={carte:'/',donnees:'/donnees',requetes:'/requetes',crm:'/crm',bofip:'/bofip',maj:'/maj'};
        function navTo(p){window.parent?.showPage?window.parent.showPage(p):location.href=_P[p]||'/';}
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
    <a href="#" onclick="navTo('requetes');return false">Requêtes</a>
    <a href="#" onclick="navTo('crm');return false">CRM</a>
    <a href="#" class="active">Mise à jour</a>
</nav>

<div class="page-content" style="max-width:1000px">
    <h1>Mise à jour des données</h1>

    <div class="main-tabs">
        <button class="main-tab active" data-panel="crm">Sync CRM</button>
        <button class="main-tab" data-panel="tsb">Tarifs &amp; Circs TSB</button>
        <button class="main-tab" data-panel="ta">Taxe d'Aménagement</button>
    </div>

    <!-- ═══════════════════════════════════════════════════
         CRM
    ═══════════════════════════════════════════════════ -->
    <div class="main-panel active" id="panel-crm">

        <div class="sync-block">
            <div class="sync-stat">Sites en BDD<br><strong id="crm-sites">—</strong></div>
            <div class="sync-sep"></div>
            <div class="sync-stat">Dossiers en BDD<br><strong id="crm-dossiers">—</strong></div>
            <div class="sync-sep"></div>
            <div class="sync-stat">Dernière sync<br><strong id="crm-date">—</strong></div>
            <div class="sync-sep"></div>
            <div class="sync-stat">Statut<br><span id="crm-status" class="badge badge-pending">—</span></div>
            <span style="flex:1"></span>
            <div class="progress-bar-wrap" id="crm-progress-wrap">
                <div class="progress-bar" id="crm-progress-bar"></div>
            </div>
            <button class="btn" id="btn-crm-sync">↻ Synchroniser depuis Dynamics</button>
        </div>

        <div class="alert alert-info"  id="crm-info"  style="display:none"></div>
        <div class="alert alert-error" id="crm-error" style="display:none"></div>

        <div class="update-card">
            <h3>À propos de la synchronisation</h3>
            <p>
                Synchronise le miroir local PostgreSQL depuis Microsoft Dynamics 365 (table <code>apo_dossiers</code>).
                La sync est incrémentale — seuls les enregistrements modifiés depuis la dernière sync réussie sont retéléchargés.
                Elle tourne en arrière-plan (~1-3 min selon le volume).
            </p>
        </div>
    </div>


    <!-- ═══════════════════════════════════════════════════
         TA
    ═══════════════════════════════════════════════════ -->
    <div class="main-panel" id="panel-ta">

        <div class="update-card">
            <h3>État de la base TA</h3>
            <div class="stat-grid">
                <div class="stat-box"><div class="val" id="ta-communes">…</div><div class="lbl">Communes</div></div>
                <div class="stat-box"><div class="val" id="ta-dep">…</div><div class="lbl">Avec taux départ.</div></div>
                <div class="stat-box"><div class="val" id="ta-reg">…</div><div class="lbl">Avec taux rég. IDF</div></div>
                <div class="stat-box"><div class="val" id="ta-date">…</div><div class="lbl">Date effet max</div></div>
            </div>
            <div style="font-size:0.82rem;color:var(--text2)">
                Dernier run :
                <span id="ta-last-status" class="status-badge status-idle">jamais lancé</span>
                <span id="ta-last-date"   style="color:var(--text3);margin-left:6px"></span>
                <span id="ta-last-msg"    style="color:var(--text3);margin-left:6px"></span>
            </div>
        </div>

        <div class="update-card">
            <h3>Lancer une mise à jour</h3>
            <p>
                Récupère les taux depuis l'API DGFIP
                (<a href="https://data.economie.gouv.fr/explore/dataset/delta_deliberation_tam_17_01_23" target="_blank">data.economie.gouv.fr</a>).
                Seules les communes avec une date d'effet plus récente sont modifiées.
            </p>
            <div style="display:flex;gap:8px;align-items:center">
                <button class="btn" id="btn-ta-update" onclick="lancerMajTA()">↻ Lancer la mise à jour TA</button>
                <span id="ta-run-status" style="font-size:0.82rem;color:var(--text3)"></span>
            </div>
            <div id="ta-log" class="log-output" style="display:none"></div>
        </div>

        <div class="update-card">
            <h3>Valeurs forfaitaires (CGI art. 1635 quater B)</h3>
            <div id="ta-forfait-table"></div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         TSB — Tarifs & Circs
    ═══════════════════════════════════════════════════ -->
    <div class="main-panel" id="panel-tsb">

        <!-- Aperçu millésimes -->
        <div class="update-card">
            <h3>Millésimes en base</h3>
            <div class="result-table-wrap">
                <table class="tsb-stats-table">
                    <thead><tr>
                        <th>Millésime</th>
                        <th><span class="badge-circ bc1">Circ 1</span></th>
                        <th><span class="badge-circ bc2">Circ 2</span></th>
                        <th>2bis</th>
                        <th><span class="badge-circ bc3">Circ 3</span></th>
                        <th><span class="badge-circ bc4">Circ 4</span></th>
                        <th>DCSUCS</th>
                        <th>PACA</th>
                        <th>Tarifs</th>
                    </tr></thead>
                    <tbody id="tsb-stats-body"><tr><td colspan="9" style="color:var(--text3);text-align:center">Chargement…</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- Tarifs TSB -->
        <div class="update-card">
            <h3>Importer les tarifs TSB</h3>
            <p>Les tarifs publiés en année N valent pour l'année N. La page BOFIP contient IDF (circ 1-4), IDF 2bis et PACA.</p>
            <div class="shortcut-list">
                <button class="shortcut-btn tsb-tarifs-btn" data-url="https://bofip.impots.gouv.fr/bofip/593-PGP.html/identifiant%3DBOI-IF-AUT-50-20-20260506" data-mil="2026">Tarifs 2026 (maj. 05/2026)</button>
                <button class="shortcut-btn tsb-tarifs-btn" data-url="https://bofip.impots.gouv.fr/bofip/593-PGP.html/identifiant%3DBOI-IF-AUT-50-20-20260204" data-mil="2026">Tarifs 2026 (pub. 02/2026)</button>
                <button class="shortcut-btn tsb-tarifs-btn" data-url="https://bofip.impots.gouv.fr/bofip/593-PGP.html/identifiant%3DBOI-IF-AUT-50-20-20250205" data-mil="2025">Tarifs 2025</button>
                <button class="shortcut-btn tsb-tarifs-btn" data-url="https://bofip.impots.gouv.fr/bofip/593-PGP.html/identifiant%3DBOI-IF-AUT-50-20-20240214" data-mil="2024">Tarifs 2024</button>
                <button class="shortcut-btn tsb-tarifs-btn" data-url="https://bofip.impots.gouv.fr/bofip/593-PGP.html/identifiant%3DBOI-IF-AUT-50-20-20230614" data-mil="2023">Tarifs 2023</button>
            </div>
            <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:8px">
                <div style="flex:1">
                    <label style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:3px">URL BOFIP tarifs TSB</label>
                    <input type="url" id="tsb-tarifs-url" placeholder="https://bofip.impots.gouv.fr/bofip/593-PGP.html/…" style="width:100%">
                </div>
                <div>
                    <label style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:3px">Millésime</label>
                    <input type="number" id="tsb-tarifs-millesime" min="2010" max="2060" value="2026" style="width:80px">
                </div>
                <button class="btn" onclick="tsbTarifsImport()">Importer tarifs</button>
            </div>
            <div id="tsb-tarifs-spinner" style="display:none;color:var(--text3);font-size:0.82rem">Import en cours…</div>
            <div id="tsb-tarifs-result" style="display:none"></div>
            <div id="tsb-tarifs-table" style="margin-top:12px"></div>
        </div>

        <!-- DCSUCS -->
        <div class="update-card">
            <h3>Importer un millésime DCSUCS (circonscriptions)</h3>
            <p>⚠️ La liste parue en année N s'applique au millésime N-1.</p>
            <div class="shortcut-list">
                <button class="shortcut-btn tsb-dcsucs-btn" data-url="https://bofip.impots.gouv.fr/bofip/9441-PGP.html/identifiant%3DBOI-ANNX-000463-20260204" data-mil="2026">TSB 2026 (liste au titre de 2025)</button>
                <button class="shortcut-btn tsb-dcsucs-btn" data-url="https://bofip.impots.gouv.fr/bofip/9441-PGP.html/identifiant%3DBOI-ANNX-000463-20250205" data-mil="2025">TSB 2025 (liste au titre de 2024)</button>
                <button class="shortcut-btn tsb-dcsucs-btn" data-url="https://bofip.impots.gouv.fr/bofip/9441-PGP.html/identifiant%3DBOI-ANNX-000463-20240214" data-mil="2024">TSB 2024 (liste au titre de 2023)</button>
                <button class="shortcut-btn tsb-dcsucs-btn" data-url="https://bofip.impots.gouv.fr/bofip/9441-PGP.html/identifiant%3DBOI-ANNX-000463-20230215" data-mil="2023">TSB 2023 (liste au titre de 2022)</button>
            </div>
            <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:8px">
                <div style="flex:1">
                    <label style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:3px">URL BOFIP DCSUCS</label>
                    <input type="url" id="tsb-url" placeholder="https://bofip.impots.gouv.fr/bofip/9441-PGP.html/…" style="width:100%">
                </div>
                <div>
                    <label style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:3px">Millésime</label>
                    <input type="number" id="tsb-millesime" min="2010" max="2050" value="2025" style="width:80px">
                </div>
                <button class="btn" onclick="tsbImport()">Importer DCSUCS</button>
            </div>
            <div id="tsb-import-spinner" style="display:none;color:var(--text3);font-size:0.82rem">Import en cours…</div>
            <div id="tsb-import-result" style="display:none"></div>
        </div>
    </div>

</div><!-- /page-content -->

<script>
// ═══════════════════════════════════════════════════════════
// Onglets principaux
// ═══════════════════════════════════════════════════════════
document.querySelectorAll('.main-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.main-tab, .main-panel').forEach(el => el.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.panel).classList.add('active');
        if (tab.dataset.panel === 'crm')   loadCrmStatus();
        if (tab.dataset.panel === 'ta')    loadTaStatus();
        if (tab.dataset.panel === 'tsb')   loadTsbStats();
    });
});

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ═══════════════════════════════════════════════════════════
// CRM
// ═══════════════════════════════════════════════════════════
let crmPoll = null;

function loadCrmStatus() {
    fetch('/api/crm/sync/status').then(r=>r.json()).then(d=>{
        document.getElementById('crm-sites').textContent    = d.sites_in_db?.toLocaleString('fr-FR') || '—';
        document.getElementById('crm-dossiers').textContent = d.dossiers_in_db?.toLocaleString('fr-FR') || '—';
        if (d.last_sync) {
            const dt = new Date(d.last_sync.finished_at || d.last_sync.started_at);
            document.getElementById('crm-date').textContent = isNaN(dt) ? '—' : dt.toLocaleString('fr-FR');
            const st = document.getElementById('crm-status');
            if (d.last_sync.status === 'ok') { st.textContent='OK'; st.className='badge badge-ok'; }
            else if (d.last_sync.status === 'error') { st.textContent='Erreur'; st.className='badge badge-warn'; }
            else { st.textContent='En cours…'; st.className='badge badge-pending'; }
            if (d.last_sync.status === 'running') startCrmPolling();
        }
    }).catch(()=>{});
}

function startCrmPolling() {
    if (crmPoll) return;
    crmPoll = setInterval(() => {
        fetch('/api/crm/sync/status').then(r=>r.json()).then(d=>{
            const s = d.last_sync?.status;
            if (s === 'ok' || s === 'error') {
                clearInterval(crmPoll); crmPoll = null;
                const btn = document.getElementById('btn-crm-sync');
                btn.disabled = false; btn.textContent = '↻ Synchroniser depuis Dynamics';
                document.getElementById('crm-progress-wrap').style.display = 'none';
                if (s === 'ok') {
                    const el = document.getElementById('crm-info');
                    el.textContent = `Sync terminée — ${d.last_sync.sites_count} sites, ${d.last_sync.dossiers_count} dossiers.`;
                    el.style.display = 'block';
                } else {
                    const el = document.getElementById('crm-error');
                    el.textContent = 'Erreur : ' + (d.last_sync.message || 'inconnue');
                    el.style.display = 'block';
                }
                loadCrmStatus();
            }
        });
    }, 30000);
}

document.getElementById('btn-crm-sync').addEventListener('click', () => {
    const btn  = document.getElementById('btn-crm-sync');
    const info = document.getElementById('crm-info');
    const err  = document.getElementById('crm-error');
    const bar  = document.getElementById('crm-progress-bar');
    const wrap = document.getElementById('crm-progress-wrap');
    btn.disabled = true; btn.textContent = '↻ Démarrage…';
    info.style.display = 'none'; err.style.display = 'none';

    fetch('/api/crm/sync', { method:'POST' })
    .then(r=>r.json())
    .then(d=>{
        if (d.error) { err.textContent='Erreur : '+d.error; err.style.display='block'; btn.disabled=false; btn.textContent='↻ Synchroniser depuis Dynamics'; return; }
        btn.textContent = '↻ Sync en cours…';
        info.textContent = 'Synchronisation lancée en arrière-plan (géocodage inclus, ~1-3 min).';
        info.style.display = 'block';
        wrap.style.display = 'block';
        let pct = 0;
        const anim = setInterval(() => { pct = Math.min(pct+0.5, 85); bar.style.width = pct+'%'; }, 1000);
        setTimeout(() => clearInterval(anim), 300000);
        startCrmPolling();
    })
    .catch(e=>{ err.textContent='Erreur réseau : '+e.message; err.style.display='block'; btn.disabled=false; btn.textContent='↻ Synchroniser depuis Dynamics'; });
});

loadCrmStatus();

// ═══════════════════════════════════════════════════════════
// TSB DCSUCS import
// ═══════════════════════════════════════════════════════════
document.querySelectorAll('.tsb-dcsucs-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('tsb-url').value = btn.dataset.url;
        document.getElementById('tsb-millesime').value = btn.dataset.mil;
    });
});

function tsbImport() {
    const url = document.getElementById('tsb-url').value.trim();
    const mil = document.getElementById('tsb-millesime').value.trim();
    if (!url || !mil) return;
    const spinner = document.getElementById('tsb-import-spinner');
    const result  = document.getElementById('tsb-import-result');
    spinner.style.display = 'block'; result.style.display = 'none';
    fetch('/api/tsb/import', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({url, millesime:mil}) })
    .then(r=>r.json()).then(d=>{
        spinner.style.display='none'; result.style.display='block';
        if (d.error) { result.className='alert alert-error'; result.textContent='Erreur : '+d.error; }
        else { result.className='alert alert-ok'; result.textContent=`Millésime ${d.millesime} — ${d.dcsucs_communes} communes DCSUCS, ${d.total_inserted} enregistrées.`; loadTsbStats(); }
    }).catch(e=>{ spinner.style.display='none'; result.style.display='block'; result.className='alert alert-error'; result.textContent='Erreur : '+e.message; });
}

// ═══════════════════════════════════════════════════════════
// TA
// ═══════════════════════════════════════════════════════════
let taPoll = null;

function loadTaStatus() {
    fetch('/api/ta/update/status').then(r=>r.json()).then(d=>{
        document.getElementById('ta-communes').textContent = d.communes?.toLocaleString('fr-FR')||'0';
        document.getElementById('ta-dep').textContent      = d.avec_dep?.toLocaleString('fr-FR')||'0';
        document.getElementById('ta-reg').textContent      = d.avec_reg?.toLocaleString('fr-FR')||'0';
        document.getElementById('ta-date').textContent     = d.date_effet_max ? d.date_effet_max.slice(0,10) : '–';
        const run = d.last_run;
        const badge = document.getElementById('ta-last-status');
        if (!run) { badge.className='status-badge status-idle'; badge.textContent='jamais lancé'; }
        else {
            badge.className = 'status-badge status-'+run.status;
            badge.textContent = run.status==='running'?'En cours…':run.status==='ok'?'Succès':'Erreur';
            document.getElementById('ta-last-date').textContent = run.finished_at ? new Date(run.finished_at).toLocaleString('fr-FR') : 'en cours…';
            document.getElementById('ta-last-msg').textContent  = run.communes_updated ? run.communes_updated.toLocaleString('fr-FR')+' communes mises à jour' : (run.message||'').slice(0,80);
            if (run.status==='running') {
                document.getElementById('btn-ta-update').disabled = true;
                if (!taPoll) taPoll = setInterval(loadTaStatus, 4000);
            } else {
                document.getElementById('btn-ta-update').disabled = false;
                if (taPoll) { clearInterval(taPoll); taPoll = null; }
            }
        }
    }).catch(()=>{});

    fetch('/api/ta/forfaitaires').then(r=>r.json()).then(d=>{
        const rows = d.forfaitaires||[];
        if (!rows.length) return;
        const mils  = [...new Set(rows.map(r=>r.annee))].sort().reverse();
        const types = [...new Set(rows.map(r=>r.type_local))];
        let html = '<div class="result-table-wrap"><table><thead><tr><th>Type</th>'+mils.map(m=>`<th>${m} IDF</th><th>${m} France</th>`).join('')+'</tr></thead><tbody>';
        types.forEach(t=>{
            html += `<tr><td>${escHtml(t)}</td>`;
            mils.forEach(m=>{
                const idf = rows.find(r=>r.annee==m&&r.zone==='IDF'&&r.type_local===t);
                const fr  = rows.find(r=>r.annee==m&&r.zone==='FRANCE'&&r.type_local===t);
                html += `<td>${idf?(+idf.valeur).toLocaleString('fr-FR')+' €/m²':'–'}</td>`;
                html += `<td>${fr ?(+fr.valeur).toLocaleString('fr-FR') +' €/m²':'–'}</td>`;
            });
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('ta-forfait-table').innerHTML = html;
    }).catch(()=>{});
}

function lancerMajTA() {
    const btn  = document.getElementById('btn-ta-update');
    const stEl = document.getElementById('ta-run-status');
    const logEl = document.getElementById('ta-log');
    btn.disabled = true; stEl.textContent='Lancement…'; logEl.style.display='none';

    fetch('/api/ta/update', {method:'POST'}).then(r=>r.json()).then(d=>{
        if (d.error) { stEl.textContent='Erreur : '+d.error; btn.disabled=false; return; }
        if (d.status==='already_running') { stEl.textContent='Déjà en cours (log #'+d.log_id+')'; btn.disabled=false; return; }
        stEl.textContent = 'Démarré (log #'+d.log_id+')…';
        logEl.style.display = 'block'; logEl.textContent = 'Initialisation…';
        if (taPoll) clearInterval(taPoll);
        taPoll = setInterval(() => {
            loadTaStatus();
            fetch('/api/ta/update/status').then(r=>r.json()).then(s=>{
                if (s.last_run?.message) logEl.textContent = s.last_run.message;
                if (s.last_run?.status!=='running')
                    logEl.textContent = s.last_run?.status==='ok'
                        ? '✓ Terminé — '+(s.last_run.communes_updated||0)+' communes mises à jour'
                        : '✗ Erreur : '+(s.last_run?.message||'');
            });
        }, 4000);
    })
    .catch(e=>{ stEl.textContent='Erreur réseau : '+e.message; btn.disabled=false; });
}

// ═══════════════════════════════════════════════════════════
// TSB stats + tarifs
// ═══════════════════════════════════════════════════════════
function loadTsbStats() {
    // Charger circs + tarifs en parallèle
    Promise.all([
        fetch('/api/tsb/stats').then(r=>r.json()),
        fetch('/api/tsb/tarifs/millesimes').then(r=>r.json()),
    ]).then(([circRows, tarifMils])=>{
        const tarifsSet = new Set(tarifMils.map(m=>+m));
        const tbody = document.getElementById('tsb-stats-body');
        if (!circRows.length) { tbody.innerHTML='<tr><td colspan="9" style="color:var(--text3);text-align:center">Aucun millésime</td></tr>'; return; }
        tbody.innerHTML = circRows.map(r=>`<tr>
            <td>${r.millesime}</td><td>${r.idf_c1}</td><td>${r.idf_c2}</td>
            <td style="color:var(--text2)">${r.idf_2bis}</td><td>${r.idf_c3}</td><td>${r.idf_c4}</td>
            <td style="color:var(--text2)">${r.idf_dcsucs_derog}</td><td>${r.paca_total}</td>
            <td>${tarifsSet.has(+r.millesime) ? '<span style="color:#166534">✓</span>' : '<span style="color:var(--text3)">–</span>'}</td>
        </tr>`).join('');
    }).catch(()=>{});
    loadTsbTarifsTable();
}

function loadTsbTarifsTable() {
    fetch('/api/tsb/tarifs/millesimes').then(r=>r.json()).then(mils=>{
        if (!mils.length) { document.getElementById('tsb-tarifs-table').innerHTML='<p style="color:var(--text3);font-size:0.82rem">Aucun tarif en base.</p>'; return; }
        // Charger tous les millésimes et construire la table
        Promise.all(mils.map(m=>fetch('/api/tsb/tarifs?millesime='+m).then(r=>r.json())))
        .then(results=>{
            const millesimes = results.map(r=>r.millesime).sort((a,b)=>b-a);
            // Récupérer tous les types + régions uniques
            const keys = [];
            results.forEach(r=>(r.tarifs||[]).forEach(t=>{
                const k = t.region+'|'+(t.circonscription??'null')+'|'+t.type_local;
                if (!keys.find(x=>x.k===k)) keys.push({k, region:t.region, circ:t.circonscription, type:t.type_local});
            }));
            // Grouper par region+circ
            const groups = {};
            keys.forEach(({k,region,circ,type})=>{
                const gk = region+'|'+(circ??'null');
                if (!groups[gk]) groups[gk] = {region, circ, types:[]};
                groups[gk].types.push({k, type});
            });
            let html = '<div style="overflow-x:auto"><table style="font-size:0.78rem;width:100%;border-collapse:collapse">';
            html += '<thead><tr><th style="text-align:left;padding:4px 8px;background:var(--surface2);border-bottom:1px solid var(--border)">Région / Circ / Type</th>';
            millesimes.forEach(m=>{ html += `<th style="text-align:right;padding:4px 8px;background:var(--surface2);border-bottom:1px solid var(--border)">${m}</th>`; });
            html += '</tr></thead><tbody>';
            const fmt = v => v!=null ? (+v).toFixed(2)+' €' : '–';
            const regLabel = {IDF:'IDF', IDF_2BIS:'IDF 2bis', PACA:'PACA'};
            Object.values(groups).forEach(g=>{
                const gLabel = `${regLabel[g.region]||g.region}${g.circ?` — circ ${g.circ}`:''}`;
                g.types.forEach((t,i)=>{
                    html += '<tr>';
                    if (i===0) html += `<td style="padding:4px 8px;font-weight:700;color:var(--blue);border-bottom:1px solid var(--border2)">${escHtml(gLabel)} — ${escHtml(t.type)}</td>`;
                    else html += `<td style="padding:4px 8px;border-bottom:1px solid var(--border2);padding-left:16px">${escHtml(t.type)}</td>`;
                    millesimes.forEach(m=>{
                        const res = results.find(r=>r.millesime===m);
                        const row = (res?.tarifs||[]).find(r=>r.region===g.region && (r.circonscription??null)===(g.circ??null) && r.type_local===t.type);
                        html += `<td style="text-align:right;padding:4px 8px;border-bottom:1px solid var(--border2);color:var(--blue);font-weight:600">${fmt(row?.tarif)}</td>`;
                    });
                    html += '</tr>';
                });
            });
            html += '</tbody></table></div>';
            document.getElementById('tsb-tarifs-table').innerHTML = html;
        });
    }).catch(()=>{});
}

// Raccourcis tarifs TSB
document.querySelectorAll('.tsb-tarifs-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('tsb-tarifs-url').value = btn.dataset.url;
        document.getElementById('tsb-tarifs-millesime').value = btn.dataset.mil;
    });
});

function _tsbTarifsImport(urlId, milId, spinnerId, resultId) {
    const url = document.getElementById(urlId).value.trim();
    const mil = document.getElementById(milId).value.trim();
    if (!url || !mil) return;
    const spinner = document.getElementById(spinnerId);
    const result  = document.getElementById(resultId);
    spinner.style.display='block'; result.style.display='none';

    fetch('/api/tsb/tarifs/import', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({url, millesime:mil})
    })
    .then(r=>r.json())
    .then(d=>{
        spinner.style.display='none'; result.style.display='block';
        if (d.error) {
            result.className='alert alert-error'; result.textContent='Erreur : '+d.error;
        } else {
            const detail = (d.detail||[]).map(r=>`${r.region} c${r.circonscription??'-'}: ${r.n}`).join(' | ');
            result.className='alert alert-ok';
            result.textContent=`Millésime ${d.millesime} — ${d.inserted} tarifs importés (${detail})`;
            loadTsbTarifsTable();
        }
    })
    .catch(e=>{spinner.style.display='none'; result.style.display='block'; result.className='alert alert-error'; result.textContent='Erreur : '+e.message;});
}
function tsbTarifsImport() { _tsbTarifsImport('tsb-tarifs-url', 'tsb-tarifs-millesime', 'tsb-tarifs-spinner', 'tsb-tarifs-result'); }

loadTsbStats();
</script>
</body>
</html>
