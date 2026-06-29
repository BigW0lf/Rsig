let _measuring = false;
export function isMeasuring() { return _measuring; }

const R = 6371000;
const RAD = Math.PI / 180;

function haversine([lng1, lat1], [lng2, lat2]) {
    const dLat = (lat2 - lat1) * RAD;
    const dLng = (lng2 - lng1) * RAD;
    const a = Math.sin(dLat/2)**2 + Math.cos(lat1*RAD)*Math.cos(lat2*RAD)*Math.sin(dLng/2)**2;
    return 2 * R * Math.asin(Math.sqrt(a));
}

function polygonArea(coords) {
    if (coords.length < 3) return 0;
    const lat0 = coords.reduce((s, c) => s + c[1], 0) / coords.length;
    const kx = Math.cos(lat0 * RAD) * 111320;
    const ky = 111320;
    let area = 0;
    for (let i = 0, j = coords.length - 1; i < coords.length; j = i++) {
        area += (coords[j][0] * kx + coords[i][0] * kx) * (coords[j][1] * ky - coords[i][1] * ky);
    }
    return Math.abs(area) / 2;
}

function fmtDist(m) {
    return m >= 1000 ? (m/1000).toFixed(2)+' km' : m >= 10 ? Math.round(m)+' m' : m.toFixed(1)+' m';
}

function fmtArea(m2) {
    return m2 >= 1e6 ? (m2/1e6).toFixed(3)+' km²' : m2 >= 1e4 ? (m2/1e4).toFixed(2)+' ha' : Math.round(m2)+' m²';
}

