<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSig — Requêtes</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .query-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        @media (max-width: 900px) { .query-grid { grid-template-columns: 1fr; } }

        .query-builder label { font-size: 12px; color: var(--text3); display: block; margin-bottom: 2px; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
        .query-builder .field { margin-bottom: 12px; position: relative; }
        .query-builder select, .query-builder input[type=text] { width: 100%; }

        .autocomplete-list {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 200;
            background: var(--surface); border: 1px solid var(--border);
            border-top: none; border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--shadow); max-height: 180px; overflow-y: auto;
        }
        .autocomplete-list .item { padding: 7px 11px; font-size: 0.82rem; cursor: pointer; color: var(--text2); }
        .autocomplete-list .item:hover { background: var(--blue-hover); color: var(--blue); }

        .generated-sql {
            background: var(--surface2); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 10px 13px;
            font-family: 'Courier New', monospace; font-size: 0.78rem;
            color: var(--text2); white-space: pre-wrap; word-break: break-all;
            margin-bottom: 10px; min-height: 38px;
        }

        .result-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); margin-top: 10px; }
        .result-table-wrap table { font-size: 0.8rem; }

        .sql-free textarea { font-family: 'Courier New', monospace; font-size: 0.82rem; }

        .tab-bar { display: flex; gap: 4px; border-bottom: 2px solid var(--border); margin-bottom: 18px; }
        .tab { padding: 8px 16px; font-size: 0.85rem; font-weight: 600; cursor: pointer;
               color: var(--text3); border: none; background: none; border-bottom: 2px solid transparent;
               margin-bottom: -2px; transition: color .15s; }
        .tab.active { color: var(--blue); border-bottom-color: var(--blue); }
        .tab-panel { display: none; } .tab-panel.active { display: block; }

        .badge-count { background: var(--blue-hover); color: var(--blue); padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 700; margin-left: 6px; }
    </style>
    <script>const _P={carte:'/',donnees:'/donnees',requetes:'/requetes',crm:'/crm'};function navTo(p){window.parent?.showPage?window.parent.showPage(p):location.href=_P[p]||'/';}if(window.self!==window.top)document.addEventListener('DOMContentLoaded',()=>{const n=document.querySelector('nav');if(n)n.style.display='none';document.body.style.paddingTop='0';});</script>
</head>
<body>

<nav>
    <span class="nav-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="white" stroke-width="1.5"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10A15.3 15.3 0 0 1 8 12 15.3 15.3 0 0 1 12 2z" stroke="white" stroke-width="1.5"/></svg>
        RSig
    </span>
    <a href="#" onclick="navTo('carte');return false">Carte</a>
    <a href="#" onclick="navTo('donnees');return false">Données</a>
    <a href="#" class="active">Requêtes</a>
    <a href="#" onclick="navTo('crm');return false">CRM</a>
</nav>

