# Guide RSig — Cours, Procédures et Déploiement

> **Public :** développeur avec bases web (PHP, CSS, JS), peu familier des patterns avancés.
> **Objectif :** comprendre comment fonctionne le projet, comment ajouter une couche, comment déployer.

---

## Table des matières

1. [Architecture générale](#1-architecture-générale)
2. [Lien entre le code et la base de données](#2-lien-entre-le-code-et-la-base-de-données)
3. [Le backend PHP (Flight)](#3-le-backend-php--le-routeur-flight)
4. [La base de données et PostGIS](#4-la-base-de-données--postgis)
5. [Le frontend MapLibre GL JS](#5-le-frontend--maplibre-gl-js)
6. [Système de couches — pattern complet](#6-système-de-couches--pattern-complet)
7. [La légende dynamique](#7-la-légende-dynamique)
8. [Le panneau d'information](#8-le-panneau-dinformation)
9. [Les utilitaires](#9-les-utilitaires)
10. [PROCÉDURE : Ajouter une nouvelle couche](#10-procédure--ajouter-une-nouvelle-couche-de-a-à-z)
11. [PROCÉDURE : Déployer le site en ligne](#11-procédure--déployer-le-site-en-ligne)

---

## 1. Architecture générale

```
Navigateur (MapLibre JS)
      │
      │  HTTP (JSON / GeoJSON)
      ▼
PHP Apache (Flight micro-framework)  ←── index.php (point d'entrée unique)
      │
      │  PDO (SQL + PostGIS)
      ▼
PostgreSQL + PostGIS
```

**Ce que ça veut dire concrètement :**
- L'utilisateur ouvre le site → son navigateur charge la carte MapLibre.
- Quand il active une couche ou se déplace, le JS envoie une requête HTTP vers le PHP (ex : `GET /api/taux?bbox=...`).
- Le PHP interroge PostgreSQL avec une requête spatiale, renvoie du GeoJSON.
- MapLibre reçoit ce GeoJSON et dessine les polygones/points sur la carte.

### Structure des dossiers clés

```
rsig/
├── index.php              ← Point d'entrée : charge tout, lance Flight
├── config.php             ← Identifiants BDD, Dynamics, variables d'env
├── app/
│   ├── helpers.php        ← Fonctions PHP réutilisables (getDb, parseBbox…)
│   └── routes/
│       ├── pages.php      ← Routes HTML (/, /crm, /donnees…)
│       └── api.php        ← Toutes les routes API (/api/taux, /api/coeff…)
├── assets/
│   ├── map.js             ← Initialisation de la carte MapLibre
│   ├── wfs.js             ← Couches cadastre IGN (zoom-based)
│   ├── legend.js          ← Moteur de légende dynamique
│   ├── panel.js           ← Panneau d'info à droite (clic sur entité)
│   ├── utils.js           ← Debounce, breaks, palettes couleurs
│   └── layers/            ← Un fichier JS par couche métier
│       ├── taux.js
│       ├── coeff.js
│       ├── sections.js
│       ├── tarifs.js
│       ├── dossiers.js
│       └── cfe.js
├── views/
│   └── accueil.php        ← Template HTML de la carte (3 colonnes)
└── Dockerfile             ← Pour construire l'image Docker
```

---

## 2. Lien entre le code et la base de données

C'est **la question centrale** : comment le code PHP sait-il où est ta base PostgreSQL ?

### Le fichier `config.php` — le seul endroit à modifier

```php
// config.php (ligne 3-5)
define('DB_DSN',  getenv('DB_DSN')  ?: 'pgsql:host=localhost;port=5432;dbname=mabase');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') ?: 'postgres');
```

Le `?:` est l'opérateur PHP "si vide, utilise la valeur à droite". Ça veut dire :
- Si une variable d'environnement `DB_DSN` existe → l'utiliser (cas Docker/production)
- Sinon → utiliser la valeur codée en dur à droite (cas local MAMP)

### En local (MAMP + PostgreSQL)

Ton setup actuel :
```
MAMP (Apache + PHP 8.3)          PostgreSQL 17
  C:/MAMP/                          C:/Program Files/PostgreSQL/17/
  Port 80 (HTTP)                    Port 5432
  DocumentRoot = C:/02_WEBcarto/rsig/
                    │
                    └── config.php ──────────────────────────────────────┐
                                                                         ▼
                                                          host=localhost, port=5432
                                                          dbname=mabase
                                                          user=postgres / pass=postgres
```

La connexion se fait via le driver **PDO pgsql** de PHP. Ce driver nécessitait une config spéciale sur ton poste :
- `libpq.dll` copiée de PostgreSQL vers `C:/MAMP/bin/php/php8.3.1/`
- `LoadFile "C:/MAMP/bin/php/php8.3.1/libpq.dll"` dans `httpd.conf`

Pour vérifier que la connexion fonctionne : `http://localhost/api/db-check`

### En production (OVH Docker)

```
Docker Compose sur VPS OVH
  ┌─────────────────────────────────────────────────────┐
  │  conteneur rsig-app (PHP 8.3 Apache)                │
  │    → lit la variable d'env DB_DSN injectée par Docker│
  │    → host=db (nom du service Docker, pas localhost!) │
  │    → dbname=Rbdd, user=rtaxes                        │
  │              │                                       │
  │              │ réseau Docker interne                 │
  │              ▼                                       │
  │  conteneur rsig-db (PostgreSQL 17 + PostGIS 3.5)    │
  │    → base de données : Rbdd                          │
  │    → user : rtaxes                                   │
  └─────────────────────────────────────────────────────┘
```

En production, `DB_DSN` vaut `pgsql:host=db;port=5432;dbname=Rbdd` — le mot `db` est le **nom du service Docker**, pas une adresse IP. Docker résout automatiquement ce nom vers le bon conteneur.

### Tableau récapitulatif local vs production

| | Local (MAMP) | Production (OVH Docker) |
|---|---|---|
| Serveur web | Apache MAMP, port 80 | Apache dans conteneur, port 8080 interne |
| PHP | C:/MAMP/bin/php/php8.3.1/ | Image Docker php:8.3-apache |
| PostgreSQL | localhost:5432 | service Docker `db` |
| Nom de la base | `mabase` | `Rbdd` |
| Utilisateur BDD | `postgres` | `rtaxes` |
| Config lue depuis | valeurs directes dans config.php | variables d'environnement Docker |
| URL | http://localhost | https://rcarto.rtaxes-geometre-expert.fr |

### Modifier la connexion BDD (si besoin)

Si tu changes de base en local, modifie **uniquement `config.php`** ligne 3 :
```php
// Exemple : changer le nom de la base
define('DB_DSN', getenv('DB_DSN') ?: 'pgsql:host=localhost;port=5432;dbname=ma_nouvelle_base');
```

Ne touche jamais aux fichiers JS ou aux routes API pour changer la BDD — tout passe par `config.php` et la fonction `getDb()` dans `helpers.php`.

---

## 3. Le backend PHP : le routeur Flight

### Qu'est-ce que Flight ?

Flight est un **micro-framework PHP**. Il fait une seule chose : associer des URLs à du code PHP.

```
GET /api/taux  →  exécute ce bloc de code PHP  →  renvoie du JSON
```

Sans Flight, il faudrait gérer les URLs manuellement. Flight s'occupe du routage.

### Comment ça démarre (`index.php`)

```php
// index.php
require 'flight/Flight.php';
require 'config.php';
require 'app/helpers.php';
require 'app/routes/pages.php';
require 'app/routes/api.php';

Flight::start();  // Lance le routeur — il intercepte toutes les requêtes HTTP
```

Le `.htaccess` redirige TOUTES les requêtes vers `index.php` :
```apache
RewriteRule ^(.*)$ index.php [QSA,L]
```

### Déclarer une route API (`app/routes/api.php`)

```php
Flight::route('GET /api/monendpoint', function () {
    $db = getDb();           // Connexion PDO à PostgreSQL
    $bbox = parseBbox();     // Lit ?bbox=x1,y1,x2,y2 depuis l'URL

    $sql = "SELECT ... FROM ma_table WHERE ...";
    $stmt = $db->prepare($sql);
    $stmt->execute();

    Flight::json([           // Renvoie du JSON (Content-Type: application/json)
        'type' => 'FeatureCollection',
        'features' => rowsToGeoJson($stmt)
    ]);
});
```

### Les fonctions helpers (`app/helpers.php`)

| Fonction | Rôle |
|---|---|
| `getDb()` | Ouvre une connexion PDO PostgreSQL |
| `parseBbox()` | Lit `?bbox=x1,y1,x2,y2` → tableau `[x1,y1,x2,y2]` |
| `bindBbox($stmt, $b)` | Lie les 4 valeurs `:x1 :y1 :x2 :y2` à un statement PDO |
| `bboxWhere($col, $srid)` | Génère le `WHERE ST_Intersects(...)` SQL |
| `rowsToGeoJson($stmt)` | Transforme les lignes SQL en tableau GeoJSON features |

**Exemple complet d'une route (`app/routes/api.php`) :**

```php
Flight::route('GET /api/taux', function () {
    $db = getDb();
    $b  = parseBbox();          // bbox depuis l'URL

    // Millesime validé contre liste autorisée (sécurité)
    $millesime = validateAnnee($_GET['millesime'] ?? '2025', ['2023','2024','2025']);
    // Champ validé contre liste blanche (évite injection SQL sur nom de colonne)
    $champ = validateChamp($_GET['champ'] ?? 'taux_fb_commune_vote', CHAMPS_TAUX);

    $sql = "SELECT code_insee, \"$champ\" AS val,
                   ST_AsGeoJSON(ST_Transform(geom,4326),6) AS geojson
            FROM taux_clean
            WHERE millesime = :millesime
              AND " . bboxWhere('geom', '2154');
    // bboxWhere génère : ST_Intersects(geom, ST_Transform(ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326),2154))

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':millesime', $millesime);
    bindBbox($stmt, $b);
    $stmt->execute();

    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});
```

**Pourquoi `ST_Transform` ?** La table `taux_clean` est stockée en EPSG:2154 (Lambert 93, système français). Le navigateur travaille en EPSG:4326 (WGS84, lat/lon). On transforme la bbox reçue en 4326 vers 2154 pour l'intersection, puis la géométrie résultat vers 4326 pour le GeoJSON.

---

## 4. La base de données : PostGIS

### PostGIS = PostgreSQL + géométries

PostGIS ajoute un type de données `geometry` à PostgreSQL. Une table avec PostGIS ressemble à :

```sql
CREATE TABLE ma_couche (
    id SERIAL PRIMARY KEY,
    code_commune VARCHAR(5),
    ma_valeur NUMERIC,
    geom geometry(MultiPolygon, 2154)  -- géométrie stockée en Lambert 93
);
```

### Fonctions PostGIS essentielles utilisées dans le projet

| Fonction SQL | Rôle |
|---|---|
| `ST_Intersects(geom, bbox)` | Vrai si la géométrie intersecte la bbox (filtre spatial) |
| `ST_MakeEnvelope(x1,y1,x2,y2, srid)` | Crée un rectangle depuis 4 coordonnées |
| `ST_Transform(geom, srid)` | Reprojette une géométrie d'un SRID vers un autre |
| `ST_AsGeoJSON(geom, precision)` | Convertit une géométrie en texte GeoJSON |
| `ST_Union(geom)` | Fusionne plusieurs géométries en une seule (agrégat) |
| `ST_Centroid(geom)` | Calcule le centroïde d'une géométrie |

### Exemple : requête complète avec bbox

```sql
SELECT
    code_commune,
    valeur,
    ST_AsGeoJSON(
        ST_Transform(geom, 4326),  -- passe de Lambert 93 → WGS84 pour le JS
        6                           -- 6 décimales (précision ~10cm)
    ) AS geojson
FROM ma_table
WHERE
    ST_Intersects(
        geom,
        ST_Transform(
            ST_MakeEnvelope(-1.5, 47.0, -1.0, 47.5, 4326),  -- bbox en WGS84
            2154                                              -- transformé en Lambert 93
        )
    )
LIMIT 2000;
```

### `rowsToGeoJson` : comment PHP transforme les résultats SQL en GeoJSON

La colonne `geojson` contient du texte JSON (ex: `{"type":"Polygon","coordinates":...}`).
La fonction PHP reconstruit la structure GeoJSON attendue par MapLibre :

```php
// app/helpers.php
function rowsToGeoJson(PDOStatement $stmt): array {
    $features = [];
    foreach ($stmt as $row) {
        $geometry = json_decode($row['geojson'], true);  // texte → tableau PHP
        unset($row['geojson']);                          // retire la colonne géo des propriétés
        $features[] = [
            'type'       => 'Feature',
            'geometry'   => $geometry,
            'properties' => $row   // tout le reste devient les propriétés GeoJSON
        ];
    }
    return $features;
}
```

Résultat JSON envoyé au navigateur :

```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": { "type": "Polygon", "coordinates": [...] },
      "properties": { "code_commune": "44109", "valeur": 1.23 }
    }
  ]
}
```

---

## 5. Le frontend : MapLibre GL JS

### Qu'est-ce que MapLibre ?

MapLibre est une bibliothèque JavaScript qui affiche des cartes interactives dans le navigateur. Elle gère :
- Le rendu WebGL des tuiles de fond (orthophoto IGN, plan…)
- L'affichage de données GeoJSON par-dessus
- Les interactions (zoom, pan, clic)

### Initialisation (`assets/map.js`)

```js
import { initTaux } from './layers/taux.js'
// ... autres imports

const map = new maplibregl.Map({
    container: 'map',          // ID de l'élément HTML <div id="map">
    style: { ... },            // Définition des fonds de carte (WMTS IGN)
    center: [2.35, 46.6],      // Centre initial (France)
    zoom: 6,
});

map.on('load', () => {
    // Une fois la carte chargée, on initialise chaque couche
    const taux = initTaux(map)
    // ...

    // À chaque déplacement/zoom : recharger les couches actives
    map.on('moveend', debounce(() => {
        if (taux.isActive()) taux.load()
        // ...
    }, 400))  // 400ms de délai pour éviter trop d'appels
})
```

### Sources et couches dans MapLibre

MapLibre distingue **source** (les données) et **layer** (le style d'affichage).

```
Source "taux-src"  →  Layer "taux-fill"  (polygones colorés)
                   →  Layer "taux-line"  (contours noirs)
```

Une **source GeoJSON** :
```js
map.addSource('taux-src', {
    type: 'geojson',
    data: { type: 'FeatureCollection', features: [] }  // données vides au départ
})
```

Un **layer fill** (polygones) :
```js
map.addLayer({
    id: 'taux-fill',
    type: 'fill',
    source: 'taux-src',
    paint: {
        'fill-color': ['step', ['get', 'val'], '#eee', 0.5, '#9c27b0', 1.0, '#5c6bc0', ...],
        'fill-opacity': 0.7
    }
})
```

Un **layer line** (contours) :
```js
map.addLayer({
    id: 'taux-line',
    type: 'line',
    source: 'taux-src',
    paint: { 'line-color': '#334455', 'line-width': 0.5 }
})
```

**Mettre à jour les données :**
```js
map.getSource('taux-src').setData(nouveauGeoJSON)
```

**Mettre à jour le style d'une couche :**
```js
map.setPaintProperty('taux-fill', 'fill-color', nouvelleExpression)
```

### Les expressions MapLibre (`step`, `match`, `get`)

Les couleurs en MapLibre ne sont pas codées en dur — ce sont des **expressions** évaluées pour chaque entité.

`['step', ['get', 'val'], couleurDéfaut, seuil1, couleur1, seuil2, couleur2, ...]`

- `['get', 'val']` : lit la propriété `val` de chaque feature
- `step` : applique la couleur du dernier seuil ≤ valeur

Exemple :
```js
['step', ['get', 'val'],
  '#eee',    // si val < 0.5 : gris clair
  0.5, '#9c27b0',  // si val ≥ 0.5 : violet
  1.0, '#5c6bc0',  // si val ≥ 1.0 : bleu
  2.0, '#1976d2'   // si val ≥ 2.0 : bleu vif
]
```

La fonction `stepExpr(prop, breaks, palette)` dans `utils.js` génère ces expressions automatiquement depuis un tableau de seuils et un tableau de couleurs.

---

## 6. Système de couches : pattern complet

Chaque couche métier suit le même **pattern** :

### Structure d'un fichier de couche (`assets/layers/taux.js`)

```js
// ── 1. État de la couche ──────────────────────────────────────
let active = false     // couche activée ou non ?
let currentAbort = null  // pour annuler les requêtes en cours

// ── 2. Fonction de chargement ─────────────────────────────────
function loadTaux(map) {
    if (!active) return

    // Annuler la requête précédente si non terminée
    if (currentAbort) currentAbort.abort()
    currentAbort = new AbortController()

    // Lire les options de l'interface
    const champ     = document.getElementById('taux-champ').value
    const millesime = document.getElementById('taux-millesime').value
    const bbox      = map.getBounds()
    const b         = `${bbox.getWest()},${bbox.getSouth()},${bbox.getEast()},${bbox.getNorth()}`

    showSpinner()

    fetch(`/api/taux?bbox=${b}&champ=${champ}&millesime=${millesime}`, {
        signal: currentAbort.signal
    })
    .then(r => r.json())
    .then(fc => {
        // Calculer les seuils de couleur depuis les valeurs reçues
        const vals   = fc.features.map(f => f.properties.val).filter(v => v != null)
        const breaks = computeBreaks(vals, 5)   // 5 classes

        // Construire l'expression de couleur
        const colorExpr = stepExpr('val', breaks, PAL.taux)

        // Mettre à jour source et style
        upsert(map, fc, colorExpr)

        // Mettre à jour la légende
        saveLegend('taux', `TFPB ${millesime}`, breaks, PAL.taux, ' %')
    })
    .finally(() => hideSpinner())
}

// ── 3. Créer/mettre à jour les layers MapLibre ─────────────────
function upsert(map, fc, colorExpr) {
    if (!map.getSource('taux-src')) {
        // Première fois : créer la source et les layers
        map.addSource('taux-src', { type: 'geojson', data: fc })
        map.addLayer({ id: 'taux-fill', type: 'fill', source: 'taux-src',
                       paint: { 'fill-color': colorExpr, 'fill-opacity': 0.7 } })
        map.addLayer({ id: 'taux-line', type: 'line', source: 'taux-src',
                       paint: { 'line-color': '#334', 'line-width': 0.5 } })
    } else {
        // Mise à jour : changer les données et le style
        map.getSource('taux-src').setData(fc)
        map.setPaintProperty('taux-fill', 'fill-color', colorExpr)
    }
    bddOnTop(map)  // Garantir que les couches de données sont au-dessus du fond
}

// ── 4. Supprimer les layers quand on désactive ─────────────────
function removeTaux(map) {
    if (map.getLayer('taux-fill')) map.removeLayer('taux-fill')
    if (map.getLayer('taux-line')) map.removeLayer('taux-line')
    if (map.getSource('taux-src')) map.removeSource('taux-src')
}

// ── 5. Initialisation : branche les écouteurs d'événements ─────
export function initTaux(map) {
    const toggle  = document.getElementById('toggle-taux')
    const options = document.getElementById('taux-options')
    const champ   = document.getElementById('taux-champ')
    const milli   = document.getElementById('taux-millesime')

    // Coche/décoche → activer/désactiver
    toggle.addEventListener('change', () => {
        active = toggle.checked
        options.classList.toggle('hidden', !active)
        if (!active) {
            removeTaux(map)
            dropLegend('taux')
            clearInfo('taux')
        } else {
            loadTaux(map)
        }
    })

    // Changement d'option → recharger
    champ.addEventListener('change', () => { if (active) loadTaux(map) })
    milli.addEventListener('change', () => { if (active) loadTaux(map) })

    // Clic sur un polygone → afficher info à droite
    map.on('click', 'taux-fill', e => {
        const p = e.features[0].properties
        showInfo('taux', 'Taux fiscal', `
            <div class="info-row"><span>Commune</span><span>${p.code_insee}</span></div>
            <div class="info-row"><span>Valeur</span><span>${fmtVal(p.val, ' %')}</span></div>
        `)
    })

    return {
        load: () => loadTaux(map),
        isActive: () => active
    }
}
```

---

## 7. La légende dynamique

La légende est gérée dans `assets/legend.js`. Elle fonctionne comme un registre :

```js
// Enregistrer une légende (appelé depuis le layer après chargement)
saveLegend('taux', 'TFPB 2025', [0.5, 1.0, 1.5, 2.0], ['#eee','#9c27b0','#5c6bc0','#1976d2'], ' %')

// Supprimer quand on désactive la couche
dropLegend('taux')
```

Le rendu HTML est automatique : `legend.js` lit toutes les légendes enregistrées et génère le bloc HTML affiché en bas à droite de la carte.

```
┌─────────────────────┐
│ TFPB 2025           │
│ ■ < 0.5 %          │
│ ■ 0.5 – 1.0 %      │
│ ■ 1.0 – 1.5 %      │
│ ■ ≥ 1.5 %          │
│                     │
│ Tarifs 2025         │
│ ■ < 2 €/m²         │
│ ...                 │
└─────────────────────┘
```

Plusieurs couches actives en même temps → plusieurs sections dans la légende.

---

## 8. Le panneau d'information

`assets/panel.js` gère le panneau qui apparaît à droite quand on clique sur une entité.

```js
// Afficher des infos pour la couche 'taux'
showInfo('taux', 'Taux fiscal', '<div class="info-row">...</div>')

// Effacer les infos de la couche 'taux'
clearInfo('taux')

// Helper pour une ligne info
irow('Code INSEE', '44109')
// → '<div class="info-row"><span>Code INSEE</span><span>44109</span></div>'
```

Le panneau peut avoir plusieurs sections en même temps (si plusieurs couches actives et cliquées), chacune identifiée par la clé de couche.

---

## 9. Les utilitaires

### `debounce` (`utils.js`)

Évite d'envoyer 50 requêtes pendant un déplacement de carte. Attend 400ms après la fin du mouvement.

```js
// Sans debounce : une requête à CHAQUE pixel de déplacement (× couches)
map.on('moveend', () => loadTaux(map))   // ← trop de requêtes

// Avec debounce : attend 400ms après le dernier événement
map.on('moveend', debounce(() => {
    if (taux.isActive()) taux.load()
}, 400))
```

### `computeBreaks` (`utils.js`)

Calcule les seuils de classification par percentiles :

```js
const vals = [0.2, 0.8, 1.2, 1.5, 2.0, 2.8, 3.5]
const breaks = computeBreaks(vals, 4)
// → [0.2, 0.95, 1.75, 2.9, 3.5]  (seuils aux percentiles 0%, 25%, 50%, 75%, 100%)
```

### `bddOnTop` (`utils.js`)

Garantit l'ordre des couches sur la carte. Sans ça, les couches cadastre IGN (WFS) s'afficheraient par-dessus les données métier.

```js
// Ordre souhaité (bas vers haut) :
// fond IGN → cadastre WFS → données métier (taux, coeff…) → dossiers CRM
bddOnTop(map)  // appelé après chaque upsert
```

### Palettes de couleurs (`PAL` dans `utils.js`)

```js
export const PAL = {
    taux:    ['#f3e5f5','#9c27b0','#5c6bc0','#1976d2','#0d47a1'],  // violet→bleu
    coeff:   ['#fff9e6','#ffe082','#ffb300','#e65100','#b71c1c'],  // jaune→rouge
    tarifs:  ['#e8f5e9','#66bb6a','#2e7d32','#1b5e20','#0d3b16'],  // vert
    // ...
}
```

---

## 10. PROCÉDURE : Ajouter une nouvelle couche de A à Z

Exemple concret : on veut afficher une couche **"zones_inondation"** stockée dans la base, avec une légende par type de zone et visible dans le menu.

---

### Étape 1 — Vérifier la table en base

Connectez-vous à PostgreSQL et vérifiez votre table :

```sql
-- Vérifier que la table existe et a une colonne géo
SELECT column_name, data_type
FROM information_schema.columns
WHERE table_name = 'zones_inondation';

-- Vérifier le SRID de la géométrie
SELECT Find_SRID('public', 'zones_inondation', 'geom');
-- Résultat attendu : 2154 (Lambert 93) ou 4326 (WGS84)

-- Tester la requête bbox que vous allez utiliser
SELECT id, type_zone, ST_AsGeoJSON(ST_Transform(geom, 4326), 6) AS geojson
FROM zones_inondation
WHERE ST_Intersects(geom, ST_Transform(ST_MakeEnvelope(2.2, 48.7, 2.5, 49.0, 4326), 2154))
LIMIT 100;
```

---

### Étape 2 — Créer la route API PHP

Dans `app/routes/api.php`, ajoutez à la fin du fichier :

```php
Flight::route('GET /api/zones-inondation', function () {
    $db = getDb();
    if (!$db) { Flight::json(['error' => 'DB'], 500); return; }

    $b = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox manquant'], 400); return; }

    $sql = "SELECT id, type_zone,
                   ST_AsGeoJSON(ST_Transform(geom, 4326), 6) AS geojson
            FROM zones_inondation
            WHERE " . bboxWhere('geom', '2154') . "
            LIMIT 2000";

    $stmt = $db->prepare($sql);
    bindBbox($stmt, $b);
    $stmt->execute();

    Flight::json([
        'type'     => 'FeatureCollection',
        'features' => rowsToGeoJson($stmt)
    ]);
});
```

**Tester l'API dans le navigateur :**
```
http://localhost/api/zones-inondation?bbox=2.2,48.7,2.5,49.0
```
Vous devez voir du JSON avec des features. Si c'est vide, vérifiez la bbox ou le contenu de la table.

---

### Étape 3 — Créer le fichier JS de la couche

Créer `assets/layers/zones_inondation.js` :

```js
import { showSpinner, hideSpinner } from '../utils.js'
import { saveLegend, dropLegend }   from '../legend.js'
import { showInfo, clearInfo, irow } from '../panel.js'
import { bddOnTop }                  from '../utils.js'

// Adaptez ces valeurs à VOS types dans la BDD
const ZONE_COLORS = {
    'Zone Rouge'   : '#c62828',
    'Zone Bleue'   : '#1565c0',
    'Zone Verte'   : '#2e7d32',
}
const DEFAULT_COLOR = '#9e9e9e'

let active = false
let currentAbort = null

function buildColorExpr() {
    const expr = ['match', ['get', 'type_zone']]
    for (const [type, color] of Object.entries(ZONE_COLORS)) {
        expr.push(type, color)
    }
    expr.push(DEFAULT_COLOR)
    return expr
}

function upsert(map, fc) {
    const colorExpr = buildColorExpr()

    if (!map.getSource('zones-inondation-src')) {
        map.addSource('zones-inondation-src', { type: 'geojson', data: fc })
        map.addLayer({
            id: 'zones-inondation-fill',
            type: 'fill',
            source: 'zones-inondation-src',
            paint: { 'fill-color': colorExpr, 'fill-opacity': 0.5 }
        })
        map.addLayer({
            id: 'zones-inondation-line',
            type: 'line',
            source: 'zones-inondation-src',
            paint: { 'line-color': '#333', 'line-width': 0.8 }
        })
    } else {
        map.getSource('zones-inondation-src').setData(fc)
    }
    bddOnTop(map)
}

function removeLayer(map) {
    if (map.getLayer('zones-inondation-fill')) map.removeLayer('zones-inondation-fill')
    if (map.getLayer('zones-inondation-line')) map.removeLayer('zones-inondation-line')
    if (map.getSource('zones-inondation-src')) map.removeSource('zones-inondation-src')
}

function loadZones(map) {
    if (!active) return

    if (currentAbort) currentAbort.abort()
    currentAbort = new AbortController()

    const bounds = map.getBounds()
    const bbox   = `${bounds.getWest()},${bounds.getSouth()},${bounds.getEast()},${bounds.getNorth()}`

    showSpinner()
    fetch(`/api/zones-inondation?bbox=${bbox}`, { signal: currentAbort.signal })
        .then(r => r.json())
        .then(fc => {
            upsert(map, fc)
            saveLegend('zones-inondation', 'Zones inondation',
                Object.keys(ZONE_COLORS), Object.values(ZONE_COLORS), '')
        })
        .catch(err => {
            if (err.name !== 'AbortError') console.error('Erreur zones inondation', err)
        })
        .finally(() => hideSpinner())
}

export function initZonesInondation(map) {
    const toggle = document.getElementById('toggle-zones-inondation')

    toggle.addEventListener('change', () => {
        active = toggle.checked
        if (!active) {
            removeLayer(map)
            dropLegend('zones-inondation')
            clearInfo('zones-inondation')
        } else {
            loadZones(map)
        }
    })

    map.on('click', 'zones-inondation-fill', e => {
        const p = e.features[0].properties
        showInfo('zones-inondation', 'Zone inondation',
            irow('Type', p.type_zone) + irow('ID', p.id)
        )
    })

    map.on('mouseenter', 'zones-inondation-fill', () => { map.getCanvas().style.cursor = 'pointer' })
    map.on('mouseleave', 'zones-inondation-fill', () => { map.getCanvas().style.cursor = '' })

    return {
        load: () => loadZones(map),
        isActive: () => active
    }
}
```

---

### Étape 4 — Gérer la priorité (ordre des couches)

L'ordre d'affichage des couches est géré par `bddOnTop` dans `assets/utils.js`. Ajoutez vos nouveaux layer IDs dans la liste ordonnée :

```js
// assets/utils.js — fonction bddOnTop
export function bddOnTop(map) {
    // Ordre du bas vers le haut (le dernier est au-dessus)
    const ORDER = [
        // Couches de fond (WFS IGN)
        'parcelles-fill', 'parcelles-line',
        'sections-wfs-fill', 'sections-wfs-line',
        // Couches métier polygones
        'taux-fill', 'taux-line',
        'tarifs-fill', 'tarifs-line',
        'sections-fill', 'sections-line',
        'cfe-fill', 'cfe-line',
        // ← Ajouter votre couche ici (après les polygones, avant les points)
        'zones-inondation-fill', 'zones-inondation-line',
        // Couches ponctuelles au-dessus
        'coeff-fill', 'coeff-line',
        'coeff-clusters', 'coeff-cluster-count',
        'dossiers-clusters', 'dossiers-points',
    ]

    ORDER.forEach(id => {
        if (map.getLayer(id)) map.moveLayer(id)
    })
}
```

**Règle :** les couches listées en dernier sont au-dessus visuellement. Les points dossiers restent cliquables par-dessus les polygones.

---

### Étape 5 — Enregistrer la couche dans `map.js`

```js
// assets/map.js — ajouter l'import
import { initZonesInondation } from './layers/zones_inondation.js'

// Dans map.on('load', ...) ajouter :
const zonesInond = initZonesInondation(map)

// Dans le gestionnaire moveend ajouter :
map.on('moveend', debounce(() => {
    // ... couches existantes ...
    if (zonesInond.isActive()) zonesInond.load()
}, 400))
```

---

### Étape 6 — Ajouter le contrôle dans le menu HTML

Dans `views/accueil.php`, trouvez la zone `<div id="layers-panel">` et ajoutez un nouveau groupe :

```html
<!-- Après les groupes existants (taux, coeff, etc.) -->
<div class="layer-group">
    <div class="layer-group-title">Risques</div>
    <div class="layer-item">
        <label class="layer-toggle">
            <input type="checkbox" id="toggle-zones-inondation">
            <span>Zones inondation</span>
        </label>
        <!-- Pas de sub-options si la couche n'a pas de paramètres -->
    </div>
</div>
```

Si votre couche a des options (ex : filtre par type), ajoutez une `sub-options` :

```html
<div class="layer-item">
    <label class="layer-toggle">
        <input type="checkbox" id="toggle-zones-inondation">
        <span>Zones inondation</span>
    </label>
    <div id="zones-inondation-options" class="sub-options hidden">
        <label>Filtrer par type
            <select id="zones-inondation-type">
                <option value="">Tous</option>
                <option value="Zone Rouge">Zone Rouge</option>
                <option value="Zone Bleue">Zone Bleue</option>
            </select>
        </label>
    </div>
</div>
```

Dans ce cas, ajoutez un écouteur dans `initZonesInondation` :
```js
document.getElementById('zones-inondation-type')
    .addEventListener('change', () => { if (active) loadZones(map) })
```

---

### Étape 7 — Vérifier le résultat

1. Rechargez la page.
2. Cochez "Zones inondation" dans le menu.
3. Vérifiez :
   - Des polygones apparaissent sur la carte avec les bonnes couleurs.
   - La légende s'affiche en bas à droite.
   - Un clic sur un polygone affiche le panneau d'info à droite.
   - Après un déplacement, les données se rechargent.
   - Décocher la couche la retire proprement.

---

### Récapitulatif des fichiers modifiés

| Fichier | Modification |
|---|---|
| `app/routes/api.php` | +1 route `GET /api/zones-inondation` |
| `assets/layers/zones_inondation.js` | Nouveau fichier (couche complète) |
| `assets/map.js` | Import + init + moveend |
| `assets/utils.js` | Ordre dans `bddOnTop` |
| `views/accueil.php` | Bouton toggle dans le menu HTML |

---

## 11. PROCÉDURE : Déployer le site en ligne

Le site est conçu pour tourner dans un conteneur Docker sur un serveur Linux (VPS OVH dans votre cas).

### Vue d'ensemble du déploiement

```
Votre machine locale           Serveur OVH (Linux)
─────────────────────          ──────────────────────────────────
rsig/ (code source)    ──SSH→  /home/debian/rsig/ (code copié)
                                    │
                               Docker Compose
                                    ├── rsig-app (PHP Apache)  ← port 8080 interne
                                    └── rsig-db  (PostgreSQL)  ← port 5432 interne
                                    │
                               Nginx (reverse proxy)
                                    └── HTTPS 443 → HTTP 8080
```

---

### Étape 1 — Préparer le serveur (une seule fois)

Connectez-vous en SSH à votre VPS :
```bash
ssh debian@votre-ip-ovh
```

Installez Docker et Docker Compose :
```bash
# Debian
apt update && apt install -y docker.io docker-compose-plugin
systemctl enable docker && systemctl start docker
```

Créez le dossier du projet :
```bash
mkdir -p /home/debian/rsig
```

---

### Étape 2 — Préparer le fichier `docker-compose.yml`

Créez `/home/debian/rsig/docker-compose.yml` sur le serveur :

```yaml
version: '3.8'

services:
  web:
    build: .                       # Utilise le Dockerfile du projet
    ports:
      - "127.0.0.1:8080:80"       # Port 8080 local (Nginx fera le proxy HTTPS)
    environment:
      DB_DSN:    "pgsql:host=db;port=5432;dbname=Rbdd"   # "db" = nom du service Docker ci-dessous
      DB_USER:   "rtaxes"
      DB_PASS:   "MOT_DE_PASSE_FORT"                     # à ne jamais mettre dans Git
      AUTH_ENABLED: "true"
      DYN_TENANT_ID:     "3af2c634-649d-4e19-9d13-6d7f025fc528"
      DYN_CLIENT_ID:     "5f172232-4ca8-4992-805e-0c5265c87ba1"
      DYN_CLIENT_SECRET: "votre-secret-dynamics"
    volumes:
      - ./nifi-drop:/var/www/html/nifi-drop
    depends_on:
      - db
    restart: unless-stopped

  db:
    image: postgis/postgis:17-3.5  # PostgreSQL 17 + PostGIS 3.5
    environment:
      POSTGRES_DB:       Rbdd       # Nom de la base en production (≠ mabase en local)
      POSTGRES_USER:     rtaxes     # Utilisateur en production (≠ postgres en local)
      POSTGRES_PASSWORD: MOT_DE_PASSE_FORT
    volumes:
      - pgdata:/var/lib/postgresql/data
    restart: unless-stopped

volumes:
  pgdata:
```

**Important :** Ne jamais mettre les mots de passe dans le dépôt Git. Sur le serveur, ce fichier reste local.

---

### Étape 3 — Copier le code source sur le serveur

Depuis votre machine locale :
```bash
# Copier le code (en excluant les fichiers sensibles et inutiles)
rsync -avz --exclude='.git' --exclude='config.php' \
      C:/02_WEBcarto/rsig/ debian@votre-ip-ovh:/home/debian/rsig/

# OU via Git si vous utilisez un dépôt :
# Sur le serveur :
cd /home/debian/rsig && git pull origin main
```

---

### Étape 4 — Construire et lancer les conteneurs

Sur le serveur :
```bash
cd /home/debian/rsig
docker compose up -d --build
# -d = détaché (tourne en arrière-plan)
# --build = reconstruit l'image PHP si le code a changé
```

Vérifier que tout tourne :
```bash
docker compose ps
# Doit afficher "Up" pour web et db

docker compose logs web   # logs du conteneur PHP
docker compose logs db    # logs PostgreSQL
```

---

### Étape 5 — Importer la base de données

Exportez la base locale puis importez-la sur le serveur :

```bash
# Sur votre machine locale (base locale = mabase, user = postgres) :
pg_dump -h localhost -U postgres -d mabase -Fc -f mabase_backup.dump

# Copier sur le serveur :
scp mabase_backup.dump debian@votre-ip-ovh:/home/debian/rsig/

# Sur le serveur, importer dans le conteneur PostgreSQL :
# La base prod s'appelle Rbdd et l'utilisateur rtaxes (≠ local)
docker exec -i rsig-db-1 pg_restore -U rtaxes -d Rbdd /mabase_backup.dump
# Adapter "rsig-db-1" au nom réel du conteneur (vérifier avec : docker ps)
```

Vérifier que les tables sont là :
```bash
docker exec -it rsig-db-1 psql -U rtaxes -d Rbdd -c "\dt"
```

---

### Étape 6 — Configurer Nginx (HTTPS)

Nginx joue le rôle de **reverse proxy** : il reçoit les requêtes HTTPS sur le port 443 et les transmet en HTTP au conteneur PHP sur le port 8080.

Installer Nginx et Certbot (Let's Encrypt) :
```bash
apt install -y nginx certbot python3-certbot-nginx
```

Créer `/etc/nginx/sites-available/rsig` :
```nginx
server {
    listen 80;
    server_name rcarto.rtaxes-geometre-expert.fr;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name rcarto.rtaxes-geometre-expert.fr;

    ssl_certificate     /etc/letsencrypt/live/rcarto.rtaxes-geometre-expert.fr/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/rcarto.rtaxes-geometre-expert.fr/privkey.pem;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto https;
    }
}
```

Activer le site et obtenir le certificat SSL :
```bash
ln -s /etc/nginx/sites-available/rsig /etc/nginx/sites-enabled/
certbot --nginx -d rcarto.rtaxes-geometre-expert.fr
nginx -t && systemctl reload nginx
```

---

### Étape 7 — DNS OVH

Dans l'interface DNS OVH, vérifiez que l'enregistrement A pointe vers l'IP de votre VPS :
```
rcarto.rtaxes-geometre-expert.fr  →  A  →  IP-de-votre-VPS-OVH
```

La propagation DNS prend 5 à 60 minutes.

---

### Procédure de mise à jour du site

À chaque modification du code :

```bash
# 1. Copier le nouveau code sur le serveur
rsync -avz --exclude='.git' --exclude='config.php' \
      C:/02_WEBcarto/rsig/ debian@votre-ip-ovh:/home/debian/rsig/

# 2. Reconstruire et relancer (sur le serveur)
cd /home/debian/rsig
docker compose up -d --build
```

---

### Dépannage rapide

| Symptôme | Commande de diagnostic |
|---|---|
| Site inaccessible | `docker compose ps` puis `docker compose logs web` |
| Erreur 502 | `systemctl status nginx` |
| BDD vide | `docker exec -it rsig-db-1 psql -U rtaxes -d Rbdd -c "SELECT COUNT(*) FROM taux_clean;"` |
| Erreur PHP | `docker compose logs web \| tail -50` |
| Recharger après modif PHP | `docker compose restart web` |
| Tout redémarrer | `docker compose down && docker compose up -d` |

---

*Document généré depuis le code source du projet RSig — C:/02_WEBcarto/rsig*
*Mis à jour : juin 2026*
