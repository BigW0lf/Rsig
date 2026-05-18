<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SIG — Carte</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<!-- ── Panneau gauche : couches ───────────────────────────────────────────── -->
<aside id="panel-left">
  <h2>Couches</h2>

  <section class="layer-group">
    <h3>Taux fiscaux</h3>
    <label class="layer-toggle">
      <input type="checkbox" id="toggle-taux"> Communes
    </label>
    <div id="taux-options" class="sub-options hidden">
      <label>Champ affiché
        <select id="taux-champ">
          <option value="taux_fb_commune_vote">Taxe foncière bâti (commune)</option>
          <option value="taux_fnb_commune">Taxe foncière non bâti (commune)</option>
          <option value="taux_fnb_gfp_vote">FNB GFP</option>
          <option value="taux_tafnb_commune_net">TAFNB commune net</option>
          <option value="taux_tafnb_gfp_net">TAFNB GFP net</option>
          <option value="taux_tse_net">TSE net</option>
          <option value="taux_tse_gemapi_net">TSE GEMAPI net</option>
          <option value="taux_fb_gfp_vote">FB GFP</option>
          <option value="taux_teom_plein">TEOM</option>
        </select>
      </label>
    </div>
  </section>

  <section class="layer-group">
    <h3>Coefficients locatifs</h3>
    <label class="layer-toggle">
      <input type="checkbox" id="toggle-coeff"> Parcelles <span class="zoom-hint">(zoom ≥ 15)</span>
    </label>
  </section>

  <section class="layer-group">
    <h3>Dossiers</h3>
    <label class="layer-toggle">
      <input type="checkbox" id="toggle-dossiers"> Points dossiers
    </label>
  </section>

  <section class="layer-group">
    <h3>Tarifs locatifs</h3>
    <label class="layer-toggle">
      <input type="checkbox" id="toggle-tarifs"> Sections cadastrales
    </label>
    <div id="tarifs-options" class="sub-options hidden">
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
  </section>

  <!-- Légende dynamique -->
  <div id="legend" class="hidden">
    <h3 id="legend-title">Légende</h3>
    <div id="legend-items"></div>
  </div>
</aside>

<!-- ── Carte ─────────────────────────────────────────────────────────────── -->
<main id="map"></main>

<!-- ── Panneau droit : infos ─────────────────────────────────────────────── -->
<aside id="panel-right" class="hidden">
  <button id="close-right">✕</button>
  <h2 id="info-title">Informations</h2>
  <div id="info-content"></div>
</aside>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="/assets/map.js"></script>
</body>
</html>
