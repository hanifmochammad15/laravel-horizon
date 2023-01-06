<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class RunningEbor implements ShouldQueue
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
        //
        try{
            Log::channel('debug')->debug('{"jobName" : "RunningEbor" },{"desc" :"start job"},{ "tglTrx": "'.$this->date->format('Y-m-d').'"}, {"timeStamp ":"'. now()->format('Y-m-d H:i:s')).'"}';

            $paramTgl = $this->date->format('Y-m-d');
            $total_sukses 	= 0;
            $total_gagal 	= 0;
            $commit=0;
            $ebor_upd =[];
            $getMid =  DB::table('job_ebor')
            //->where('ebor_mode','1')
            ->where('tgl_trx',$paramTgl)
            ->orderBy('mid','asc')
            ->first();

            $getMerchantData =  DB::table('merchant_data')
            ->where('mid',$getMid->mid )
            ->first();

            if(!empty($getMerchantData)){


                if ($getMid->ebor_mode == 1){
                    $this->generatePdf();
                }

                if($getMid->ebor_mode == 2){
                    $this->generateCsv();
                }

            }else{
                $ebor_upd[$commit]['keterangan'] = "Gagal! detail merchant tidak ditemukan";
                $ebor_upd[$commit]['status']		= 3;
                $total_gagal++;
                Log::channel('debug')->debug('{"jobName" : "RunningEbor" }, {"mid" :"'.$getMid->mid.'" },{"desc" :"not found"},{ "tglTrx": "'.$this->date->format('Y-m-d').'"}, {"timeStamp ":"'. now()->format('Y-m-d H:i:s')).'"}';
            }
            //update status
        }catch(\Throwable $e){

        }
    }

    public function generateCsv(){
        // logic generate csv
    }

    public function generatePdf(){
        // logic generate csv
        Storage::disk('local')->put('file.txt', 'Your content here');
    }
}
