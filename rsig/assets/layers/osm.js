import { showSpinner, hideSpinner, bddOnTop, EMPTY_FC } from '../utils.js';
import { isMeasuring } from '../measure.js';

const OVERPASS = 'https://overpass-api.de/api/interpreter';
const MIN_ZOOM = 14;

// ── Type → [emoji, couleur fond cercle] ──────────────────────
const TYPES = {
    restaurant:       ['🍽', '#e67e22'],
    cafe:             ['☕', '#6f4e37'],
    bar:              ['🍺', '#d35400'],
    pub:              ['🍻', '#d35400'],
    fast_food:        ['🍔', '#e74c3c'],
    pharmacy:         ['💊', '#27ae60'],
    hospital:         ['🏥', '#c0392b'],
    clinic:           ['🏥', '#e74c3c'],
    doctors:          ['🩺', '#27ae60'],
    dentist:          ['🦷', '#1abc9c'],
    school:           ['🏫', '#3498db'],
    university:       ['🎓', '#2980b9'],
    college:          ['🎓', '#2980b9'],
    kindergarten:     ['🧒', '#9b59b6'],
    library:          ['📚', '#8e44ad'],
    bank:             ['🏦', '#2c3e50'],
    atm:              ['💳', '#34495e'],
    post_office:      ['📮', '#f39c12'],
    fuel:             ['⛽', '#7f8c8d'],
    police:           ['🚔', '#2c3e50'],
    fire_station:     ['🚒', '#c0392b'],
    townhall:         ['🏛', '#003189'],
    courthouse:       ['⚖', '#2c3e50'],
    cinema:           ['🎬', '#8e44ad'],
    theatre:          ['🎭', '#8e44ad'],
    place_of_worship: ['⛪', '#7f8c8d'],
    museum:           ['🏛', '#f39c12'],
    hotel:            ['🏨', '#9b59b6'],
    supermarket:      ['🛒', '#27ae60'],
    convenience:      ['🏪', '#2ecc71'],
    bakery:           ['🥐', '#f39c12'],
    butcher:          ['🥩', '#c0392b'],
    hairdresser:      ['✂', '#9b59b6'],
    car_repair:       ['🔧', '#7f8c8d'],
    park:             ['🌳', '#2ecc71'],
    playground:       ['🛝', '#f39c12'],
    sports_centre:    ['🏋', '#e74c3c'],
    swimming_pool:    ['🏊', '#3498db'],
    attraction:       ['🎡', '#e91e63'],
    viewpoint:        ['🔭', '#1abc9c'],
};
const DEFAULT_TYPE = ['📍', '#003189'];

const LABELS = {
    restaurant:'Restaurant', cafe:'Café', bar:'Bar', pub:'Pub',
    fast_food:'Restauration rapide', pharmacy:'Pharmacie',
    hospital:'Hôpital', clinic:'Clinique', doctors:'Médecin',
    dentist:'Dentiste', school:'École', university:'Université',
    college:'Lycée', kindergarten:'Maternelle', library:'Bibliothèque',
    bank:'Banque', atm:'Distributeur', post_office:'Bureau de poste',
    fuel:'Station-service', police:'Police', fire_station:'Pompiers',
    townhall:'Mairie', courthouse:'Tribunal', cinema:'Cinéma',
    theatre:'Théâtre', place_of_worship:'Lieu de culte', museum:'Musée',
    hotel:'Hôtel', supermarket:'Supermarché', convenience:'Épicerie',
    bakery:'Boulangerie', butcher:'Boucherie', hairdresser:'Coiffeur',
    car_repair:'Garage', park:'Parc', playground:'Aire de jeux',
    sports_centre:'Centre sportif', swimming_pool:'Piscine',
    attraction:'Attraction', viewpoint:'Point de vue',
};

function getKey(props) {
    if (props.amenity  && TYPES[props.amenity])  return props.amenity;
    if (props.shop     && TYPES[props.shop])     return props.shop;
    if (props.leisure  && TYPES[props.leisure])  return props.leisure;
    if (props.tourism  && TYPES[props.tourism])  return props.tourism;
    return props.amenity || props.shop || props.leisure || props.tourism || 'default';
}

// ── Images canvas (cercle + emoji rendu par le navigateur) ───
const SZ = 36;

function makePng(emoji, bg) {
    const c = document.createElement('canvas');
    c.width = c.height = SZ;
    const x = c.getContext('2d');
    x.beginPath(); x.arc(SZ/2, SZ/2, SZ/2 - 2, 0, Math.PI*2);
    x.fillStyle = bg; x.fill();
    x.strokeStyle = '#fff'; x.lineWidth = 2.5; x.stroke();
    x.font = `${Math.round(SZ * 0.52)}px 'Apple Color Emoji','Segoe UI Emoji','Noto Color Emoji',serif`;
    x.textAlign = 'center'; x.textBaseline = 'middle';
    x.fillText(emoji, SZ/2, SZ/2 + 1);
    return c.getContext('2d').getImageData(0, 0, SZ, SZ);
}

