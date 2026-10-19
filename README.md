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

Autentificarea admin folosește conturi din tabela `users`, cu parole hash-uite. Creează sau actualizează un administrator cu:

```bash
php artisan betlens:admin-user
```

Comanda solicită utilizatorul, emailul și parola în mod interactiv; parola nu este salvată în configurație sau în repository.

## Surse reale

Registrul include football-data.org, API-Football, Football-Data.co.uk, The Odds API, OpenLigaDB, StatsBomb Open Data, Understat, Sportmonks Football API și Open-Meteo. Sursele protejate necesită în `.env`:

```env
FOOTBALL_DATA_API_KEY=
API_FOOTBALL_KEY=
ODDS_API_KEY=
SPORTMONKS_API_TOKEN=
```

Sportmonks este o sursă secundară pentru statistici avansate, line-up-uri, accidentări, suspendări și xG/xGA. Disponibilitatea fiecărui tip de date depinde de ligă și de plan; lipsa datelor este raportată ca „Indisponibil”, niciodată ca zero. Open-Meteo nu necesită cheie și folosește coordonatele stadionului sau ale orașului echipei gazdă.

Prioritatea surselor este:

1. API-Football pentru identitatea meciului, echipe, competiție, start și rezultat.
2. The Odds API pentru cote.
3. Sportmonks pentru date avansate, fără suprascrierea sursei principale.
4. Understat pentru xG/xGA în ligile unde există acoperire.
5. Open-Meteo pentru context meteo cu pondere mică în analiză.

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

## Compararea rezultatelor cu predicțiile

Migrația `2026_10_19_000000_create_game_comparison_tables.php` creează exclusiv trei tabele noi. În toate, `game_id` referă **`matches.id`**, modelul existent fiind `FootballMatch`; nu există o tabelă `games` suplimentară.

- `game_actual_results` / `GameActualResult`: un singur rezultat per meci, scoruri și cornere întregi nullable, `match_status`, `result_updated_at` și timestamp-uri Laravel.
- `betlens_predictions` / `BetlensPrediction`: snapshoturi multiple per meci, cu `predicted_at` obligatoriu și `model_version` nullable.
- `external_platform_predictions` / `ExternalPlatformPrediction`: snapshoturi multiple per meci/platformă, `platform_name` obligatoriu și `source_prediction_id` nullable. ID-ul extern nu este unic: aceeași predicție externă poate avea observații la momente diferite.

Cele 12 câmpuri comune au aceleași nume în toate tabelele: `home_score`, `away_score`, `home_score_ht`, `away_score_ht`, `home_score_2h`, `away_score_2h`, `home_corners`, `away_corners`, `home_corners_ht`, `away_corners_ht`, `home_corners_2h`, `away_corners_2h`. În predicții sunt `DECIMAL(7,2) NULL`; probabilitățile 1X2 sunt `DECIMAL(7,6) NULL`, iar cotele estimate sunt `DECIMAL(10,4) NULL`. Nu se transformă automat cotele în probabilități și nu se presupune că o valoare lipsă este zero. Salvările Eloquent blochează editarea predicțiilor deja existente dacă meciul este marcat `finished` în `matches` sau în rezultatul real; importul retrospectiv de snapshoturi distincte rămâne posibil.

`GamePrediction::rules()` este reutilizabil pentru validarea intrărilor, iar salvările Eloquent validează automat probabilitățile între 0 și 1, inclusiv capetele intervalului. Baza de date protejează și scrierile directe: constrângeri CHECK pentru MySQL/MariaDB, respectiv trigger-e echivalente pentru SQLite. Nu este impusă o sumă de 1 pentru probabilități parțial furnizate. Câmpurile zecimale sunt expuse de Eloquent ca string-uri, pentru păstrarea preciziei.

Relații:

```php
$game->load(['actualResult', 'betlensPredictions', 'externalPlatformPredictions']);
$game->betlensPredictions()->orderByDesc('predicted_at')->get();
$game->externalPlatformPredictions()->where('platform_name', 'Forebet')->orderByDesc('predicted_at')->get();
```

