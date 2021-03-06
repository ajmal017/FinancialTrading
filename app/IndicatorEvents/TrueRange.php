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


namespace App\IndicatorEvents;
use App\Services\Utility;
use \Log;
use \App\Services\CurrencyIndicators;
use \App\IndicatorEvents\EventHelpers;

class TrueRange {

    public $trueRangeValues = [];
    public $averageTrueRangeValues;

    public function __construct() {
        $this->indicators = new CurrencyIndicators();
        $this->eventHelpers = new EventHelpers();
    }

    public function trueRange($currentRate, $previousRate) {
        $trueRangeOptions = [];
        $trueRangeOptions[] = $currentRate->highMid - $currentRate->lowMid;
        $trueRangeOptions[] = abs($currentRate->highMid - $previousRate->closeMid);
        $trueRangeOptions[] = abs($currentRate->lowMid - $previousRate->closeMid);
        return max($trueRangeOptions);
    }

    public function averageTrueRange($rates, $length) {
        //Get All True Range Values
        foreach ($rates as $index=>$rate) {
            if ($index > 0) {
                $this->trueRangeValues[] = $this->trueRange($rate, $rates[$index-1]);
            }
            else {
                $this->trueRangeValues[] = $rate->highMid - $rate->lowMid;
            }
        }

        //Average True Range
        $atrIndex = 0;
        $initialTrSum = 0;
        foreach ($this->trueRangeValues as $index=>$trueRangeValue) {
            if (($index + 1) > $length) {
                $this->averageTrueRangeValues[] = (($this->averageTrueRangeValues[$atrIndex-1]*($length-1)) + $trueRangeValue)/$length;
                $atrIndex++;
            }
            elseif (($index + 1) == $length) {
                $initialTrSum = $initialTrSum + $trueRangeValue;
                $this->averageTrueRangeValues[] = $initialTrSum/$length;
                $atrIndex++;
            }
            else {
                $initialTrSum = $initialTrSum + $trueRangeValue;
            }
        }
        return $this->averageTrueRangeValues;
    }

    public function currentAverageTrueRange($rates, $length) {
        $atr = $this->averageTrueRange($rates, $length);
        return end($atr);
    }

    public function averageTrueRangePips($rates, $length, $pipValue) {
        $atr = $this->averageTrueRange($rates, $length);

        $averageTrueRangePips = array_map(function($atr) use ($pipValue) {
            return $atr/$pipValue;
        }, $atr);
        return $averageTrueRangePips;
    }

    public function getTakeProfitLossPipValues($rates, $periods, $exchangePips, $profitMultiplier , $lossMultiplier) {
        $trueRange = $this->indicators->averageTrueRange($rates, $periods);

        $response = [];

        $response['profitPips'] = round(($trueRange/$exchangePips)*$profitMultiplier);
        $response['lossPips'] = round(($trueRange/$exchangePips)*$lossMultiplier);
        return $response;
    }

    public function getStopLossPipValue($rates, $periods, $exchangePips , $lossMultiplier) {
        $trueRange = $this->currentAverageTrueRange($rates, $periods);
        return round(($trueRange/$exchangePips)*$lossMultiplier);
    }

    public function currentPriceSurpassedAtrFromOpen($averageTrueRangePips, $openPosition, $rates, $exchangePips, $trueRangeCutoff) {
        $utility = new Utility();

        $relevantAtr = $utility->getLastXElementsInArray($averageTrueRangePips, $openPosition['periodsOpen']);
        $relevantRates = $utility->getLastXElementsInArray($rates, $openPosition['periodsOpen'] + 1);

        $gainLossInPips = array_map(function($rate) use ($exchangePips, $openPosition) {
            if ($openPosition['side'] == 'long') {
                return ($rate->closeMid - $openPosition['openPrice'])/$exchangePips;
            }
            elseif ($openPosition['side'] == 'short') {
                return ($openPosition['openPrice'] - $rate->closeMid)/$exchangePips;
            }
        }, $relevantRates);

        $index = 0;
        $pricePassedAtrProfit = false;

        while (isset($relevantAtr[$index]) && !$pricePassedAtrProfit) {
            $atrCutoffPipAmount = $averageTrueRangePips[$index] * $trueRangeCutoff;

            if ($gainLossInPips[$index] >= $atrCutoffPipAmount) {
                $pricePassedAtrProfit = true;
            }
            $index++;
        }
        return $pricePassedAtrProfit;
    }

