<?php

namespace Database\Seeders;

use App\Models\ImportedTicket;
use Illuminate\Database\Seeder;

class SuperbetScreenshotTicketsSeeder extends Seeder
{
    public function run(): void
    {
        // Transcribed from user-supplied SuperBet screenshots. Do not infer missing markets.
        $tickets = [
            ['891J-1BYB22','2026-10-17 20:15','won',1.14,25,28.50,'Belgia · Cupa','2026-10-17 18:30','Renaissance Mons','Sporting Charleroi',0,1,'Final','X','won',null,null],
            ['890R-Y1UVWO','2026-10-17 20:14','lost',3.20,5,null,'Turcia · Super Lig','2026-10-17 19:00','Fenerbahce','Alanyaspor',3,0,'Superpariuri','Peste 1.5 șuturi pe poartă Alanyaspor + Peste 0.5 goluri Alanyaspor','lost','Alanyaspor a avut 5 șuturi pe poartă, dar nu a înscris: condiția „Peste 0.5 goluri Alanyaspor” a pierdut.','Cealaltă condiție a fost câștigătoare.'],
            ['891T-YQ2IWF','2026-10-17 20:11','lost',5.00,5,null,'Anglia · Premier League','2026-10-17 19:30','Newcastle','Aston Villa',0,1,'Bet Builder','1 (Final) + Newcastle (Golul 2)','lost','Newcastle a pierdut cu 0–1, deci selecția „1” la rezultat final a eșuat. Captura marchează și selecția „Newcastle / Golul 2” drept pierdută.','Sensul exact al pieței „Golul 2” nu este detaliat în captură.'],
            ['891F-1SZJH2','2026-10-17 19:49','lost',5.50,5,null,'Spania · LaLiga','2026-10-17 19:30','Betis','Barcelona',2,5,'Total goluri','Sub 2.5','lost','S-au marcat 7 goluri; selecția „Sub 2.5” necesita cel mult 2.',''],
            ['8914-YQPYBM','2026-10-17 19:43','won',1.10,10,11,'Germania · Bundesliga','2026-10-17 19:30','Bayern Munchen','RB Leipzig',6,1,'Final','1','won',null,null],
            ['899E-EZEHDO','2026-10-17 09:09','lost',3.15,5,null,'Japonia · J1 League','2026-10-17 08:00','Sanfrecce Hiroshima','Kyoto Sanga',3,0,'Final','X','lost','Meciul s-a terminat 3–0, nu la egalitate; selecția „X” a pierdut.',null],
            ['898S-E5F6TA','2026-10-16 23:38','won',2.12,5,10.60,'Irlanda · Premier Division','2026-10-16 22:00',"St Patrick’s Athletic",'Shelbourne',1,2,'Total goluri','Peste 2.5','won',null,null],
            ['899K-2U9GF2','2026-10-16 23:37','won',1.83,5,9.15,'Islanda · Urvalsdeild - Gr. Campionat','2026-10-16 22:15','Breidablik','Vikingur Reykjavik',1,2,'Total goluri','Sub 3.5','won',null,null],
            ['899B-2U3TSI','2026-10-16 23:35','lost',4.50,5,null,'Italia · Serie A','2026-10-16 21:45','Frosinone','Sassuolo',4,2,'Total goluri','Peste 6.5','lost','S-au marcat 6 goluri; selecția „Peste 6.5” necesita cel puțin 7.',null],
            ['891N-1I7VNA','2026-10-17 23:32','won',1.16,25,29.00,'Franța · Ligue 1','2026-10-17 21:45','Lille','Brest',1,1,'Final','X','won',null,null],
            ['891C-1I8DS5','2026-10-17 23:39','won',1.45,5,7.25,'Spania · LaLiga','2026-10-17 22:00','Valencia','Athletic Bilbao',0,0,'Final','X','won',null,null],
        ];

        foreach ($tickets as [$reference,$placedAt,$status,$odds,$stake,$payout,$competition,$kickoff,$home,$away,$homeScore,$awayScore,$market,$selection,$result,$failure,$note]) {
            $ticket = ImportedTicket::firstOrCreate(
                ['source' => 'superbet', 'reference' => $reference],
                ['placed_at' => $placedAt, 'status' => $status, 'total_odds' => $odds, 'stake' => $stake, 'payout' => $payout, 'currency' => 'RON']
            );
            if (! $ticket->wasRecentlyCreated) {
                continue;
            }
            $ticket->selections()->firstOrCreate(['position' => 1], [
                'competition' => $competition, 'kickoff_at' => $kickoff, 'home_team' => $home,
                'away_team' => $away, 'home_score' => $homeScore, 'away_score' => $awayScore,
                'market_label' => $market, 'selection_label' => $selection, 'odds' => $odds,
                'result' => $result, 'failure_reason' => $failure, 'details' => $note ? ['note' => $note] : null,
            ]);
        }
    }
}
