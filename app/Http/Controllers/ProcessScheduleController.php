<?php

namespace App\Http\Controllers;

use App\Model\ProcessScheduleDefTimes;
use Illuminate\Http\Request;
use App\Model\ProcessLog\Process;
use App\Model\ProcessLog\ProcessQueue;
use App\Http\Controllers\ServersController;
use App\Http\Controllers\AutomatedBackTestController;
use App\Services\Utility;
use App\Services\ProcessLogger;
use App\Model\ProcessScheduleDefinition;

class ProcessScheduleController extends Controller
{
    protected $processId;
    public $logger;
    public $serverController;

    protected $dayOfWeekDays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function __construct()
    {
        $this->utility = new Utility();
    }

    public function checkForDueProcesses() {
        $this->logger = new ProcessLogger('prc_scheduler');

        $dueSchedules = ProcessScheduleDefinition::where('next_time', '<=', time())->get()->toArray();

        foreach ($dueSchedules as $dueSchedule) {
            $this->logger->logMessage('Due Schedule'.$dueSchedule['id'].'-'.$dueSchedule['name']);
            $this->createProcessRecords($dueSchedule);
            $this->createNextScheduleTime($dueSchedule);
        }

        $this->logger->processEnd();
    }

    public function createProcessRecords($dueSchedule) {
        \DB::enableQueryLog();
        $processes = Process::where('schedule_def_id', '=', $dueSchedule['id'])->where('active','=',1)->get()->toArray();
        $a = \DB::getQueryLog();

        foreach ($processes as $process) {
            $this->logger->logMessage('Creating Queue Record for '.$process['id'].'-'.$process['name']);

            $newProcessQueue = new ProcessQueue();
            $newProcessQueue->process_id = $process['id'];
            $newProcessQueue->priority = $process['priority'];
            $newProcessQueue->save();

        }
    }

    public function getNextRunTime($day, $hour, $minute) {
        $current_day_of_week = date('N', time());

        if ($current_day_of_week != $day) {
            $next_day_of_week = $this->dayOfWeekDays[$day];
//            if ($day < $current_day_of_week) {
//                $nextTime = strtotime($next_day_of_week.' next week '.$this->utility->addZero($hour).':'.$this->utility->addZero($minute));
//            }
//            else {
//                $nextTime = strtotime($next_day_of_week.' '.$this->utility->addZero($hour).':'.$this->utility->addZero($minute));
//            }

            $nextTime = strtotime($next_day_of_week.' '.$this->utility->addZero($hour).':'.$this->utility->addZero($minute));
        }
        else {
            $nextTime = strtotime('today '.$this->utility->addZero($hour).':'.$this->utility->addZero($minute));
        }
        return $nextTime;
    }

    public function createNextScheduleTime($dueSchedule) {
            $definition = ProcessScheduleDefTimes::where('process_schedule_def_id', '=', $dueSchedule['id'])
            ->where('id', '>', $dueSchedule['next_time_id'])
            ->first();

        if (is_null($definition)) {
            $min_id = ProcessScheduleDefTimes::where('process_schedule_def_id', '=', $dueSchedule['id'])
                ->min('id');

            $definition = ProcessScheduleDefTimes::find($min_id)->toArray();
        }

        $scheduleDefToUpdate = ProcessScheduleDefinition::find($dueSchedule['id']);

        $nextTime = $this->getNextRunTime($definition['day_of_week'], $definition['hours'], $definition['minutes']);

        $scheduleDefToUpdate->next_time_id = $definition['id'];
        $scheduleDefToUpdate->next_time = $nextTime;

        $scheduleDefToUpdate->save();
    }

    public function createQueueRecord($code) {
        $process = Process::where('code','=', $code)->first();

        $newProcessQueue = new ProcessQueue();
        $newProcessQueue->process_id = $process->id;
        $newProcessQueue->priority = $process->priority;
        $newProcessQueue->save();
    }

    public function createQueueRecordsWithVariableIds($code, $variableIds) {
        $process = Process::where('code', '=', $code)->first();

        $insertRecords = [];

        foreach ($variableIds as $variableId) {
            $insertRecords[] = [
                'process_id'=> $process->id,
                'variable_id'=> $variableId,
                'priority'=> $process->priority
            ];
        }
        ProcessQueue::insert($insertRecords);
    }
}