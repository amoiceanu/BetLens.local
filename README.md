# BetLens

Aplicație Laravel 12 pentru analiză statistică a meciurilor de fotbal. BetLens folosește exclusiv surse externe reale, nu plasează pariuri și nu procesează plăți.

## Instalare locală

Aplicația folosește MySQL. Creează baza `betlens`, apoi rulează:

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
```

În instalarea XAMPP curentă aplicația este disponibilă la `http://127.0.0.1/php-sites/BetLens.local/public/`.

## Baza de date DigitalOcean

Configurația locală din `.env` rămâne pe `127.0.0.1` / `betlens`. Pe droplet, modifică **doar** fișierul `.env` de acolo pentru baza managed `BetLens-Prod`:

```env
DB_CONNECTION=mysql
DB_HOST=expona-do-user-6467325-0.i.db.ondigitalocean.com
DB_PORT=<portul din DigitalOcean>
DB_DATABASE=BetLens-Prod
DB_USERNAME=<utilizatorul bazei>
DB_PASSWORD=<parola bazei>
MYSQL_ATTR_SSL_CA=<calea absolută către certificatul CA>
MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=true
```

Nu salva parola sau certificatul în repository. După configurare, rulează `php artisan config:clear`, verifică numele bazei conectate, apoi aplică migrațiile cu `php artisan migrate --force`. Nu rula `migrate:fresh` decât dacă ai decis explicit să ștergi toate datele din baza vizată. Când un administrator este autentificat, footerul arată `BD: DigitalOcean · BetLens-Prod` numai dacă conexiunea activă folosește atât hostul, cât și baza indicate; în rest arată baza alternativă sau eroarea conexiunii. Indicatorul nu este afișat utilizatorilor obișnuiți ori vizitatorilor.

Autentificarea admin folosește conturi din tabela `users`, cu parole hash-uite. Creează sau actualizează un administrator cu:

```bash
php artisan betlens:admin-user
```

Comanda solicită utilizatorul, emailul și parola în mod interactiv; parola nu este salvată în configurație sau în repository.

## Niveluri de acces

- Dashboard și Meciuri sunt publice, fără cont. Generarea și salvarea unui bilet cer autentificare.
- Utilizatorii își creează cont la `/cont/inregistrare`, intră la `/cont/login` și văd doar propriile bilete la `/bilete`.
- Performanță, Operatori, Surse de date și Admin necesită cont de administrator. Formularul `/admin/login` rămâne public pentru autentificare; accesul la funcțiile Admin este protejat.

După actualizare, rulează `php artisan migrate --force`. Biletele existente fără `user_id` rămân vizibile doar administratorului, până când sunt atribuite explicit unui utilizator; nu sunt expuse automat tuturor conturilor noi.

## Surse reale

Registrul include football-data.org, API-Football, Football-Data.co.uk, The Odds API, Odds-API.io (Superbet), OddsPapi (Superbet RO), OpenLigaDB, StatsBomb Open Data, Understat, Sportmonks Football API și Open-Meteo. Sursele protejate necesită în `.env`:

```env
FOOTBALL_DATA_API_KEY=
API_FOOTBALL_KEY=
ODDS_API_KEY=
ODDS_API_IO_KEY=
ODDSPAPI_API_KEY=
SPORTMONKS_API_TOKEN=
```

Sportmonks este o sursă secundară pentru statistici avansate, line-up-uri, accidentări, suspendări și xG/xGA. Disponibilitatea fiecărui tip de date depinde de ligă și de plan; lipsa datelor este raportată ca „Indisponibil”, niciodată ca zero. Open-Meteo nu necesită cheie și folosește coordonatele stadionului sau ale orașului echipei gazdă.

Prioritatea surselor este:

1. API-Football pentru identitatea meciului, echipe, competiție, start și rezultat.
2. The Odds API pentru cote.
3. Sportmonks pentru date avansate, fără suprascrierea sursei principale.
4. Understat pentru xG/xGA în ligile unde există acoperire.
5. Open-Meteo pentru context meteo cu pondere mică în analiză.

