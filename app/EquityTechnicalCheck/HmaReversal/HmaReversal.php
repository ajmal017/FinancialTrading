<?php namespace App\EquityTechnicalCheck\HmaReversal;

use \Log;
use App\EquityTechnicalCheck\EquityTechnicalCheckBase;
use \App\IndicatorEvents\HullMovingAverage;


class HmaReversal extends EquityTechnicalCheckBase {

    public $hmaLength;
    public $hmaChangeDirPeriods;

    public function check() {
        $hmaEvents = new \App\IndicatorEvents\HullMovingAverage;
        $this->decisionIndicators['hmaRevAfterXPeriods'] = $hmaEvents->hmaChangeDirectionForFirstTimeInXPeriods($this->rates['simple'], $this->hmaLength, $this->hmaChangeDirPeriods);

        if ($this->decisionIndicators['hmaRevAfterXPeriods'] == 'reversedUp') {
            $this->result = 'long';
        }
        elseif ($this->decisionIndicators['hmaRevAfterXPeriods'] == 'reversedDown') {
            $this->result = 'short';
        }

        $this->storeResult();
    }
}