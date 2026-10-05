# Changelog

All notable changes to `dashed-ecommerce-bol` will be documented in this file.

## v4.8.0 - 2026-10-05

### Changed
- Bol-orders naar een ander EU-land volgen de OSS-instelling van dashed-ecommerce-core (>= v4.149.0): de regels krijgen het btw-tarief van het afleverland en `btw`/`vat_percentages` van de order worden daarna uit de regels opgebouwd (`OssVat::recalculateOrderVat()`). Zonder de instelling, of met een oudere dashed-ecommerce-core, verandert er niets.

## v4.7.0 - 2026-09-30

### Changed
- **Bol-titels volgen de richtlijnen van bol.** De AI krijgt het format `[Merk] [Serie] [Productnaam] - [Producttype] - [Kenmerk]` met een voorbeeld, mikt op 70 tekens en zet merk en producttype in de eerste 35 (wat op mobiel zichtbaar is). De controle eist nu dat de titel met het merk begint (instelling of kenmerk/filter Merk), weigert symbolen (`? & * # @ € $ < > = ~ ^` naast `|` en `!`), emoji, woorden van vier letters of meer in hoofdletters (behalve het merk), uitgeschreven getallen (twee t/m tien) en meer actietaal (korting, sale, morgen in huis, kerstcadeau, ...). De harde lengtegrens gaat van 150 naar 100 tekens.

## v4.6.1 - 2026-09-30

### Fixed
- **Bol-commissie op het dashboard telde elke retour dubbel.** Een creditorder draagt de commissie van de oorspronkelijke order als positief bedrag, terwijl bol die commissie bij een verwerkte retour volledig terugstort. De widget "Statistieken vanuit Bol" telt de commissie van creditorders nu negatief ("Na retouren, incl. btw") en telt creditorders niet meer mee als bestelling. Lovora, september 2026: € 905,97 werd € 428,15, 126 bestellingen werden er 102.

## v4.6.0 - 2026-09-30

### Added
- **Bol-titels laten genereren door AI.** Op een productgroep staat de knop "Bol-titel genereren" en op de productgroepenlijst dezelfde actie als bulkactie (via de wachtrij). De AI schrijft per taal een sjabloon met plaatshouders (`:kleur:`) op basis van de variatiefilters, en schrijft dat weg in het bestaande blok `bol-product-title`; de feed vult het sjabloon daarna per variant in, dus één aanroep per groep volstaat en een nieuwe variant krijgt vanzelf een titel. Een voorstel wordt eerst getoetst door `BolTitleRules` (alleen bekende plaatshouders, elke variatiefilter erin, maximaal 150 tekens ingevuld over de eerste 200 producten, geen reclamewoorden, `|` of `!`); bij een afkeuring krijgt de AI één herkansing met de meldingen, en de bulkactie slaat een voorstel met meldingen nooit op. Merknaam en extra aanwijzingen voor de AI zijn per site instelbaar op de Bol-instellingenpagina.
- **Knop "Ontbrekende Bol-titels genereren"** bij Instellingen, Bol: zet de AI aan het werk voor elke productgroep met naam en producten die nog geen sjabloon heeft, in jobs van vijftig groepen met een melding per portie. Groepen met een sjabloon blijven ongemoeid. Vereist dashed-ecommerce-core v4.144.0 of hoger.

## v4.5.0 - 2026-09-17

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
