<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Artisan;

class MainVimeo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vimeo:start {no} {infile}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        /*        if (count($this->argument())!=3) {
                    echo ('Wrong command use :  php artisan '. $this->signature);
                    exit -1;
                }*/
        $no_of_commands = $this->argument('no');
        $in_file = $this->argument('infile');
        $json = json_decode(file_get_contents($in_file), true);

        $chunkSize = round( count($json) / $no_of_commands);
        $chunks = array_chunk($json,$chunkSize  );
        $in_file = str_replace('.json','',$in_file);

        echo(count($chunks));
        for ($i = 0; $i < count($chunks); $i++) {
            $cmd_in_file = $in_file.$i.'.json';
            $cmd_out_file = $in_file.'_out'.$i.'.json';
            echo $cmd_in_file."\n";
            echo $cmd_out_file."\n";
            echo count($chunks[$i])."\n";
            file_put_contents($cmd_in_file,json_encode($chunks[$i],JSON_PRETTY_PRINT));

            call_in_background('vimeo:threads '.$cmd_in_file.' '.$cmd_out_file);

        }

    }
}