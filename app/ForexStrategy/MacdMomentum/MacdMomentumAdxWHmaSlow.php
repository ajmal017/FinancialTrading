<?php
/**
 * Created by PhpStorm.
 * User: diego.rodriguez
 * Date: 8/31/17
 * Time: 5:11 PM
 * Description: This is a basic strategy that uses an EMA break through with a longer (100 EMA to start) and short EMA (50 to start).
 *
 * Decisions:
 * BUY      ---> Short EMA crosses above Long EMA
 * Short    ---> Short EMA crosses below Long EMA
 *
 *During Open Position:
 * -If another breakthrouch occurs we will close the current position and open a new, opposite one
 * -If the linear regression slope of the fast EMA is opposite of the position direction, a tighter stop loss will be added.
 */


namespace App\ForexStrategy\MacdMomentum;
use \Log;
use \App\IndicatorEvents\Momentum;
use \App\IndicatorEvents\TrendStrength;


class MacdMomentumAdxWHmaSlow extends \App\ForexStrategy\Strategy  {

    public $oanda;

    public $fastLength;
    public $slowLength;
    public $signalLength = 9;

    public $slowHmaLength;
    public $slowHmaSlopeConfirm;
    public $linHmaRegLength = 2;

    public $adxCutoff = 20;
    public $adxPeriodLength;

    //Whether you will enter a new position
    public function decision() {
        Log::info($this->runId.': New Position Decision Indicators: '.PHP_EOL.' '.$this->logIndicators());

        //Simple MACD Crossover
        if ($this->decisionIndicators['macdCrossover'] == 'crossedAbove' && $this->decisionIndicators['hmaSlopeCheck'] == 'long' && $this->decisionIndicators['adx'] == 'pass') {
            Log::warning($this->runId.': NEW LONG POSITION');
            return "long";
        }
        elseif ($this->decisionIndicators['macdCrossover'] == 'crossedBelow' && $this->decisionIndicators['hmaSlopeCheck'] == 'short' && $this->decisionIndicators['adx'] == 'pass') {
            Log::warning($this->runId.': NEW SHORT POSITION');
            return "short";
        }
        else {
            Log::info($this->runId.': Failed Ema Breakthrough');
            return "none";
        }
    }

    public function entryCheck() {
        $this->utility = new \App\Services\Utility();

        $momentum = new Momentum();
        $trendStrength = new TrendStrength();

        $this->decisionIndicators['macd'] = $this->indicators->macd($this->rates['simple'], $this->fastLength, $this->slowLength, $this->signalLength);
        $this->decisionIndicators['macdCrossover'] = $this->indicators->checkCrossover($this->decisionIndicators['macd']['macd'], $this->decisionIndicators['macd']['signal']);

        $ratePips = $this->getRatesInPips($this->rates['simple']);

        $this->decisionIndicators['hmaSlopeCheck'] = $momentum->hmaSlopeMinCheck($ratePips, $this->slowHmaLength, $this->linHmaRegLength, $this->slowHmaSlopeConfirm);

        $this->decisionIndicators['adx'] = $trendStrength->adxSimpleCutoff($this->rates['full'], $this->adxCutoff);

        $this->decision = $this->decision();

        if ($this->checkOpenPositionsThreshold()) {
            if ($this->decision == 'long') {
                $this->newLongOrStayInPosition();
            }
            elseif ($this->decision == 'short') {
                $this->newShortOrStayInPosition();
            }
            elseif ($this->decision == 'none') {
                $this->closePosition();
            }
        }
    }

    public function exitCheck() {
        $momentum = new Momentum();

        $decision = $momentum->macdExitCrossover($this->rates['simple'], $this->fastLength, $this->slowLength, $this->signalLength, $this->currentPosition['current_position']);

        if ($decision == 'close') {
            $this->closePosition();
        }
        else {
            return 'nothing';
        }
    }

    public function checkForNewPosition() {
        if (!$this->fullPositionInfo['open']) {
            $this->entryCheck();
        }
        else {
            $this->exitCheck();
        }
    }
}
