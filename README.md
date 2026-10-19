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
