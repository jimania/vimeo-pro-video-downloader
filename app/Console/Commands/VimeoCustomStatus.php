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
    protected $signature = 'vimeo:download {client_id} {video_id} {mime}';

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
        if (!isset($latestRequest['body']['download'])) {
            echo "download not found";die;
        }
        if(isset($latestRequest['body']['download'][0] ))
        foreach ($latestRequest['body']['download'] as $source){
            if($source['quality'] == 'source')
            {
                //echo(var_dump($source));
                $data['video_main_url'] = $source['link'];
                $data['size'] = $source['size'];
                $data['md5'] = $source['md5'];
                //$data['type'] = $source['type']; //this is always source from vimeo, not to rely on
            }
        }

        return $data;
    }

    private function findSourceVideo($video_id)
    {
        $latestRequest = Vimeo::request('/videos/' . $video_id.'?fields=uri,duration,download,name', ['per_page' => 20], 'GET');
        //echo (var_dump($latestRequest['body']));

        if (intval($latestRequest['status'])!=200) {
            throw new \Exception('OOOps video not found, Error: '.$latestRequest['body']['error']."\n".$latestRequest['body']['developer_message']."\n");
        }
        $dataV = $this->getExactlySourceQuality($latestRequest);

        $dataV['video_uri'] = $latestRequest['body']['uri'];
        //$dataV['name'] = $latestRequest['body']['name'];
        $dataV['rateLimit'] = $latestRequest['headers'];
        return $dataV;
    }


        private function rateLimitSleep($header){
         //echo(var_dump($header));
            $threshold = 5; // safer for threads
            echo $header['X-RateLimit-Remaining'];
            echo "\n";
            echo Carbon::parse($header['X-RateLimit-Reset'], 'UTC');
            echo  "\n";
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
        $video_id = $this->argument('video_id');
        $client_id = $this->argument('client_id');
        $mime = $this->argument('mime');
        $mimes = new \Mimey\MimeTypes;
        $extension = $mimes->getExtension($mime);

        $gDisk = Storage::disk('gcs');
        $localDisk = Storage::disk('public');

        $jsonArray = [];


        $jsonArray['client_id'] = $client_id;
        $jsonArray['video_id'] = $video_id;
        $jsonArray['time_started'] = Carbon::now();


        try {

            $targetUrl = $this->findSourceVideo($video_id);
            $this->rateLimitSleep($targetUrl['rateLimit']);
            $fromUrl = $targetUrl['video_main_url'];
            unset($targetUrl['rateLimit']);
            $jsonArray=array_merge($jsonArray, $targetUrl);
            $bucket = $value = config('app.gcs_bucket');
            $targetGCSFilename = $bucket . $client_id . "/" . $video_id . "." . $extension;
            $localTempFileName = 'VimeoTemp/' . $video_id . '.' . $extension;

            $jsonArray['Result'] = 'Success';


            if (!$gDisk->exists($targetGCSFilename)) {

                $data = file($fromUrl);
                $localDisk->put($localTempFileName, $data);
                $contents = $localDisk->get($localTempFileName);
                $gDisk->put($targetGCSFilename, $contents);

                echo "Gcloud uploaded!\n";
                $localDisk->delete($localTempFileName);

                //check size
                $gSize = $gDisk->size($targetGCSFilename) . "\n";
                echo('size ' . $jsonArray['size'] . ' vs ' . $gSize . "\n");

                if ($gSize != $jsonArray['size']) {
                    $jsonArray['Result'] = 'Failed';
                    $jsonArray['Error_Reason'] = 'error on transfer file size ' . $gSize . ' do not match with vimeo file size ' . $jsonArray['size'];
                }
                //can't check md5 no interface with google cloud with current api
                //todo might check with our database file size.
            } else {
                $jsonArray['Result'] = 'Failed';
                $jsonArray['Error_Reason'] = 'File already exists in the cloud';
            }

        }

        catch (\Exception $ex) {
            $error = $ex->getMessage()."\n".$ex->getLine()."\n".$ex->getTraceAsString();
            echo ($ex->getMessage());
            Log::debug($error);
            $jsonArray['Result'] = 'Failed';
            $jsonArray['Error_Reason'] = "Exception:\n".$error;
        } finally {
            $ended_time = Carbon::now();
            $jsonArray['ended_time'] = $ended_time;
            // now time to update ended time and elapsed time.
            $jsonArray['elapsed_time'] = $ended_time->diffInRealSeconds($jsonArray['time_started']);
            $gDisk->append('video_targets.json', json_encode($jsonArray).',');

            //$localDisk->append('VimeoTemp/local.json',json_encode($jsonArray).',');
        }
    }

}