<div class="page-content" style="max-width:1200px">
    <h1>Requêtes</h1>

    <?php if (!$connected): ?>
    <div class="alert alert-error">Base PostgreSQL non disponible. <a href="/api/db-check" target="_blank">→ Diagnostic</a></div>
    <?php endif; ?>

    <div class="tab-bar">
        <button class="tab active" data-tab="tarifs">Tarifs locatifs</button>
        <button class="tab" data-tab="coeff">Coefficients de localisation</button>
        <button class="tab" data-tab="libre">SQL libre</button>
    </div>

    <!-- ═══ TARIFS ═══════════════════════════════════════════ -->
    <div class="tab-panel active" id="tab-tarifs">
        <div class="query-grid">
            <div class="card query-builder">
                <h2>Tarifs par section</h2>
                <p>Retrouve les tarifs locatifs d'une catégorie sur une commune donnée.</p>

                <div class="field">
                    <label>Commune</label>
                    <input type="text" id="tarifs-commune-input" placeholder="ex : Paris, Bordeaux…" autocomplete="off" <?= !$connected ? 'disabled' : '' ?>>
                    <input type="hidden" id="tarifs-commune-code">
                    <div class="autocomplete-list" id="tarifs-commune-list" style="display:none"></div>
                </div>

                <div class="field">
                    <label>Catégorie</label>
                    <select id="tarifs-cat-select" <?= !$connected ? 'disabled' : '' ?>>
                        <option value="">— chargement —</option>
                    </select>
                </div>

                <div class="field">
                    <label>Année</label>
                    <select id="tarifs-annee-select" <?= !$connected ? 'disabled' : '' ?>>
                        <option value="2026">2026</option>
                        <option value="2025" selected>2025</option>
                        <option value="2024">2024</option>
                        <option value="2023">2023</option>
                        <option value="2022">2022</option>
                        <option value="2021">2021</option>
                        <option value="2020">2020</option>
                        <option value="2019">2019</option>
                        <option value="2017">2017</option>
                    </select>
                </div>

                <div class="field">
                    <label>Secteur (optionnel)</label>
                    <select id="tarifs-secteur-select" <?= !$connected ? 'disabled' : '' ?>>
                        <option value="">Tous</option>
                        <option value="1">Secteur 1</option>
                        <option value="2">Secteur 2</option>
                        <option value="3">Secteur 3</option>
                        <option value="4">Secteur 4</option>
                        <option value="5">Secteur 5</option>
                        <option value="6">Secteur 6</option>
                        <option value="7">Secteur 7</option>
                    </select>
                </div>

                <div class="generated-sql" id="tarifs-sql-preview">Remplis les champs ci-dessus pour générer la requête.</div>
                <button class="btn" id="tarifs-run" <?= !$connected ? 'disabled' : '' ?>>Lancer la requête</button>
            </div>

            <div>
                <div class="card" id="tarifs-result-card" style="display:none">
                    <h2>Résultats <span class="badge-count" id="tarifs-count"></span></h2>
                    <div class="result-table-wrap" id="tarifs-result"></div>
                </div>
                <div class="alert alert-info" id="tarifs-empty" style="display:none">Aucun résultat pour ces critères.</div>
                <div class="alert alert-error" id="tarifs-error" style="display:none"></div>
            </div>
        </div>
    </div>

    <!-- ═══ COEFFICIENTS ═════════════════════════════════════ -->
    <div class="tab-panel" id="tab-coeff">
        <div class="query-grid">
            <div class="card query-builder">
                <h2>Coefficients de localisation</h2>
                <p>Retrouve les coefficients par parcelle sur une commune.</p>

                <div class="field">
                    <label>Commune</label>
                    <input type="text" id="coeff-commune-input" placeholder="ex : Bordeaux, Lyon…" autocomplete="off" <?= !$connected ? 'disabled' : '' ?>>
                    <input type="hidden" id="coeff-commune-code">
                    <div class="autocomplete-list" id="coeff-commune-list" style="display:none"></div>
                </div>

                <div class="field">
                    <label>Année du coefficient</label>
                    <select id="coeff-annee-select" <?= !$connected ? 'disabled' : '' ?>>
                        <option value="coeff_2026" selected>2026</option>
                        <option value="coeff_2024">2024</option>
                        <option value="coeff_2020">2020</option>
                        <option value="coeff_2019">2019</option>
                        <option value="coeff_2018">2018</option>
                        <option value="coeff_2017">2017</option>
                    </select>
                </div>

                <div class="field">
                    <label>Section cadastrale (optionnel)</label>
                    <input type="text" id="coeff-section-input" placeholder="ex : A, B, AB…" autocomplete="off" <?= !$connected ? 'disabled' : '' ?>>
                </div>

                <div class="generated-sql" id="coeff-sql-preview">Remplis les champs ci-dessus pour générer la requête.</div>
                <button class="btn" id="coeff-run" <?= !$connected ? 'disabled' : '' ?>>Lancer la requête</button>
            </div>

            <div>
                <div class="card" id="coeff-result-card" style="display:none">
                    <h2>Résultats <span class="badge-count" id="coeff-count"></span></h2>
                    <div class="result-table-wrap" id="coeff-result"></div>
                </div>
                <div class="alert alert-info" id="coeff-empty" style="display:none">Aucun résultat pour ces critères.</div>
                <div class="alert alert-error" id="coeff-error" style="display:none"></div>
            </div>
        </div>
    </div>

    <!-- ═══ SQL LIBRE ════════════════════════════════════════ -->
    <div class="tab-panel sql-free" id="tab-libre">
        <div class="card">
            <h2>SQL libre</h2>
            <p class="hint">Lecture seule — uniquement SELECT. Résultats limités à 500 lignes.</p>
            <div class="field">
                <textarea id="libre-sql" rows="7" placeholder="SELECT s.nom_com, s.section, s.secteur, t.val_2025&#10;FROM sections_2025 s&#10;JOIN tarifs_pivot t ON t.dep = s.code_dep AND t.num_secteur = s.secteur&#10;WHERE left(s.code_insee,5) = '75056' AND t.categorie = 'ATE1'&#10;LIMIT 50;" <?= !$connected ? 'disabled' : '' ?>></textarea>
            </div>
            <button class="btn" id="libre-run" <?= !$connected ? 'disabled' : '' ?>>Exécuter</button>
        </div>

        <div class="card" id="libre-result-card" style="display:none; margin-top:0">
            <h2>Résultats <span class="badge-count" id="libre-count"></span></h2>
            <div class="result-table-wrap" id="libre-result"></div>
        </div>
        <div class="alert alert-info" id="libre-empty" style="display:none">Aucun résultat.</div>
        <div class="alert alert-error" id="libre-error" style="display:none"></div>
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

