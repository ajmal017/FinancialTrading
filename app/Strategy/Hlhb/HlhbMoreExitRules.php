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


namespace App\Strategy\Hlhb;
use \Log;
use App\StrategyEvents\Momentum;
use App\StrategyEvents\RsiEvents;

class HlhbMoreExitRules extends \App\Strategy\Strategy  {

    public $fastEma;
    public $slowEma;

    public $rsiLength;
    public $rsiBreakthroughLevel;

    public $periodsBack = 2;


    //Whether you will enter a new position
    public function decision() {
        Log::info($this->runId.': New Position Decision Indicators: '.PHP_EOL.' '.$this->logIndicators());

        //Simple MACD Crossover
        if ($this->decisionIndicators['emaCrossover'] == 'crossedAbove' && $this->decisionIndicators['rsiCrossedLevel'] == 'crossedAbove') {
            Log::warning($this->runId.': NEW LONG POSITION');
            return "long";
        }
        elseif ($this->decisionIndicators['emaCrossover'] == 'crossedBelow' && $this->decisionIndicators['rsiCrossedLevel'] == 'crossedBelow') {
            Log::warning($this->runId.': NEW SHORT POSITION');
            return "short";
        }
        else {
            Log::info($this->runId.': Failed Ema Breakthrough');
            return "none";
        }
    }

    public function entryCheck() {

        $momentum = new Momentum();
        $momentum->strategyLogger = $this->strategyLogger;
        $rsiEvents = new RsiEvents();
        $rsiEvents->strategyLogger = $this->strategyLogger;

        $this->decisionIndicators['emaCrossover'] = $momentum->emaCrossoverGoBackX($this->rates, $this->fastEma, $this->slowEma, $this->periodsBack);

        $this->decisionIndicators['rsiCrossedLevel'] = $rsiEvents->crossedLevelWithinPastXPeriods($this->rates, $this->rsiLength, $this->rsiBreakthroughLevel, $this->periodsBack);

        $this->decision = $this->decision();

        if ($this->decision == 'long') {
            $this->newLongOrStayInPosition();
        }
        elseif ($this->decision == 'short') {
            $this->newShortOrStayInPosition();
        }
    }

    public function exitCheck() {
        $momentum = new Momentum();
        $momentum->strategyLogger = $this->strategyLogger;
        $rsiEvents = new RsiEvents();
        $rsiEvents->strategyLogger = $this->strategyLogger;

        $this->decisionIndicators['emaCrossover'] = $momentum->emaCrossoverGoBackX($this->rates, $this->fastEma, $this->slowEma, $this->periodsBack);

        $this->decisionIndicators['rsiCrossedLevel'] = $rsiEvents->crossedLevelWithinPastXPeriods($this->rates, $this->rsiLength, $this->rsiBreakthroughLevel, $this->periodsBack);

        if (($this->decisionIndicators['emaCrossover'] == 'crossedBelow' || $this->decisionIndicators['rsiCrossedLevel'] == 'crossedBelow') && $this->currentPosition['current_position'] == 1) {
            if ($this->decisionIndicators['emaCrossover'] == 'crossedBelow' && $this->decisionIndicators['rsiCrossedLevel'] == 'crossedBelow') {
                $this->newShortOrStayInPosition();
            }
            else {
                $this->closePosition();
            }
        }
        elseif (($this->decisionIndicators['emaCrossover'] == 'crossedAbove' || $this->decisionIndicators['rsiCrossedLevel'] == 'crossedAbove') && $this->currentPosition['current_position'] == -1) {
            if ($this->decisionIndicators['emaCrossover'] == 'crossedAbove' && $this->decisionIndicators['rsiCrossedLevel'] == 'crossedAbove') {
                $this->newLongOrStayInPosition();
            }
            else {
                $this->closePosition();
            }
        }
        else {
            $this->addBreakEvenTrailingStopCheck();
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
