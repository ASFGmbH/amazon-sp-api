# Amazon Ring Manager

Technische Projektbeschreibung des bereitgestellten PHP-Projekts für die Verwaltung und Veröffentlichung von Partnerringen auf Amazon über die Selling Partner API.

## Zweck

Das Projekt bildet eine schlanke Webanwendung mit drei Hauptaufgaben:

1. Modellbasierte Amazon-Listings aus PIMCORE-Produktdaten, Lagerdaten und Medien erzeugen.
2. Preisaufschläge je `priceGroup` verwalten.
3. Bereits veröffentlichte Child-SKUs über einen Feed-basierten Bestandsabgleich aktuell halten.

## Technologie-Stack

- PHP `^8.2`
- Composer
- `vlucas/phpdotenv` `^5.6`
- `jlevers/selling-partner-api` `5.0.0`

Relevante transitive Composer-Pakete laut `composer.lock`:

- `aws/aws-sdk-php` `3.371.2`
- `guzzlehttp/guzzle` `7.10.0`
- `phpoffice/phpspreadsheet` `1.30.2`

## Composer

Die Abhängigkeiten werden vollständig über Composer geladen:

```bash
composer install
```

Die PSR-4-Autoload-Konfiguration ist:

```json
{
  "autoload": {
    "psr-4": {
      "App\": "src/"
    }
  }
}
```

## Projektstruktur

```text
public/
  index.php                    Modellübersicht
  prices.php                   Preis-Aufschläge
  settings.php                 zentrale Einstellungen
  push.php                     AJAX-Endpunkt für Erstupload
  cron_inventory_sync.php      Feed-Einreichung für Bestandsänderungen
  cron_inventory_finalize.php  Feed-Finalisierung + optionaler E-Mail-Report
  test_*.php                   Debug- und Schema-Tests

src/
  Database/                    PDO-Factories für lokale und externe DBs
  Helpers/                     Variant- und Steinhilfsfunktionen
  Services/                    Fachlogik für Push, Feed, Settings, Preise, Bilder
  Amazon/Builders/             Builder für Stones-Attribute

storage/
  schema-cache/                Amazon-Schema-Caches
  html/                        HTML-Templates / generierte HTML-Dateien
  pdf/                         generierte PDFs

asfmtm_db0.sql                 Export der lokalen App-Datenbank
composer.json
composer.lock
```

## Datenquellen

Das Projekt verwendet vier logische Datenquellen.

### 1. Lokale App-Datenbank

Verbindungsaufbau über `App\Database\Database`.

Lokale Tabellen laut `asfmtm_db0.sql`:

- `settings`
- `price_markup`
- `ring_color`
- `amazon_listing_inventory`

### 2. ProductPDO

Verweist auf die **PIMCORE Partnerringe Tabelle** `object_query_1`.

Verwendet für:

- Stammdaten des Modells
- Material, Typ, Maße, Steine
- `priceGroup`
- optionale Identifikatoren wie EAN/GTIN/UPC
- optionale `suggested_asin`

### 3. StockPDO

Verweist auf das **Interne Lager**, Tabelle `products`.

Verwendet für:

- `product_code`
- `quantity`

`VariantParser` extrahiert aus dem `product_code` die Damen-/Herrenkennung (`D` oder `H`) und die Ringgröße.

### 4. ZweipunktPDO

Verweist auf die **PIMCORE Settings Tabelle** `zweipunkt_setting`.

Verwendet für:

- `priceGroup_<X>`
- `priceGroup_<X>_pseudo`

Daraus werden Basispreis und Pseudopreis je Preisgruppe gelesen.

## Öffentliche Routen

| Route | Funktion |
|---|---|
| `public/index.php` | Modellübersicht, Hauptfarbe speichern, Upload auslösen |
| `public/prices.php` | Preisaufschläge verwalten |
| `public/settings.php` | Vorlagen, Standardwerte, Compliance, Minstock, E-Mail-Report |
| `public/push.php` | Nimmt `model` und `dry_run` entgegen und startet `AmazonPushService` |
| `public/cron_inventory_sync.php` | Erstellt Bestands-Feed nur für geänderte Child-SKUs |
| `public/cron_inventory_finalize.php` | Finalisiert offene Feed-Läufe und optional E-Mail-Report |

## Kernservices

### `SettingsService`

