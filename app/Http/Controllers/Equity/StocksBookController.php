<?php namespace App\Http\Controllers\Equity;

use App\Model\Stocks\StocksCompanyProfile;
use \DB;
use \Log;
use Request;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ServersController;
use App\Http\Controllers\ProcessScheduleController;
use App\Broker\IexTrading;
use Illuminate\Support\Facades\Config;

use App\Model\Stocks\StocksDump;
use App\Model\Stocks\Stocks;
use App\Model\Stocks\StocksApiJobs;
use App\Model\Stocks\StocksBook;
use App\Model\Stocks\StocksDailyPricesYahoo;
use App\Model\Stocks\StocksDailyPricesYahoosYahoo;
use App\Model\Stocks\StocksHistoryBook;
use App\Model\ProcessLog\ProcessQueue;
use App\Model\Stocks\StocksTags;
use App\Model\Stocks\StocksIndustry;
use App\Model\Stocks\StocksFundamentalData;
use App\Services\ProcessLogger;
use App\Services\Csv;
use App\Services\Utility;

class StocksBookController extends Controller {

    public $symbol = false;
    public $stock = false;
    public $year = false;
    public $keepRunningCheck = true;
    public $lastPullTime = 0;
    public $keepRunningStartTime = 0;
    public $logger;
    protected $utility;

    public $currentStockDate;
    protected $currentStockPrice;

    public function __construct() {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        $this->utility = new Utility();

        $serverController = new ServersController();

        $serverController->setServerId();
    }

    public function keepRunning() {
        $this->logger = new ProcessLogger('eq_book_iex');

        $serverController = new ServersController();
        $lastGitPullTime = $serverController->getLastGitPullTime();
        Config::set('last_git_pull_time', $lastGitPullTime);

        $currentDay = date('z') + 1;
        $stocksToPullCount = StocksApiJobs::where('last_book_pull', '!=', $currentDay)->count();

        while ($stocksToPullCount > 0) {
            $this->lastPullTime = $currentDay;
            $this->pullOneStock($currentDay);

            $lastGitPullTime = $serverController->getLastGitPullTime();
            $configLastGitPullTime = Config::get('last_git_pull_time');

            if ($lastGitPullTime != $configLastGitPullTime) {
                $this->keepRunningCheck = false;
            }
            $stocksToPullCount = StocksApiJobs::where('last_book_pull', '!=', $currentDay)->count();
        }
        $this->logger->processEnd();
    }

    public function pullOneStock($currentDay) {
        $stockApi = StocksApiJobs::where('last_book_pull', '!=', $currentDay)->first();
        $this->stock = Stocks::find($stockApi->stock_id);

        try {
            $this->updateBook();
        }
        catch (\Exception $exception) {
            $this->logger->logMessage('Error Exception '.$this->stock->id.'-'.$this->stock->symbol.' '.$exception->getMessage());
        }

        $stockApi->last_book_pull = $currentDay;
        $stockApi->save();
    }

