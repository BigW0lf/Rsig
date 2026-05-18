# Mini SIG

Application cartographique légère connectée au CRM Dynamics 365 de RTaxes.

## Fonctionnalités

- Fond de carte IGN orthophoto (Géoplateforme WMTS)
- Couches cadastrales dynamiques selon zoom (départements → communes → sections → parcelles)
- Barre de recherche géographique avec autocomplétion (Géoplateforme)
- Lien avec les dossiers Dynamics 365 par code commune

## Prérequis

- PHP 8.x avec cURL activé
- Serveur web (Apache/Nginx) ou `php -S localhost:8080`

## Lancement rapide

```bash
php -S localhost:8080
```

Ouvrir `http://localhost:8080`.

## Technologies

| Composant | Outil |
|-----------|-------|
| Routeur PHP | [Flight](https://flightphp.com/) |
| Carte | [Leaflet.js 1.9.4](https://leafletjs.com/) |
| Fonds / données | [IGN Géoplateforme](https://geoservices.ign.fr/) |
| Géocodage | API Géoplateforme autocomplétion |
| CRM | Microsoft Dynamics 365 |

## Structure

```
index.php       — routes et appels Dynamics
views/          — templates HTML/PHP
assets/         — JS et CSS
flight/         — micro-framework
```