Verantwortlich für:

- Lesen einzelner Settings
- Batch-Lesen und Batch-Schreiben
- Integer-/Boolean-Helfer
- Zugriff auf `amazon_inventory.minstock`
- Zugriff auf `amazon_inventory.finalize_report_enabled`
- Zugriff auf `amazon_inventory.finalize_report_email`

### `PriceMarkupService`

Verantwortlich für:

- Ermittlung aller verwendeten `priceGroup`-Werte aus `object_query_1`
- Lesen und Schreiben der Tabelle `price_markup`
- Laden von Basispreis und Streichpreis aus `zweipunkt_setting`
- Reine Zuschlagslogik (absolut / prozentual)

### `StockService`

Verantwortlich für:

- Laden der Lagerdaten pro Modell aus dem internen Lager
- Trennung in Damen- und Herrengrößen
- Bildung der paarweisen Child-Menge via `min(damen, herren) - minstock`
- Bereitstellung von Badges für die Modellübersicht

### `MediaScanner`

Verantwortlich für:

- Scannen aller Modellordner im Media-Verzeichnis
- Ignorieren des Unterordners `basic`
- Ermittlung von Hauptbild und Galerie

### `AmazonImageHelper`

Verantwortlich für:

- Umwandlung lokaler Medienpfade in öffentliche URLs
- Aufbau der Amazon-Bildattribute
- Ergänzung um feste Standardbilder aus `media/basic`

### `TemplateService`

Verantwortlich für:

- Ersetzen von `{{platzhaltern}}`
- Rendering von Bulletpoints

Nicht ersetzte Platzhalter bleiben absichtlich sichtbar.

### `ProductPlaceholderService`

Verantwortlich für:

- Lesen der Spaltenstruktur von `object_query_1`
- Generierung der klickbaren Platzhalterliste in `settings.php`
- Laden einer Beispielzeile für die Live-Vorschau

### `AmazonListingPayloadBuilder`

Ein dedizierter Builder für Amazon-Attribute. Er kapselt typische Setter wie:

- `setItemName()`
- `setProductDescription()`
- `setBulletPoints()`
- `setPrice()`
- `setListPrice()`
- `setQuantity()`
- `setRecommendedBrowseNode()`
- `setRingSize()`
- `setRawAttribute()`

### `AmazonPushService`

Orchestriert den Erstupload eines Modells.

Ablauf:

1. Produktdatensatz aus `object_query_1` laden.
2. Hauptfarbe aus `ring_color` laden.
3. Lagerdaten lesen und Child-Paare bilden.
4. Parent-Payload und Child-Payloads erzeugen.
5. Titel, Beschreibung, Bulletpoints rendern.
6. Preis aus `price_markup` + `zweipunkt_setting` berechnen.
7. Bilder, Material, Gem Type, GPSR, Compliance und Mengen setzen.
8. Parent und Children per Listings Items API senden.
9. Erfolgreiche Child-SKUs in `amazon_listing_inventory` seeden.

Child-SKU-Schema:

```text
<MODEL>-D<damen_size>-H<herren_size>
```

Parent-SKU-Schema:

```text
<MODEL>-PARENT
```

### `AmazonInventorySyncService`

Verantwortlich für Bestandsabgleich nach dem Erstupload.

#### `syncTrackedInventory()`

- lädt aktive Trackingzeilen aus `amazon_listing_inventory`
- berechnet je Zeile die Sollmenge neu aus dem Lager
- vergleicht gegen `last_pushed_quantity`
- baut nur für Änderungen Feed-Messages
- reicht diese als `JSON_LISTINGS_FEED` ein
- markiert die betroffenen Zeilen als `submitted`

Feed-Messages werden als `PATCH` mit Austausch von `fulfillment_availability` gebaut.

#### `finalizeSubmittedFeeds()`

- lädt offene `submitted`-Zeilen
- gruppiert nach `last_feed_id`
- fragt Amazon über `getFeed()` und `getFeedDocument()` ab
- wertet Issues pro SKU aus
- bestätigt erfolgreiche Mengen erst bei verarbeiteter Feed-Antwort
- schreibt Fehlerstatus und Meldungen zurück

## Amazon-Integration

Die Amazon-Anbindung erfolgt über `jlevers/selling-partner-api` 5.0.0.

Eingesetzte API-Klassen:

