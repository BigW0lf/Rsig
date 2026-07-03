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
    <a href="#" data-page="bofip">BOFIP</a>
    <?php if (isAdmin()): ?>
    <a href="#" data-page="maj">Mise à jour</a>
    <a href="#" data-page="donnees">Données</a>
    <a href="/admin/stats" target="_blank" style="margin-left:auto;font-size:0.78rem;opacity:.8" title="Stats d'utilisation">📊 Stats</a>
    <?php else: ?>
    <a href="#" data-page="donnees" style="margin-left:auto">Données</a>
    <?php endif; ?>
    <div style="display:flex;align-items:center;gap:4px;margin-left:auto">
        <button onclick="window.copyPermalink?.()" title="Copier le lien de cette vue" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:background .15s" onmouseover="this.style.background='rgba(255,255,255,.28)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        </button>
        <button onclick="openModal('modal-aide')" title="Aide" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:14px;font-weight:700;display:flex;align-items:center;justify-content:center;transition:background .15s" onmouseover="this.style.background='rgba(255,255,255,.28)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">?</button>
        <button onclick="openModal('modal-apropos')" title="À propos" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:background .15s" onmouseover="this.style.background='rgba(255,255,255,.28)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">i</button>
        <span class="nav-user"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></span>
        <a href="/auth/logout" class="nav-logout" title="Déconnexion">&#x2715;</a>
    </div>
</nav>

<!-- ═══ MODAL AIDE ══════════════════════════════════════════ -->
<div id="modal-aide" class="rsig-modal" style="display:none" onclick="if(event.target===this)closeModal('modal-aide')">
    <div class="rsig-modal-box">
        <div class="rsig-modal-head">
            <span>Aide — RSig</span>
            <button onclick="closeModal('modal-aide')">✕</button>
        </div>
        <div class="rsig-modal-body">

            <div class="rsig-help-section">
                <div class="rsig-help-title">Navigation sur la carte</div>
                <div class="rsig-help-row"><kbd>Scroll</kbd><span>Zoom avant / arrière</span></div>
                <div class="rsig-help-row"><kbd>Clic gauche + glisser</kbd><span>Déplacer la carte</span></div>
                <div class="rsig-help-row"><kbd>Ctrl + Scroll</kbd><span>Zoom précis</span></div>
                <div class="rsig-help-row"><kbd>+</kbd> / <kbd>−</kbd><span>Boutons zoom (haut droite)</span></div>
            </div>

            <div class="rsig-help-section">
                <div class="rsig-help-title">Couches de données</div>
                <div class="rsig-help-row"><span style="font-weight:600">Répertoire</span><span>Parcourez et ajoutez des couches via l'onglet gauche</span></div>
                <div class="rsig-help-row"><span style="font-weight:600">Œil</span><span>Masquer / afficher une couche temporairement</span></div>
                <div class="rsig-help-row"><span style="font-weight:600">×</span><span>Retirer une couche des couches actives</span></div>
                <div class="rsig-help-row"><span style="font-weight:600">Chevron ›</span><span>Déployer les options de la couche</span></div>
            </div>

            <div class="rsig-help-section">
                <div class="rsig-help-title">Fond de carte IGN</div>
                <div class="rsig-help-row"><span>Menu déroulant bas droite</span><span>Changer la campagne d'acquisition ortho (2000 → actuelle)</span></div>
                <div class="rsig-help-row"><span>Couche <em>Ortho historique IGN</em></span><span>Active l'overlay millésimes par département avec légende</span></div>
            </div>

            <div class="rsig-help-section">
                <div class="rsig-help-title">Informations sur un objet</div>
                <div class="rsig-help-row"><span>Clic sur la carte</span><span>Affiche un popup avec les infos du département / commune / section / parcelle selon le zoom, ainsi que la date d'acquisition de l'ortho</span></div>
            </div>

            <div class="rsig-help-section">
                <div class="rsig-help-title">POI OpenStreetMap</div>
                <div class="rsig-help-row"><span>Activation</span><span>Répertoire → Autres → POI OpenStreetMap — nécessite zoom niveau 14+</span></div>
                <div class="rsig-help-row"><span>Catégories</span><span>Restauration, Santé, Éducation, Services, Commerces, Tourisme…</span></div>
                <div class="rsig-help-row"><span>Clic sur un POI</span><span>Affiche le nom, adresse, téléphone, site web, horaires dans le panneau droit</span></div>
                <div class="rsig-help-row"><span>Clusters</span><span>Points regroupés à petite échelle — cliquer pour dézoomer</span></div>
                <div class="rsig-help-row"><span>Source</span><span>Overpass API (OpenStreetMap) — données contributives</span></div>
            </div>

            <div class="rsig-help-section">
                <div class="rsig-help-title">Outil de mesure</div>
                <div class="rsig-help-row"><kbd>Bouton règle (haut droite)</kbd><span>Active l'outil de mesure</span></div>
                <div class="rsig-help-row"><span>Distance</span><span>Cliquez pour poser des points, double-clic ou "Terminer" pour finir — distance totale affichée</span></div>
                <div class="rsig-help-row"><span>Surface</span><span>Idem — 3 points minimum — surface en m², ha ou km²</span></div>
                <div class="rsig-help-row"><span>Labels</span><span>Chaque segment affiche sa longueur sur la carte</span></div>
            </div>

            <div class="rsig-help-section">
                <div class="rsig-help-title">Recherche d'adresse</div>
                <div class="rsig-help-row"><span>Barre de recherche</span><span>Géocodage IGN — saisissez une adresse, une commune ou un code INSEE</span></div>
            </div>

            <div class="rsig-help-section">
                <div class="rsig-help-title">Pages secondaires</div>
                <div class="rsig-help-row"><span>Requêtes</span><span>Exécuter des requêtes SQL SELECT sur la base</span></div>
                <div class="rsig-help-row"><span>BOFIP</span><span>Parser les tarifs depuis les pages BOFIP</span></div>
                <div class="rsig-help-row"><span>Données</span><span>Explorateur de tables + mise à jour Taxe d'Aménagement</span></div>
            </div>

        </div>
    </div>
