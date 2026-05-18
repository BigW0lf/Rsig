'use strict';

// ── Carte ────────────────────────────────────────────────────────────────────
const map = L.map('map', { preferCanvas: true, zoomControl: true }).setView([46.8, 2.3], 6);

L.tileLayer(
  'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0' +
  '&LAYER=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&STYLE=normal&TILEMATRIXSET=PM' +
  '&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}&FORMAT=image%2Fpng',
  { attribution: '© IGN Géoplateforme', maxZoom: 20 }
).addTo(map);

// ── Couches Leaflet ──────────────────────────────────────────────────────────
const layers = {
  taux:    L.layerGroup(),
  coeff:   L.layerGroup(),
  dossiers: L.layerGroup(),
  tarifs:  L.layerGroup(),
};

// ── Contrôleurs AbortController ─────────────────────────────────────────────
const abort = { taux: null, coeff: null, dossiers: null, tarifs: null };

// ── Palette choroplèthe ──────────────────────────────────────────────────────
const PALETTES = {
  taux:   ['#f7fbff','#c6dbef','#6baed6','#2171b5','#08306b'],
  coeff:  ['#fff5eb','#fdd0a2','#fd8d3c','#d94801','#7f2704'],
  tarifs: ['#f7fcf5','#c7e9c0','#74c476','#238b45','#00441b'],
};

function getColor(val, breaks, palette) {
  if (val === null || val === undefined) return '#cccccc';
  for (let i = breaks.length - 1; i >= 0; i--) {
    if (val >= breaks[i]) return palette[i];
  }
  return palette[0];
}

function computeBreaks(values, n) {
  const sorted = [...values].filter(v => v !== null && v !== undefined).sort((a, b) => a - b);
  if (!sorted.length) return Array(n).fill(0);
  const breaks = [];
  for (let i = 0; i < n; i++) breaks.push(sorted[Math.floor(i * sorted.length / n)] ?? 0);
  return breaks;
}

// ── Panneau droit ────────────────────────────────────────────────────────────
const panelRight  = document.getElementById('panel-right');
const infoTitle   = document.getElementById('info-title');
const infoContent = document.getElementById('info-content');
document.getElementById('close-right').addEventListener('click', () => panelRight.classList.add('hidden'));

function showPanel(title, html) {
  infoTitle.textContent  = title;
  infoContent.innerHTML  = html;
  panelRight.classList.remove('hidden');
}

function infoRow(label, value) {
  if (value === null || value === undefined || value === '') return '';
  return `<div class="info-row"><span class="info-label">${label}</span><span class="info-value">${value}</span></div>`;
}

// ── Légende ──────────────────────────────────────────────────────────────────
const legend      = document.getElementById('legend');
const legendTitle = document.getElementById('legend-title');
const legendItems = document.getElementById('legend-items');

function renderLegend(title, breaks, palette, suffix = '') {
  legendTitle.textContent = title;
  legendItems.innerHTML = '';
  breaks.forEach((b, i) => {
    const next = breaks[i + 1];
    const label = next !== undefined
      ? `${b.toFixed(2)}${suffix} – ${next.toFixed(2)}${suffix}`
      : `≥ ${b.toFixed(2)}${suffix}`;
    legendItems.innerHTML += `
      <div class="legend-item">
        <span class="legend-swatch" style="background:${palette[i]}"></span>
        <span>${label}</span>
      </div>`;
  });
  legend.classList.remove('hidden');
}

function hideLegend() {
  legend.classList.add('hidden');
}

// ── Fetch avec bbox ──────────────────────────────────────────────────────────
function bboxParam() {
  const b = map.getBounds();
  return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
}

function fetchLayer(key, url, onData) {
  if (abort[key]) { abort[key].abort(); }
  abort[key] = new AbortController();
  fetch(url, { signal: abort[key].signal })
    .then(r => r.json())
    .then(onData)
    .catch(e => { if (e.name !== 'AbortError') console.error(key, e); });
}

// ════════════════════════════════════════════════════════════════════════════
// TAUX CLEAN
// ════════════════════════════════════════════════════════════════════════════
const toggleTaux  = document.getElementById('toggle-taux');
const tauxOptions = document.getElementById('taux-options');
const tauxChamp   = document.getElementById('taux-champ');
let tauxActive    = false;

