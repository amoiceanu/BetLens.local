<?php

namespace App\Services;

use App\Models\ImportedTicketSelection;

class ImportedTicketPresentationService
{
    public function selection(ImportedTicketSelection $selection): array
    {
        $hasScore=$selection->home_score!==null && $selection->away_score!==null;
        $total=$hasScore?$selection->home_score+$selection->away_score:null;
        $isGoalTotal=mb_strtolower(trim($selection->market_label))==='total goluri';
        $line=null;
        if($isGoalTotal && preg_match('/^(Peste|Sub)\s+(\d+(?:[.,]\d+)?)/ui',$selection->selection_label,$parts)){
            $line=(float)str_replace(',','.',$parts[2]);
        }

        $summary=[];
        if($hasScore){
            $summary=[
                ['value'=>$total,'label'=>'Goluri totale','icon'=>'circle-dot'],
                ['value'=>$selection->home_score,'label'=>'Goluri '.$selection->home_team,'icon'=>'house'],
                ['value'=>$selection->away_score,'label'=>'Goluri '.$selection->away_team,'icon'=>'plane'],
            ];
            if($line!==null)$summary[]=['value'=>number_format($line,1),'label'=>'Linie goluri','icon'=>'list-filter'];
        }

        $explanation=null;
        if($selection->result==='lost'){
            $explanation=$selection->failure_reason ?: 'Nu am nicio recomandare pentru acest bilet.';
        }elseif($selection->result==='won' && $hasScore && $line!==null){
            $over=mb_strtolower($parts[1])==='peste';
            if(($over && $total>$line)||(!$over && $total<$line)){
                $explanation="S-au marcat {$total} goluri; selecția „{$selection->selection_label}” a fost câștigătoare.";
            }
        }elseif($selection->result==='won' && $hasScore && mb_strtolower(trim($selection->market_label))==='final'){
            $result=$selection->home_score>$selection->away_score?'1':($selection->home_score<$selection->away_score?'2':'X');
            if($selection->selection_label===$result){
                $explanation="Rezultatul final a fost {$selection->home_score}–{$selection->away_score}; selecția „{$result}” a fost câștigătoare.";
            }
        }

        return ['selection'=>$selection,'has_score'=>$hasScore,'summary'=>$summary,'explanation'=>$explanation];
    }
}
