<?php namespace App\Http\Controllers;

use App\ForexBackTest\BackTestToBeProcessed\FiftyOneHundredEmaTBP;
use App\ForexBackTest\BackTestToBeProcessed\HmaTrendTBP;
use App\ForexBackTest\BackTestToBeProcessed\HighLowBreakoutTBP;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\EmaMomentum\EmaMomentumBackTest;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\TwoTier\EmaFastHmaSlowBT;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\Bollinger\BollingerSlTp;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\TwoTier\ThreeMaSystem\StayIn;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\TwoTier\PivotPoint\PivotPointTestTPSl;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\MacdMomentum\MacdStayInOrClose;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\Stochastic\StochasticTPSl;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\TwoTier\SlowOverBoughtFastMomentum\SlowOverboughtFastMomentumTpSL;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\Hlhb\HlhbTpWTrailingStop;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\BollingerMomentum\BollingerMomentumBackTest;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\ThreeDucks\ThreeDucks;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\PreviousPeriodPriceBreakout\PreviousPeriodPriceBreakout;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\NewIndicatorTesting\NewIndicatorTestingBackTestToBeProcessed;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\TestingSystems\TestingSystemsBackTestToBeProcessed;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\HmaReversal\HmaReversalBackTestToBeProcessed;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\RsiPullback\RsiPullbackBackTestToBeProcessed;
use App\ForexBackTest\BackTestToBeProcessed\ForexStrategy\HmaPricePoint\HmaPricePointBackTestToBeProcessed;
use App\BackTest\BackTestToBeProcessed\Strategy\EmaPriceCross\EmaPriceCrossBackTestToBeProcessed;
//END OF Backtest Declarations

use \Log;

use App\Model\BackTest;
use App\Model\BackTestToBeProcessed;
use App\Model\BackTestGroup;
use Illuminate\Support\Facades\Config;
use App\Model\Servers;


class AutomatedBackTestController extends Controller {

    public function __construct() {
        \Log::emergency('AutomatedBTController Construct Start');
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        $serverController = new ServersController();

        $test = $serverController->setServerId();
        \Log::emergency($test);
        \Log::emergency('Got to End of AutomatedBackTestController Construct');
    }

    public function sleepThirty() {
        sleep(30);
    }

    public function sleepTen() {
        sleep(10);
    }

    public function sleepSixty() {
        sleep(60);
    }

    public function runAutoBackTestIfFailsUpdate() {

        Log::emergency('runAutoBackTestIfFailsUpdate starting');

        //Set Last Git Pull Time To Check Later
        $serverController = new ServersController();
        $lastGitPullTime = $serverController->getLastGitPullTime();
        Config::set('last_git_pull_time', $lastGitPullTime);

        $server = Servers::find(Config::get('server_id'));

        $firstCount = BackTestToBeProcessed::where('back_test_group_id', '=', $server->current_back_test_group_id)->where('start', '=', 0)->where('finish', '=', 0)->count();

        Log::emergency('runAutoBackTestIfFailsUpdate first count '.$firstCount);

        if ($firstCount == 0) {
            $backTestGroup = BackTestGroup::find($server->current_back_test_group_id);
            $backTestGroup->process_run = 1;
            $backTestGroup->save();

            $statCount = BackTestToBeProcessed::where('stats_finish', '=', 0)->where('stats_start', '=', 0)
                ->where('hung_up', '=', 0)
                ->where('finish', '=', 1)->where('start', '=', 1)->where('back_test_group_id', '=', $server->current_back_test_group_id)->count();

            if ($statCount == 0) {
                $backTestGroup = BackTestGroup::find($server->current_back_test_group_id);
                $backTestGroup->stats_run = 1;
                $backTestGroup->save();

                $serverController = new ServersController();
                $serverController->getNextBackTestGroupForServer();

                $this->runAutoBackTestIfFailsUpdate();
            }
            else {
                $this->processBackTestStats();
            }
        }
        else {
            Log::emergency('Else we have more to process');

            $inProcessCount = BackTestToBeProcessed::where('back_test_group_id', '=', $server->current_back_test_group_id)->where('start', '=', 1)->where('finish', '=', 0)->where('hung_up', '=', 0)->count();

            Log::emergency('In Process Count '.$inProcessCount);

            if ($inProcessCount == 0) {
                Log::emergency('Starting Keep Backtest Running');
                //No Tests In Process, Start Running
                $this->keepBackTestRunning();
                }
            else {
                Log::emergency('In Process Count');

                //Check Test That's In Process
                $runningProcess = BackTestToBeProcessed::where('back_test_group_id', '=', $server->current_back_test_group_id)->where('start', '=', 1)->where('finish', '=', 0)->where('hung_up', '=', 0)->first();
                $last_update_time = $runningProcess->in_process_unix_time;

                $fifteenMinutes = 30*60;
                sleep($fifteenMinutes);

                //Get Same Process After 20 Minutes
                $runningProcess = BackTestToBeProcessed::find($runningProcess->id);

                //If the Process has not gotten any more rates in 20 minutes, something is almost definitely up
                if ($last_update_time == $runningProcess->in_process_unix_time && $runningProcess->finish != 1) {
                    //Delete BackTest Record because it needs to be rolled back since it's hung up
                    BackTest::where('process_id', '=', $runningProcess->id)->delete();

                    //Save the Process as Hung Up To Review Later
                    $runningProcess->hung_up = 1;
                    $runningProcess->save();

                    $this->keepBackTestRunning();
                }
                else {
                    return true;
                }
            }
        }
    }

