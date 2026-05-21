<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSig — Carte</title>
    <link rel="stylesheet" href="https://unpkg.com/maplibre-gl/dist/maplibre-gl.css" crossorigin="">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        /* Évite le FOUC : body invisible jusqu'au premier paint avec CSS */
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
</nav>
<!-- Iframes pages secondaires — chargées une seule fois, gardent leur état -->
<div id="page-overlay" style="display:none;position:fixed;top:48px;left:0;right:0;bottom:0;z-index:100;flex-direction:column">
    <iframe id="iframe-donnees"  src=""  style="width:100%;height:100%;border:none;display:none"></iframe>
    <iframe id="iframe-requetes" src=""  style="width:100%;height:100%;border:none;display:none"></iframe>
    <iframe id="iframe-crm"      src=""  style="width:100%;height:100%;border:none;display:none"></iframe>
</div>

<div id="app">

    <!-- ═══ PANNEAU GAUCHE ══════════════════════════════════ -->
    <aside id="panel-left">
        <div class="panel-left-head">Couches</div>

        <!-- Zoom courant -->
        <div id="zoom-info">Zoom : —</div>

        <!-- Fond cadastral (info seule) -->
        <div class="layer-group">
            <div class="layer-group-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                Fond cadastral IGN
            </div>
            <div class="layer-item" style="font-size:11px;color:var(--text3);padding-bottom:8px">
                Orthophotos + limites selon zoom
            </div>
        </div>

        <!-- Taux fiscaux -->
        <div class="layer-group">
            <div class="layer-group-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>
                Taux fiscaux
            </div>
            <div class="layer-item">
                <label class="layer-toggle">
                    <input type="checkbox" id="toggle-taux">
                    <span>Taux fiscaux</span>
                </label>
                <div id="taux-options" class="sub-options hidden">
                    <div id="taux-level-info" style="font-size:11px;color:var(--text3);margin-bottom:4px"></div>
                    <label>Champ
                        <select id="taux-champ">
                            <option value="taux_fb_commune_vote">TF bâti commune</option>
                            <option value="taux_fnb_commune">TF non bâti commune</option>
                            <option value="taux_fnb_gfp_vote">TF non bâti GFP</option>
                            <option value="taux_tafnb_commune_net">TAFNB commune net</option>
                            <option value="taux_tafnb_gfp_net">TAFNB GFP net</option>
                            <option value="taux_tse_net">TSE net</option>
                            <option value="taux_tse_gemapi_net">TSE GEMAPI net</option>
                            <option value="taux_fb_gfp_vote">TF bâti GFP</option>
                            <option value="taux_teom_plein">TEOM</option>
                        </select>
                    </label>
                </div>
            </div>
        </div>

        <!-- Coefficients de localisation -->
        <div class="layer-group">
            <div class="layer-group-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10H3M21 6H3M21 14H3M21 18H3"/></svg>
                Coeff. de localisation
            </div>
            <div class="layer-item">
                <label class="layer-toggle">
                    <input type="checkbox" id="toggle-coeff">
                    <span>Parcelles</span>
                </label>
                <div id="coeff-options" class="sub-options hidden">
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
        </div>

        <!-- Dossiers -->
        <div class="layer-group">
            <div class="layer-group-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>
                Dossiers
            </div>
            <div class="layer-item">
                <label class="layer-toggle">
                    <input type="checkbox" id="toggle-dossiers">
                    <span>Points dossiers</span>
                </label>
            </div>
        </div>

        <!-- Tarifs locatifs -->
        <div class="layer-group">
            <div class="layer-group-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                Tarifs locatifs
            </div>
            <div class="layer-item">
                <label class="layer-toggle">
                    <input type="checkbox" id="toggle-tarifs">
                    <span>Tarifs locatifs</span>
                </label>
                <div id="tarifs-options" class="sub-options hidden">
                    <div id="tarifs-level-info" style="font-size:11px;color:var(--text3);margin-bottom:4px"></div>
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
        </div>

    </aside>

    <!-- ═══ CARTE ════════════════════════════════════════════ -->
    <div id="map-wrap">
        <div id="map"></div>
        <!-- Barre de recherche centrée sur la carte -->
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
const PAGES = { donnees: '/donnees', requetes: '/requetes', crm: '/crm' };
const overlay = document.getElementById('page-overlay');

function showPage(page) {
    const isMap = (page === 'carte');

    overlay.style.display = isMap ? 'none' : 'flex';
    // Rend #app inactif sans le détruire (MapLibre doit rester monté)
    const app = document.getElementById('app');
    app.style.visibility  = isMap ? '' : 'hidden';
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

// Restaure la page active selon l'URL — après chargement complet pour ne pas bloquer MapLibre
window.addEventListener('load', () => {
    const pathToPage = { '/donnees': 'donnees', '/requetes': 'requetes', '/crm': 'crm' };
    const initPage = pathToPage[location.pathname] || 'carte';
    if (initPage !== 'carte') showPage(initPage);
    else history.replaceState({ page: 'carte' }, '', location.pathname);
});
</script>
</body>
</html>