    public function getCurrentPriceTrueRangeStopLoss($rates, $openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips) {
        $currentPrice = end($rates);
        if ($openPosition['side'] == 'long') {
            return $currentPrice->closeMid - (end($averageTrueRangePips)*$trueRangeMultiplier*$exchangePips);
        }
        elseif ($openPosition['side'] == 'short') {
            return $currentPrice->closeMid + (end($averageTrueRangePips)*$trueRangeMultiplier*$exchangePips);
        }
    }

    public function getPositionOpenPriceTrueRangeStopLoss($openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips) {
        if ($openPosition['side'] == 'long') {
            return $openPosition['openPrice'] - (end($averageTrueRangePips)*$trueRangeMultiplier*$exchangePips);
        }
        elseif ($openPosition['side'] == 'short') {
            return $openPosition['openPrice'] + (end($averageTrueRangePips)*$trueRangeMultiplier*$exchangePips);
        }
    }

    public function getBreakEvenStopLoss($openPosition, $exchangePips) {
        if ($openPosition['side'] == 'long') {
            return $openPosition['openPrice'] + ($exchangePips*2);
        }
        elseif ($openPosition['side'] == 'short') {
            return $openPosition['openPrice'] - ($exchangePips*2);
        }
    }

    public function getOnePipProfitStopLoss($openPosition, $exchangePips) {
        if ($openPosition['side'] == 'long') {
            return $openPosition['openPrice'] + ($exchangePips*3);
        }
        elseif ($openPosition['side'] == 'short') {
            return $openPosition['openPrice'] - ($exchangePips*3);
        }
    }

    public function getStopLossTrueRangeOrBreakEven($rates, $length, $trueRangeMultiplier, $exchangePips , $openPosition) {
        $averageTrueRangePips = $this->averageTrueRangePips($rates, $length, $exchangePips);

        $pricePassedAtrProfit = $this->currentPriceSurpassedAtrFromOpen($averageTrueRangePips, $openPosition, $rates, $exchangePips, $trueRangeMultiplier);

        if ($pricePassedAtrProfit) {
            return $this->getCurrentPriceTrueRangeStopLoss($rates, $openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips);
        }
        else {
            return $this->getBreakEvenStopLoss($openPosition, $exchangePips);
        }
    }

    public function getStopLossTrueRangeOrBreakEvenMostProfitable($rates, $length, $trueRangeMultiplier, $exchangePips , $openPosition) {
        $averageTrueRangePips = $this->averageTrueRangePips($rates, $length, $exchangePips);

        $pricePassedAtrProfit = $this->currentPriceSurpassedAtrFromOpen($averageTrueRangePips, $openPosition, $rates, $exchangePips, $trueRangeMultiplier);

        if ($pricePassedAtrProfit) {
            if ($openPosition['side'] == 'long') {
                $breakEven = $this->getBreakEvenStopLoss($openPosition, $exchangePips);
                $trueRangeStopLoss = $this->getCurrentPriceTrueRangeStopLoss($rates, $openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips);
                return max([$breakEven, $trueRangeStopLoss]);
            }
            elseif ($openPosition['side'] == 'short') {
                $breakEven = $this->getBreakEvenStopLoss($openPosition, $exchangePips);
                $trueRangeStopLoss = $this->getCurrentPriceTrueRangeStopLoss($rates, $openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips);
                return min([$breakEven, $trueRangeStopLoss]);
            }
        }
        else {
            return $this->getCurrentPriceTrueRangeStopLoss($rates, $openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips);
        }
    }

