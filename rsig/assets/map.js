import { debounce } from './utils.js';
import { updateWfs, initWfsClick } from './wfs.js';
import { applyState, saveState, loadSavedState, readHashState, copyPermalink } from './state.js';
import { initTaux }     from './layers/taux.js';
import { initCoeff }    from './layers/coeff.js';
import { initDossiers } from './layers/dossiers.js';
import { initTarifs }   from './layers/tarifs.js';
import { initZfu }      from './layers/zfu.js';
import { initTsb }      from './layers/tsb.js';
import { initTass }     from './layers/tass.js';
import { initTa }       from './layers/ta.js';
import { initTaMajore } from './layers/ta_majore.js';
import { initSections }          from './layers/sections.js';
import { initCfe }               from './layers/cfe.js';
import { initTf }                from './layers/tf.js';
import { initOrtho, setCampagne } from './layers/ortho.js';
import { initCatalogue }         from './catalogue.js';
import { initDossiersFilter }    from './dossiers-filter.js';

// Pré-charge les catégories tarifs avant que la carte soit prête
const catsReady = fetch('/api/tarifs/categories').then(r => r.json()).catch(e => { console.warn('[rsig] categories', e); return []; });

const map = new maplibregl.Map({
    container: 'map',
    attributionControl: false,
    style: {
        version: 8,
        glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
        sources: {
            ign_ortho: {
                type: 'raster',
                tiles: [
                    'https://data.geopf.fr/wmts?REQUEST=GetTile&SERVICE=WMTS&VERSION=1.0.0'
                    + '&STYLE=normal&TILEMATRIXSET=PM&FORMAT=image/jpeg'
                    + '&LAYER=ORTHOIMAGERY.ORTHOPHOTOS'
                    + '&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}'
                ],
                tileSize: 256, maxzoom: 19, attribution: 'IGN-F/Géoportail',
            },
            ign_labels: {
                type: 'raster',
                tiles: [
                    'https://data.geopf.fr/wmts?REQUEST=GetTile&SERVICE=WMTS&VERSION=1.0.0'
                    + '&STYLE=normal&TILEMATRIXSET=PM&FORMAT=image/png'
                    + '&LAYER=GEOGRAPHICALNAMES.NAMES'
                    + '&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}'
                ],
                tileSize: 256, maxzoom: 19, attribution: 'IGN-F/Géoportail',
            },
        },
        layers: [
            { id: 'ign-ortho',  type: 'raster', source: 'ign_ortho' },
            { id: 'ign-labels', type: 'raster', source: 'ign_labels' },
        ],
    },
    center: [2.35, 46.6],
    zoom: 5,
});

map.addControl(new maplibregl.NavigationControl(), 'top-right');

// ── Contrôle IGN — bas à droite ───────────────────────────
const CAMPAGNES_CTRL = [
    { id: 'actuelle',  label: 'Actuelle (2024-2025)' },
    { id: '2021-2023', label: '2021 – 2023' },
    { id: '2016-2020', label: '2016 – 2020' },
    { id: '2011-2015', label: '2011 – 2015' },
    { id: '2006-2010', label: '2006 – 2010' },
    { id: '2000-2005', label: '2000 – 2005' },
];

class IgnControl {
    onAdd(m) {
        this._map = m;
        this._container = document.createElement('div');
        this._container.className = 'maplibregl-ctrl';
        this._container.style.cssText = [
            'background:rgba(255,255,255,.88)',
            'backdrop-filter:blur(4px)',
            'padding:3px 8px',
            'display:flex',
            'align-items:center',
            'gap:6px',
            'border-radius:4px',
            'box-shadow:0 1px 4px rgba(0,0,0,.15)',
            'white-space:nowrap',
        ].join(';');

        // Logo IGN
        const logo = document.createElement('span');
        logo.innerHTML = `<svg width="24" height="12" viewBox="0 0 56 28"><rect width="56" height="28" rx="3" fill="#003189"/><text x="28" y="20" font-family="Arial" font-weight="700" font-size="16" fill="white" text-anchor="middle">IGN</text></svg>`;
        logo.style.cssText = 'flex-shrink:0;display:flex;align-items:center;opacity:.9';

        // Select campagne
        const sel = document.createElement('select');
        sel.id = 'ign-campagne-ctrl';
        sel.style.cssText = 'font-size:10px;border:none;outline:none;background:transparent;cursor:pointer;color:#1a2332;padding:0';
        CAMPAGNES_CTRL.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.label;
            sel.appendChild(opt);
        });
        sel.addEventListener('change', () => setCampagne(sel.value));

        // Séparateur
        const sep = document.createElement('span');
        sep.style.cssText = 'width:1px;height:14px;background:#d8dce4;flex-shrink:0';

        // Attribution
        const attr = document.createElement('span');
        attr.style.cssText = 'font-size:10px;color:#666';
        attr.textContent = '© IGN-F/Géoportail';

        this._container.appendChild(logo);
        this._container.appendChild(sel);
        this._container.appendChild(sep);
        this._container.appendChild(attr);
        return this._container;
    }
    onRemove() { this._container.parentNode?.removeChild(this._container); }
}
map.addControl(new IgnControl(), 'bottom-right');