function registerImages(map) {
    const all = { ...TYPES, default: DEFAULT_TYPE };
    Object.entries(all).forEach(([key, [emoji, color]]) => {
        const id = 'poi-' + key;
        if (map.hasImage(id)) return;
        const img = makePng(emoji, color);
        map.addImage(id, { width: SZ, height: SZ, data: img.data });
    });
}

// ── Requête Overpass ──────────────────────────────────────────
const QUERY_CONDS = [
    '["amenity"~"^(restaurant|cafe|bar|fast_food|pub|pharmacy|hospital|clinic|doctors|dentist|school|university|college|kindergarten|library|bank|atm|post_office|fuel|police|fire_station|townhall|courthouse|cinema|theatre|place_of_worship)$"]',
    '["shop"~"^(supermarket|convenience|bakery|butcher|hairdresser|car_repair)$"]',
    '["leisure"~"^(park|playground|sports_centre|swimming_pool)$"]',
    '["tourism"~"^(hotel|museum|attraction|viewpoint)$"]',
];

function buildQuery(s, w, n, e) {
    const bbox  = `(${s},${w},${n},${e})`;
    const parts = QUERY_CONDS.flatMap(cond => [`node${cond}${bbox};`, `way${cond}${bbox};`]).join('');
    return `[out:json][timeout:20];(${parts});out body center;`;
}

function toFeatures(elements) {
    return elements
        .filter(el => el.tags && (el.type === 'node' || (el.type === 'way' && el.center)))
        .map(el => {
            const coords = el.type === 'node' ? [el.lon, el.lat] : [el.center.lon, el.center.lat];
            const key    = getKey(el.tags);
            return {
                type: 'Feature',
                geometry: { type: 'Point', coordinates: coords },
                properties: { ...el.tags, _osmid: el.id, _key: key, _img: 'poi-' + (TYPES[key] ? key : 'default') },
            };
        });
}

let active    = false;
let _ctrl     = null;
let _retryTimer = null;
let _popup    = null;
// Cache : ne recharge pas si la bbox a peu bougé ET le zoom n'a pas changé
let _lastBbox = null;
let _lastZoom = null;
const BBOX_THRESHOLD = 0.02; // degrés — en dessous, on réutilise le cache

function bboxKey(b) {
    return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()]
        .map(v => v.toFixed(3)).join(',');
}

function bboxMoved(b, zoom) {
    if (!_lastBbox) return true;
    if (Math.round(zoom) !== Math.round(_lastZoom)) return true;
    const prev = _lastBbox.split(',').map(Number);
    const cx1  = (prev[0] + prev[2]) / 2, cy1 = (prev[1] + prev[3]) / 2;
    const cx2  = (b.getWest() + b.getEast()) / 2, cy2 = (b.getSouth() + b.getNorth()) / 2;
    return Math.abs(cx1 - cx2) > BBOX_THRESHOLD || Math.abs(cy1 - cy2) > BBOX_THRESHOLD;
}