    public function getClosestPriceDate($unix_time) {
        $mysql_date_time = $this->utility->unixToMysqlDate($unix_time);

        $closestPriceRecord = DB::select("select * from stocks_daily_prices_yahoo 
                            where stock_id = ?
                            ORDER BY ABS( DATEDIFF( price_date_time, ? ) ) 
                            LIMIT 0, 1
                              ", [$this->stock->id, $mysql_date_time]);

        return $closestPriceRecord[0];
    }

    public function getPercentChange($start_price, $end_price) {
        $difference = $end_price - $start_price;
        return round($difference/$start_price, 5);
    }

    public function getStockBook() {
        $stockBook = [];

        try {
            $this->currentStockPrice = StocksDailyPricesYahoo::where('stock_id','=', $this->stock->id)
                ->orderBy('price_date_time', 'desc')
                ->first();

            $oneYearAgoDate = strtotime($this->currentStockPrice->price_date_time.' -1 year');
            $oneYearAgoPriceRecord = $this->getClosestPriceDate($oneYearAgoDate);

            $stockBook['ytd_change'] = $this->getPercentChange($oneYearAgoPriceRecord->close, $this->currentStockPrice->close);

            $oneWeekAgoDate = strtotime($this->currentStockPrice->price_date_time.' -1 week');
            $oneWeekAgoDatePriceRecord = $this->getClosestPriceDate($oneWeekAgoDate);

            $stockBook['week_change'] = $this->getPercentChange($oneWeekAgoDatePriceRecord->close, $this->currentStockPrice->close);

            $oneMonthAgoDate = strtotime($this->currentStockPrice->price_date_time.' -1 month');
            $oneMonthAgoDatePriceRecord = $this->getClosestPriceDate($oneMonthAgoDate);

            $stockBook['month_change'] = $this->getPercentChange($oneMonthAgoDatePriceRecord->close, $this->currentStockPrice->close);

            $oneDayAgoPrice = StocksDailyPricesYahoo::where('stock_id','=', $this->stock->id)->where('price_date_time', '<', $this->currentStockPrice->price_date_time)->orderBy('price_date_time', 'desc')
                ->first();

            $stockBook['change_percent'] = $this->getPercentChange($oneDayAgoPrice->close, $this->currentStockPrice->close);

            return $stockBook;
        }
        catch (\Exception $e) {
            $this->logger->logMessage('Error Exception '.$this->stock->id.'-'.$this->stock->symbol.' '.$e->getMessage());
            return $e->getMessage();
        }

    }

    public function updateBook() {
        $this->logger->logMessage('Getting Book Calculations for id: '.$this->stock->id.' symbol: '.$this->stock->symbol);

        $this->currentStockDate = StocksDailyPricesYahoo::where('stock_id','=', $this->stock->id)->max('price_date_time');

        $stockBook = $this->getStockBook();

        if ($stockBook) {
            $stockBookRecord = StocksBook::firstOrNew(['stock_id'=> $this->stock->id]);
            $stockBookRecord->ytd_change = $stockBook['ytd_change'];
            $stockBookRecord->week_change = $stockBook['week_change'];
            $stockBookRecord->month_change = $stockBook['month_change'];
            $stockBookRecord->change_percent = $stockBook['change_percent'];
            $stockBookRecord->save();
            $this->logger->logMessage('Successful Save '.$this->stock->id.' symbol: '.$this->stock->symbol);
        }
    }

    public function updateBookSingleStockBook($stockId) {
        $this->stock = Stocks::find($stockId);

        $this->logger = new ProcessLogger('eq_book_iex');

        $this->logger->logMessage('Getting Book Calculations for id: '.$this->stock->id.' symbol: '.$this->stock->symbol);

        $this->currentStockDate = StocksDailyPricesYahoo::where('stock_id','=', $this->stock->id)->max('price_date_time');

        $stockBook = $this->getStockBook();

        if ($stockBook) {
            try {
                $stockBookRecord = StocksBook::firstOrNew(['stock_id'=> $this->stock->id]);
                $stockBookRecord->ytd_change = $stockBook['ytd_change'];
                $stockBookRecord->week_change = $stockBook['week_change'];
                $stockBookRecord->month_change = $stockBook['month_change'];
                $stockBookRecord->change_percent = $stockBook['change_percent'];
                $stockBookRecord->save();
                $this->logger->logMessage('Successful Save '.$this->stock->id.' symbol: '.$this->stock->symbol);
            }
            catch (\Exception $e) {
                $this->logger->logMessage('Error on Save '.$this->stock->id.' symbol: '.$this->stock->symbol);
                $this->logger->logMessage($e->getMessage());
            }
        }
    }

    public function getInitialHistoricalBookDate() {
        $minDate = StocksDailyPricesYahoo::where('stock_id','=', $this->stock->id)->min('price_date_time');

        if (is_null($minDate)) {
            //$this->logger->logMessage('No Rates for '.$this->stock->id.' symbol: '.$this->stock->symbol.' CANCELLING');
            return false;
        }

        $oneYearAfterMinDate = strtotime($minDate.' +1 year');

        $dailyStockRate = $this->getClosestPriceDate($oneYearAfterMinDate);

        return $dailyStockRate->price_date_time;
    }

    public function createHistoricalStockBooks($stockId) {
        $this->logger = new ProcessLogger('stck_book_hist_1_stck');

        $this->stock = Stocks::find($stockId);

        $mostRecentStockDate = StocksHistoryBook::where('stock_id','=', $this->stock->id)->max('book_date');

        if (is_null($mostRecentStockDate)) {
            $this->currentStockDate = $this->getInitialHistoricalBookDate();
        }
        else {
            $this->currentStockDate = $mostRecentStockDate;
        }

        if ($this->currentStockDate) {
            while (!is_null($this->currentStockDate)) {
                $stockBook = $this->getStockBook();
                $stockBook['book_date_unix'] = strtotime($this->currentStockDate);
                $stockBook['stock_id'] = $this->stock->id;
                $stockBook['book_date'] = $this->currentStockDate;
                StocksHistoryBook::firstOrCreate($stockBook);

                $this->currentStockDate = StocksDailyPricesYahoo::where('stock_id','=', $this->stock->id)->where('price_date_time', '>', $this->currentStockDate)->min('price_date_time');
            }
        }
    }

    public function createHistoricalStockBookProcesses() {
        $stocks = Stocks::where('initial_daily_load', '=', 1)->get(['id'])->toArray();
        $variableIds = array_column($stocks,'id');

        $processScheduleController = new ProcessScheduleController();
        $processScheduleController->createQueueRecordsWithVariableIds('stck_book_hist_1_stck', $variableIds);
    }



}