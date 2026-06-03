<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSig — Carte</title>
    <link rel="stylesheet" href="https://unpkg.com/maplibre-gl/dist/maplibre-gl.css" crossorigin="">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        body { opacity: 0; transition: opacity .15s; }
        body.ready { opacity: 1; }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => document.body.classList.add('ready'));
    </script>
</head>
<body>

<nav>
    <span class="nav-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="12" cy="12" r="10" stroke="white" stroke-width="1.5"/>
            <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10A15.3 15.3 0 0 1 8 12 15.3 15.3 0 0 1 12 2z" stroke="white" stroke-width="1.5"/>
        </svg>
        RSig
    </span>
    <a href="#" class="active" data-page="carte">Carte</a>
    <a href="#" data-page="requetes">Requêtes</a>
    <a href="#" data-page="crm">CRM</a>
    <a href="#" data-page="donnees" style="margin-left:auto">Données</a>
    <span class="nav-user"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></span>
    <a href="/auth/logout" class="nav-logout" title="Déconnexion">&#x2715;</a>
</nav>

<!-- Iframes pages secondaires -->
<div id="page-overlay" style="display:none;position:fixed;top:48px;left:0;right:0;bottom:0;z-index:100;flex-direction:column">
    <iframe id="iframe-donnees"  src="" style="width:100%;height:100%;border:none;display:none"></iframe>
    <iframe id="iframe-requetes" src="" style="width:100%;height:100%;border:none;display:none"></iframe>
    <iframe id="iframe-crm"      src="" style="width:100%;height:100%;border:none;display:none"></iframe>
</div>

<div id="app">

    <!-- ═══ PANNEAU GAUCHE ══════════════════════════════════ -->
    <aside id="panel-left">
        <div class="panel-left-head">Couches</div>

        <!-- Fond cadastral IGN (informatif) -->
        <div class="layer-row">
            <span class="layer-row-label layer-row-label--static">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                Fond cadastral IGN
            </span>
            <span class="layer-row-hint">ortho + limites selon zoom</span>
        </div>

        <!-- Taux fiscaux -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-taux">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 20h20M5 20V10m4 10V4m4 16v-7m4 7V8"/></svg>
                Taux fiscaux
            </label>
            <div id="taux-options" class="layer-sub hidden">
                <label>Taux
                    <select id="taux-champ">
                        <optgroup label="TFPB — Taxe foncière bâti">
                            <option value="taux_fb_commune_vote">TFPB Commune</option>
                            <option value="taux_fb_syndicats_net">TFPB Syndicat</option>
                            <option value="taux_fb_gfp_vote">TFPB EPCI</option>
                            <option value="taux_tse_net">TFPB TSE</option>
                            <option value="taux_tafnb_commune_net">TFPB TASA</option>
                            <option value="taux_teom_plein">TFPB TEOM</option>
                            <option value="taux_tse_gemapi_net">TFPB GEMAPI</option>
                        </optgroup>
                        <optgroup label="TFPNB — Taxe foncière non bâti">
                            <option value="taux_fnb_commune">TFPNB Commune</option>
                            <option value="taux_fnb_syndicats_net">TFPNB Syndicat</option>
                            <option value="taux_fnb_gfp_vote">TFPNB EPCI</option>
                            <option value="taux_tafnb_gfp_net">TFPNB TASA EPCI</option>
                        </optgroup>
                    </select>
                </label>
                <label>Millésime
                    <select id="taux-millesime"><option value="2025">2025</option></select>
                </label>
            </div>
        </div>

        <!-- Coefficients de localisation -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-coeff">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Coeff. localisation
            </label>
            <div id="coeff-options" class="layer-sub hidden">
                <label>Afficher par
                    <select id="coeff-champ">
                        <option value="coeff_2026">Coeff 2026</option>
                        <option value="coeff_2024">Coeff 2024</option>
                        <option value="coeff_2020">Coeff 2020</option>
                        <option value="coeff_2019">Coeff 2019</option>
                        <option value="coeff_2018">Coeff 2018</option>
                        <option value="coeff_2017">Coeff 2017</option>
                        <option value="evolution">Évolution 2017→2026 (%)</option>
                    </select>
                </label>
            </div>
        </div>

        <!-- Secteurs cadastraux -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-sections">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
                Secteurs cadastraux
            </label>
            <div id="sections-options" class="layer-sub hidden"></div>
        </div>

        <!-- CFE -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-cfe">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
                CFE estimée €/m²
            </label>
            <div id="cfe-options" class="layer-sub hidden">
                <label>Catégorie
                    <select id="cfe-categorie">
                        <option value="">— choisir —</option>
                    </select>
                </label>
                <label>Année
                    <select id="cfe-annee">
                        <option value="2026">2026</option>
                        <option value="2025">2025</option>
                        <option value="2024">2024</option>
                        <option value="2023">2023</option>
                        <option value="2022">2022</option>
                        <option value="2021">2021</option>
                        <option value="2020">2020</option>
                        <option value="2019">2019</option>
                        <option value="2017">2017</option>
                    </select>
                </label>
                <div id="cfe-msg" class="layer-msg"></div>
            </div>
        </div>

        <!-- ZFU -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-zfu">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/><line x1="12" y1="2" x2="12" y2="7"/></svg>
                ZFU — Exo. TSB
            </label>
        </div>

        <!-- Dossiers CRM -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-dossiers">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                Dossiers
            </label>
        </div>

        <!-- Tarifs locatifs -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-tarifs">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Tarifs locatifs
            </label>
            <div id="tarifs-options" class="layer-sub hidden">
                <label>Catégorie
                    <select id="tarifs-cat"><option value="">— chargement —</option></select>
                </label>
                <label>Année
                    <select id="tarifs-annee">
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
                </label>
            </div>
        </div>

    </aside>

    <!-- ═══ CARTE ════════════════════════════════════════════ -->
    <div id="map-wrap">
        <div id="map"></div>
        <div id="search-wrap">
            <input type="search" id="search" placeholder="🔍 Rechercher une adresse…" autocomplete="off">
            <div id="resultats" class="dropdown"></div>
        </div>
        <div id="legend" class="hidden">
            <div id="legend-title"></div>
            <div id="legend-items"></div>
        </div>
        <div id="map-spinner">
            <div class="spinner-ring"></div>
            <span>Chargement…</span>
        </div>
    </div>

    <!-- ═══ PANNEAU DROIT ════════════════════════════════════ -->
    <aside id="panel-right" class="hidden">
        <button id="close-right" title="Fermer">✕</button>
        <div class="panel-right-head" id="info-title">Informations</div>
        <div id="info-content"></div>
    </aside>

