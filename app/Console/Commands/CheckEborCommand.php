<?php

namespace App\Console\Commands;

use App\Jobs\CheckEborReady;
use Illuminate\Console\Command;

class CheckEborCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'checkEbor {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $date = !is_null($this->option('date'))
        ? $this->option('date')
        : now()->toDateString();


        CheckEborReady::dispatch($date);
    }
}
