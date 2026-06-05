<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSig — BOFIP</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .main-tabs { display:flex; gap:4px; border-bottom:3px solid var(--border); margin-bottom:24px; }
        .main-tab  { padding:10px 20px; font-size:0.88rem; font-weight:700; cursor:pointer; color:var(--text3);
                     border:none; background:none; border-bottom:3px solid transparent; margin-bottom:-3px; transition:color .15s; }
        .main-tab.active { color:var(--blue); border-bottom-color:var(--blue); }
        .main-panel { display:none; } .main-panel.active { display:block; }

        /* Sélecteur millésime */
        .mil-bar { display:flex; align-items:center; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
        .mil-bar label { font-size:0.82rem; color:var(--text2); font-weight:600; }
        .mil-bar select { min-width:100px; }

        /* Tableau tarifs */
        .tarif-table-wrap { overflow-x:auto; margin-bottom:20px; }
        .tarif-table { width:100%; border-collapse:collapse; font-size:0.82rem; }
        .tarif-table th { background:var(--surface2); padding:8px 12px; font-weight:700; border-bottom:2px solid var(--border);
                          text-align:center; white-space:nowrap; }
        .tarif-table th:first-child { text-align:left; }
        .tarif-table td { padding:7px 12px; border-bottom:1px solid var(--border2); }
        .tarif-table td:not(:first-child) { text-align:right; font-weight:600; color:var(--blue); }
        .tarif-table .circ-header { background:var(--blue-hover); font-weight:700; color:var(--blue);
                                     text-align:center; padding:5px 12px; font-size:0.75rem; letter-spacing:.5px; }
        .tarif-table tbody tr:hover { background:var(--surface2); }

        /* Exonérations TA */
        .exo-table { width:100%; border-collapse:collapse; font-size:0.8rem; }
        .exo-table th { background:var(--surface2); padding:6px 10px; font-weight:700; border-bottom:2px solid var(--border); text-align:left; white-space:nowrap; }
        .exo-table td { padding:6px 10px; border-bottom:1px solid var(--border2); vertical-align:top; }
        .exo-table tbody tr:hover { background:var(--surface2); }
        .exo-badge { display:inline-block; background:#dcfce7; color:#166534; border-radius:10px; padding:1px 7px; font-size:0.72rem; font-weight:700; white-space:nowrap; }
        .exo-taux  { font-weight:700; color:var(--blue); }
        .exo-link  { color:var(--blue); font-size:0.75rem; text-decoration:underline; }

        /* Comparateur */
        .cmp-table { width:100%; border-collapse:collapse; font-size:0.82rem; }
        .cmp-table th { background:var(--surface2); padding:7px 12px; font-weight:700; border-bottom:2px solid var(--border); text-align:center; white-space:nowrap; }
        .cmp-table th:first-child { text-align:left; min-width:200px; }
        .cmp-table td { padding:7px 12px; border-bottom:1px solid var(--border2); }
        .cmp-table td:not(:first-child) { text-align:right; }
        .cmp-table tbody tr:hover { background:var(--surface2); }
        .cmp-group { background:var(--blue-hover); font-weight:700; color:var(--blue); font-size:0.78rem; padding:5px 12px; }
        .cmp-up   { color:#166534; font-weight:700; }
        .cmp-down { color:#991b1b; font-weight:700; }
        .cmp-same { color:var(--text3); }
        .cmp-mil-cb { display:flex; align-items:center; gap:5px; font-size:0.82rem; background:var(--surface);
                      border:1px solid var(--border); border-radius:var(--radius); padding:4px 10px; cursor:pointer;
                      transition:background .15s; user-select:none; }
        .cmp-mil-cb input { margin:0; cursor:pointer; }
        .cmp-mil-cb:has(input:checked) { background:var(--blue-hover); border-color:var(--blue); color:var(--blue); }

        /* Circ badges */
        .badge-circ { display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.75rem; font-weight:700; }
        .bc1{background:#fecaca;color:#991b1b} .bc2{background:#fed7aa;color:#c2410c}
        .bc2b{background:#fde047;color:#a16207} .bc3{background:#bbf7d0;color:#15803d}
        .bc4{background:#6ee7b7;color:#065f46} .bpaca{background:#e0e7ff;color:#3730a3}

        /* Tableau des circs */
        .circ-desc { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:14px 18px; margin-bottom:12px; }
        .circ-desc h3 { font-size:0.85rem; font-weight:700; margin-bottom:6px; display:flex; align-items:center; gap:8px; }
        .circ-desc p  { font-size:0.8rem; color:var(--text2); line-height:1.6; }

        /* Stats circs */
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:8px; margin-bottom:16px; }
        .stat-box  { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:10px; text-align:center; }
        .stat-box .val { font-size:1.2rem; font-weight:700; color:var(--blue); }
        .stat-box .lbl { font-size:0.7rem; color:var(--text3); margin-top:2px; }

        /* TASS */
        .tass-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);
                     padding:14px 18px; margin-bottom:12px; }
        .tass-card h3 { font-size:0.88rem; font-weight:700; margin-bottom:10px; }
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
    <a href="#" class="active">BOFIP</a>
    <a href="#" onclick="navTo('maj');return false">Mise à jour</a>
</nav>

<div class="page-content" style="max-width:1100px">
    <h1>Données BOFIP</h1>

    <div class="main-tabs">
        <button class="main-tab active" data-panel="tsb-tarifs">Tarifs TSB</button>
        <button class="main-tab" data-panel="tass-tarifs">Tarifs TASS</button>
        <button class="main-tab" data-panel="circs">Circonscriptions TSB</button>
        <button class="main-tab" data-panel="ta-exo">Exonérations TA</button>
        <button class="main-tab" data-panel="comparer">Comparer années</button>
    </div>

    <!-- ═══════════════════════════════════════════════════
         TARIFS TSB
    ═══════════════════════════════════════════════════ -->
    <div class="main-panel active" id="panel-tsb-tarifs">

        <div class="mil-bar">
            <label>Millésime</label>
            <select id="tsb-mil-sel"></select>
            <span id="tsb-tarifs-info" style="font-size:0.78rem;color:var(--text3)"></span>
        </div>

        <div id="tsb-tarifs-content">
            <div style="color:var(--text3);font-size:0.82rem">Chargement…</div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         TARIFS TASS
    ═══════════════════════════════════════════════════ -->
    <div class="main-panel" id="panel-tass-tarifs">

        <div class="mil-bar">
            <label>Millésime</label>
            <select id="tass-mil-sel"></select>
        </div>

        <div id="tass-tarifs-content">
            <div style="color:var(--text3);font-size:0.82rem">Chargement…</div>
        </div>

        <div class="tass-card" style="margin-top:20px">
            <h3>Géographie TASS (3 circonscriptions IDF)</h3>
            <div id="tass-circ-stats" class="stat-grid"></div>
            <div style="font-size:0.8rem;color:var(--text2);line-height:1.7">
                <strong>1ère circ.</strong> — Paris (arrondissements) + département des Hauts-de-Seine (92)<br>
                <strong>2ème circ.</strong> — Communes de l'unité urbaine de Paris, hors Paris et Hauts-de-Seine<br>
                <strong>3ème circ.</strong> — Reste de la région Île-de-France
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         CIRCONSCRIPTIONS TSB
    ═══════════════════════════════════════════════════ -->
    <div class="main-panel" id="panel-circs">

        <div class="mil-bar">
            <label>Millésime</label>
            <select id="circ-mil-sel"></select>
        </div>

        <div class="stat-grid" id="circ-stats"></div>

        <div class="circ-desc">
            <h3><span class="badge-circ bc1">1ère circ.</span></h3>
            <p>1<sup>er</sup>, 2<sup>e</sup>, 7<sup>e</sup>, 8<sup>e</sup>, 9<sup>e</sup>, 10<sup>e</sup>, 15<sup>e</sup>, 16<sup>e</sup> et 17<sup>e</sup> arrondissements de Paris + Boulogne-Billancourt, Courbevoie, Issy-les-Moulineaux, Levallois-Perret, Neuilly-sur-Seine, Puteaux.</p>
        </div>
        <div class="circ-desc">
            <h3><span class="badge-circ bc2">2ème circ.</span> <span class="badge-circ bc2b" style="margin-left:4px">2ème circ. bis</span></h3>
            <p>Arrondissements de Paris et communes du département des Hauts-de-Seine autres que ceux de la 1ère circonscription.<br>
            <em>2ème circ. bis (tarif réduit 10%)</em> : communes de la 2ème circ. éligibles à la fois à la DSU-CS et au FSRIF (dep. 92 uniquement).</p>
        </div>
        <div class="circ-desc">
            <h3><span class="badge-circ bc3">3ème circ.</span></h3>
            <p>Communes de l'unité urbaine de Paris (arrêté du 28 novembre 2024) autres que Paris et les communes du département des Hauts-de-Seine.</p>
        </div>
        <div class="circ-desc">
            <h3><span class="badge-circ bc4">4ème circ.</span></h3>
            <p>Autres communes de la région Île-de-France + par dérogation : communes de la 3ème circ. éligibles à la fois à la DSU-CS et au FSRIF (DCSUCS hors dep. 92).</p>
        </div>
        <div class="circ-desc" style="margin-top:8px">
            <h3><span class="badge-circ bpaca">PACA</span></h3>
            <p>Communes des départements des Bouches-du-Rhône (13), du Var (83) et des Alpes-Maritimes (06). 1 seule circonscription.</p>
        </div>

        <h3 style="margin:20px 0 10px;font-size:0.88rem">Communes DCSUCS (dérogation circ 3→4)</h3>
        <p style="font-size:0.8rem;color:var(--text2);margin-bottom:10px">Liste publiée chaque année avec 1 an de décalage (liste parue en N vaut pour N-1).</p>
        <div id="dcsucs-list" style="font-size:0.8rem;color:var(--text3)">Chargement…</div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         EXONÉRATIONS TA
    ═══════════════════════════════════════════════════ -->
    <div class="main-panel" id="panel-ta-exo">

        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:20px">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <div style="display:flex;align-items:center;gap:8px">
                    <label style="font-size:0.82rem;font-weight:600;color:var(--text2)">Département</label>
                    <input type="text" id="exo-dep" placeholder="ex: 69" maxlength="3" style="width:70px">
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <label style="font-size:0.82rem;font-weight:600;color:var(--text2)">Commune (nom)</label>
                    <input type="text" id="exo-com" placeholder="ex: Lyon" style="width:160px">
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <label style="font-size:0.82rem;font-weight:600;color:var(--text2)">Exo. seulement</label>
                    <input type="checkbox" id="exo-only" checked style="width:auto">
                </div>
                <button class="btn" onclick="loadExoTA()">Rechercher</button>
            </div>
        </div>

        <div id="exo-result"><p style="color:var(--text3);font-size:0.82rem">Saisissez un département et/ou une commune pour visualiser les taux et exonérations votés.</p></div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         COMPARATEUR D'ANNÉES
    ═══════════════════════════════════════════════════ -->
    <div class="main-panel" id="panel-comparer">

        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:20px">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                <div style="display:flex;align-items:center;gap:8px">
                    <label style="font-size:0.82rem;font-weight:600;color:var(--text2)">Taxe</label>
                    <select id="cmp-taxe" style="min-width:120px">
                        <option value="tsb">TSB</option>
                        <option value="tass">TASS</option>
                    </select>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <label style="font-size:0.82rem;font-weight:600;color:var(--text2)">Années</label>
                    <div id="cmp-mils-wrap" style="display:flex;gap:6px;flex-wrap:wrap"></div>
                </div>
                <button class="btn" onclick="loadComparaison()">Comparer</button>
            </div>
        </div>

        <div id="cmp-result"></div>
    </div>

</div>

<script>
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ═══════════════════════════════════════════════════════════
// Onglets
// ═══════════════════════════════════════════════════════════
document.querySelectorAll('.main-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.main-tab,.main-panel').forEach(el=>el.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-'+tab.dataset.panel).classList.add('active');
        if (tab.dataset.panel === 'tass-tarifs') loadTassTarifs();
        if (tab.dataset.panel === 'circs')       loadCircs();
    });
});

// ═══════════════════════════════════════════════════════════
// TSB Tarifs
// ═══════════════════════════════════════════════════════════
const CIRC_LABELS = {
    IDF:     { 1:'1ère circ.', 2:'2ème circ.', 3:'3ème circ.', 4:'4ème circ.' },
    IDF_2BIS:{ 2:'2ème circ. bis (réduit 10%)' },
    PACA:    { null:'PACA' },
};
const CIRC_COLORS = { 1:'bc1', 2:'bc2', '2b':'bc2b', 3:'bc3', 4:'bc4', null:'bpaca' };

function renderTsbTarifs(tarifs, millesime) {
    const el = document.getElementById('tsb-tarifs-content');
    if (!tarifs.length) { el.innerHTML='<div class="alert alert-warn">Aucun tarif pour ce millésime.</div>'; return; }

    const types   = [...new Set(tarifs.map(t=>t.type_local))];
    const regions = [
        {key:'IDF',      label:'IDF — Île-de-France', circs:[1,2,3,4]},
        {key:'IDF_2BIS', label:'IDF — 2ème circ. bis (tarif réduit 10%)', circs:[2]},
        {key:'PACA',     label:'PACA — Bouches-du-Rhône, Var, Alpes-Maritimes', circs:[null]},
    ];

    let html = '';
    regions.forEach(reg => {
        const rows = tarifs.filter(t=>t.region===reg.key);
        if (!rows.length) return;
        const cols = reg.circs;
        html += `<div style="margin-bottom:24px">
            <h3 style="font-size:0.85rem;font-weight:700;margin-bottom:8px;color:var(--text)">${escHtml(reg.label)}</h3>
            <div class="tarif-table-wrap"><table class="tarif-table"><thead><tr>
            <th>Type de local</th>`;
        cols.forEach(c=>{
            const lbl = (CIRC_LABELS[reg.key]||{})[c] || `Circ. ${c||''}`;
            const cls = reg.key==='IDF_2BIS'?'bc2b':(c?CIRC_COLORS[c]||'':'bpaca');
            html += `<th><span class="badge-circ ${cls}">${escHtml(lbl)}</span></th>`;
        });
        html += '</tr></thead><tbody>';
        types.forEach(type=>{
            const anyRow = rows.find(r=>r.type_local===type);
            if (!anyRow) return;
            html += `<tr><td>${escHtml(type)}</td>`;
            cols.forEach(c=>{
                const row = rows.find(r=>r.type_local===type && (r.circonscription===c||(c===null&&r.circonscription==null)));
                html += `<td>${row ? (+row.tarif).toFixed(2)+' €/m²' : '–'}</td>`;
            });
            html += '</tr>';
        });
        html += '</tbody></table></div></div>';
    });

    el.innerHTML = html;
    document.getElementById('tsb-tarifs-info').textContent = `Source BOFIP — ${tarifs.length} tarifs`;
}

function loadTsbTarifs(mil) {
    const el = document.getElementById('tsb-tarifs-content');
    el.innerHTML = '<div style="color:var(--text3);font-size:0.82rem">Chargement…</div>';
    fetch('/api/tsb/tarifs?millesime=' + mil)
        .then(r=>r.json())
        .then(d=>renderTsbTarifs(d.tarifs||[], d.millesime))
        .catch(()=>{ el.innerHTML='<div class="alert alert-error">Erreur de chargement.</div>'; });
}

fetch('/api/tsb/tarifs/millesimes').then(r=>r.json()).then(mils=>{
    const sel = document.getElementById('tsb-mil-sel');
    mils.forEach(m=>{
        const opt = document.createElement('option'); opt.value=m; opt.textContent=m; sel.appendChild(opt);
    });
    if (mils.length) loadTsbTarifs(mils[0]);
    sel.addEventListener('change', ()=>loadTsbTarifs(+sel.value));
}).catch(()=>{});

// ═══════════════════════════════════════════════════════════
// TASS Tarifs
// ═══════════════════════════════════════════════════════════
function loadTassTarifs(mil) {
    const el = document.getElementById('tass-tarifs-content');
    el.innerHTML = '<div style="color:var(--text3);font-size:0.82rem">Chargement…</div>';
    const url = mil ? '/api/tass/tarifs?millesime='+mil : '/api/tass/tarifs';
    fetch(url).then(r=>r.json()).then(d=>{
        const tarifs = d.tarifs||[];
        if (!tarifs.length) { el.innerHTML='<div class="alert alert-warn">Aucun tarif disponible.</div>'; return; }
        let html = `<div class="tarif-table-wrap"><table class="tarif-table"><thead><tr>
            <th>Circonscription</th><th>Tarif</th></tr></thead><tbody>`;
        const labels = {1:'1ère circ. — Paris + Hauts-de-Seine', 2:'2ème circ. — UU Paris (hors Paris/92)', 3:'3ème circ. — Reste IDF'};
        const cls    = {1:'bc1', 2:'bc2', 3:'bc3'};
        tarifs.forEach(t=>{
            const c = +t.circonscription;
            html += `<tr><td><span class="badge-circ ${cls[c]||''}">${escHtml(labels[c]||'Circ '+c)}</span></td>
                     <td>${(+t.tarif).toFixed(2)} €/m²</td></tr>`;
        });
        html += '</tbody></table></div>';
        el.innerHTML = html;
    }).catch(()=>{ el.innerHTML='<div class="alert alert-error">Erreur de chargement.</div>'; });

    // Stats géo
    fetch('/api/tass').then(r=>r.json()).then(fc=>{
        const counts = {1:0,2:0,3:0};
        (fc.features||[]).forEach(f=>{ const c=+f.properties.circonscription; if(counts[c]!==undefined) counts[c]++; });
        const statsEl = document.getElementById('tass-circ-stats');
        statsEl.innerHTML = [1,2,3].map(c=>`<div class="stat-box"><div class="val">${counts[c]}</div><div class="lbl">${c}<sup>${c===1?'ère':'ème'}</sup> circ.</div></div>`).join('');
    }).catch(()=>{});
}

fetch('/api/tass/millesimes').then(r=>r.json()).then(mils=>{
    const sel = document.getElementById('tass-mil-sel');
    mils.forEach(m=>{ const opt=document.createElement('option'); opt.value=m; opt.textContent=m; sel.appendChild(opt); });
    if (mils.length) loadTassTarifs(mils[0]);
    sel.addEventListener('change', ()=>loadTassTarifs(+sel.value));
}).catch(()=>{});

// ═══════════════════════════════════════════════════════════
// Circonscriptions TSB
// ═══════════════════════════════════════════════════════════
function loadCircs(mil) {
    const url = mil ? '/api/tsb?region=IDF&millesime='+mil : '/api/tsb?region=IDF';
    fetch(url).then(r=>r.json()).then(fc=>{
        const counts = {1:0,2:0,'2b':0,3:0,4:0,paca:0};
        let hasDcsucs = false;
        (fc.features||[]).forEach(f=>{
            const c = +f.properties.circonscription;
            const is2b = c===2 && f.properties.dcsucs;
            if (f.properties.dcsucs) hasDcsucs = true;
            if (is2b) counts['2b']++;
            else if (counts[c]!==undefined) counts[c]++;
        });
        // Stats PACA
        fetch('/api/tsb?region=PACA').then(r=>r.json()).then(fp=>{
            counts.paca = (fp.features||[]).length;
            const statsEl = document.getElementById('circ-stats');
            const items = [
                {c:'1',  lbl:'1ère circ.',  cls:'bc1',   val:counts[1]},
                {c:'2',  lbl:'2ème circ.',  cls:'bc2',   val:counts[2]},
                {c:'2b', lbl:'2ème bis',    cls:'bc2b',  val:counts['2b']},
                {c:'3',  lbl:'3ème circ.',  cls:'bc3',   val:counts[3]},
                {c:'4',  lbl:'4ème circ.',  cls:'bc4',   val:counts[4]},
                {c:'p',  lbl:'PACA',        cls:'bpaca', val:counts.paca},
            ];
            statsEl.innerHTML = items.map(i=>`<div class="stat-box">
                <div class="val">${i.val}</div>
                <div class="lbl"><span class="badge-circ ${i.cls}" style="font-size:0.7rem">${i.lbl}</span></div>
            </div>`).join('');
        });
    });

    // DCSUCS communes
    fetch('/api/tsb?region=IDF'+(mil?'&millesime='+mil:'')).then(r=>r.json()).then(fc=>{
        const dcsucs = (fc.features||[]).filter(f=>f.properties.dcsucs && +f.properties.circonscription===4);
        const byDep = {};
        dcsucs.forEach(f=>{ const d=f.properties.dep; if(!byDep[d]) byDep[d]=[]; byDep[d].push(f.properties.libcom||f.properties.code_insee); });
        const el = document.getElementById('dcsucs-list');
        if (!Object.keys(byDep).length) { el.innerHTML='<span style="color:var(--text3)">Aucune commune DCSUCS pour ce millésime.</span>'; return; }
        el.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px">' +
            Object.entries(byDep).sort().map(([dep,coms])=>`
                <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:8px 12px">
                    <div style="font-weight:700;font-size:0.8rem;margin-bottom:4px">Dep. ${escHtml(dep)} (${coms.length})</div>
                    <div style="font-size:0.76rem;color:var(--text2);line-height:1.6">${coms.map(escHtml).join(', ')}</div>
                </div>`).join('') + '</div>';
    }).catch(()=>{});
}

fetch('/api/tsb/millesimes').then(r=>r.json()).then(mils=>{
    const sel = document.getElementById('circ-mil-sel');
    mils.forEach(m=>{ const opt=document.createElement('option'); opt.value=m; opt.textContent=m; sel.appendChild(opt); });
    if (mils.length) loadCircs(mils[0]);
    sel.addEventListener('change', ()=>loadCircs(+sel.value));
}).catch(()=>{});

// ═══════════════════════════════════════════════════════════
// Comparateur d'années
// ═══════════════════════════════════════════════════════════
let cmpAllMils = { tsb: [], tass: [] };

// Pré-charger les millésimes dispo pour les deux taxes
Promise.all([
    fetch('/api/tsb/tarifs/millesimes').then(r=>r.json()).catch(()=>[]),
    fetch('/api/tass/millesimes').then(r=>r.json()).catch(()=>[]),
]).then(([tsbMils, tassMils]) => {
    cmpAllMils.tsb  = tsbMils.map(Number).sort((a,b)=>b-a);
    cmpAllMils.tass = tassMils.map(Number).sort((a,b)=>b-a);
    buildCmpCheckboxes();
});

document.getElementById('cmp-taxe').addEventListener('change', buildCmpCheckboxes);

function buildCmpCheckboxes() {
    const taxe = document.getElementById('cmp-taxe').value;
    const mils = cmpAllMils[taxe] || [];
    const wrap = document.getElementById('cmp-mils-wrap');
    wrap.innerHTML = mils.map((m,i) => `
        <label class="cmp-mil-cb">
            <input type="checkbox" value="${m}" ${i < 3 ? 'checked' : ''}>
            ${m}
        </label>`).join('');
}

function loadComparaison() {
    const taxe = document.getElementById('cmp-taxe').value;
    const checked = [...document.querySelectorAll('#cmp-mils-wrap input:checked')].map(i=>+i.value).sort((a,b)=>a-b);
    if (checked.length < 2) {
        document.getElementById('cmp-result').innerHTML = '<div class="alert alert-warn">Sélectionnez au moins 2 années à comparer.</div>';
        return;
    }
    const el = document.getElementById('cmp-result');
    el.innerHTML = '<div style="color:var(--text3);font-size:0.82rem">Chargement…</div>';

    const apiBase = taxe === 'tsb' ? '/api/tsb/tarifs' : '/api/tass/tarifs';
    Promise.all(checked.map(m => fetch(`${apiBase}?millesime=${m}`).then(r=>r.json())))
        .then(results => renderComparaison(taxe, checked, results))
        .catch(() => { el.innerHTML = '<div class="alert alert-error">Erreur de chargement.</div>'; });
}

function renderComparaison(taxe, mils, results) {
    const el = document.getElementById('cmp-result');
    const fmt = v => v != null ? (+v).toFixed(2) + ' €/m²' : '–';

    // Construire la liste des lignes (groupe + type)
    const rows = [];
    results.forEach(r => {
        (r.tarifs || []).forEach(t => {
            const key = (t.region||'') + '|' + (t.circonscription ?? 'null') + '|' + (t.type_local||'');
            if (!rows.find(x => x.key === key)) {
                rows.push({ key, region: t.region, circ: t.circonscription, type: t.type_local });
            }
        });
    });

    // Grouper par region+circ
    const groups = [];
    rows.forEach(row => {
        const gk = (row.region||'') + '|' + (row.circ ?? 'null');
        let g = groups.find(g => g.gk === gk);
        if (!g) {
            const regLabel = { IDF:'IDF', IDF_2BIS:'IDF 2bis', PACA:'PACA' }[row.region] || row.region;
            const circLabel = row.circ ? ` — Circ. ${row.circ}` : '';
            g = { gk, label: regLabel + circLabel, rows: [] };
            groups.push(g);
        }
        g.rows.push(row);
    });

    // Construire le tableau
    const milRef = mils[0]; // première année = référence
    let html = '<div style="overflow-x:auto"><table class="cmp-table"><thead><tr>';
    html += '<th>Type de local</th>';
    mils.forEach(m => { html += `<th>${m}</th>`; });
    // Colonnes évolution entre chaque année consécutive
    for (let i = 1; i < mils.length; i++) {
        html += `<th>${mils[i-1]}→${mils[i]}</th>`;
    }
    // Colonne évolution globale si plus de 2 années
    if (mils.length > 2) html += `<th>${mils[0]}→${mils[mils.length-1]}</th>`;
    html += '</tr></thead><tbody>';

    groups.forEach(g => {
        html += `<tr><td colspan="${mils.length + (mils.length - 1) + (mils.length > 2 ? 1 : 0) + 1}" class="cmp-group">${escHtml(g.label)}</td></tr>`;
        g.rows.forEach(row => {
            // Récupérer les tarifs par année
            const vals = mils.map(m => {
                const res = results.find(r => r.millesime === m);
                const t = (res?.tarifs || []).find(t =>
                    t.region === row.region &&
                    (t.circonscription ?? null) === (row.circ ?? null) &&
                    t.type_local === row.type
                );
                return t ? +t.tarif : null;
            });

            html += `<tr><td>${escHtml(row.type)}</td>`;
            vals.forEach(v => { html += `<td>${fmt(v)}</td>`; });

            // Évolutions consécutives
            for (let i = 1; i < vals.length; i++) {
                if (vals[i] != null && vals[i-1] != null) {
                    const pct = ((vals[i] - vals[i-1]) / vals[i-1] * 100);
                    const cls = pct > 0.01 ? 'cmp-up' : pct < -0.01 ? 'cmp-down' : 'cmp-same';
                    const sign = pct > 0 ? '+' : '';
                    html += `<td class="${cls}">${sign}${pct.toFixed(1)} %</td>`;
                } else html += `<td class="cmp-same">–</td>`;
            }

            // Évolution globale
            if (mils.length > 2) {
                const v0 = vals[0], vn = vals[vals.length-1];
                if (v0 != null && vn != null) {
                    const pct = ((vn - v0) / v0 * 100);
                    const cls = pct > 0.01 ? 'cmp-up' : pct < -0.01 ? 'cmp-down' : 'cmp-same';
                    const sign = pct > 0 ? '+' : '';
                    html += `<td class="${cls}" style="font-weight:700">${sign}${pct.toFixed(1)} %</td>`;
                } else html += `<td class="cmp-same">–</td>`;
            }

            html += '</tr>';
        });
    });

    html += '</tbody></table></div>';
    document.getElementById('cmp-result').innerHTML = html;
}

// Déclencher au clic sur l'onglet
document.querySelector('[data-panel="comparer"]').addEventListener('click', () => {
    if (!document.getElementById('cmp-mils-wrap').children.length) buildCmpCheckboxes();
});

// ═══════════════════════════════════════════════════════════
// Exonérations TA
// ═══════════════════════════════════════════════════════════
const EXO_LABELS = {
    exo_habitation:        'Locaux d\'habitation',
    exo_pret_ptx:          'Logements PTZ',
    exo_industriel:        'Locaux industriels / artisanaux',
    exo_commerce:          'Commerces de détail',
    exo_immeubles_classes: 'Immeubles classés',
    exo_abris_jardin:      'Abris de jardin',
    exo_maisons_sante:     'Maisons de santé',
    exo_terrains_rehab:    'Terrains réhabilités',
    exo_transf_habitation: 'Transf. locaux → habitation',
};

document.getElementById('exo-dep').addEventListener('keydown', e => { if(e.key==='Enter') loadExoTA(); });
document.getElementById('exo-com').addEventListener('keydown', e => { if(e.key==='Enter') loadExoTA(); });

function loadExoTA() {
    const dep     = document.getElementById('exo-dep').value.trim();
    const com     = document.getElementById('exo-com').value.trim();
    const exoOnly = document.getElementById('exo-only').checked;
    const el      = document.getElementById('exo-result');

    if (!dep && !com) { el.innerHTML='<div class="alert alert-warn">Saisissez au moins un département ou un nom de commune.</div>'; return; }

    el.innerHTML = '<div style="color:var(--text3);font-size:0.82rem">Chargement…</div>';

    const params = new URLSearchParams({ exo_only: exoOnly ? '1' : '0' });
    if (dep) params.set('dep', dep);
    if (com) params.set('com', com);

    fetch('/api/ta/exo?' + params)
    .then(r=>r.json())
    .then(rows=>{
        if (!rows.length) { el.innerHTML='<div class="alert alert-warn">Aucune commune trouvée avec ces critères.</div>'; return; }

        let html = `<p style="font-size:0.78rem;color:var(--text3);margin-bottom:10px">${rows.length} commune(s) — <a href="https://www.impots.gouv.fr/portail/particulier/taxe-damenagement" target="_blank" class="exo-link">→ Accéder à la déclaration TA (DGFiP)</a></p>`;
        html += '<div style="overflow-x:auto"><table class="exo-table"><thead><tr>';
        html += '<th>Commune</th><th>Dep.</th><th>Taux com.</th><th>Taux total</th><th>Forfait station.</th><th>Exonérations votées</th><th>Date effet</th></tr></thead><tbody>';

        rows.forEach(r=>{
            const exos = Object.entries(EXO_LABELS)
                .filter(([k]) => r[k] != null)
                .map(([k, lbl]) => `<span class="exo-badge">${escHtml(lbl)} : ${(+r[k]).toFixed(0)} %</span>`)
                .join(' ');

            html += `<tr>
                <td><strong>${escHtml(r.libcom||r.code_insee)}</strong><br><span style="color:var(--text3);font-size:0.72rem">${escHtml(r.code_insee)}</span></td>
                <td>${escHtml(r.dep)}</td>
                <td class="exo-taux">${r.taux_com != null ? (+r.taux_com).toFixed(2)+' %' : '–'}</td>
                <td class="exo-taux">${r.taux_total != null ? (+r.taux_total).toFixed(2)+' %' : '–'}</td>
                <td>${r.val_forfait_station != null ? (+r.val_forfait_station).toLocaleString('fr-FR')+' €' : '–'}</td>
                <td>${exos || '<span style="color:var(--text3);font-size:0.75rem">Aucune</span>'}</td>
                <td style="white-space:nowrap">${r.date_effet ? r.date_effet.slice(0,10) : '–'}</td>
            </tr>`;
        });

        html += '</tbody></table></div>';
        el.innerHTML = html;
    })
    .catch(e=>{ el.innerHTML='<div class="alert alert-error">Erreur : '+escHtml(e.message)+'</div>'; });
}
</script>
</body>
</html>