// ── Rendu tableau ─────────────────────────────────────────
function renderTable(cols, rows, container) {
    if (!cols.length) { container.innerHTML = ''; return; }
    const ths = cols.map(c => `<th>${c}</th>`).join('');
    const trs = rows.map(r =>
        '<tr>' + cols.map(c => `<td title="${String(r[c]??'').replace(/"/g,'&quot;')}">${r[c]??''}</td>`).join('') + '</tr>'
    ).join('');
    container.innerHTML = `<table><thead><tr>${ths}</tr></thead><tbody>${trs}</tbody></table>`;
}

function runQuery(sql, resultEl, emptyEl, errorEl, countEl, cardEl) {
    [resultEl, emptyEl, errorEl].forEach(el => el.style.display = 'none');
    if (cardEl) cardEl.style.display = 'none';
    fetch('/api/query', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ sql }) })
    .then(r => r.json())
    .then(d => {
        if (d.error) { errorEl.textContent = 'Erreur : ' + d.error; errorEl.style.display = 'block'; return; }
        if (!d.rows?.length) { emptyEl.style.display = 'block'; return; }
        if (countEl) countEl.textContent = d.count + ' ligne' + (d.count > 1 ? 's' : '');
        if (cardEl) cardEl.style.display = 'block';
        renderTable(d.cols, d.rows, resultEl);
    })
    .catch(() => { errorEl.textContent = 'Erreur de communication.'; errorEl.style.display = 'block'; });
}

// ── Autocomplete commune générique ────────────────────────
function setupAutocomplete(inputEl, hiddenEl, listEl, onSelect) {
    let timer;
    inputEl.addEventListener('input', () => {
        clearTimeout(timer);
        const q = inputEl.value.trim();
        hiddenEl.value = '';
        if (q.length < 2) { listEl.style.display = 'none'; return; }
        timer = setTimeout(() => {
            fetch('/api/communes/search?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(results => {
                if (!results.length) { listEl.style.display = 'none'; return; }
                listEl.innerHTML = results.map(r =>
                    `<div class="item" data-code="${r.code_insee}" data-label="${r.label}">${r.label}</div>`
                ).join('');
                listEl.style.display = 'block';
                listEl.querySelectorAll('.item').forEach(item => {
                    item.addEventListener('click', () => {
                        inputEl.value    = item.dataset.label;
                        hiddenEl.value   = item.dataset.code;
                        listEl.style.display = 'none';
                        if (onSelect) onSelect(item.dataset.code, item.dataset.label);
                    });
                });
            });
        }, 300);
    });
    document.addEventListener('click', e => {
        if (!e.target.closest('#' + inputEl.id) && !e.target.closest('#' + listEl.id))
            listEl.style.display = 'none';
    });
}

// ── TARIFS ────────────────────────────────────────────────
// Charger les catégories
fetch('/api/tarifs/categories').then(r=>r.json()).then(cats => {
    const sel = document.getElementById('tarifs-cat-select');
    sel.innerHTML = cats.map(c => `<option value="${c}">${c}</option>`).join('');
    updateTarifsPreview();
}).catch(()=>{});

