<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Vimeo\Laravel\Facades\Vimeo;
use Illuminate\Support\Facades\Log;

class VimeoCustomStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vimeo:download {client_id} {video_id} {extension}';

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

    private function getExactlySourceQuality($latestRequest){
        $data = [];
        if(isset($latestRequest['body']['download'][0] ))
        foreach ($latestRequest['body']['download'] as $source){
            if($source['quality'] == 'source')
            {
                //echo(var_dump($source));
                $data['video_main_url'] = $source['link'];
                $data['size'] = $source['size'];
                $data['md5'] = $source['md5'];
                $data['type'] = $source['type']; //this is always source from vimeo, not to rely on
            }
        }

        return $data;
    }

    private function findSourceVideo($video_id)
    {
        $latestRequest = Vimeo::request('/me/videos/' . $video_id, ['per_page' => 10], 'GET');
//        dd($latestRequest);
        $dataV = $this->getExactlySourceQuality($latestRequest);

        if(is_null($dataV)){
            $dataV['video_main_url'] = isset($latestRequest['body']['files'][0]['link']) ? $latestRequest['body']['download'][0]['link'] : $latestRequest['files']['download'][0][0]['link'];
            $dataV['size'] = isset($latestRequest['body']['files'][0]['size']) ? $latestRequest['body']['files'][0]['size'] : $latestRequest['body']['files'][0][0]['size'];
            $dataV['md5'] = isset($latestRequest['body']['files'][0]['md5']) ? $latestRequest['body']['files'][0]['md5'] : $latestRequest['body']['files'][0][0]['md5'];
            $dataV['type'] = isset($latestRequest['body']['files'][0]['type']) ? $latestRequest['body']['files'][0]['type'] : $latestRequest['body']['files'][0][0]['type'];
        }

        $video_extension = explode("/",$dataV['type']);
        $dataV['video_extension'] = isset($video_extension[1]) ? ($video_extension[1]) : 'mp4';
        $dataV['video_uri'] = $latestRequest['body']['uri'];
        $dataV['video_id'] = $video_id;
        $dataV['name'] = $latestRequest['body']['name'];
        $dataV['status'] = 2;
        $dataV['rateLimit'] = $latestRequest['headers'];
        return $dataV;
    }


        private function rateLimitSleep($header){
         //echo(var_dump($header));
            $threshold = 5; // safer for threads
            if ($header['X-RateLimit-Remaining'] !== null && $header['X-RateLimit-Remaining'] <= $threshold) {
                $date = Carbon::parse($header['X-RateLimit-Reset'], 'UTC');

                if ($date->isFuture()) {
                    $now = \Carbon\Carbon::now('UTC');
                    $minutesToSleep = $now->diffInMinutes($date);

                    Log::info('Now: ' . $now);
                    Log::info('Resets: ' . $date);

                    Log::info('Rate limit hit, SLEEPING for ' . ($minutesToSleep + 1) . ' min');
                    sleep(($minutesToSleep + 1) * 60);
                }

                Log::info('Remaining Calls: ' . $header['X-RateLimit-Remaining']);
            }
        }

    public function handle()
    {
        $gDisk = Storage::disk('gcs');
        $localDisk = Storage::disk('public');
        $video_id = $this->argument('video_id');
        $client_id = $this->argument('client_id');
        $extension = $this->argument('extension');





        $targetGCSFilename = "Don't know yet";

        try {
            $targetUrl = $this->findSourceVideo($video_id);
            $this->rateLimitSleep($targetUrl['rateLimit']);
            $jsonArray = ($targetUrl);
            $jsonArray['time_started'] = Carbon::now();
            $fromUrl = $targetUrl['video_main_url'];
            $jsonArray['client_id'] = $client_id;
            $bucket = $value = config('app.gcs_bucket');
            $targetGCSFilename = $bucket . $client_id . "/" . $video_id . "." .$extension;
            $localTempFileName = 'vimeoTemp/'.$video_id.'.'.$extension;

            if (!$gDisk->exists($targetGCSFilename)) {

                $data =file($fromUrl);
                $localDisk->put($localTempFileName,$data);

                $contents = $localDisk->get($localTempFileName);

                $gDisk->put($targetGCSFilename , $contents);

                echo "Gcloud uploaded!";
                $localDisk->delete($localTempFileName);


                //check size
                $gSize = $gDisk->size($targetGCSFilename ) . "\n";
                echo('size '.$jsonArray['size'].' vs '.$gSize."\n");
                $jsonArray['size'];
                if ($gSize != $jsonArray['size']) {
                    $jsonArray['size_error'] = 'error on transfer file size ' . $gSize . ' doesnt match with vimeo file size ' . $jsonArray['size'];
                } else {
                    $jsonArray['size_success'] = 'file size  on transfer file size ' . $gSize . ' matched with vimeo file size ' . $jsonArray['size'];
                }

                $ended_time = Carbon::now();
                $jsonArray['ended_time'] = $ended_time;
                // now time to update ended time and elapsed time.
                $jsonArray['elapsed_time'] = $ended_time->diffInSeconds($jsonArray['time_started']);

                $gDisk->append('video_targets.json', json_encode($jsonArray));
            }else{
                echo "already Exists!\n";
            }


        }catch (\Exception $ex) {
            //if already created
            $error = $ex->getMessage()."\n".$ex->getLine()."\n".$ex->getTraceAsString();
            echo($error);
            Log::debug($error);
        }
    }

}
