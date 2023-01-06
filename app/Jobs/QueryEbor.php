<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class QueryEbor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public Carbon $date;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Carbon $date)
    {
        //
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
            Log::channel('debug')->debug('{"jobName" : "QueryEbor" },{"desc" :"start job"},{ "tglTrx": "'.$this->date->format('Y-m-d').'"}, {"timeStamp ":"'. now()->format('Y-m-d H:i:s')).'"}';
            $paramTgl = $this->date->format('Y-m-d');

            $insert = DB::table('merchant_data')
            ->select(['mid','nama_merchant','channel','sub_channel','no_rek','email','ebor_mode'])
            ->addSelect(DB::raw("'$paramTgl' as tgl_trx"))
            ->addSelect(DB::raw("-1 as status"))
            ->where('email','!=','')
            //->where('ebor_mode','1')
            ->groupBy('mid')
            ->orderBy('mid','asc')
            ->chunk(1000,function(Collection $collections){

            $data = [];

            $newData = [];

            foreach($collections as $collection){
                $newData['mid'] = $collection->mid;
                $newData['nama_merchant'] = $collection->nama_merchant;
                $newData['channel'] = $collection->channel;
                $newData['sub_channel'] = $collection->sub_channel;
                $newData['no_rek'] = $collection->no_rek;
                $newData['email'] = $collection->email;
                $newData['status'] = $collection->status;
                $newData['tgl_trx'] = $collection->tgl_trx;
                $newData['ebor_mode'] = $collection->ebor_mode;

                $data[] = $newData;
            }
                DB::table('job_ebor')
                ->insert($data);

            });
            Log::channel('debug')->debug('{"jobName" : "QueryEbor" },{"desc" :"insert job success"},{ "tglTrx": "'.$this->date->format('Y-m-d').'"}, {"timeStamp ":"'. now()->format('Y-m-d H:i:s')).'"}';
            RunningEbor::dispatch($this->date);

        }catch(\Throwable $e){

        }

    }
}