Fiecare copil are relația `game()`. Indexurile acoperă meciul, momentul predicției și platforma. Ștergerea meciurilor cu rezultate sau predicții este restricționată pentru a păstra istoricul.

Importatorul OpenLigaDB scrie de acum rezultatele numai în `game_actual_results`, fără să modifice snapshoturile de predicții. Datele nespecificate de furnizor rămân `NULL`; inclusiv statisticile reprizei a doua și cornerele nu sunt deduse. `match_results` rămâne intactă pentru istoricul existent. `MatchResultHistory` permite analizelor să citească acel istoric când nu există un rezultat nou pentru meci; dacă există, este preferat exclusiv rezultatul nou, finalizat și cu ambele scoruri disponibile. Nu se copiază sau rescriu rezultate istorice prin migrație. Generarea/importul noilor snapshoturi de predicții se poate conecta separat la aceste modele.

Verificări: `php artisan test --filter=GameComparisonTest` și `php artisan test`, în baza SQLite în memorie configurată de `phpunit.xml`. Pentru aplicarea exclusivă a acestei migrații, după verificarea conexiunii destinație: `php artisan migrate --path=database/migrations/2026_10_19_000000_create_game_comparison_tables.php`. Nu folosi `migrate:fresh` sau `migrate:refresh` pe o bază cu date existente.

## Acoperirea surselor de date (admin)

Pagina **Admin → Acoperirea surselor** este disponibilă la `/admin/acoperire`; comparațiile sunt la `/admin/acoperire/neconcordante`. Toate paginile, acțiunile de revizuire și exporturile CSV folosesc middleware-ul admin existent, cu verificarea utilizatorului din baza de date înainte de încărcarea resursei. Nu se apelează furnizori externi la afișare, filtrare, export sau reconstruire.

Migrația `2026_10_19_010000_create_source_coverage_observability.php` adaugă:

- `source_match_observations`: observații locale pe sursă și ID extern, GameID nullable, liga/sezonul cunoscute, asociere explicită sau ambiguă, valori dintr-o listă permisă și timestampuri pe câmp. Nu conține payloaduri brute, URL-uri sau acreditări.
- `source_coverage_cells`: agregări pe sursă–ligă–sezon pentru matrice; se actualizează în loturi după import, fără scanarea payloadurilor pe GET.
- `source_data_discrepancies`: câmpul, sursele și identificatorii, valorile comparate, severitate, prima/ultima detectare, rezolvare și revizuire admin. Cheia unică pe GameID/pereche surse/câmp previne duplicatele.
- Coloane nullable în `import_audits` pentru ligă/sezon, început/sfârșit, citite/ignorate/erori. Informațiile istorice necunoscute nu sunt inventate sau completate retrospectiv. `data_sync_logs` primește un index sursă/timp.

Nu se introduc importatori paraleli sau joburi programate noi. `SourcePayloadStoreService`, `VerifyDataSource` și importatorul OpenLigaDB alimentează proiecția locală după salvările existente; mapările `ExternalIdMapping` și snapshoturile meteo existente sunt reutilizate. Erorile acestei proiecții sunt înregistrate generic și nu opresc importul principal. Mesajele noi de audit sunt curățate de tokenuri configurate, URL-uri, query tokens și antete de autentificare; interfața și CSV-ul nu afișează mesaje brute din audit.

După aplicarea migrațiilor pe conexiunea verificată, reconstruirea din datele deja stocate se rulează explicit:

```bash
php artisan betlens:coverage-rebuild
php artisan betlens:coverage-rebuild --source=football-data-uk
```

Comanda este repetabilă și actualizează numai cele trei tabele de evidență. Nu schimbă meciurile, mapările, rezultatele, predicțiile, payloadurile sau jurnalele istorice. Pentru OpenLigaDB, istoricul cu `external_id=openligadb:…` poate reconstrui identitatea și ora meciului; scorurile din rezultate agregate fără proveniență pe sursă nu sunt atribuite retrospectiv. Pentru meteo se folosesc snapshoturile care păstrează furnizorul și `fetched_at`. După deploy, repornește lucrătorii de coadă conform procedurii existente, ca să încarce codul nou.