function loadTaux() {
  if (!tauxActive) return;
  const champ = tauxChamp.value;
  const url   = `/api/taux?bbox=${bboxParam()}&champ=${champ}`;
  fetchLayer('taux', url, fc => {
    layers.taux.clearLayers();
    if (!fc.features?.length) return;
    const vals   = fc.features.map(f => f.properties.valeur_affichee);
    const breaks = computeBreaks(vals, 5);
    const pal    = PALETTES.taux;

    fc.features.forEach(f => {
      const val = f.properties.valeur_affichee;
      const color = getColor(val, breaks, pal);
      L.geoJSON(f, {
        style: { fillColor: color, weight: .5, color: '#666', fillOpacity: .7 },
      }).on('click', () => {
        const p = f.properties;
        const champLabel = tauxChamp.options[tauxChamp.selectedIndex].text;
        showPanel(`${p.libcom} (${p.com})`, `
          ${infoRow('Département', p.dep)}
          ${infoRow('Millésime', p.millesime)}
          ${infoRow(champLabel, val !== null ? val.toFixed(4) + ' %' : '–')}
          ${infoRow('TF bâti commune', p.taux_fb_commune_vote?.toFixed(4) + ' %')}
          ${infoRow('TF non bâti', p.taux_fnb_commune?.toFixed(4) + ' %')}
          ${infoRow('TSE net', p.taux_tse_net?.toFixed(4) + ' %')}
          ${infoRow('TEOM', p.taux_teom_plein?.toFixed(4) + ' %')}
        `);
      }).addTo(layers.taux);
    });
    renderLegend(tauxChamp.options[tauxChamp.selectedIndex].text, breaks, pal, ' %');
  });
}

toggleTaux.addEventListener('change', () => {
  tauxActive = toggleTaux.checked;
  tauxOptions.classList.toggle('hidden', !tauxActive);
  if (tauxActive) { map.addLayer(layers.taux); loadTaux(); }
  else { map.removeLayer(layers.taux); layers.taux.clearLayers(); hideLegend(); }
});
tauxChamp.addEventListener('change', loadTaux);

// ════════════════════════════════════════════════════════════════════════════
// COEFF LOC FINAL  (bbox, zoom ≥ 15)
// ════════════════════════════════════════════════════════════════════════════
const toggleCoeff = document.getElementById('toggle-coeff');
let coeffActive   = false;

function loadCoeff() {
  if (!coeffActive) return;
  if (map.getZoom() < 15) { layers.coeff.clearLayers(); return; }
  const url = `/api/coeff?bbox=${bboxParam()}`;
  fetchLayer('coeff', url, fc => {
    layers.coeff.clearLayers();
    if (!fc.features?.length) return;
    const vals   = fc.features.map(f => f.properties.coeff_2026 ?? f.properties.coeff_2024);
    const breaks = computeBreaks(vals, 5);
    const pal    = PALETTES.coeff;

    fc.features.forEach(f => {
      const val = f.properties.coeff_2026 ?? f.properties.coeff_2024;
      const color = getColor(val, breaks, pal);
      L.geoJSON(f, {
        style: { fillColor: color, weight: .4, color: '#888', fillOpacity: .75 },
      }).on('click', () => {
        const p = f.properties;
        const evol = p.coeff_2026 && p.coeff_2017
          ? ((p.coeff_2026 - p.coeff_2017) / p.coeff_2017 * 100).toFixed(1)
          : null;
        const evolClass = evol > 0 ? 'tag-up' : evol < 0 ? 'tag-down' : '';
        showPanel(`Parcelle ${p.idu}`, `
          ${infoRow('IDU', p.idu)}
          ${infoRow('Commune', p.codecommune)}
          ${infoRow('Section', p.section)}
          ${infoRow('Parcelle', p.parcelle)}
          <div class="info-row">
            <span class="info-label">Évolution 2017→2026</span>
            <span class="info-value ${evolClass}">${evol !== null ? evol + ' %' : '–'}</span>
          </div>
          <table class="evolution-table">
            <tr><th>Année</th><th>Coeff</th></tr>
            ${[2017,2018,2019,2020,2024,2026].map(y =>
              `<tr><td>${y}</td><td>${p['coeff_'+y] ?? '–'}</td></tr>`
            ).join('')}
          </table>
        `);
      }).addTo(layers.coeff);
    });
    renderLegend('Coeff locatif 2026', breaks, pal);
  });
}

toggleCoeff.addEventListener('change', () => {
  coeffActive = toggleCoeff.checked;
  if (coeffActive) { map.addLayer(layers.coeff); loadCoeff(); }
  else { map.removeLayer(layers.coeff); layers.coeff.clearLayers(); }
});

// ════════════════════════════════════════════════════════════════════════════
// DOSSIERS  (points)
// ════════════════════════════════════════════════════════════════════════════
const toggleDossiers = document.getElementById('toggle-dossiers');
let dossiersActive   = false;
let dossiersLoaded   = false;

const dossierIcon = L.divIcon({
  className: '',
  html: '<div style="width:10px;height:10px;border-radius:50%;background:#3b82f6;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.4)"></div>',
  iconSize: [10, 10],
  iconAnchor: [5, 5],
});