    public function processBackTestStats() {
        $server = Servers::find(Config::get('server_id'));

        $recordCount = BackTestToBeProcessed::where('stats_finish', '=', 0)->where('stats_start', '=', 0)->where('finish', '=', 1)->where('start', '=', 1)
            ->where('back_test_group_id', '=', $server->current_back_test_group_id)
            ->count();

        if ($recordCount > 0) {
            while ($recordCount > 0) {
                $autoController = new BackTestStatsController();
                $autoController->backtestProcessStats();

                $recordCount = BackTestToBeProcessed::where('stats_finish', '=', 0)->where('stats_start', '=', 0)->where('finish', '=', 1)->where('start', '=', 1)
                    ->where('back_test_group_id', '=', $server->current_back_test_group_id)
                    ->count();
            }
        }
        else {
            $serverController = new ServersController();
            $serverController->getNextBackTestGroupForServer();
            $this->runAutoBackTestIfFailsUpdate();
        }
    }

    public function keepBackTestRunning() {
        $recordCount = 1;
        $server = Servers::find(Config::get('server_id'));

        $groupId = $server->current_back_test_group_id;

        while ($recordCount > 0) {
            try {
                Log::emergency('Starting environmentVariableDriveProcess');
                $this->environmentVariableDriveProcess();
            }
            catch (\Exception $e) {
                Log::critical('BT Exception '.$e);
            }
            $recordCount = BackTestToBeProcessed::where('back_test_group_id', '=', $groupId)
                ->where('finish', '=', 0)
                ->where('start', '=', 0)
                ->where('hung_up', '=', 0)
                ->count();

            //Set Last Git Pull Time To Check Later
            $serverController = new ServersController();
            $lastGitPullTime = $serverController->getLastGitPullTime();
            $configLastGitPullTime = Config::get('last_git_pull_time');

            $server = Servers::find(Config::get('server_id'));

            if ($lastGitPullTime != $configLastGitPullTime || $server->current_back_test_group_id != $groupId) {
                $recordCount = 0;
            }
        }
        return true;
    }

    public function environmentVariableDriveProcessId($processId) {
        $this->environmentVariableDriveProcess($processId);
    }

