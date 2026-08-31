# Nearby - Venue Discovery PWA

> Location-based venue discovery: find places around you on a map, check in, review them, and climb a leaderboard. Installable as a mobile app, with a self-service panel for business owners.
>
> *Konum tabanli mekan kesif platformu - harita, check-in, yorum, isletme paneli ve kurulabilir mobil uygulama.*

![PHP](https://img.shields.io/badge/PHP-8-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8-4479A1?style=flat-square&logo=mysql&logoColor=white)
![Leaflet](https://img.shields.io/badge/Leaflet-Maps-199900?style=flat-square&logo=leaflet&logoColor=white)
![PWA](https://img.shields.io/badge/PWA-installable-5A0FC8?style=flat-square&logo=pwa)
![Capacitor](https://img.shields.io/badge/Capacitor-Android%2FiOS-119EFF?style=flat-square&logo=capacitor&logoColor=white)

## What it does

| Feature | Detail |
|---|---|
| **Map discovery** | Leaflet map centred on the user, venues loaded by viewport |
| **OpenStreetMap ingest** | `import_osm.php` pulls venues from the Overpass API and normalises them into the local schema |
| **Check-in & leaderboard** | Users check in at venues and earn a place on the leaderboard |
| **Reviews & favourites** | Per-venue ratings, comments and a personal favourites list |
| **Business panel** | Owners claim a venue (`isletme.php`) and manage details and photo gallery |
| **Admin panel** | Moderation of venues, reviews and ownership claims |
| **Installable PWA** | Web app manifest + service worker; installs to the home screen |
| **Native shell** | Capacitor wrapper under `mobile/` for Android/iOS packaging |
| **Legal pages** | KVKK, privacy, cookie and terms pages wired into a consent flow |

## Architecture

```
index.html + script.js       Map UI, viewport-driven venue loading
get_venues.php               Bounding-box venue query (JSON)
db.php                       PDO connection, tries 127.0.0.1 then localhost
auth.php                     Registration / login, password hashing
checkin.php                  Check-in + points
reviews.php  favorites.php   Per-user venue interactions
isletme*.php                 Business-owner claim and management
import_osm.php               Overpass API -> local schema
sql/                         Schema migrations (no data)
mobile/                      Capacitor native wrapper
sw.js  manifest.json         PWA offline shell
```

### Design decisions worth noting

- **Venues are fetched by map viewport, not all at once.** The client sends its bounding box and `get_venues.php` returns only what fits, so the payload stays flat as the dataset grows.
- **OpenStreetMap as the seed source.** Rather than hand-entering thousands of venues, `import_osm.php` queries Overpass and maps OSM tags onto the local schema - the database bootstraps itself for any city.
- **The connection layer tries two hosts.** Shared hosting is inconsistent about whether `localhost` or `127.0.0.1` resolves to the MySQL socket, so `db.php` attempts both before failing.

## Running it locally

**Requirements:** PHP 8+, MySQL 8+

```bash
git clone https://github.com/<user>/php-venue-discovery-pwa.git
cd php-venue-discovery-pwa
mysql -u root -p < sql/01_schema_osm.sql
```

Apply the remaining migrations in `sql/` in numeric order, set your credentials in `db.php`, then:

```bash
php -S localhost:8000
```

Populate venues from OpenStreetMap:

```bash
php import_osm.php
```

## A note on this repository

Portfolio version of a live product. Database credentials, seed data containing real business records, uploaded media and server addresses have been removed; only the schema and application code remain. Configuration values are `CHANGE_ME` placeholders - set your own before running.