export function initOsm(map) {
    registerImages(map);

    map.addSource('osm-src', {
        type: 'geojson',
        data: EMPTY_FC,
    });

    map.addLayer({ id: 'osm-point', type: 'symbol', source: 'osm-src',
        layout: {
            'icon-image':            ['get', '_img'],
            'icon-size':             0.75,
            'icon-allow-overlap':    true,
            'icon-ignore-placement': true,
        },
        minzoom: MIN_ZOOM,
    });

    setVis('none');

    async function _fetch(retryDelay) {
        if (!active) return;
        if (map.getZoom() < MIN_ZOOM) return;

        // Pas de rechargement si la vue n'a pas assez bougé
        const b    = map.getBounds();
        const zoom = map.getZoom();
        if (!bboxMoved(b, zoom)) return;
        _lastBbox = bboxKey(b);
        _lastZoom = zoom;

        if (_ctrl) _ctrl.abort();
        _ctrl = new AbortController();
        showSpinner();
        try {
            const r = await fetch(OVERPASS, {
                method: 'POST',
                body: 'data=' + encodeURIComponent(buildQuery(
                    b.getSouth().toFixed(5), b.getWest().toFixed(5),
                    b.getNorth().toFixed(5), b.getEast().toFixed(5)
                )),
                signal: _ctrl.signal,
            });

            // Rate-limit → retry après le délai indiqué ou 30 s
            if (r.status === 429 || r.status === 504 || r.status === 503) {
                hideSpinner();
                const wait = r.status === 429
                    ? parseInt(r.headers.get('Retry-After') || '30', 10) * 1000
                    : 20000;
                console.warn(`[osm] Overpass ${r.status} — retry dans ${wait/1000}s`);
                clearTimeout(_retryTimer);
                _retryTimer = setTimeout(() => { _lastBbox = null; _fetch(0); }, wait);
                return;
            }
            if (!r.ok) throw new Error('Overpass ' + r.status);

            const data = await r.json();
            if (!active) return;
            map.getSource('osm-src').setData({ type: 'FeatureCollection', features: toFeatures(data.elements || []) });
            bddOnTop(map);
        } catch (e) {
            if (e.name !== 'AbortError') console.warn('[osm]', e.message ?? e);
        } finally { hideSpinner(); }
    }

    // load() : entrée publique + depuis moveend
    function load() { _fetch(0); }

    function setVis(v) {
        map.setLayoutProperty('osm-point', 'visibility', v);
    }

    function setActive(val) {
        active = val;
        clearTimeout(_retryTimer);
        if (!active) {
            setVis('none');
            map.getSource('osm-src')?.setData(EMPTY_FC);
            if (_popup) { _popup.remove(); _popup = null; }
            if (_ctrl) _ctrl.abort();
            _lastBbox = null;
        } else {
            setVis('visible');
            load();
        }
    }

    // Point → popup MapLibre (supprime le popup cadastral s'il est ouvert)
    map.on('click', 'osm-point', e => {
        if (!active || isMeasuring()) return;
        const props = e.features[0].properties;
        const key   = props._key || 'default';
        const [emoji] = TYPES[key] || DEFAULT_TYPE;
        const typeStr = LABELS[key] || (key !== 'default' ? key.replace(/_/g,' ') : 'Point d\'intérêt');
        const name    = props.name || typeStr;

        const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const row = (label, val) => `<tr>
            <td style="color:#888;font-size:11px;padding:2px 8px 2px 0;white-space:nowrap">${label}</td>
            <td style="font-size:12px;font-weight:500;padding:2px 0">${val}</td></tr>`;
        const rowLink = (label, html) => `<tr>
            <td style="color:#888;font-size:11px;padding:2px 8px 2px 0;white-space:nowrap">${label}</td>
            <td style="font-size:12px;font-weight:500;padding:2px 0">${html}</td></tr>`;

        let rows = '';
        const street = [props['addr:housenumber'], props['addr:street']].filter(Boolean).join(' ');
        const city   = props['addr:city'] || props['addr:postcode'] || '';
        if (typeStr !== name)                rows += row('Type', esc(typeStr));
        if (street || city)                  rows += row('Adresse', esc([street, city].filter(Boolean).join(', ')));
        if (props.phone)                     rows += rowLink('Téléphone', `<a href="tel:${esc(props.phone)}" style="color:#3b82f6">${esc(props.phone)}</a>`);
        if (props['contact:phone'])          rows += rowLink('Tél.', `<a href="tel:${esc(props['contact:phone'])}" style="color:#3b82f6">${esc(props['contact:phone'])}</a>`);
        if (props.website)                   rows += rowLink('Site web', `<a href="${esc(props.website)}" target="_blank" rel="noopener" style="color:#3b82f6;word-break:break-all">${esc(props.website.replace(/^https?:\/\//,''))}</a>`);
        if (props['contact:website'])        rows += rowLink('Site', `<a href="${esc(props['contact:website'])}" target="_blank" rel="noopener" style="color:#3b82f6;word-break:break-all">${esc(props['contact:website'].replace(/^https?:\/\//,''))}</a>`);
        if (props.opening_hours)             rows += row('Horaires', esc(props.opening_hours));
        if (props.wheelchair === 'yes')      rows += row('PMR', '✓ Accessible');
        if (props.cuisine)                   rows += row('Cuisine', esc(props.cuisine.replace(/;/g,', ')));
        if (props.capacity)                  rows += row('Capacité', esc(props.capacity) + ' places');

        const html = `<div style="min-width:160px">
            <div style="font-size:13px;font-weight:700;margin-bottom:5px">${emoji} ${esc(name)}</div>
            ${rows ? `<table style="border-collapse:collapse;width:100%">${rows}</table>` : ''}
        </div>`;

        if (_popup) _popup.remove();
        _popup = new maplibregl.Popup({ closeButton: true, maxWidth: '280px' })
            .setLngLat(e.lngLat)
            .setHTML(html)
            .addTo(map);
    });

    map.on('mouseenter', 'osm-point', () => { if (!isMeasuring()) map.getCanvas().style.cursor = 'pointer'; });
    map.on('mouseleave', 'osm-point', () => { map.getCanvas().style.cursor = ''; });

    return { setActive, isActive: () => active, load, hasPopup: () => !!_popup,
        closePopup() { if (_popup) { _popup.remove(); _popup = null; } } };
}