</div>

<!-- ═══ MODAL À PROPOS ═════════════════════════════════════ -->
<div id="modal-apropos" class="rsig-modal" style="display:none" onclick="if(event.target===this)closeModal('modal-apropos')">
    <div class="rsig-modal-box">
        <div class="rsig-modal-head">
            <span>À propos — RSig</span>
            <button onclick="closeModal('modal-apropos')">✕</button>
        </div>
        <div class="rsig-modal-body">

            <div style="text-align:center;padding:8px 0 20px">
                <div style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;background:var(--blue);border-radius:12px;margin-bottom:10px">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="white" stroke-width="1.5"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10A15.3 15.3 0 0 1 8 12 15.3 15.3 0 0 1 12 2z" stroke="white" stroke-width="1.5"/></svg>
                </div>
                <div style="font-size:1.15rem;font-weight:700;color:var(--text)">RSig</div>
                <div style="font-size:0.8rem;color:var(--text3);margin-top:2px">Référentiel SIG interne — RTaxes</div>
            </div>

            <div class="rsig-help-section">
                <div class="rsig-help-title">Application</div>
                <div class="rsig-help-row"><span>Objet</span><span>Consultation et analyse des données fiscales foncières géolocalisées</span></div>
                <div class="rsig-help-row"><span>Hébergement</span><span>VPS OVH — rcarto.rtaxes-geometre-expert.fr</span></div>
                <div class="rsig-help-row"><span>Accès</span><span>Authentification Microsoft (compte RTaxes)</span></div>
            </div>

            <div class="rsig-help-section">
                <div class="rsig-help-title">Données</div>
                <div class="rsig-help-row"><span>Orthophotographies</span><span>IGN Géoplateforme — campagnes 2000 à 2025</span></div>
                <div class="rsig-help-row"><span>Cadastre</span><span>IGN — Parcellaire Express (WFS temps réel)</span></div>
                <div class="rsig-help-row"><span>Taux fiscaux</span><span>DGFIP — TFPB, TFPNB, millésimes 2017-2025</span></div>
                <div class="rsig-help-row"><span>Taxe d'Aménagement</span><span>API data.economie.gouv.fr — mise à jour automatique</span></div>
                <div class="rsig-help-row"><span>TSB / TASS</span><span>BOFIP (parser interne)</span></div>
                <div class="rsig-help-row"><span>CRM</span><span>Microsoft Dynamics 365 — dossiers et sites RTaxes</span></div>
            </div>

            <div class="rsig-help-section">
                <div class="rsig-help-title">Développement</div>
                <div class="rsig-help-row"><span>Développeur</span><span>Jules Faguet</span></div>
                <div class="rsig-help-row"><span>Backend</span><span>PHP 8.3 + Flight — PostgreSQL 17 + PostGIS 3.5</span></div>
                <div class="rsig-help-row"><span>Frontend</span><span>MapLibre GL JS — vanilla JS ES modules</span></div>
                <div class="rsig-help-row"><span>Infrastructure</span><span>Docker Compose — Nginx — Let's Encrypt</span></div>
            </div>

            <div style="text-align:center;margin-top:16px;font-size:11px;color:var(--text3)">
                © <?= date('Y') ?> RTaxes — Usage interne exclusif
            </div>

        </div>
    </div>