Regulile și pragurile sunt centralizate în `config/coverage.php`:

- Registrul de câmpuri este intersectat cu tabelele/coloanele reale. Sunt susținute identitatea/orarul, scorurile și cornerele existente, statisticile cu nume explicite din CSV-urile deja importate, numărul jucătorilor din formațiile Sportmonks și cele trei măsurători meteo existente. Nu se ghicesc coduri numerice de statistici, posesie, arbitri, evenimente sau informații despre jucători fără un adaptor stocat verificabil.
- Completitudine = valori disponibile / valori aplicabile pe meciurile asociate sigur. Identitatea și ora sunt relevante întotdeauna; statisticile sportive numai dacă sursa declară meciul finalizat. Profilul meteo urmărește numai vremea. Exemplul `72% (72 din 100)` reprezintă **valori aplicabile**, nu 100 de meciuri dintr-un calendar oficial necunoscut. Medie = media procentelor cunoscute ale rândurilor filtrate; rândurile fără numitor sunt excluse.
- Zero este disponibil; NULL este Nedisponibilă. Câmpurile suportate, dar fără valori într-o sursă, sunt explicit neacoperite. Un răspuns fără câmpuri parțiale nu șterge alte câmpuri observate anterior; fiecare păstrează timestampul lui. O valoare explicit NULL o face indisponibilă. Cornerele totale sunt o sumă în interiorul aceleiași observații, numai dacă ambele valori sunt prezente.
- Prospețime din ultima reușită: până la 24 h proaspătă, până la 72 h învechită, ulterior critică. Nu se presupune prospețime fără timestamp. Prospețimea importului nu garantează prospețimea fiecărui câmp; aceasta se vede în detaliile observației. Frecvența este media intervalelor dintre reușitele distincte ale aceluiași tip, cu minimum două timestampuri; pentru istorice mixte rămâne Necunoscut.
- Matricea arată numărul de meciuri programate/observate **în datele locale**, nu numărul total oferit de furnizor. O sursă care nu raportează statusul produce număr de programate Necunoscut. Auditul cu ligă/sezon are prioritate; în absența lui, auditul global este etichetat ca atare, fără atribuirea falsă a importului unei ligi. Eșecurile recente sunt încercările cu status nereușit în ultimele 7 zile. Nu există backfill de frecvențe sau eșecuri care nu au fost păstrate.
- Se preferă mapările `external_id_mappings` și `provider_match_mappings`. În lipsa lor, se cere ligă exactă și o singură pereche de echipe normalizate sau aliasuri explicite, într-o fereastră de ±120 min. Pentru CSV fără fus orar configurat, se poate asocia numai ziua, cu aceleași reguli de unicitate; ora exactă rămâne necunoscută. Nu se creează automat mapări pentru cazuri ambigue.
- Toleranța orei între surse este 15 min. Scoruri și echipe diferite sunt critice; alte valori diferite sunt avertizări; lipsa unilaterală este informativă. Se compară doar câmpuri aplicabile ambelor profiluri. Nu se suprascriu valorile altor surse. Neconcordanțele noi sunt numărate în matrice; cele ignorate rămân vizibile în listă. Dispariția unei diferențe o rezolvă; modificarea valorilor ori reconfirmarea după rezolvare o redeschide.

Filtrele sunt validate și folosite împreună; CSV-ul păstrează filtrele și protejează împotriva formulelor în celule. Listele sunt paginate, relațiile sunt preîncărcate, iar exportul este limitat la 10.000 de rânduri, cu eroare explicită dacă trebuie restrânse filtrele. Proiecția poate fi reconstruită din nou după schimbarea profilurilor sau aliasurilor. Nu se aplică automat migrații pe DigitalOcean.

Verificări: `php artisan test --filter=SourceCoverageTest`, suita PHPUnit cu coverage, build Vite și scenariile de acceptanță existente. Testele de acoperire includ accesul neautorizat, filtre/export/paginare, lipsa datelor și a schemei, timestampuri și frecvențe reale, conflicte/revizuiri, matching ambiguu, idempotență, protecția secretelor și număr de interogări independent de mărimea paginii.