    public function environmentVariableDriveProcess($processId = false) {
        $server = Servers::find(Config::get('server_id'));

        if ($server->current_back_test_strategy == 'HMA') {
            $fiftyOneHundredToBeProcessed = new HmaTrendTBP($processId, $server);
            $fiftyOneHundredToBeProcessed->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'HIGH_LOW_BREAKOUT') {
            $fiftyOneHundredToBeProcessed = new HighLowBreakoutTBP($processId, $server);
            $fiftyOneHundredToBeProcessed->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'EMA_MOMENTUM') {
            $fiftyOneHundredToBeProcessed = new EmaMomentumBackTest($processId, $server);
            $fiftyOneHundredToBeProcessed->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'HMA_TPSL') {
            $hmaTakeProfitStopLoss = new HmaTpSlTBP($processId, $server);
            $hmaTakeProfitStopLoss->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'EMA_MOMENTUM_TPSL') {
            $emaMomentumProcess = new EmaMomentumSlTP($processId, $server);
            $emaMomentumProcess->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'EMA_MOMENTUM_TPSL_WITH_TRAILING_STOP') {
            $emaMomentumProcess = new EmaMomentumTPSLAndTrailingStop($processId, $server);
            $emaMomentumProcess->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'HMA_STOP_OR_STRATEGY_CLOSE') {
            $emaMomentumProcess = new HmaStayInStopLoss($processId, $server);
            $emaMomentumProcess->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'TWO_TIER_EMA_HMA') {
            $emaMomentumProcess = new EmaFastHmaSlowBT($processId, $server);
            $emaMomentumProcess->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'HMA_CROSS') {
            $emaMomentumProcess = new HmaCrossTPSL($processId, $server);
            $emaMomentumProcess->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'THREE_STAY_IN') {
            $emaMomentumProcess = new StayIn($processId, $server);
            $emaMomentumProcess->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'PIVOT_TPSL') {
            $emaMomentumProcess = new PivotPointTestTPSl($processId, $server);
            $emaMomentumProcess->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'MACD_MOMENTUM_STAYIN') {
            $emaMomentumProcess = new MacdStayInOrClose($processId, $server);
            $emaMomentumProcess->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'STOCH_TPSL') {
            $emaMomentumProcess = new StochasticTPSl($processId, $server);
            $emaMomentumProcess->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'TT_OVBT_MOM_TPSL') {
            $slowOverboughtFastMomentumTpSl = new SlowOverboughtFastMomentumTpSL($processId, $server);
            $slowOverboughtFastMomentumTpSl->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'HMA_X_STAYIN') {
            $slowOverboughtFastMomentumTpSl = new HmaCrossoverStayIn($processId, $server);
            $slowOverboughtFastMomentumTpSl->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'HLHB') {
            $slowOverboughtFastMomentumTpSl = new HlhbTpWTrailingStop($processId, $server);
            $slowOverboughtFastMomentumTpSl->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'BOLLINGER' || $server->current_back_test_strategy == 'BOLLINGER_PULLBACK') {
            $bolingerMomentumTest = new BollingerMomentumBackTest($processId, $server);
            $bolingerMomentumTest->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'HMA_CHANGE_DIRECTION') {
            $hmaReverseTest = new HmaRev($processId, $server);
            $hmaReverseTest->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'THREE_DUCKS') {
            $hmaReverseTest = new ThreeDucks($processId, $server);
            $hmaReverseTest->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'PREVIOUS_PRICE_BREAKOUT') {
            $hmaReverseTest = new PreviousPeriodPriceBreakout($processId, $server);
            $hmaReverseTest->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'TEST_STUFFED_NOSE') {
            $backTestStrategy = new TestStuffedNoseBackTestToBeProcessed($processId, $server);
            $backTestStrategy->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'HMA_QUICK_TEST') {
            $backTestStrategy = new HmaQuickTestBackTestToBeProcessed($processId, $server);
            $backTestStrategy->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'HMA_TURN') {
            $backTestStrategy = new HmaTurnBackTestToBeProcessed($processId, $server);
            $backTestStrategy->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'NEW_INDICATOR_TESTING') {
            $backTestStrategy = new NewIndicatorTestingBackTestToBeProcessed($processId, $server);
            $backTestStrategy->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'TESTING_SYSTEMS') {
            $backTestStrategy = new TestingSystemsBackTestToBeProcessed($processId, $server);
            $backTestStrategy->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'HMA_REVERSAL') {
            $backTestStrategy = new HmaReversalBackTestToBeProcessed($processId, $server);
            $backTestStrategy->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'RSI_PULLBACK') {
            $backTestStrategy = new RsiPullbackBackTestToBeProcessed($processId, $server);
            $backTestStrategy->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'HMA_PRICE_POINT') {
            $backTestStrategy = new HmaPricePointBackTestToBeProcessed($processId, $server);
            $backTestStrategy->callProcess();
        }
        elseif ($server->current_back_test_strategy == 'EMA_PRICE_X') {
            $backTestStrategy = new EmaPriceCrossBackTestToBeProcessed($processId, $server);
            $backTestStrategy->callProcess();
        }
        //END OF STRATEGY IFS
    }
}