</div>

<style>
.rsig-modal {
    position: fixed; inset: 0; z-index: 2000;
    background: rgba(0,0,0,.45);
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
}
.rsig-modal-box {
    background: var(--surface);
    border-radius: 10px;
    width: 100%; max-width: 520px;
    max-height: 85vh;
    display: flex; flex-direction: column;
    box-shadow: 0 8px 32px rgba(0,0,0,.22);
    overflow: hidden;
}
.rsig-modal-head {
    background: var(--blue);
    color: #fff;
    padding: 12px 16px;
    font-size: 13px; font-weight: 700;
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
}
.rsig-modal-head button {
    background: rgba(255,255,255,.2); border: none; color: #fff;
    width: 24px; height: 24px; border-radius: 50%; cursor: pointer;
    font-size: 13px; display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.rsig-modal-head button:hover { background: rgba(255,255,255,.35); }
.rsig-modal-body { overflow-y: auto; padding: 16px; }
.rsig-help-section { margin-bottom: 16px; }
.rsig-help-section:last-child { margin-bottom: 0; }
.rsig-help-title {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .6px; color: var(--text3);
    border-bottom: 1px solid var(--border2); padding-bottom: 4px; margin-bottom: 8px;
}
.rsig-help-row {
    display: flex; align-items: baseline; gap: 10px;
    padding: 4px 0; font-size: 12px;
    border-bottom: 1px solid var(--border2);
}
.rsig-help-row:last-child { border-bottom: none; }
.rsig-help-row > span:first-child { flex-shrink: 0; min-width: 140px; color: var(--text2); font-weight: 500; }
.rsig-help-row > span:last-child  { color: var(--text); }
kbd {
    display: inline-block; padding: 1px 6px; font-size: 11px;
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 3px; font-family: monospace; white-space: nowrap;
    flex-shrink: 0; min-width: 140px; text-align: center;
}
</style>

<!-- Iframes pages secondaires -->
<div id="page-overlay" style="display:none;position:fixed;top:48px;left:0;right:0;bottom:0;z-index:100;flex-direction:column">
    <iframe id="iframe-donnees"  src="" style="width:100%;height:100%;border:none;display:none"></iframe>
    <iframe id="iframe-requetes" src="" style="width:100%;height:100%;border:none;display:none"></iframe>
    <iframe id="iframe-crm"      src="" style="width:100%;height:100%;border:none;display:none"></iframe>
    <iframe id="iframe-bofip"    src="" style="width:100%;height:100%;border:none;display:none"></iframe>
    <iframe id="iframe-maj"      src="" style="width:100%;height:100%;border:none;display:none"></iframe>
</div>

<div id="app">

    <!-- ═══ PANNEAU GAUCHE ══════════════════════════════════ -->
    <aside id="panel-left">
        <div class="panel-left-head">Couches</div>

        <!-- Ortho historique IGN -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-ortho">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="1"/><path d="M3 9h18M9 21V9"/></svg>
                Ortho historique IGN
            </label>
        </div>

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
                <label>Mode
                    <select id="taux-mode">
                        <option value="normal">Valeur</option>
                        <option value="evolution">Évolution entre 2 millésimes</option>
                    </select>
                </label>
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
                <div id="taux-normal-opts">
                    <label>Millésime
                        <select id="taux-millesime"><option value="2025">2025</option></select>
                    </label>
                </div>
                <div id="taux-evol-opts" class="hidden">
                    <label>De
                        <select id="taux-evol-de"></select>
                    </label>
                    <label>À
                        <select id="taux-evol-a"></select>
                    </label>
                </div>
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
                <label class="coeff-seuil-label">
                    Seuil min. <span id="coeff-seuil-val">1,00</span>
                    <input type="range" id="coeff-seuil" min="0.5" max="2" step="0.05" value="1">
                </label>
                <div id="coeff-seuil-hint" class="layer-hint">Affiche uniquement coeff ≥ seuil — prospects surévalués</div>
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

        <!-- TF estimée €/m² -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-tf">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
                TF estimée €/m²
            </label>
            <div id="tf-options" class="layer-sub hidden">
                <label>Catégorie
                    <select id="tf-categorie">
                        <option value="">— choisir —</option>
                    </select>
                </label>
                <label>Année
                    <select id="tf-annee">
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
                <div id="tf-msg" class="layer-msg"></div>
            </div>
        </div>

        <!-- Taxe d'Aménagement -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-ta">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Taxe d'aménagement
            </label>
            <div id="ta-options" class="layer-sub hidden">
                <label>Mode
                    <select id="ta-mode">
                        <option value="union">Avec zones majorées</option>
                        <option value="commune">Communes seulement</option>
                    </select>
                </label>
                <label>Afficher
                    <select id="ta-champ">
                        <option value="ta_estime_log">TA estim. logement €/m²</option>
                        <option value="ta_estime_aut">TA estim. autres constr. €/m²</option>
                        <option value="taux_total">Taux total (%)</option>
                        <option value="taux_com">Taux communal (%)</option>
                    </select>
                </label>
                <label>Val. forfaitaires année
                    <select id="ta-annee">
                        <option value="2025">2025</option>
                        <option value="2024">2024</option>
                        <option value="2023">2023</option>
                        <option value="2022">2022</option>
                    </select>
                </label>
                <label>Millésime zones majorées
                    <select id="ta-millesime-union"></select>
                </label>
            </div>
        </div>

        <!-- TA taux majorés (sections + clusters) -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-ta-majore">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                TA majorée &gt;5%
            </label>
            <div id="ta-majore-options" class="layer-sub hidden">
                <label>Millésime
                    <select id="ta-majore-millesime"></select>
                </label>
            </div>
        </div>

        <!-- TSB IDF -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-tsb-idf">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                TSB — Circ. IDF
            </label>
            <div id="tsb-idf-options" class="layer-sub hidden">
                <label>Millésime
                    <select id="tsb-idf-millesime"></select>
                </label>
            </div>
        </div>

        <!-- TSB PACA -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-tsb-paca">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                TSB — PACA
            </label>
            <div id="tsb-paca-options" class="layer-sub hidden">
                <label>Millésime
                    <select id="tsb-paca-millesime"></select>
                </label>
            </div>
        </div>

        <!-- TASS IDF -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-tass">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6v6H9z"/></svg>
                TASS — Stationnement IDF
            </label>
            <div id="tass-options" class="layer-sub hidden">
                <label>Millésime
                    <select id="tass-millesime"></select>
                </label>
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

        <!-- Prospects coeff loc -->
        <div class="layer-row">
            <label class="layer-toggle">
                <input type="checkbox" id="toggle-prospects">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                Prospects coeff loc.
            </label>
            <div id="prospects-options" class="layer-sub hidden">
                <label class="coeff-seuil-label">
                    Surface bâtie min. <span id="prospects-surface-val">500 m²</span>
                    <input type="range" id="prospects-surface" min="500" max="5000" step="100" value="500">
                </label>
                <div class="layer-hint" style="margin-bottom:6px">Filtre les parcelles dont le bâti est ≥ seuil</div>
                <label style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--text3);display:block;margin-bottom:2px">Client / dénomination</label>
                <input type="search" id="prospects-client-filter" class="df-input-inline" placeholder="Rechercher un nom…" autocomplete="off">
                <div class="prospect-crm-filter-row" style="margin-top:6px">
                    <button id="prospects-rtaxes-only" class="prospect-rtaxes-btn" data-active="0">★ Clients RTaxes uniquement</button>
                </div>
                <div class="prospect-filter-title">Afficher les états</div>
                <div class="prospect-filter-statuts">
                    <label class="prospect-filter-item"><input type="checkbox" class="prospect-statut-filter" value="nouveau" checked><span class="prospect-filter-dot" style="background:#dc2626"></span>Nouveau</label>
                    <label class="prospect-filter-item"><input type="checkbox" class="prospect-statut-filter" value="contacte" checked><span class="prospect-filter-dot" style="background:#3b82f6"></span>Contacté</label>
                    <label class="prospect-filter-item"><input type="checkbox" class="prospect-statut-filter" value="en_attente" checked><span class="prospect-filter-dot" style="background:#8b5cf6"></span>En attente</label>
                    <label class="prospect-filter-item"><input type="checkbox" class="prospect-statut-filter" value="annule" checked><span class="prospect-filter-dot" style="background:#94a3b8"></span>Annulé</label>
                    <label class="prospect-filter-item"><input type="checkbox" class="prospect-statut-filter" value="client" checked><span class="prospect-filter-dot" style="background:#16a34a"></span>Client</label>
                </div>
            </div>
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
        <div id="db-offline-banner" style="display:none;position:absolute;top:0;left:0;right:0;z-index:2000;background:#b91c1c;color:#fff;text-align:center;padding:7px 12px;font-size:13px;font-weight:600;letter-spacing:.02em;pointer-events:none;">
            ⚠ Base de données déconnectée — les couches de données fiscales sont indisponibles
        </div>
        <div id="map"></div>
        <div id="search-wrap">
            <input type="search" id="search" placeholder="🔍 Rechercher une adresse ou un lieu…" autocomplete="off">
            <button id="search-clear" title="Effacer" style="display:none">✕</button>
            <div id="search-spinner"></div>
            <div id="resultats" class="dropdown"></div>
        </div>
        <div id="legend" class="legend-hidden">
            <div id="legend-title"></div>
            <div id="legend-items"></div>
        </div>
        <div id="map-spinner">
            <div class="spinner-ring"></div>
            <span>Chargement…</span>
        </div>
    </div>

    <!-- ═══ PANNEAU DROIT ════════════════════════════════════ -->
    <aside id="panel-right" class="panel-closed">
        <button id="close-right" title="Fermer">✕</button>
        <div class="panel-right-head" id="info-title">Informations</div>
        <div id="info-content"></div>
    </aside>

