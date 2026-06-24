# RSig — SIG Fiscal Web RTaxes

Système d'Information Géographique (SIG) web dédié à l'analyse de la fiscalité immobilière française. Permet de visualiser interactivement les taux et indicateurs fiscaux à l'échelle de la parcelle, de la section, de la commune et du département.

Développé par **Jules Faguet** pour **RTaxes** — mars à juin 2026.

> **Production** : [rcarto.rtaxes-geometre-expert.fr](https://rcarto.rtaxes-geometre-expert.fr) — accès restreint comptes @rtaxes.fr

---

## Fonctionnalités

### Couches cartographiques (14)
| Couche | Description |
|---|---|
| **Taux fonciers** | Taux TFPB, TFPNB, TSE, TAFNB, TEOM, GEMAPI par commune — millésimes 2017–2025 |
| **Coefficients de localisation** | Coefficients AB15 par commune, millésimes 2017–2026, évolution relative |
| **CFE estimée €/m²** | Cotisation Foncière des Entreprises estimée par section cadastrale, par catégorie et année |
| **TF estimée €/m²** | Taxe Foncière estimée par section cadastrale (coefficients av./ap. 2021) |
| **Taxe d'Aménagement** | Taux communaux + zones de majoration, TA estimée €/m² logement et autres constructions |
| **TA majorée >5%** | 1 019 sections cadastrales à taux majoré avec clusters et hachures, millésimée |
| **TSB IDF** | Taxe Spéciale sur les Bureaux — 4 circonscriptions IDF + DCSUCS, millésimée |
| **TSB PACA** | Taxe Spéciale sur les Bureaux — PACA, millésimée |
| **TASS** | Taxe Annuelle sur les Surfaces de Stationnement — 3 circonscriptions IDF |
| **Tarifs locatifs** | Tarifs CFE/TF par secteur, catégorie de local et année |
| **Sections cadastrales** | Découpage cadastral national avec secteurs et données fiscales |
| **Dossiers CRM** | Dossiers RTaxes géolocalisés depuis Dynamics 365, filtrables par auditeur / phase / produit / état |
| **ZFU** | Zones Franches Urbaines — exonération TSB |
| **Ortho IGN** | Photographies aériennes historiques IGN (6 campagnes 2000–2025) |

### Outils
- **Recherche d'adresse** — géocodage API Adresse (BAN), historique 8 recherches, navigation clavier
- **WFS cadastral IGN** — clic sur la carte : données département / commune / section / parcelle + millésime ortho
- **Panneau couches** — catalogue deux onglets (Répertoire / Couches actives), œil masquer/afficher, options repliables
- **Légende dynamique** — breaks adaptatifs calculés sur la bbox courante
- **Permalien** — URL restituant exactement la vue courante (couches, position, options)
- **BOFiP intégré** — import des tarifs TSB/TASS directement depuis les pages de publication
- **Sync CRM** — synchronisation incrémentale Dynamics 365 → PostgreSQL
- **MAJ TA** — lancement asynchrone du script DGFiP depuis l'interface admin
- **Stats d'usage** — tableau de bord admin : visites, couches activées, géocodages

---

## Stack technique

| Composant | Technologie |
|---|---|
| Cartographie | MapLibre GL JS (ES6 modules, WebGL) |
| Fonds de carte | IGN Géoplateforme WMTS / WFS |
| Backend | PHP 8.3 + Flight micro-framework |
| Base de données | PostgreSQL 17 + PostGIS 3.5 (154 Go, 212 M lignes) |
| Authentification | OAuth2 Microsoft Entra ID |
| CRM | Microsoft Dynamics 365 / Dataverse API |
| Déploiement | Docker Compose + Nginx, VPS OVH Debian, SSL Let's Encrypt |

---

## Structure du projet

```
rsig/
├── index.php / config.php     # Entrée + configuration (config.php non versionné)
├── app/
│   ├── routes/api.php         # 80+ routes API REST
│   ├── routes/pages.php       # Routes pages HTML
│   ├── helpers.php            # getDb(), cache, parseBbox(), rowsToGeoJson()
│   ├── auth.php               # requireAuth(), requireAdmin()
│   ├── dynamics.php           # Client Dataverse
│   └── crm_sync.php           # UPSERT incrémental CRM → PostgreSQL
├── assets/
│   ├── map.js / catalogue.js / legend.js / state.js / wfs.js
│   ├── style.css
│   └── layers/                # 14 modules couches JS
├── views/                     # accueil, bofip, maj, donnees, requetes, crm, admin_stats
└── cache/                     # Cache JSON fichier (TTL 5 min)
```

---

## Base de données

**154 Go · 59 tables · 212 millions de lignes**

Tables clés : `parcelles` (83 M), `taux_clean` (175 K communes), `ta_taux` (29 720 communes), `ta_majore_sections` (1 019 sections), `tsb_circonscriptions`, `tass_circonscriptions`, `coeff_neut_tf_av21/ap21`, `tarifs_pivot`, `crm_dossiers`.

Vues PostGIS calculées : `cfe_calcul`, `tf_calcul`, `sections_tarifs`, `ta_majore_sections_latest`, `departements_geom_4326`.

---

## Exploitation métier

RSig est conçu pour être un outil de travail quotidien au service des missions d'audit. Voici les cas d'usage concrets et les leviers d'exploitation prioritaires.

### 1. Prospection et ciblage commercial

Le croisement des couches taux + coeff + dossiers CRM permet d'identifier en un coup d'œil :

- Les **zones à fort potentiel d'économie** : communes avec taux TFPB ou CFE dans le quintile supérieur national
- Les **blancs géographiques** : secteurs sans dossier RTaxes mais avec une pression fiscale élevée
- Les **secteurs déjà travaillés** vs secteurs vierges, par auditeur

**Utilisation recommandée** : activer simultanément les couches *Taux fiscaux* (TFPB commune) + *Dossiers CRM* + *CFE estimée* pour une revue commerciale mensuelle par zone.

### 2. Préparation des missions d'audit

Avant une mission, RSig permet de contextualiser le bien à auditer :

- Identifier son **secteur cadastral** et le tarif de référence applicable
- Comparer les **coefficients de localisation** avec les communes voisines (évolution 2017–2026)
- Vérifier l'**applicabilité TSB/TASS** selon la localisation exacte (circonscription, ZFU)
- Contrôler le taux TA applicable en cas de projet de construction ou d'extension

**Utilisation recommandée** : géocoder l'adresse du client → activer sections + taux + TSB/TASS → extraire les valeurs avec le clic WFS.

### 3. Argumentation client et pitch

La carte est un outil de conviction visuelle. Montrer à un prospect :

- Sa commune en **rouge foncé** sur la carte taux = preuve immédiate d'une pression fiscale supérieure à la moyenne
- La **comparaison avec le bassin économique** immédiat (communes voisines, même zone d'activité)
- L'**impact estimé** de la valorisation incorrecte (CFE/TF €/m² × superficie)

Ce type de visualisation, présentée en réunion, est nettement plus percutant qu'un tableau Excel.

### 4. Suivi de portefeuille

La couche *Dossiers CRM* filtrée par **phase** et **état** offre une vision géographique du portefeuille :

- Identifier les dossiers en phase *Pré-étude* sur des zones à fort taux → prioriser la relance
- Visualiser la **charge géographique par auditeur** (filtre auditeur)
- Repérer les dossiers *Clôturés* dans des zones où une nouvelle mission serait pertinente

### 5. Veille réglementaire et mise à jour des données

L'interface intégrée BOFiP + MAJ TA permet :

- **Import des nouveaux tarifs TSB/TASS** dès leur publication annuelle sur BOFiP (sans manipulation de fichiers)
- **Mise à jour automatique des taux TA** depuis l'API DGFiP
- Accès direct aux pages BOFiP de référence pour chaque taxe depuis l'onglet dédié

---

## Développements futurs recommandés

Les fonctionnalités suivantes représentent les évolutions à plus forte valeur ajoutée pour le cabinet.

### Court terme (2–5 jours)

| Développement | Valeur métier | Complexité |
|---|---|---|
| **Export carte PDF** (vue + légende + titre personnalisé) | Permet d'intégrer la carte dans les rapports d'audit et les pitch clients | Moyenne |
| **Fiche synthèse par commune** (PDF généré) | Résumé cliquable : taux TFPB, CFE estimée, TSB, TA — en un seul document imprimable | Faible |
| **Couche comparaison communes** (sélection multiple + tableau) | Comparatif fiscal entre 2 à 5 communes côte à côte | Moyenne |

### Moyen terme (1–3 semaines)

| Développement | Valeur métier | Complexité |
|---|---|---|
| **Accès client sécurisé** (partage de vue en lecture seule) | Permettre à un prospect de consulter sa carte fiscale sans compte @rtaxes.fr | Élevée |
| **Intégration DVF** (Demandes de Valeurs Foncières) | Croiser valeur vénale des transactions avec les indicateurs CFE/TF → argument chiffré pour l'audit | Élevée |
| **Couche PLU / zonage urbanisme** (via Géoportail de l'Urbanisme) | Qualification précise de l'applicabilité de la TA et des zonages de majoration | Moyenne |
| **Alertes automatiques** (taux en hausse > seuil sur communes du portefeuille) | Notification email ou Dynamics quand un taux communal dépasse un seuil sur les zones suivies | Élevée |

### Long terme

| Développement | Valeur métier |
|---|---|
| **Module de calcul d'économie** (simulateur gain audit) | Estimation du gain potentiel en € pour un bien donné, directement depuis la carte |
| **Rapport d'audit automatisé** | Génération PDF structuré depuis les données de la carte + CRM |
| **API partenaires** | Exposition des indicateurs fiscaux à des outils tiers (Excel, Power BI, outils internes) |

---

## Installation

### Local (MAMP Windows)
```bash
cp config.example.php config.php
# Renseigner DB_DSN, DB_USER, DB_PASS, AUTH_ENABLED=false, ADMIN_EMAIL
mkdir cache
```

### Production (VPS OVH)
```bash
# Depuis la machine locale
git add <fichiers> && git commit -m "..." && git push origin main
ssh -i ~/.ssh/id_ed25519 debian@162.19.229.153

# Sur le VPS
cd ~/rsig && git pull origin main && cd deploy
docker compose build app && docker compose up -d app
docker logs -f rsig-app
```

---

## Chiffres clés

| Métrique | Valeur |
|---|---|
| Durée de développement | ~4 mois (mars–juin 2026) |
| Couches cartographiques | 14 |
| Routes API | 80+ |
| Volume base de données | 154 Go · 212 M lignes |
| Parcelles couvertes | 83 millions (France entière) |
| Communes couvertes (TA) | 29 720 |
| Valeur développement estimée | 35 000 – 45 000 € |

---

## Auteur

**Jules Faguet** — Développeur & géomaticien  
RTaxes — Géomètre-Expert & Audit Fiscal Immobilier · 2026