function updateTarifsPreview() {
    const insee   = document.getElementById('tarifs-commune-code').value;
    const commune = document.getElementById('tarifs-commune-input').value.trim();
    const cat     = document.getElementById('tarifs-cat-select').value;
    const annee   = document.getElementById('tarifs-annee-select').value;
    const secteur = document.getElementById('tarifs-secteur-select').value;
    const col     = 'val_' + annee;

    if (!commune || !cat) {
        document.getElementById('tarifs-sql-preview').textContent = 'Remplis les champs ci-dessus pour générer la requête.';
        return;
    }

    const communeFilter = insee
        ? `left(s.code_insee, 5) = '${insee}'`
        : `lower(s.nom_com) = lower('${commune.replace(/'/g,"''")}')`;
    const secteurFilter = secteur ? `\n  AND s.secteur = ${secteur}` : '';

    document.getElementById('tarifs-sql-preview').textContent =
`SELECT s.nom_com, s.section, s.secteur, t.categorie,
       t.${col} AS tarif_${annee}
FROM sections_2025 s
JOIN tarifs_pivot t
  ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3)
                  ELSE s.code_dep END
 AND t.num_secteur = s.secteur
 AND t.categorie = '${cat}'
WHERE ${communeFilter}${secteurFilter}
ORDER BY s.section
LIMIT 500`;
}

['tarifs-cat-select','tarifs-annee-select','tarifs-secteur-select'].forEach(id =>
    document.getElementById(id)?.addEventListener('change', updateTarifsPreview)
);

setupAutocomplete(
    document.getElementById('tarifs-commune-input'),
    document.getElementById('tarifs-commune-code'),
    document.getElementById('tarifs-commune-list'),
    () => updateTarifsPreview()
);

document.getElementById('tarifs-run').addEventListener('click', () => {
    const sql = document.getElementById('tarifs-sql-preview').textContent;
    if (sql.startsWith('Remplis')) return;
    runQuery(sql,
        document.getElementById('tarifs-result'),
        document.getElementById('tarifs-empty'),
        document.getElementById('tarifs-error'),
        document.getElementById('tarifs-count'),
        document.getElementById('tarifs-result-card')
    );
});

// ── COEFFICIENTS ──────────────────────────────────────────
function updateCoeffPreview() {
    const insee   = document.getElementById('coeff-commune-code').value;
    const commune = document.getElementById('coeff-commune-input').value.trim();
    const champ   = document.getElementById('coeff-annee-select').value;
    const section = document.getElementById('coeff-section-input').value.trim().toUpperCase();

    if (!commune) {
        document.getElementById('coeff-sql-preview').textContent = 'Remplis les champs ci-dessus pour générer la requête.';
        return;
    }

    const communeFilter = insee
        ? `codecommune = '${insee}'`
        : `lower(codecommune) IN (SELECT left(code_insee,5) FROM sections_2025 WHERE lower(nom_com) = lower('${commune.replace(/'/g,"''")}'))`;
    const sectionFilter = section ? `\n  AND section = '${section.replace(/'/g,"''")}'` : '';

    document.getElementById('coeff-sql-preview').textContent =
`SELECT idu, codecommune, section, parcelle,
       coeff_2017, coeff_2018, coeff_2019,
       coeff_2020, coeff_2024, coeff_2026,
       ${champ} AS coeff_selectionne
FROM coeff_loc_final
WHERE ${communeFilter}${sectionFilter}
ORDER BY section, parcelle
LIMIT 500`;
}

['coeff-annee-select'].forEach(id =>
    document.getElementById(id)?.addEventListener('change', updateCoeffPreview)
);
document.getElementById('coeff-section-input').addEventListener('input', updateCoeffPreview);

setupAutocomplete(
    document.getElementById('coeff-commune-input'),
    document.getElementById('coeff-commune-code'),
    document.getElementById('coeff-commune-list'),
    () => updateCoeffPreview()
);

document.getElementById('coeff-run').addEventListener('click', () => {
    const sql = document.getElementById('coeff-sql-preview').textContent;
    if (sql.startsWith('Remplis')) return;
    runQuery(sql,
        document.getElementById('coeff-result'),
        document.getElementById('coeff-empty'),
        document.getElementById('coeff-error'),
        document.getElementById('coeff-count'),
        document.getElementById('coeff-result-card')
    );
});

// ── SQL LIBRE ─────────────────────────────────────────────
document.getElementById('libre-run').addEventListener('click', () => {
    const sql = document.getElementById('libre-sql').value.trim();
    if (!sql) return;
    runQuery(sql,
        document.getElementById('libre-result'),
        document.getElementById('libre-empty'),
        document.getElementById('libre-error'),
        document.getElementById('libre-count'),
        document.getElementById('libre-result-card')
    );
});
</script>
</body>
</html>