Odds-API.io și OddsPapi sunt opționale, fiecare cu cheia sa. Ambele pot furniza instantanee de cote Superbet, care se păstrează separat în `source_records` și apar în „Sursa datelor” după configurarea cheii în `.env`. Nu suprascriu cotele sau identitatea meciurilor din sursele principale. Aceste API-uri nu oferă acces la istoricul biletelor personale Superbet; un URL de meci sau un instantaneu de cote nu dovedește ce selecție a fost plasată ori dacă biletul a câștigat.

Cheile pentru sursele care necesită autentificare pot fi introduse și din **Admin → Chei pentru sursele de date**. Sunt criptate în coloana `data_sources.credential_secret` și au prioritate față de `.env`; valoarea nu este afișată niciodată în formular. Un câmp gol păstrează cheia existentă, iar „Elimină cheia” șterge doar valoarea din Admin și revine la `.env` dacă există. După deploy, rulează `php artisan migrate --force` și păstrează `APP_KEY` stabilă: schimbarea ei face cheile salvate imposibil de decriptat.

După adăugarea `ODDS_API_IO_KEY` și/sau `ODDSPAPI_API_KEY` în `.env`, rulează `php artisan config:clear`, apoi folosește „Verifică acum” pentru sursa respectivă. În producție aplică mai întâi `php artisan migrate --force`. Importul asociază evenimentele numai când numele echipelor, competiția și ora de start (±15 minute) indică un singur meci BetLens; cele neasociate nu sunt atribuite automat unui meci greșit.

Asocierea între furnizori folosește echipele, competiția și ora de start cu toleranță de maximum 15 minute. Conflictele sunt înregistrate în `data_sync_logs`, iar meciurile afectate sunt excluse din recomandări.

## Aliasuri pentru căutarea meciurilor

Pagina **Meciuri** acceptă URL-uri de eveniment Superbet și Winbet și caută meciurile deja importate în BetLens. Nu interoghează operatorii și nu folosește ID-ul evenimentului pentru identificare; pentru URL-uri respectă ordinea gazdă–oaspeți din link. Variantele obișnuite precum `Utd`/`United` și diacriticele sunt normalizate automat.

Administratorul poate adăuga, edita și șterge aliasuri din **Admin → Aliasuri echipe** (`/admin/aliasuri`). Fiecare alias aparține unei echipe și poate fi valabil pentru toți operatorii sau doar pentru Superbet ori Winbet. Aliasurile sunt stocate în `team_aliases`, sunt unice în cadrul ligii și operatorului și au prioritate față de potrivirea aproximativă a numelor. Un alias identic cu numele altei echipe din aceeași ligă este refuzat. După deploy, rulează `php artisan migrate --force` înainte de a folosi această pagină.

Pentru a încărca o singură dată aliasurile cunoscute ale echipelor deja importate, rulează `php artisan db:seed --class=KnownTeamAliasesSeeder --force`. Seederul poate fi reluat după importuri noi: nu dublează aliasurile și nu suprascrie intrările editate manual. Aliasurile sunt adăugate numai pentru echipe existente; dacă o echipă nu este încă în baza de date, importă mai întâi meciurile/echipele și reexecută seederul.

Butonul **Verifică acum** testează sursa și importă automat răspunsul în `source_records`. Fiecare înregistrare are identificator extern și checksum: la următoarele sincronizări este clasificată drept nouă, actualizată sau neschimbată, fără duplicate. Rezultatul fiecărei rulări este salvat și în `data_sync_logs`. Nicio selecție nu este inventată când sursele nu furnizează suficiente date.

## Procese de fundal

```bash
php artisan queue:work
php artisan schedule:work
```

Butonul „Verifică acum” pornește un job în coadă și afișează progresul live în modal. Pentru asta trebuie să ruleze permanent un worker (de exemplu prin Supervisor/systemd) cu `php artisan queue:work --timeout=540`; `DB_QUEUE_RETRY_AFTER` trebuie să fie mai mare decât timeout-ul workerului (implicit 600 secunde). Fără worker, modalul arată că verificarea așteaptă în coadă.

