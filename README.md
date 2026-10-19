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

Fiecare instalare își citește conexiunea din propriul `.env`. În instalarea curentă, `.env` local poate indica și baza managed `BetLens-Prod`; verifică ținta înainte de migrare sau import. Pe droplet, configurează propriul `.env` pentru baza managed:

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
- Administratorii folosesc același formular `/cont/login` și sunt direcționați către `/admin`. Adresa veche `/admin/login` redirecționează la formularul comun; accesul la funcțiile Admin rămâne protejat. Conturile admin nu văd „Biletele mele” și nu pot accesa sau genera bilete, inclusiv prin URL direct.

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

## Auditul importurilor

Migrarea `import_audits` și extensia ei se aplică pe conexiunea `DB_*` configurată: `php artisan migrate --force`. Pagina de admin `/admin/audit-importuri` (disponibilă din meniul Admin) arată ora rulării, jobul, furnizorul, instanța de execuție, înregistrările noi/actualizate/șterse și statusul general. Același tabel rămâne și în „Sursa datelor”. `Importate` reprezintă noi + actualizate, nu toate rândurile detectate la furnizor. Istoricul anterior extensiei rămâne în tabel, cu instanța marcată „Necunoscut”.

Setează `BETLENS_IMPORT_EXECUTION_SOURCE=Laravel-local` pe PC, `Laravel-DO` pe droplet sau `Function-DO` în mediul unei DigitalOcean Function care folosește serviciul `ImportAuditService`. Dacă variabila lipsește, aplicația folosește `Laravel-DO` când `APP_ENV=production`, altfel `Laravel-local`. O Function separată care nu folosește acest cod trebuie să trimită ea însăși evenimentul de audit; simpla schimbare a bazei de date nu poate identifica execuția unei funcții externe.

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

Pagina `/admin/mindmap` este centrul de import pentru administrator. Are șase niveluri L1–L6, filtre pentru ligă/sezon/sursă/status, configurare per sursă, import individual/de nivel/global, previzualizare a înregistrărilor stocate, stare prin polling și istoric. Sursele sunt citite din `data_sources` + `data_source_levels`; candidatele fără adaptor sunt marcate **Nesuportat**, fără date sau sincronizări fictive. Selectorul de ligă afișează acoperirea declarată în `data_source_competitions`. Adaptoarele externe existente nu sunt încă capabile de import limitat la liga selectată, așadar butoanele lor sunt dezactivate în acel context. L6 este un procesor intern de recalculare, nu un API extern.

Migrarea `2026_10_18_000002_create_mindmap_import_management_tables.php` adaugă `data_source_levels`, `data_source_competitions`, `import_runs`, `import_documents`, `source_field_mappings` și setări de import în `data_sources`. `import_runs` păstrează fiecare execuție nouă pornită din Mindmap; `import_audits` rămâne jurnalul general al tuturor adaptoarelor existente. Răspunsurile brute disponibile și fișierele încărcate sunt stocate privat prin Laravel Storage, cu checksum și cale în `import_documents`. Pentru feedurile speciale care nu expun răspunsul brut către orchestrator, documentul nu este inventat.

Adaptoarele aprobate existente sunt folosite numai la endpointurile deja cunoscute de cod. Pentru surse noi, administratorul poate configura import manual JSON/CSV, fără schimbare de cod; fișierul este salvat privat și procesat asincron în `source_records`, cu deduplicare după identificator și checksum. Mapările de câmp sunt aplicate în payloadul standardizat `_mapped`, dar nu suprascriu identitatea, ora sau rezultatul meciurilor din tabelele canonice. RSS, scraping HTML și import generic din URL arbitrar nu sunt activate: ar necesita validarea permisiunii, a acoperirii și protecții SSRF suplimentare. **Testează conexiunea** face doar un apel la endpointul aprobat; nu salvează date sportive.

Pornire locală (în terminalul integrat; fără ferestre separate):

```bash
php artisan migrate --force
php artisan queue:work database --tries=3
php artisan schedule:work
```

Pe droplet, workerul și schedulerul trebuie administrate ca servicii persistente. Dacă sunt lansate manual în background, redirecționează stdout/stderr în loguri și păstrează PID-ul pentru oprire. Nu rula mai multe instanțe de scheduler. `ImportScheduler` verifică în fiecare minut frecvența setată per sursă și nu reintroduce un job deja în coadă sau în curs. Configurația locală folosește `QUEUE_CONNECTION=database` și `BETLENS_RAW_IMPORT_DISK=local`. Pentru Spaces se poate seta un disk S3 compatibil (`BETLENS_RAW_IMPORT_DISK=s3` și variabilele `AWS_*`), după instalarea adaptorului Flysystem S3; Redis/Valkey este opțional și nu păstrează singur datele persistente.

Limitări: acoperirea pe competiție nu este dedusă din simpla existență a unei surse; importul specific ligii necesită adaptoare și ID-uri externe verificate. Datele meteo se corelează prin integrarea Open-Meteo existentă, nu prin țara ligii. Indicatorul de calitate verifică structura și checksumul payloadurilor, nu adevărul sportiv. Istoricul `import_runs` începe la migrarea acestei funcționalități; importurile mai vechi rămân în `import_audits` / `data_sync_logs`.

### API-Football L1 prin DigitalOcean Function

Setează `BETLENS_API_FOOTBALL_FUNCTION_URL` și `BETLENS_API_FOOTBALL_FUNCTION_TOKEN` doar în mediul Laravel. Headerul `X-Require-Whisk-Auth` este trimis exclusiv din backend. După `php artisan migrate --force`, Mindmap oferă separat **Verifică acum** (apel fără scrieri sportive) și **Importă acum** (job asincron). Funcția furnizează lista `records` cu competiții și sezoane; importul păstrează numai România în `source_records`, legând fiecare sezon de competiția sa. Identificatorii externi rămân separați de ID-urile interne, iar unicitatea existentă `(data_source_id, external_key)` previne duplicatele. Sunt excluse fixtures, jucătorii, clasamentele și cotele.

```bash
php artisan betlens:import-api-football-l1 --probe   # verifică răspunsul, fără scriere sportivă
php artisan betlens:import-api-football-l1           # pune un import în coadă
php artisan queue:work database --queue=betlens-api-football-l1 --tries=1 --timeout=180
php artisan betlens:import-api-football-l1 --latest  # status și număr de înregistrări
```

Workerul acestei cozi trebuie să ruleze codul Laravel actualizat. O instanță veche de pe droplet poate prelua și eșua joburi necunoscute dacă ascultă aceeași coadă; coada dedicată evită acest lucru. Pentru funcționare continuă după repornire, configurează workerul ca serviciu persistent pe mediul actualizat. Nu porni două importuri simultane pentru aceeași sursă; `ImportManager` respinge a doua rulare activă. Erorile rămân în `import_runs` și `import_audits` fără URL sau token.

# Simulator admin

Pagina `/admin/simulator` compară probabilitățile 1/X/2 pentru un meci cu nivelurile L1–L6 activate/dezactivate. Este disponibilă numai contului de admin și numai când conexiunea activă este DigitalOcean `BetLens-Prod` (testele folosesc SQLite izolat). L1 calculează frecvențele din rezultatele anterioare ale ligii, L2 normalizează cotele 1/X/2 complete capturate înaintea meciului, iar L6 aplică modelul Poisson existent pe istoricul anterior meciului. Combinația model/cote este o medie 50/50 pentru explorare, nu o recomandare oficială. L3–L5 arată datele disponibile, dar nu schimbă șansele 1/X/2 fără o regulă calibrată. Simularea este doar de citire și nu creează recomandări.