- `ListingsV20210801Api`
- `FeedsV20210630Api`
- `ProductTypeDefinitionsV20200901Api`

Eingesetzte Flows:

### Listings Items API

Verwendet für den initialen Parent-/Child-Upload über `putListingsItem()`.

### Feeds API

Verwendet für spätere Bestandsänderungen mit `JSON_LISTINGS_FEED`.

Ablauf in `submitJsonListingsFeed()`:

1. Feed-JSON serialisieren
2. Feed-Dokument mit `application/json` anlegen
3. Dokument hochladen
4. Feed mit `JSON_LISTINGS_FEED` erstellen
5. Feed-ID zurückgeben

## Bildlogik

Modellbilder werden aus dem Media-Ordner geladen. Zusätzlich werden feste Standardbilder angehängt:

- Gravur
- Ringgröße
- Etui / Versand
- Vorteile

`AmazonImageHelper` ordnet diese Bilder den Amazon-Attributen

- `main_product_image_locator`
- `other_product_image_locator_1..8`

zu.

## Template- und Textlogik

Titel, Beschreibung und Bulletpoints stammen aus `settings`.

Variablenbasis:

- alle skalaren Felder aus `object_query_1`
- zusätzliche Hilfsvariablen wie
  - `model`
  - `material`
  - `oo_id`
  - `damen_size`
  - `herren_size`
  - `pair_size`

Fallbacks:

- fehlt die Beschreibungsvorlage, baut `AmazonPushService` eine generische Produktbeschreibung
- fehlen Bulletpoints, werden nur vorhandene Bulletpoint-Felder übertragen

## Preislogik

### Aktiver Live-Pfad

Der aktuelle Live-Push nutzt in `AmazonPushService::buildPricing()`:

- `object_query_1.priceGroup`
- `zweipunkt_setting.priceGroup_X`
- `zweipunkt_setting.priceGroup_X_pseudo`
- `price_markup`

### Vorbereitete, aber aktuell nicht verdrahtete Logik

Die Felder

- `amazon_price.tax_rate`
- `amazon_price.round_to`
- `amazon_price.min_price`
- `amazon_price.default_price_group`

werden zwar in `settings.php` gespeichert und von `AmazonPriceService` unterstützt, sind im bereitgestellten aktiven Uploadpfad derzeit aber nicht eingebunden.

## SQL-Struktur der lokalen App-Datenbank

### `settings`

Speichert zentrale Konfigurationswerte als Schlüssel/Wert-Paare.

Wichtige Keys aus dem Projekt:

- `amazon_title_template`
- `amazon_description_template`
- `amazon_bullet_1` bis `amazon_bullet_6`
- `amazon_country_of_origin`
- `amazon_recommended_browse_node`
- `amazon_supplier_declared_dg_hz_regulation`
- `amazon_jewelry_material_categorization`
- `amazon_supplier_declared_has_product_identifier_exemption`
- `amazon_gpsr_manufacturer_reference`
- `amazon_gpsr_safety_attestation`
- `amazon_compliance_media_type`
- `amazon_compliance_media_url`
- `amazon_inventory.minstock`
- `amazon_inventory.finalize_report_enabled`
- `amazon_inventory.finalize_report_email`

Zusätzlich werden aktuell auch gespeichert, im aktiven Push aber nicht verdrahtet:

- `amazon_price.tax_rate`
- `amazon_price.round_to`
- `amazon_price.min_price`
- `amazon_price.default_price_group`
- `amazon_gem_type_default`
- `amazon_color`

### `price_markup`

Speichert pro `price_group`:

- `markup_type` (`absolute` oder `percent`)
- `markup_value`

### `ring_color`

Speichert pro Modell die Amazon-Farbe. Diese Tabelle ist im Live-Push maßgeblich für die Farbe des Listings.

### `amazon_listing_inventory`

Trackingtabelle für Child-SKUs. Kerngedanke:

- Erstupload seedet alle veröffentlichten Child-SKUs
- spätere Crons synchronisieren nur diese bekannten SKUs

Wichtige Felder:

- `model`
- `parent_sku`
- `sku`
- `damen_size`
- `herren_size`
- `current_stock_quantity`
- `last_pushed_quantity`
- `last_feed_id`
- `last_sync_status`
- `last_sync_message`
- `is_active`

## Umgebungsvariablen