Schedulerul sincronizează fixtures Sportmonks orar, statisticile la șase ore, absențele zilnic și validează conflictele orar. Open-Meteo este actualizat zilnic pentru meciurile viitoare și la fiecare trei ore în ultimele 24 de ore înainte de start.

## Teste

```bash
php artisan test
composer run test:acceptance
```

Scenariile Gherkin din `features/` rulează prin Behat, cu SQLite în memorie și date recreate pentru fiecare scenariu. Acoperă istoricul biletelor, salvarea simultană a detaliilor, respingerea unui interval invalid și afișarea surselor de date. Nu folosesc baza MySQL locală.

GitHub Actions rulează testele PHPUnit și scenariile Gherkin pe fiecare pull request către `main` și la fiecare push pe `main`. Pipeline-ul include testele de securitate, build-ul frontend, `composer audit`, `npm audit` și impune minimum 80% coverage pe linii.

## Expunere publică

În producție folosește HTTPS și configurează cel puțin:

```env
APP_ENV=production
APP_DEBUG=false
SESSION_ENCRYPT=true
SESSION_EXPIRE_ON_CLOSE=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict
```

Loginul admin este limitat la 5 încercări pe minut și regenerează sesiunea după autentificare. Aplicația adaugă antete de securitate, iar configurația Apache elimină antetul `X-Powered-By` și dezactivează expunerea versiunii PHP.

Rezultatele istorice nu garantează performanța viitoare, iar BetLens nu promite profit.

## Mindmap date BetLens (admin)

Pagina `/admin/mindmap` este accesibilă prin **Admin → Mindmap date**, cu aceeași sesiune de administrator. Nu necesită migrații noi, servicii externe sau o bibliotecă de grafuri. Vizualizarea folosește SVG, CSS și JavaScript simplu în buildul Vite existent.

Catalogul de integrare este separat în `App\Services\Mindmap\SourceCatalog`; `MindmapData` construiește un snapshot exclusiv din modelele și jurnalele existente. În absența meciurilor și înregistrărilor importate, nodurile demonstrative și fiecare indicator din panou sunt etichetate **Demo**. Vizualizarea Demo nu inserează meciuri, sincronizări sau statistici fictive în bază. Sursele adăugate prin modal sunt salvate în `data_sources`, inactive, cu metadate în `application_settings` (grupul `mindmap`). Nu se solicită și nu se afișează chei API.

Indicatorii au proveniență și timestamp de observare:

- Acoperire: câmpuri completate / câmpuri așteptate pentru echipa gazdă, echipa oaspete, ora și stadionul meciurilor din competiția/sezonul selectate. Nu reprezintă acoperirea calendarului oficial, care nu este disponibil ca numitor.
- Calitate: înregistrări cu identificator extern, payload nenul și checksum SHA-256 valid / înregistrările surselor afișate. Acesta este un control de integritate, nu o certificare a rezultatelor sportive.
- Contradicțiile checksum/payload sunt marcate `conflict`. Reconcilierea semantică între furnizori nu există încă și este `unavailable`, la fel ca progresul procentual fără un total de lucru cunoscut.
- Importurile de astăzi sunt rulările `source_sync` cu status `healthy`, conform fusului orar al aplicației. O verificare API reușită nu implică automat import de meciuri.
- Costul API rămâne **De configurat** până la introducerea planurilor tarifare. Următoarea fereastră este estimată din programarea orară existentă; nu dovedește că schedulerul rulează.

**Rulează import** este disponibil în drawer numai pentru adaptoarele existente cu endpoint exact aprobat: API-Football (starea API), football-data.org (competiții), OpenLigaDB și StatsBomb Open Data. Endpointurile adăugate arbitrar nu sunt executate. Lipsa acreditărilor blochează importul înainte de apelul extern. Importatoarele RSS/Atom, HTML cu permisiune explicită, CSV încărcat manual, corectare manuală și un API REST public necesită implementări separate; pagina nu simulează aceste operații. Sursele neconfigurate rămân disponibile în catalog pentru planificare.

Verificări: `php artisan test`, `npm run build`; testele specifice sunt în `tests/Feature/AdminMindmapTest.php` și nu apelează servicii externe.