    public function getStopLossTrueRangeMostProfitable($rates, $length, $trueRangeCutoff, $exchangePips , $openPosition) {
        $averageTrueRangePips = $this->averageTrueRangePips($rates, $length, $exchangePips);
        $currentPrice = end($rates);

        if ($openPosition['side'] == 'long') {
            $breakEven = $openPosition['openPrice'] + ($exchangePips*3);
            $trueRangeStopLoss = $currentPrice->closeMid - (end($averageTrueRangePips)*$trueRangeCutoff*$exchangePips);
            return max([$breakEven, $trueRangeStopLoss]);
        }
        elseif ($openPosition['side'] == 'short') {
            $breakEven = $openPosition['openPrice'] - ($exchangePips*3);
            $trueRangeStopLoss = $currentPrice->closeMid + (end($averageTrueRangePips)*$trueRangeCutoff*$exchangePips);
            return min([$breakEven, $trueRangeStopLoss]);
        }
    }

    public function getStopLossOpenTrUntilCurrentTrProfitableThenBestCurrentTrOrOnePip($rates, $length, $trueRangeMultiplier, $exchangePips , $openPosition)
    {
        $averageTrueRangePips = $this->averageTrueRangePips($rates, $length, $exchangePips);

        $pricePassedAtrProfit = $this->currentPriceSurpassedAtrFromOpen($averageTrueRangePips, $openPosition, $rates, $exchangePips, $trueRangeMultiplier);

        if ($pricePassedAtrProfit) {
            if ($openPosition['side'] == 'long') {
                $breakEven = $this->getBreakEvenStopLoss($openPosition, $exchangePips);
                $trueRangeStopLoss = $this->getCurrentPriceTrueRangeStopLoss($rates, $openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips);
                return max([$breakEven, $trueRangeStopLoss]);
            }
            elseif ($openPosition['side'] == 'short') {
                $breakEven = $this->getBreakEvenStopLoss($openPosition, $exchangePips);
                $trueRangeStopLoss = $this->getCurrentPriceTrueRangeStopLoss($rates, $openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips);
                return min([$breakEven, $trueRangeStopLoss]);
            }
        }
        else {
            return $this->getPositionOpenPriceTrueRangeStopLoss($openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips);
        }
    }

    public function getSLOpenTrUntilCurrentTrProfOrNumberOfPeriods($rates, $length, $trueRangeMultiplier, $exchangePips , $openPosition, $trPeriodCutoff)
    {
        $averageTrueRangePips = $this->averageTrueRangePips($rates, $length, $exchangePips);

        $pricePassedAtrProfit = $this->currentPriceSurpassedAtrFromOpen($averageTrueRangePips, $openPosition, $rates, $exchangePips, $trueRangeMultiplier);

        if ($pricePassedAtrProfit) {
            if ($openPosition['side'] == 'long') {
                $breakEven = $this->getBreakEvenStopLoss($openPosition, $exchangePips);
                $trueRangeStopLoss = $this->getCurrentPriceTrueRangeStopLoss($rates, $openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips);
                return max([$breakEven, $trueRangeStopLoss]);
            }
            elseif ($openPosition['side'] == 'short') {
                $breakEven = $this->getBreakEvenStopLoss($openPosition, $exchangePips);
                $trueRangeStopLoss = $this->getCurrentPriceTrueRangeStopLoss($rates, $openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips);
                return min([$breakEven, $trueRangeStopLoss]);
            }
        }
        elseif ($openPosition['periodsOpen'] >= $trPeriodCutoff) {
            return $this->getBreakEvenStopLoss($openPosition, $exchangePips);
        }
        else {
            return $this->getPositionOpenPriceTrueRangeStopLoss($openPosition, $trueRangeMultiplier, $exchangePips, $averageTrueRangePips);
        }
    }
}