Der Code referenziert folgende `.env`-Schlüssel:

### Lokale App-DB

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

### ProductPDO

- `PRODUCT_DB_HOST`
- `PRODUCT_DB_NAME`
- `PRODUCT_DB_USER`
- `PRODUCT_DB_PASS`

### StockPDO

- `STOCK_DB_HOST`
- `STOCK_DB_NAME`
- `STOCK_DB_USER`
- `STOCK_DB_PASS`

### ZweipunktPDO

- `ZWEIPUNKT_DB_HOST`
- `ZWEIPUNKT_DB_NAME`
- `ZWEIPUNKT_DB_USER`
- `ZWEIPUNKT_DB_PASS`

### Amazon SP-API

- `LWA_CLIENT_ID`
- `LWA_CLIENT_SECRET`
- `LWA_REFRESH_TOKEN`
- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `AWS_ROLE_ARN`
- `AMAZON_SELLER_ID`
- `AMAZON_MARKETPLACE_ID`
- `AMAZON_LANGUAGE_TAG`

### Cron / Mail / Fallbacks

- `CRON_TOKEN`
- `MAIL_FROM`
- `AMAZON_GPSR_MANUFACTURER_REFERENCE`
- `AMAZON_GPSR_SAFETY_ATTESTATION`

## Debug- und Testskripte

Das Projekt enthält mehrere Hilfsskripte unter `public/`:

- `test_product_type.php`
- `test_product_types.php`
- `test_sku.php`
- `test_skus.php`
- `_test_sku.php`
- `test_group_materials.php`

Diese Skripte dienen vor allem der Schemaanalyse, Produkttyp-Suche und Payload-Prüfung.

## Bekannte Besonderheiten / technische Hinweise

1. **Buttontext vs. Verhalten**
   - In `index.php` heißt der Button „In Amazon prüfen“.
   - In `public/assets/js/app.js` wird aber `dryRun = 0` gesetzt.
   - Ein Klick löst daher im aktuellen Stand einen echten Upload aus.

2. **Nicht vollständig verdrahtete Settings-Felder**
   - Mehrere Felder in `settings.php` werden gespeichert, aber im aktiven Live-Push noch nicht verwendet.
   - Dazu zählen insbesondere die Preislogik-Felder, `amazon_gem_type_default` und `amazon_color`.

3. **Compliance-URL**
   - Der UI-Hinweis erwähnt eine mögliche automatische pro-Modell-URL durch einen Dokumentenservice.
   - Der aktive Push-Code nutzt derzeit nur die feste URL aus `settings`.

4. **Legacy-Datei mit Tippfehler**
   - Es existiert zusätzlich `src/Services/SettingsServive.php`.
   - Die produktiv genutzte Klasse ist `src/Services/SettingsService.php`.

5. **Schema-/SQL-Abgleich prüfen**
   - Der bereitgestellte SQL-Export definiert `amazon_listing_inventory.last_sync_status` als Enum mit `seeded`, `submitted`, `error`.
   - Der aktuelle PHP-Code schreibt jedoch zusätzlich Statuswerte wie `success`, `in_queue`, `in_progress`, `cancelled` und `fatal`.
   - Dafür ist eine konsistente Datenbank-Migration erforderlich, sonst passen Code und Schema nicht vollständig zusammen.

## GPSR-Dokumentservice

`GpsrDocumentService` kann HTML- und PDF-Dokumente erzeugen und legt Dateien unter `storage/html/generated` bzw. `storage/pdf` ab.

Im bereitgestellten Projektstand ist dieser Service nicht als öffentliche Standardroute verdrahtet, aber als technischer Baustein vorhanden.

## Empfehlung für den Betrieb

Empfohlener fachlicher Ablauf:

1. Stammdaten und Medien pflegen
2. Hauptfarbe je Modell setzen
3. Preisaufschläge pflegen
4. Einstellungen prüfen
5. Erstupload des Modells
6. Danach nur noch `cron_inventory_sync.php`
7. Anschließend `cron_inventory_finalize.php`

## Lizenz- / Betriebsnotiz

Dieses README beschreibt ausschließlich den gelieferten Projektstand. Zugangsdaten, `.env`, `vendor` und `media` gehören nicht zum bereitgestellten Archiv und müssen in der Zielumgebung separat vorhanden sein.
