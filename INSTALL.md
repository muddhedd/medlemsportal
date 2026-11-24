# Installation

Krav: PHP 8.1 eller senare (med `pdo_mysql`, `mbstring` och `sodium`),
MariaDB/MySQL och Apache med `mod_rewrite` och `mod_headers` (vanligt på webbhotell).

## 1. Databaser

Skapa två databaser – eller en, om webbhotellet bara ger en (tabellerna heter
`adm_*` respektive `grp_*` och krockar inte).

- Kör `_core/sql/install-groups.sql` i databasen för föreningarna.
- Kör `_core/sql/install-admin.sql` i admindatabasen.

## 2. Inställningar

Kopiera de två exempelfilerna i `_core/config/` och ta bort `.example`:

- `groupportal-admin-config.php` – bara för adminportalen (båda databaserna,
  `ADMIN_SECRET`, `SETUP_CODE`).
- `groupportal-group-config.php` – för medlemssidorna (bara föreningsdatabasen).

Bäst är att lägga dem **utanför webbroten**: koden letar först i mappen
`httpd.private` bredvid webbroten (`../httpd.private/`), sedan i `_core/config/`
(som är spärrad med `.htaccess`).

`ADMIN_SECRET` tar du fram med `php -r "echo bin2hex(random_bytes(32));"`.

## 3. Ladda upp

Ladda upp allt till webbroten. Ska portalen ligga i en undermapp, t.ex.
`/portal/`:

- sätt `BASE_PATH` till `'/portal'` i båda config-filerna, och
- ändra `#RewriteBase /` till `RewriteBase /portal/` i `.htaccess`.

## 4. Första administratören

Öppna `https://din-domän/group-admin/setup.php`, ange `SETUP_CODE` och skapa
kontot. Sidan raderar sig själv. Logga in på `/group-admin/` och ställ in
tvåstegsinloggning i en autentiseringsapp.

Ta gärna bort `|group-admin/setup\.php` ur `.htaccess` efteråt.

## 5. Skapa en förening

I adminportalen: **Skapa förening** → namn och trebokstavskod. Du får en adress,
ett användarnamn och ett engångslösenord att skicka till ordföranden.

## Demoläge

`define('DEMO_MODE', true);` i båda config-filerna ger en demo med påhittade
uppgifter som återställs varje natt kl. 03 (första besöket efter 03:00), färdiga
exempelkonton och skyddade lösenord. Använd aldrig demoläget skarpt.
