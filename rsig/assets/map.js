import { debounce } from './utils.js';
import { updateWfs }    from './wfs.js';
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

// Pré-charge les catégories tarifs avant que la carte soit prête
const catsReady = fetch('/api/tarifs/categories').then(r => r.json()).catch(() => []);

const map = new maplibregl.Map({
    container: 'map',
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
    zoom: 6,
});

map.addControl(new maplibregl.NavigationControl(), 'top-right');

// ── Pin géocodage (appelé depuis accueil.php) ─────────────
let searchMarker = null;
window.afficherSurCarte = function (lat, lon, classif) {
    const lngLat = [parseFloat(lon), parseFloat(lat)];
    if (searchMarker) {
        searchMarker.setLngLat(lngLat);
    } else {
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
        searchMarker = new maplibregl.Marker({ element: el, anchor: 'bottom' }).setLngLat(lngLat).addTo(map);
    }
    let zoom = 13;
    if (classif == 4) zoom = 15;
    else if (classif == 3) zoom = 16;
    else if (classif == 7) zoom = 17;
    map.flyTo({ center: lngLat, zoom, duration: 1800, essential: true });
};

map.on('load', () => {
    const taux     = initTaux(map);
    const coeff    = initCoeff(map);
    const zfu      = initZfu(map);
    const tsb      = initTsb(map);
    const tass     = initTass(map);
    const ta       = initTa(map);
    const taMajore = initTaMajore(map);
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
    }, 400));

    updateWfs(map);
});