function loadDossiers() {
  if (!dossiersActive || dossiersLoaded) return;
  fetchLayer('dossiers', '/api/dossiers', fc => {
    layers.dossiers.clearLayers();
    fc.features?.forEach(f => {
      const [lng, lat] = f.geometry.coordinates;
      const p = f.properties;
      L.marker([lat, lng], { icon: dossierIcon })
        .on('click', () => {
          showPanel(`Dossier ${p.dossier}`, `
            ${infoRow('Dossier', p.dossier)}
            ${infoRow('Nom', p.name)}
            ${infoRow('Code', p.rtx_code)}
            ${infoRow('Adresse', p.adresse_complete)}
            ${infoRow('Taxe foncière', p.apo_montanttaxefonciere ? p.apo_montanttaxefonciere.toLocaleString('fr-FR') + ' €' : '–')}
            ${infoRow('Lot', p.lot)}
            ${infoRow('Section', p.section)}
            ${infoRow('INSEE', p.insee)}
          `);
        }).addTo(layers.dossiers);
    });
    dossiersLoaded = true;
  });
}

toggleDossiers.addEventListener('change', () => {
  dossiersActive = toggleDossiers.checked;
  if (dossiersActive) { map.addLayer(layers.dossiers); loadDossiers(); }
  else { map.removeLayer(layers.dossiers); }
});

// ════════════════════════════════════════════════════════════════════════════
// TARIFS SECTIONS
// ════════════════════════════════════════════════════════════════════════════
const toggleTarifs  = document.getElementById('toggle-tarifs');
const tarifsOptions = document.getElementById('tarifs-options');
const tarifsCat     = document.getElementById('tarifs-cat');
const tarifsAnnee   = document.getElementById('tarifs-annee');
let tarifsActive    = false;

// Charger les catégories dispo
fetch('/api/tarifs/categories')
  .then(r => r.json())
  .then(cats => {
    tarifsCat.innerHTML = cats.map(c => `<option value="${c}">${c}</option>`).join('');
  });

function loadTarifs() {
  if (!tarifsActive) return;
  const cat   = tarifsCat.value;
  const annee = tarifsAnnee.value;
  if (!cat) return;
  const url = `/api/tarifs?bbox=${bboxParam()}&categorie=${cat}&annee=${annee}`;
  fetchLayer('tarifs', url, fc => {
    layers.tarifs.clearLayers();
    if (!fc.features?.length) return;
    const vals   = fc.features.map(f => f.properties.valeur);
    const breaks = computeBreaks(vals, 5);
    const pal    = PALETTES.tarifs;

    fc.features.forEach(f => {
      const val = f.properties.valeur;
      const color = getColor(val, breaks, pal);
      L.geoJSON(f, {
        style: { fillColor: color, weight: .5, color: '#555', fillOpacity: .7 },
      }).on('click', () => {
        const p = f.properties;
        const annees = [2017,2019,2020,2021,2022,2023,2024,2025,2026];
        const rows = annees.map(y => {
          const v = p[`val_${y}`];
          if (v === null || v === undefined) return '';
          return `<tr><td>${y}</td><td>${v} €/m²</td></tr>`;
        }).join('');
        showPanel(`Section ${p.section} — ${p.nom_com}`, `
          ${infoRow('Commune', p.nom_com)}
          ${infoRow('INSEE', p.code_insee)}
          ${infoRow('Secteur', p.secteur)}
          ${infoRow('Catégorie', p.categorie)}
          ${infoRow(`Tarif ${annee}`, val !== null ? val + ' €/m²' : '–')}
          <div class="info-row">
            <span class="info-label">Évolution par année</span>
          </div>
          <table class="evolution-table">
            <tr><th>Année</th><th>Tarif</th></tr>
            ${rows}
          </table>
        `);
      }).addTo(layers.tarifs);
    });
    renderLegend(`${cat} — ${annee}`, breaks, pal, ' €/m²');
  });
}

toggleTarifs.addEventListener('change', () => {
  tarifsActive = toggleTarifs.checked;
  tarifsOptions.classList.toggle('hidden', !tarifsActive);
  if (tarifsActive) { map.addLayer(layers.tarifs); loadTarifs(); }
  else { map.removeLayer(layers.tarifs); layers.tarifs.clearLayers(); hideLegend(); }
});
tarifsCat.addEventListener('change', loadTarifs);
tarifsAnnee.addEventListener('change', loadTarifs);

// ════════════════════════════════════════════════════════════════════════════
// Rechargement au déplacement / zoom (debounce 300 ms)
// ════════════════════════════════════════════════════════════════════════════
let debounceTimer = null;
map.on('moveend zoomend', () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    loadTaux();
    loadCoeff();
    loadTarifs();
    // dossiers : pas de rechargement bbox (déjà tout chargé)
  }, 300);
});
