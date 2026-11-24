# Säkerhet

- Lösenord hashas med Argon2id (eller bcrypt) och uppgraderas automatiskt.
- Administratörer har obligatorisk tvåstegsinloggning (TOTP); hemligheten lagras krypterad.
- Medlemmar loggar in med anonyma användarnamn; inga e-postadresser krävs och
  ingen återställning sker via e-post. Nytt engångslösenord ges av en administratör
  i användarens hemförening.
- Låsning med ökande väntetid vid upprepade felaktiga inloggningar.
- Sessioner lagras hashade i databasen; nytt sessions-id vid inloggning;
  cookies är `Secure`, `HttpOnly` och `SameSite=Lax`.
- CSRF-skydd och kontroll av `Sec-Fetch-Site` på alla formulär.
- Strikt Content-Security-Policy (inga inline-skript), `X-Frame-Options`,
  `Referrer-Policy: no-referrer`, `noindex` och spärr mot kända AI-robotar.
- Medlemssidornas konfiguration saknar uppgifter till admindatabasen.
- Adminportalen kan inte visa innehållet i en förening.
- Export innehåller aldrig lösenord eller sessioner; CSV skyddas mot formler.
- Kassabokens poster kan aldrig ändras eller raderas – fel rättas med rättelseposter.

Hittar du en sårbarhet: rapportera den privat till förvaltaren av repot i stället
för att öppna ett publikt ärende.
