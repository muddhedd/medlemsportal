# Medlemsportal

En liten, säker och mobilvänlig portal för föreningar: slutna medlemssidor med
kalender, formella möten med protokoll, anonym förslagslåda, kassabok och
nyckelutlåning. Skriven i ren PHP 8.1+ och MariaDB/MySQL – inga ramverk,
inga beroenden, inga externa tjänster. Fungerar på vanliga webbhotell.

*English below.*

## Funktioner

- **Adminportal** (`/group-admin/`) med tvåstegsinloggning (TOTP). Skapar,
  pausar och raderar föreningar – men kan aldrig se vad som finns inuti dem.
- **En adress per förening**, t.ex. `/batklubben-on/`. Inget syns utan inloggning,
  inte ens om adressen finns.
- **Anonyma användarnamn** (t.ex. `BKO-K7Q2M`) och engångslösenord som måste
  bytas vid första inloggningen. Ingen e-post krävs. Frivilligt visningsnamn,
  och – om föreningen slår på det – frivilligt namn, telefon och e-post.
- **Roller som behörighetspaket**: Ordförande, Kassör, Nyckelansvarig,
  Administratör och egna roller. Alla medlemmar kan läsa allt.
- **Verksamhet/kalender**: återkommande aktiviteter (varje vecka, varje månad,
  "sista torsdagen" …), ändringar per tillfälle, ansvarig per tillfälle,
  "Jag tar den" och "Jag har förhinder".
- **Formella möten**: mötestyper med mallar (Arbetsmöte, Styrelsemöte, Årsmöte),
  automatiskt schema, dagordning, anonym förslagslåda som hamnar under Övriga
  frågor, protokoll med beslut och bordläggning som följer med till nästa möte,
  automatisk sparning, låsning, tillägg och utskrift/PDF.
- **Kassabok**: konton, insättningar/utbetalningar med verifikationsnummer,
  överföringar, rättelseposter (inget kan ändras eller raderas), bokslut och
  kassarapport som PDF.
- **Nycklar och taggar**: utlåning, återlämning, förlorad/hittad och full historik.
- **QR-kod** till föreningens sida, skapad lokalt på servern.
- **Export** av all data (JSON) och kassaboken (CSV för Excel).
- **Svenska och engelska**, valbart per förening och per användare.
- **Säkerhet**: hashade lösenord (Argon2id/bcrypt), sessioner i databasen,
  CSRF-skydd, strikt CSP, låsning vid upprepade felförsök, noindex och spärr
  mot AI-robotar. Se [SECURITY.md](SECURITY.md).

## Kom igång

Se [INSTALL.md](INSTALL.md). I korthet: skapa en eller två databaser, kör
`_core/sql/install-groups.sql` och `_core/sql/install-admin.sql`, fyll i
config-filerna, ladda upp och öppna `/group-admin/setup.php`.

## Anpassa orden

All text finns i `_core/lang/sv.php` och `_core/lang/en.php`. Ord som skiljer
sig mellan olika sorters organisationer (t.ex. "förening", "aktivitet",
"Ordförande") ligger i `_core/lang/terms/<profil>.<språk>.php`. Kopiera
`assoc.*.php` till en ny profil för att skapa en egen variant – med egna
standardmallar för möten och egna kontonamn.

## Licens

Public domain ([The Unlicense](LICENSE)) – använd, ändra och dela helt fritt.

---

## English

A small, secure, mobile-first portal for associations and clubs: closed member
pages with a calendar, formal meetings with minutes, an anonymous suggestion
box, a cash book and key lending. Plain PHP 8.1+ and MariaDB/MySQL – no
framework, no dependencies, no external services. Runs on ordinary shared hosting.

Features: admin portal with TOTP (cannot see inside associations), one address
per association, anonymous usernames with one-time passwords, roles as
permission sets, recurring activities with responsible persons, meeting
templates and schedules, agenda → minutes with decisions and tabled items,
cash book with voucher numbers, corrections and closing of the books, keys,
locally generated QR codes, JSON/CSV export, Swedish and English.

Installation: see [INSTALL.md](INSTALL.md). Licence: public domain ([Unlicense](LICENSE)).