export function initMeasure(map) {
    let active = false;
    let mode = 'distance';
    let pts = [];
    let mouseCoord = null;
    let finished = false;
    let clickTimeout = null;

    // ── Sources ──────────────────────────────────────────────
    map.addSource('measure-line', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addSource('measure-fill', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addSource('measure-pts',  { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addSource('measure-lbl',  { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });

    // ── Layers ───────────────────────────────────────────────
    map.addLayer({ id: 'measure-fill-layer', type: 'fill', source: 'measure-fill',
        paint: { 'fill-color': '#003189', 'fill-opacity': 0.1 } });
    map.addLayer({ id: 'measure-line-layer', type: 'line', source: 'measure-line',
        paint: { 'line-color': '#003189', 'line-width': 2, 'line-dasharray': [4, 3] } });
    map.addLayer({ id: 'measure-pts-layer', type: 'circle', source: 'measure-pts',
        paint: { 'circle-radius': 5, 'circle-color': '#fff', 'circle-stroke-width': 2, 'circle-stroke-color': '#003189' } });
    map.addLayer({ id: 'measure-lbl-layer', type: 'symbol', source: 'measure-lbl',
        layout: {
            'text-field': ['get', 'text'],
            'text-size': 11,
            'text-anchor': 'bottom',
            'text-offset': [0, -0.4],
            'text-font': ['Open Sans Regular'],
            'text-allow-overlap': true,
        },
        paint: { 'text-color': '#003189', 'text-halo-color': '#fff', 'text-halo-width': 2 }
    });

    // ── Panel UI ─────────────────────────────────────────────
    const panel = document.createElement('div');
    panel.id = 'measure-panel';
    panel.innerHTML = `
        <div id="measure-mode-btns">
            <button id="measure-btn-dist" class="measure-mode active">Distance</button>
            <button id="measure-btn-area" class="measure-mode">Surface</button>
        </div>
        <div id="measure-result">Cliquez sur la carte pour commencer</div>
        <div id="measure-actions">
            <button id="measure-clear">Effacer</button>
            <button id="measure-finish" style="display:none">Terminer</button>
        </div>
    `;
    panel.style.display = 'none';
    document.getElementById('map-wrap').appendChild(panel);

    // ── Contrôle bouton barre outils ─────────────────────────
    class MeasureControl {
        onAdd() {
            this._container = document.createElement('div');
            this._container.className = 'maplibregl-ctrl maplibregl-ctrl-group';
            const btn = document.createElement('button');
            btn.id = 'measure-toggle-btn';
            btn.type = 'button';
            btn.title = 'Outil de mesure (distance / surface)';
            btn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="2" y1="19" x2="22" y2="5"/><polyline points="17 5 22 5 22 10"/><polyline points="7 19 2 19 2 14"/></svg>`;
            btn.addEventListener('click', toggleMeasure);
            this._container.appendChild(btn);
            return this._container;
        }
        onRemove() { this._container.parentNode?.removeChild(this._container); }
    }
    map.addControl(new MeasureControl(), 'top-right');

    // ── Mise à jour des sources ───────────────────────────────
    function updateSources() {
        const preview = !finished && mouseCoord ? [...pts, mouseCoord] : pts;

        // Contour : tracé ouvert (distance) ou fermé (surface)
        let lineCoords = [];
        if (preview.length >= 2) {
            lineCoords = mode === 'area' && preview.length >= 3
                ? [...preview, preview[0]]
                : preview;
        }
        map.getSource('measure-line').setData({
            type: 'FeatureCollection',
            features: lineCoords.length >= 2 ? [{ type: 'Feature', geometry: { type: 'LineString', coordinates: lineCoords }, properties: {} }] : []
        });

        // Fill polygone (surface seulement)
        const fillCoords = mode === 'area' && preview.length >= 3 ? [[...preview, preview[0]]] : [];
        map.getSource('measure-fill').setData({
            type: 'FeatureCollection',
            features: fillCoords.length ? [{ type: 'Feature', geometry: { type: 'Polygon', coordinates: fillCoords }, properties: {} }] : []
        });

        // Points fixes
        map.getSource('measure-pts').setData({
            type: 'FeatureCollection',
            features: pts.map(p => ({ type: 'Feature', geometry: { type: 'Point', coordinates: p }, properties: {} }))
        });

        // Labels sur segments fixes (distance de chaque côté)
        const lblFeats = [];
        const forLbls = mode === 'area' && pts.length >= 3 ? [...pts, pts[0]] : pts;
        for (let i = 1; i < forLbls.length; i++) {
            const d = haversine(forLbls[i-1], forLbls[i]);
            lblFeats.push({
                type: 'Feature',
                geometry: { type: 'Point', coordinates: [(forLbls[i-1][0]+forLbls[i][0])/2, (forLbls[i-1][1]+forLbls[i][1])/2] },
                properties: { text: fmtDist(d) }
            });
        }
        map.getSource('measure-lbl').setData({ type: 'FeatureCollection', features: lblFeats });

        // Résultat panel
        const resEl = document.getElementById('measure-result');
        if (!resEl) return;
        if (pts.length === 0) {
            resEl.textContent = 'Cliquez sur la carte pour commencer';
        } else if (mode === 'distance') {
            let total = 0;
            for (let i = 1; i < preview.length; i++) total += haversine(preview[i-1], preview[i]);
            resEl.innerHTML = pts.length === 1
                ? 'Cliquez pour un second point'
                : `<strong>${fmtDist(total)}</strong>`;
        } else {
            resEl.innerHTML = pts.length < 3
                ? `${pts.length} / 3 points minimum`
                : `<strong>${fmtArea(polygonArea(preview))}</strong>`;
        }
    }

    function clearMeasure() {
        pts = [];
        mouseCoord = null;
        finished = false;
        clearTimeout(clickTimeout);
        const finBtn = document.getElementById('measure-finish');
        if (finBtn) finBtn.style.display = 'none';
        updateSources();
        if (active) map.getCanvas().style.cursor = 'crosshair';
    }

    function finishMeasure() {
        if (pts.length < 2) return;
        finished = true;
        mouseCoord = null;
        map.getCanvas().style.cursor = '';
        const finBtn = document.getElementById('measure-finish');
        if (finBtn) finBtn.style.display = 'none';
        updateSources();
    }

    function toggleMeasure() {
        active = !active;
        _measuring = active;
        const btn = document.getElementById('measure-toggle-btn');
        if (active) {
            panel.style.display = 'flex';
            map.getCanvas().style.cursor = 'crosshair';
            if (btn) btn.classList.add('measure-toggle-active');
        } else {
            panel.style.display = 'none';
            map.getCanvas().style.cursor = '';
            if (btn) btn.classList.remove('measure-toggle-active');
        }
        clearMeasure();
    }

    // ── Événements carte ─────────────────────────────────────
    map.on('click', e => {
        if (!active || finished) return;
        clearTimeout(clickTimeout);
        const coord = [e.lngLat.lng, e.lngLat.lat];
        clickTimeout = setTimeout(() => {
            pts.push(coord);
            const finBtn = document.getElementById('measure-finish');
            if (finBtn) finBtn.style.display = pts.length >= 2 ? 'inline-block' : 'none';
            updateSources();
        }, 220);
    });

    map.on('dblclick', e => {
        if (!active) return;
        clearTimeout(clickTimeout);
        e.preventDefault();
        if (pts.length >= 2) finishMeasure();
    });

    map.on('mousemove', e => {
        if (!active || finished) return;
        mouseCoord = [e.lngLat.lng, e.lngLat.lat];
        updateSources();
    });

    // ── Événements panel ─────────────────────────────────────
    document.getElementById('measure-btn-dist').addEventListener('click', () => {
        mode = 'distance';
        document.getElementById('measure-btn-dist').classList.add('active');
        document.getElementById('measure-btn-area').classList.remove('active');
        clearMeasure();
    });
    document.getElementById('measure-btn-area').addEventListener('click', () => {
        mode = 'area';
        document.getElementById('measure-btn-area').classList.add('active');
        document.getElementById('measure-btn-dist').classList.remove('active');
        clearMeasure();
    });
    document.getElementById('measure-clear').addEventListener('click', clearMeasure);
    document.getElementById('measure-finish').addEventListener('click', finishMeasure);
}