</div>

<script src="https://unpkg.com/maplibre-gl/dist/maplibre-gl.js" crossorigin=""></script>
<script type="module" src="assets/map.js"></script>
<script>
// ── Recherche géocodage ──────────────────────────────────
const HIST_KEY = 'rsig_search_history';
const HIST_MAX = 8;

function histLoad() {
    try { return JSON.parse(localStorage.getItem(HIST_KEY) || '[]'); } catch { return []; }
}
function histAdd(entry) {
    let h = histLoad().filter(e => e.label !== entry.label);
    h.unshift(entry);
    if (h.length > HIST_MAX) h = h.slice(0, HIST_MAX);
    localStorage.setItem(HIST_KEY, JSON.stringify(h));
}
function histRemove(label) {
    localStorage.setItem(HIST_KEY, JSON.stringify(histLoad().filter(e => e.label !== label)));
}

let searchTimer;
let _currentResults = []; // [{label, lat, lon, class, isPoi?}]
let _focusIdx = -1;
const input    = document.getElementById('search');
const dropdown = document.getElementById('resultats');
const clearBtn = document.getElementById('search-clear');

// ── Recherche POI via Nominatim ───────────────────────────
const POI_LABELS = {
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
// Classes Nominatim autorisées comme POI
const NOM_POI_CLASSES = new Set(['amenity','shop','leisure','tourism']);

async function searchPoi(q) {
    const params = new URLSearchParams({
        q, format: 'jsonv2', limit: 8,
        countrycodes: 'fr',
        addressdetails: 1,
        'accept-language': 'fr',
    });
    try {
        const r = await fetch('https://nominatim.openstreetmap.org/search?' + params, {
            headers: { 'Accept': 'application/json', 'User-Agent': 'RSig/1.0' },
            signal: AbortSignal.timeout(5000),
        });
        if (!r.ok) return [];
        const data = await r.json();
        return data
            .filter(el => NOM_POI_CLASSES.has(el.class) && el.lat && el.lon)
            .map(el => {
                const key  = el.type || '';
                const type = POI_LABELS[key] || el.class;
                const addr = el.address;
                const parts = [addr?.road, addr?.city || addr?.town || addr?.village].filter(Boolean).join(', ');
                const name  = el.name || el.display_name.split(',')[0];
                return {
                    label: `📍 ${name}${parts ? ' — ' + parts : ''}${type ? ' (' + type + ')' : ''}`,
                    lat: parseFloat(el.lat), lon: parseFloat(el.lon),
                    class: 4, isPoi: true,
                };
            });
    } catch { return []; }
}

function renderDropdown(items, isHistory) {
    _focusIdx = -1;
    dropdown.innerHTML = '';
    if (!items.length) return;
    if (isHistory) {
        const hdr = document.createElement('div');
        hdr.style.cssText = 'padding:4px 12px 2px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);background:var(--surface2)';
        hdr.textContent = 'Recherches récentes';
        dropdown.appendChild(hdr);
    }

    // Séparer adresses et POI
    const adresses = items.filter(r => !r.isPoi);
    const pois     = items.filter(r => r.isPoi);

    function addSection(list, title) {
        if (!list.length) return;
        if (title && (adresses.length && pois.length)) {
            const hdr = document.createElement('div');
            hdr.style.cssText = 'padding:4px 12px 2px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);background:var(--surface2)';
            hdr.textContent = title;
            dropdown.appendChild(hdr);
        }
        list.forEach(r => {
            const i = items.indexOf(r);
            const row = document.createElement('div');
            row.className = 'item search-item';
            row.dataset.idx = i;
            row.innerHTML = isHistory
                ? `<span style="opacity:.45;margin-right:6px;font-size:11px">🕐</span><span style="flex:1">${escHtml(r.label)}</span><button class="hist-del" data-label="${escHtml(r.label)}" title="Supprimer">✕</button>`
                : escHtml(r.label);
            row.addEventListener('mousedown', ev => {
                if (ev.target.classList.contains('hist-del')) {
                    ev.preventDefault();
                    histRemove(ev.target.dataset.label);
                    renderDropdown(histLoad(), true);
                    return;
                }
                ev.preventDefault();
                selectResult(r);
            });
            dropdown.appendChild(row);
        });
    }

    if (isHistory) {
        addSection(items, null);
    } else {
        addSection(adresses, '📌 Adresses');
        addSection(pois,     '📍 Points d\'intérêt');
    }
    _currentResults = items;
}

function selectResult(r) {
    input.value = r.label;
    clearBtn.style.display = 'flex';
    dropdown.innerHTML = '';
    _focusIdx = -1;
    histAdd(r);
    if (r.isPoi) {
        window.zoomOnPoi?.(r.lat, r.lon);
    } else {
        afficherSurCarte(r.lat, r.lon, r.class);
    }
}

function closeDropdown() {
    dropdown.innerHTML = '';
    _focusIdx = -1;
}

input.addEventListener('focus', () => {
    if (!input.value.trim()) {
        const h = histLoad();
        if (h.length) renderDropdown(h, true);
    }
});

input.addEventListener('input', function () {
    clearTimeout(searchTimer);
    const val = this.value.trim();
    clearBtn.style.display = val ? 'flex' : 'none';
    if (!val) { renderDropdown(histLoad(), true); return; }
    const searchWrap = document.getElementById('search-wrap');
    searchTimer = setTimeout(async () => {
        searchWrap.classList.add('searching');
        try {
            const poiActive = window.isOsmActive?.() ?? false;

            const [addrData, poiResults] = await Promise.all([
                fetch('/search?barre=' + encodeURIComponent(val)).then(r => r.json()).catch(() => ({ results: [] })),
                poiActive ? searchPoi(val) : Promise.resolve([]),
            ]);

            const adresses = addrData.results || [];
            const all = [...adresses, ...poiResults];

            if (!all.length) {
                dropdown.innerHTML = "<div class='item' style='color:var(--text3)'>Aucun résultat</div>";
                _currentResults = [];
            } else {
                renderDropdown(all, false);
            }
        } finally {
            searchWrap.classList.remove('searching');
        }
    }, 300);
});

input.addEventListener('keydown', e => {
    const items = dropdown.querySelectorAll('.search-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        _focusIdx = Math.min(_focusIdx + 1, items.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        _focusIdx = Math.max(_focusIdx - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const idx = _focusIdx >= 0 ? _focusIdx : 0;
        if (_currentResults[idx]) selectResult(_currentResults[idx]);
        return;
    } else if (e.key === 'Escape') {
        closeDropdown();
        input.blur();
        return;
    } else { return; }
    items.forEach((el, i) => el.classList.toggle('item-focused', i === _focusIdx));
    if (_focusIdx >= 0) input.value = _currentResults[_focusIdx]?.label ?? input.value;
});

clearBtn.addEventListener('click', () => {
    input.value = '';
    clearBtn.style.display = 'none';
    closeDropdown();
    window.clearSearchMarker?.();
    input.focus();
    const h = histLoad();
    if (h.length) renderDropdown(h, true);
});

document.addEventListener('click', e => {
    if (!e.target.closest('#search-wrap')) closeDropdown();
});

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Modales Aide / À propos ──────────────────────────────
function openModal(id) {
    const m = document.getElementById(id);
    if (m) { m.style.display = 'flex'; }
}
function closeModal(id) {
    const m = document.getElementById(id);
    if (m) { m.style.display = 'none'; }
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        ['modal-aide','modal-apropos'].forEach(closeModal);
    }
});

// ── Navigation SPA ───────────────────────────────────────
const PAGES   = { donnees: '/donnees', requetes: '/requetes', crm: '/crm', bofip: '/bofip', maj: '/maj' };
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
    const pathToPage = { '/donnees': 'donnees', '/requetes': 'requetes', '/crm': 'crm', '/bofip': 'bofip', '/maj': 'maj' };
    const initPage = pathToPage[location.pathname] || 'carte';
    if (initPage !== 'carte') showPage(initPage);
    else history.replaceState({ page: 'carte' }, '', location.pathname);
});

// ── Banner BDD offline ───────────────────────────────────
(function pollDbStatus() {
    const banner = document.getElementById('db-offline-banner');
    if (!banner) return;
    function check() {
        fetch('/api/db/status').then(r => r.ok ? r.json() : null).then(d => {
            if (d) banner.style.display = d.offline ? 'block' : 'none';
        }).catch(() => {});
    }
    check();
    setInterval(check, 30000);
})();
</script>
</body>
</html>
