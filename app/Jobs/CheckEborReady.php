<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckEborReady implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected Carbon $date;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
         string $date
    )
    {
        $this->date = Carbon::parse($date);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try{
            // check db reckon
            Log::channel('debug')->debug('{"jobName" : "RunningEbor" },{"desc" :"start job"},{ "tglTrx": "'.$this->date->format('Y-m-d').'"}, {"timeStamp ":"'. now()->format('Y-m-d H:i:s')).'"}';

            $dbEbor = DB::connection('sqlsrv');
            $readyEbor = $dbEbor->table('EBOR_JOB')
            ->where('TGL_RK',$this->date->format('Ymd'))
            ->first('TOTAL');

            $isReady = ! empty($readyEbor->TOTAL);

            if (! $isReady){
                Log::channel('debug')->debug('{jobName : CheckEborReady }, {desc :ebor not ready},{ tglTrx:: '.$this->date->format('Y-m-d').'}, {timeStamp :'. now()->format('Y-m-d H:i:s')).'}';
                $this->release(
                    now()->addMinutes(
                        config('app.recheck_ebor_ready')
                    )
                );
                return;
            }

            Log::channel('debug')->debug('{"jobName" : "RunningEbor" },{"desc" :"ebor ready finish"},{ "tglTrx": "'.$this->date->format('Y-m-d').'"}, {"timeStamp ":"'. now()->format('Y-m-d H:i:s')).'"}';

            QueryEbor::dispatch($this->date);
        }catch(\Throwable $e){

        }

    }
}