// ── Pin géocodage (appelé depuis accueil.php) ─────────────
let searchMarker = null;

function makeMarkerEl() {
    const el = document.createElement('div');
    el.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="36" height="48" viewBox="0 0 36 48">
  <defs><radialGradient id="pg" cx="40%" cy="35%" r="60%">
    <stop offset="0%" stop-color="#60a5fa"/>
    <stop offset="100%" stop-color="#1d4ed8"/>
  </radialGradient></defs>
  <path d="M18 2C9.163 2 2 9.163 2 18c0 10 16 28 16 28s16-18 16-28C34 9.163 26.837 2 18 2z"
        fill="url(#pg)" filter="drop-shadow(0 3px 3px rgba(0,0,0,.4))"/>
  <circle cx="18" cy="18" r="6" fill="white" opacity=".95"/>
  <circle cx="18" cy="18" r="2.5" fill="#1d4ed8"/>
</svg>`;
    el.style.cssText = 'cursor:pointer;width:36px;height:48px;';
    el.title = 'Cliquer pour supprimer';
    el.addEventListener('click', () => window.clearSearchMarker());
    return el;
}

window.clearSearchMarker = function () {
    if (searchMarker) { searchMarker.remove(); searchMarker = null; }
    const inp = document.getElementById('search');
    const btn = document.getElementById('search-clear');
    if (inp) inp.value = '';
    if (btn) btn.style.display = 'none';
};

window.afficherSurCarte = function (lat, lon, classif) {
    const lngLat = [parseFloat(lon), parseFloat(lat)];
    if (searchMarker) {
        searchMarker.setLngLat(lngLat);
    } else {
        searchMarker = new maplibregl.Marker({ element: makeMarkerEl(), anchor: 'bottom' })
            .setLngLat(lngLat).addTo(map);
    }
    let zoom = 13;
    if (classif == 4) zoom = 15;
    else if (classif == 3) zoom = 16;
    else if (classif == 7) zoom = 17;
    map.flyTo({ center: lngLat, zoom, duration: 1800, essential: true });
};

map.on('load', () => {
    initCatalogue(map);
    initWfsClick(map);

    // ── Bouton permalien ─────────────────────────────────
    window.copyPermalink = () => copyPermalink(map);

    const taux     = initTaux(map);
    const coeff    = initCoeff(map);
    const zfu      = initZfu(map);
    const tsb      = initTsb(map);
    const tass     = initTass(map);
    const ta       = initTa(map);
    const taMajore = initTaMajore(map);
    // Filtre dossiers — doit être init avant initDossiers pour que setFullData soit disponible
    window._dossiersFilter = initDossiersFilter(map);
    initOrtho(map);
    const dossiers = initDossiers(map);
    const tarifs   = initTarifs(map, catsReady);
    const sections = initSections(map);
    const cfe      = initCfe(map);
    const tf       = initTf(map);

    // Peupler le select catégorie CFE et TF (même liste) (même liste que tarifs)
    catsReady.then(cats => {
        ['cfe-categorie', 'tf-categorie'].forEach(id => {
            const sel = document.getElementById(id);
            if (sel && cats.length) {
                cats.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c; opt.textContent = c;
                    sel.appendChild(opt);
                });
            }
        });
    });

    map.on('moveend', debounce(() => {
        updateWfs(map);
        if (taux.isActive())     taux.load();
        if (coeff.isActive())    coeff.load();
        if (tarifs.isActive())   tarifs.load();
        if (sections.isActive()) sections.load();
        if (cfe.isActive())      cfe.load();
        if (tf.isActive())       tf.load();
        if (ta.isActive())       ta.load();
        if (taMajore.isActive()) taMajore.load();
        saveState(map);
    }, 200));

    updateWfs(map);

    // ── Restaurer l'état (hash URL > localStorage) ───────
    const initState = readHashState() || loadSavedState();
    if (initState) {
        // Attendre que les selects dynamiques (millesimes, catégories) soient peuplés
        setTimeout(() => applyState(initState, map), 600);
    }

    // Sauvegarder quand les couches changent
    document.querySelectorAll('[id^="toggle-"]').forEach(cb => {
        cb.addEventListener('change', () => saveState(map));
    });
    document.querySelectorAll('select[id]').forEach(sel => {
        sel.addEventListener('change', () => saveState(map));
    });
    document.getElementById('ign-campagne-ctrl')?.addEventListener('change', () => saveState(map));
});
