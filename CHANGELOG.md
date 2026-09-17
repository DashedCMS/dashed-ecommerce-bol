# Changelog

All notable changes to `dashed-ecommerce-bol` will be documented in this file.

## Unreleased

### Added
- **Bol-retouren in het systeem.** `bol:sync-returns` (elk kwartier) haalt open FBR-retouren binnen als goedgekeurde `OrderReturn` via `ReturnRegistrar` (zonder klantmail of label), met `bol_return_id` en per regel `bol_rma_id`. Verwerken, sluiten en afkeuren melden de uitkomst per regel terug aan Bol (`HandleBolReturnJob`, `BolReturnHandler`); bij verwerken komt een betaling "Via Bol" op de creditorder. Bij sluiten en afkeuren kiest de beheerder "Resultaat voor Bol". Niet plaatsbare retouren geven één beheerdersmelding per Bol-retour (`AdminBolReturnUnmatchedMail`). Vereist dashed-ecommerce-core met `ReturnActionExtensions` en de retour-events; zonder die werkt het binnenhalen wel maar niet het terugmelden en de select.

### Fixed
- **De herkansing van een terugmelding houdt nu op.** Het venster van dertig dagen loopt op het moment van de eindstand (`COALESCE(processed_at, handled_at, closed_at, rejected_at)`) en niet op `updated_at`: elke mislukte poging schrijft `bol_handle_error` en verzette daarmee `updated_at`, dus een retour die Bol permanent weigert zette zichzelf elk kwartier eeuwig opnieuw klaar. Daarnaast stopt `bol:sync-returns` na veertig pogingen (`bol_handle_attempts`, nieuwe kolom), met een orderlog `order.bol-return-handle-abandoned` en de prefix "Opgegeven na :n pogingen" op `bol_handle_error`.
- **Een niet plaatsbare Bol-retour schreef elke sync een orderlog.** De orderlog viel buiten de guard die de beheerdersmail op één per Bol-retour houdt, dus het orderlogboek groeide elk kwartier met dezelfde regel zolang niemand de retour oploste. De log valt nu onder dezelfde guard.
- **Een orderregel die al helemaal terug is wordt overgeslagen.** Het koppelen matchte op EAN en keek daarna alleen bij de eerste treffer naar het restant, dus een bestelling met twee regels van dezelfde EAN waarvan de eerste al terug was werd gemeld in plaats van op de tweede regel gezet. Het restant zit nu in het filter; is er nergens genoeg restant, dan noemt de melding het grootste restant.
- **Een mislukte betaling "Via Bol" laat een spoor achter.** `RefundRegistrar::register()` stond buiten de try, dus een registrar die gooit (bedrag klopt niet, al terugbetaald) liet niets op de retour of in het orderlogboek achter en de job verdween in de mislukte-jobs-tabel.
- Kleiner: de process-status-poll stopt na vijftien rondes en de paginatie na 200 pagina's, de beheerdersmelding leest de ontvangers en de afzender van de site waarvoor gesynct wordt in plaats van van de actieve site, en de luisteraar dispatcht `HandleBolReturnJob` binnen `rescue()` zodat een wachtrij die de job niet aanneemt het verwerken van de retour niet omgooit.

## v4.4.1 - 2026-09-15

### Fixed
- **Nieuwe Bol-klanten werden nooit geblokkeerd voor de nieuwsbrief.** `ExcludeBolCustomers` hing aan `OrderCreatedEvent`, maar dat event vuurt alleen vanuit de checkout; de Bol-sync slaat een bestelling op met een kale `save()`. Sinds v4.4.0 zijn dus alleen de bestaande klanten (via de migratie) uitgesloten en geen enkele nieuwe. De luisteraar hangt nu aan de Eloquent-gebeurtenis `created` van `Order`, die elke aanmaakroute raakt, binnen `rescue()` zodat de nieuwsbrief de sync nooit stopt. Hoort bij dashed-newsletter v4.18.0, dat een geblokkeerd adres ook bij het aanmelden weigert.
- Migratie `exclude_bol_customers_missed_by_event_listener` haalt bij de uitrol de sinds v4.4.0 gemiste Bol-klanten alsnog in: blokkeren en van de lijsten af, zoals de migratie van 26 augustus dat voor de bestaande klanten deed.
- `dashed:exclude-bol-newsletter-contacts --dry-run` toont per actief contact de lijst, de bron en de aanmelddatum, zodat te zien is langs welke weg een Bol-adres binnenkwam.

## 1.0.0 - 202X-XX-XX

- initial release
