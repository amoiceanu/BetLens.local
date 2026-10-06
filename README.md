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
```

GitHub Actions rulează întreaga suită pe fiecare pull request către `main` și la fiecare push pe `main`. Pipeline-ul include testele de securitate, build-ul frontend, `composer audit`, `npm audit` și impune minimum 80% coverage pe linii.

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
