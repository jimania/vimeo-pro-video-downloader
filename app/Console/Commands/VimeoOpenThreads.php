<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Vimeo\Laravel\Facades\Vimeo;
use Illuminate\Support\Facades\Log;

class VimeoOpenThreads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vimeo:threads {in_file} {out_file}';
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
    private function getExactlySourceQuality($latestRequest)
    {
        $data = [];
        $sourceFound = false;
        if (isset($latestRequest['body']['download'][0])) {
            foreach ($latestRequest['body']['download'] as $source) {
                if ($source['quality'] == 'source') {
                    //echo(var_dump($source));
                    $data['video_main_url'] = $source['link'];
                    $data['size'] = $source['size'];
                    $data['md5'] = $source['md5'];
                    //$data['type'] = $source['type']; //this is always source from vimeo, not to rely on
                    $sourceFound = true;
                }
            }
        }else {
            throw new \Exception('OOOps video not found, Error: could not find section "download" in the body of the vimeo reply'."\n");
        }

        if (!$sourceFound) {
            throw new \Exception('OOOps video not found, Error: no Source section found in vimeo downloads'."\n");
        }
        return $data;
    }

    private function findSourceVideo($video_id)
    {
        $latestRequest = Vimeo::request('/me/videos/' . $video_id.'?fields=uri,duration,download,name', ['per_page' => 10], 'GET');
        //echo (var_dump($latestRequest));
        if (intval($latestRequest['status']) != 200) {
            throw new \Exception('OOOps video not found, Error: ' . $latestRequest['body']['error'] . "\n" . $latestRequest['body']['developer_message'] . "\n");
        }
        $dataV = $this->getExactlySourceQuality($latestRequest);

        $dataV['video_uri'] = $latestRequest['body']['uri'];
        //$dataV['name'] = $latestRequest['body']['name'];
        $dataV['rateLimit'] = $latestRequest['headers'];
        return $dataV;
    }


    private function rateLimitSleep($header)
    {
        //echo(var_dump($header));
        $threshold = 2000; // safer for threads
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
        $in_file = $this->argument('in_file');
        $out_file = $this->argument('out_file');




        $gDisk = Storage::disk('gcs');
        $localDisk = Storage::disk('public');
        $video_ids = json_decode(file_get_contents($in_file), true);
        $errorArray = [];
        foreach ($video_ids as $video) {
            $video_id = $video['VimeoID'];
            $client_id = $video['ClientID'];
            $mime = $video['MimeType'];
            $sourceFileSize = $video['FileSize'];
            echo ('Processing video: '.$video_id." mimetype: ".$mime. " size: ". $sourceFileSize."\n");
            $mimes = new \Mimey\MimeTypes;
            $extension = $mimes->getExtension($mime);

            $jsonArray = [];


            $jsonArray['client_id'] = $client_id;
            $jsonArray['video_id'] = $video_id;
            $jsonArray['time_started'] = Carbon::now();


            try {

                $targetUrl = $this->findSourceVideo($video_id);
                $this->rateLimitSleep($targetUrl['rateLimit']);
                $fromUrl = $targetUrl['video_main_url'];
                unset($targetUrl['rateLimit']);
                unset($targetUrl['video_main_url']);
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

                    //echo "Gcloud uploaded!\n";
                    $localDisk->delete($localTempFileName);

                    //check size
                    $gSize = $gDisk->size($targetGCSFilename) . "\n";
                    //echo('size ' . $jsonArray['size'] . ' vs ' . $gSize . "\n");

                    if ($gSize != $jsonArray['size']) {
                        $jsonArray['Result'] = 'Failed';
                        $jsonArray['Error_Reason'] = 'error on transfer file size ' . $gSize . ' do not match with vimeo file size ' . $jsonArray['size'];
                    }
                    if ($sourceFileSize != $jsonArray['size']) {
                        $jsonArray['Result'] = 'Warning';
                        $jsonArray['Warning_Reason'] = 'The file size in our records (source json): ' . $sourceFileSize. ' do not match with vimeo file size ' . $jsonArray['size'];
                    }

                    //can't check md5 no interface with google cloud with current api
                } else {
                    $jsonArray['Result'] = 'Failed';
                    $jsonArray['Error_Reason'] = 'File already exists in the cloud';
                }

            }

            catch (\Exception $ex) {
                $error = 'Handle Video Error: '.$ex->getMessage()."\n".$ex->getLine()."\n".$ex->getTraceAsString();
                echo ($ex->getMessage());
                Log::debug($error);
                $jsonArray['Result'] = 'Failed';
                $jsonArray['Error_Reason'] = "Exception:\n".$error;
            } finally {
                $ended_time = Carbon::now();
                $jsonArray['ended_time'] = $ended_time;
                // now time to update ended time and elapsed time.
                $jsonArray['elapsed_time'] = $ended_time->diffInRealSeconds($jsonArray['time_started']);
                //$gDisk->append('video_targets.json', json_encode($jsonArray).',');
                array_push($errorArray,$jsonArray);
                file_put_contents($out_file,json_encode($errorArray,JSON_PRETTY_PRINT));
                //$localDisk->append('VimeoTemp/local.json',json_encode($jsonArray).',');
            }
        }

    }
}