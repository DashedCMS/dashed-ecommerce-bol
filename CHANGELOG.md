# Changelog

All notable changes to `dashed-ecommerce-bol` will be documented in this file.

## Unreleased

### Added
- **Bol-retouren in het systeem.** `bol:sync-returns` (elk kwartier) haalt open FBR-retouren binnen als goedgekeurde `OrderReturn` via `ReturnRegistrar` (zonder klantmail of label), met `bol_return_id` en per regel `bol_rma_id`. Verwerken, sluiten en afkeuren melden de uitkomst per regel terug aan Bol (`HandleBolReturnJob`, `BolReturnHandler`); bij verwerken komt een betaling "Via Bol" op de creditorder. Bij sluiten en afkeuren kiest de beheerder "Resultaat voor Bol". Niet plaatsbare retouren geven één beheerdersmelding per Bol-retour (`AdminBolReturnUnmatchedMail`). Vereist dashed-ecommerce-core met `ReturnActionExtensions` en de retour-events; zonder die werkt het binnenhalen wel maar niet het terugmelden en de select.

## v4.4.1 - 2026-09-15

### Fixed
- **Nieuwe Bol-klanten werden nooit geblokkeerd voor de nieuwsbrief.** `ExcludeBolCustomers` hing aan `OrderCreatedEvent`, maar dat event vuurt alleen vanuit de checkout; de Bol-sync slaat een bestelling op met een kale `save()`. Sinds v4.4.0 zijn dus alleen de bestaande klanten (via de migratie) uitgesloten en geen enkele nieuwe. De luisteraar hangt nu aan de Eloquent-gebeurtenis `created` van `Order`, die elke aanmaakroute raakt, binnen `rescue()` zodat de nieuwsbrief de sync nooit stopt. Hoort bij dashed-newsletter v4.18.0, dat een geblokkeerd adres ook bij het aanmelden weigert.
- Migratie `exclude_bol_customers_missed_by_event_listener` haalt bij de uitrol de sinds v4.4.0 gemiste Bol-klanten alsnog in: blokkeren en van de lijsten af, zoals de migratie van 26 augustus dat voor de bestaande klanten deed.
- `dashed:exclude-bol-newsletter-contacts --dry-run` toont per actief contact de lijst, de bron en de aanmelddatum, zodat te zien is langs welke weg een Bol-adres binnenkwam.

## 1.0.0 - 202X-XX-XX

- initial release
