# Mini SIG — CLAUDE.md

## Stack

- **Backend** : PHP + [Flight](https://flightphp.com/) (micro-framework, routeur REST)
- **Frontend** : HTML/CSS/JS vanilla + [Leaflet.js 1.9.4](https://leafletjs.com/)
- **Fond de carte** : IGN Géoplateforme WMTS (orthophotos)
- **Données carto** : IGN Géoplateforme WFS (départements, communes, sections, parcelles cadastrales)
- **Géocodage** : API Géoplateforme autocomplétion (`data.geopf.fr/geocodage/completion`)
- **CRM** : Microsoft Dynamics 365 (`rtaxes.api.crm4.dynamics.com`) via OAuth2 client_credentials

## Structure des fichiers

```
index.php          — routeur Flight + API BDD + Dynamics + NiFi
views/
  accueil.php      — carte 3 colonnes : panneau gauche (couches), carte, panneau droit (infos)
  crm.php          — vue CRM
assets/
  map.js           — logique Leaflet : WFS IGN + couches BDD + panneau infos
  style.css        — thème sombre, layout 3 colonnes
  crm.js           — logique CRM
flight/            — micro-framework Flight
nifi-drop/         — dossier de dépôt CSV pour NiFi
```

## Layout carte (3 colonnes)

```
#panel-left (220px) | #map-wrap (flex:1) | #panel-right (280px, caché par défaut)
```

- **Gauche** : sélection des couches à afficher, légende dynamique
- **Centre** : carte Leaflet (WFS IGN + couches BDD superposées)
- **Droite** : panneau infos au clic sur un objet (s'ouvre automatiquement)

## Couches BDD (mabase PostgreSQL)

| Couche | Table | Clé de jointure | Particularité |
|--------|-------|-----------------|---------------|
| Taux fiscaux | `taux_clean` | bbox | Choroplèthe, champ sélectionnable |
| Coeff locatifs | `coeff_loc_final` | bbox | Zoom ≥ 15 obligatoire |
| Dossiers | `dossier_acc_geo` | — | Points, tout chargé une fois |
| Tarifs sections | `sections_2025` + `tarifs_pivot` | bbox + categorie + annee | Évolution 2017-2026 |

## Comportement de la carte

WFS IGN change selon le niveau de zoom :

| Zoom | Couche |
|------|--------|
| < 11 | Départements (emprise France entière fixe) |
| < 13 | Communes |
| ≤ 16 | Sections cadastrales |
| > 16 | Parcelles cadastrales |

Chaque changement de vue (`moveend`) déclenche un rechargement debounced (350 ms) pour WFS et couches BDD actives.
Les requêtes précédentes sont annulées via `AbortController`.

## Routes API (index.php)

| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `/` | Affiche la carte |
| GET | `/crm` | Vue CRM |
| GET | `/search?barre=` | Autocomplétion géocodage |
| GET | `/api/commune/@code` | Données Dynamics pour une commune |
| GET | `/api/taux?bbox=&champ=` | GeoJSON taux_clean |
| GET | `/api/coeff?bbox=` | GeoJSON coeff_loc_final (zoom ≥ 15) |
| GET | `/api/dossiers[?bbox=]` | GeoJSON dossier_acc_geo |
| GET | `/api/tarifs?bbox=&categorie=&annee=` | GeoJSON sections + tarifs |
| GET | `/api/tarifs/categories` | Liste des catégories tarifs |

## Intégration Dynamics 365

- Auth : `client_credentials` (tenant `3af2c634-…`, app `5f172232-…`)
- Endpoint : `https://rtaxes.api.crm4.dynamics.com/api/data/v9.2/`
- Table principale : `apo_dossiers`

## Conventions

- Pas de framework JS, pas de bundler — tout est vanilla.
- Les appels WFS utilisent `EPSG:4326` / `OUTPUTFORMAT=application/json`.
- Le fond de carte est en WMTS tuilé (PM / Web Mercator).
- `preferCanvas: true` activé sur la carte Leaflet pour les performances.