</div>

<script src="https://unpkg.com/maplibre-gl/dist/maplibre-gl.js" crossorigin=""></script>
<script type="module" src="assets/map.js"></script>
<script>
// ── Recherche géocodage ──────────────────────────────────
let searchTimer;
const input    = document.getElementById('search');
const dropdown = document.getElementById('resultats');

input.addEventListener('input', function () {
    clearTimeout(searchTimer);
    const val = this.value.trim();
    if (!val) { dropdown.innerHTML = ''; return; }
    searchTimer = setTimeout(() => {
        fetch('/search?barre=' + encodeURIComponent(val))
            .then(r => r.json())
            .then(data => {
                dropdown.innerHTML = '';
                if (!data.results?.length) {
                    dropdown.innerHTML = "<div class='item'>Aucun résultat</div>";
                    return;
                }
                data.results.forEach(r => {
                    const div = document.createElement('div');
                    div.className   = 'item';
                    div.textContent = r.label;
                    div.addEventListener('click', () => {
                        input.value        = r.label;
                        dropdown.innerHTML = '';
                        afficherSurCarte(r.lat, r.lon, r.class);
                    });
                    dropdown.appendChild(div);
                });
            }).catch(() => {});
    }, 400);
});

document.addEventListener('click', e => {
    if (!e.target.closest('#search-wrap')) dropdown.innerHTML = '';
});

// ── Navigation SPA ───────────────────────────────────────
const PAGES   = { donnees: '/donnees', requetes: '/requetes', crm: '/crm' };
const overlay = document.getElementById('page-overlay');

function showPage(page) {
    const isMap = (page === 'carte');
    overlay.style.display = isMap ? 'none' : 'flex';
    const app = document.getElementById('app');
    app.style.visibility   = isMap ? '' : 'hidden';
    app.style.pointerEvents = isMap ? '' : 'none';
    Object.keys(PAGES).forEach(p => {
        const f = document.getElementById('iframe-' + p);
        if (f) f.style.display = 'none';
    });
    if (!isMap) {
        const iframe = document.getElementById('iframe-' + page);
        if (iframe) {
            if (!iframe.getAttribute('data-loaded')) {
                iframe.src = PAGES[page];
                iframe.setAttribute('data-loaded', '1');
            }
            iframe.style.display = 'block';
        }
    }
    document.querySelectorAll('nav a[data-page]').forEach(a => {
        a.classList.toggle('active', a.dataset.page === page);
    });
    history.pushState({ page }, '', isMap ? '/' : PAGES[page]);
}

document.querySelectorAll('nav a[data-page]').forEach(a => {
    a.addEventListener('click', e => { e.preventDefault(); showPage(a.dataset.page); });
});
window.addEventListener('popstate', e => showPage(e.state?.page || 'carte'));
window.addEventListener('load', () => {
    const pathToPage = { '/donnees': 'donnees', '/requetes': 'requetes', '/crm': 'crm' };
    const initPage = pathToPage[location.pathname] || 'carte';
    if (initPage !== 'carte') showPage(initPage);
    else history.replaceState({ page: 'carte' }, '', location.pathname);
});
</script>
</body>
</html>
