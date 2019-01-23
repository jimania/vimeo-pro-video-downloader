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
        ini_set('memory_limit','5120M');
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
                    $data['video_main_url'] = $source['link'];
                    $data['size'] = $source['size'];
                    $data['md5'] = $source['md5'];
                    //$data['type'] = $source['type']; //this is always source from vimeo, not to rely on
                    $sourceFound = true;
                }
            }
        }
        else {
            throw new \Exception('OOOps video not found, Error: could not find section "download" in the body of the vimeo reply'."\n");
        }

        if (!$sourceFound) {
            $data = $this->getBestQuality($latestRequest);
        }
        return $data;
    }

    private function getBestQuality($latestRequest)
    {
        $data = [];
        $data['size'] = 0;
        $sourceFound = false;
        if (isset($latestRequest['body']['download'][0])) {
            foreach ($latestRequest['body']['download'] as $source) {
                if ($source['quality'] != 'source' and $source['size'] >$data['size']) {
                    $data['video_main_url'] = $source['link'];
                    $data['size'] = $source['size'];
                    $data['md5'] = $source['md5'];
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

    private function rateLimitSleep($header)
    {
        //echo(var_dump($header));
        $threshold = 800; // safer for threads
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

    function formatBytes($size, $precision = 2)
    {
        $size = max($size, 0);
        $base = log($size, 1024);
        $suffixes = array('', 'K', 'M', 'G', 'T');

        return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
    }

    public function handle()
    {
        $in_file = $this->argument('in_file');
        $out_file = $this->argument('out_file');




        $gDisk = Storage::disk('gcs');
        $localDisk = Storage::disk('public');
        $video_ids = json_decode(file_get_contents($in_file), true);
        $errorArray = [];
        $logArray = [];
        $bucket = $value = config('app.gcs_bucket');

        $intervalStarted = \Carbon\Carbon::now('UTC');
        foreach ($video_ids as $video) {

            //------- check and slip for 15 mins after 30 mins
            $now = \Carbon\Carbon::now('UTC');
            $runningMinutes = $now->diffInMinutes($intervalStarted);
            if ($runningMinutes>30) {
                sleep(15 * 60);
                $intervalStarted = \Carbon\Carbon::now('UTC');
            }

            $video_id = $video['VimeoID'];
            $client_id = $video['ClientID'];
            $mime = $video['MimeType'];
            $sourceFileSize = $video['FileSize'];
            echo ('Processing video: '.$video_id." mimetype: ".$mime. " size: ". $sourceFileSize."\n");
            $mimes = new \Mimey\MimeTypes;
            $extension = $mimes->getExtension($mime);

            $jsonArray = [];


            $jsonArray['ClientName'] = $video['ClientName'];
            $jsonArray['ClientID'] = $client_id;
            $jsonArray['VimeoID'] = $video_id;
            $jsonArray['FileSize'] = $video['FileSize'];
            $jsonArray['FileSizeReadable'] = $this->formatBytes( $video['FileSize']);
            $jsonArray['MimeType'] = $video['MimeType'];
            $jsonArray['duration'] = $video['duration'];
            $jsonArray['account'] = $video['account'];

            $jsonArray['time_started'] = Carbon::now();


            $targetGCSFilename = $bucket . $client_id . "/" . $video_id . "." . $extension;
            $localTempFileName = 'VimeoTemp/' . $video_id . '.' . $extension;
            $targetGCSFilename_mp4 = $bucket . $client_id . "/" . $video_id . ".mp4";
            $localTempFileName_mp4 = 'VimeoTemp/' . $video_id . '.mp4';

            try {

                $jsonArray['Result'] = 'Success';
                $jsonArray['GCS_Target_File'] = $targetGCSFilename;

                if (!$gDisk->exists($targetGCSFilename)) {
                    $latestRequest = Vimeo::request('/me/videos/' . $video_id.'?fields=uri,duration,download,name', ['per_page' => 10], 'GET');
                    //echo (var_dump($latestRequest));
                    if (intval($latestRequest['status']) != 200) {
                        throw new \Exception('OOOps video not found, Error: ' . $latestRequest['body']['error'] . "\n");
                    }
                    $this->rateLimitSleep($latestRequest['headers']);

                    $targetUrl = $this->getExactlySourceQuality($latestRequest);

                    $fromUrl = $targetUrl['video_main_url'];
                    unset($targetUrl['video_main_url']);
                    $jsonArray=array_merge($jsonArray, $targetUrl);


                    $jsonArray['sizeReadable'] = $this->formatBytes($jsonArray['size']);

                    $data = fopen($fromUrl,'r');
                    $localDisk->put($localTempFileName, $data);
                    $contents = $localDisk->readStream($localTempFileName);
                    $gDisk->put($targetGCSFilename, $contents);


                    //check size
                    $gSize = $gDisk->size($targetGCSFilename) . "\n";
                    //echo('size ' . $jsonArray['size'] . ' vs ' . $gSize . "\n");

                    if ($gSize != $jsonArray['size']) {
                        $jsonArray['Result'] = 'Failed';
                        $jsonArray['Error_Reason'] = 'error on transfer file size ' . $gSize . ' do not match with vimeo file size ' . $jsonArray['size'];
                        //delete the file from the bucket, it is not good!!!
                        $gDisk->delete($targetGCSFilename);
                    }
                    if ($sourceFileSize != $jsonArray['size']) {
                        $jsonArray['Result'] = 'Warning';
                        $jsonArray['Warning_Reason'] = 'The file size in our records (source json): ' . $sourceFileSize. ' do not match with vimeo file size ' . $jsonArray['size'];
                    }
                    //can't check md5 no interface with google cloud with current api
                    if($extension !='mp4'){
                        // also download max mp4 video
                        $targetUrl = $this->getBestQuality($latestRequest);

                        $fromUrl = $targetUrl['video_main_url'];
                        unset($targetUrl['video_main_url']);
                        $jsonArray['GCS_Target_File_mp4'] = $targetGCSFilename_mp4;

                        $data = fopen($fromUrl,'r');
                        $localDisk->put($localTempFileName_mp4, $data);
                        $contents = $localDisk->readStream($localTempFileName_mp4);
                        $gDisk->put($targetGCSFilename_mp4, $contents);

                        $jsonArray['GCS_Target_File_mp4_Size']= $gDisk->size($targetGCSFilename_mp4);
                    }
                } else {
                    $jsonArray['Result'] = 'Warning';
                    $jsonArray['Warning_Reason'] = 'File already exists in the cloud';
                }

            }

            catch (\Exception $ex) {
                $error = 'Handle Video Error: '.$ex->getMessage()."\n".$ex->getLine()."\n".$ex->getTraceAsString();
                echo ($ex->getMessage());
                Log::debug($error);
                $jsonArray['Result'] = 'Failed';
                $jsonArray['Error_Reason'] = "Exception:\n".$error;
                if ($gDisk->exists($targetGCSFilename)) {
                    $gDisk->delete($targetGCSFilename);
                }
                if ($gDisk->exists($targetGCSFilename_mp4)) {
                    $gDisk->delete($targetGCSFilename_mp4);
                }
            } finally {
                $localDisk->delete($localTempFileName);
                $localDisk->delete($localTempFileName_mp4);
                $ended_time = Carbon::now();
                $jsonArray['time_ended'] = $ended_time;
                // now time to update ended time and elapsed time.
                $jsonArray['elapsed_time'] = $ended_time->diffInRealSeconds($jsonArray['time_started']);
                $jsonArray['time_ended'] = $jsonArray['time_ended']->toDateTimeString();
                $jsonArray['time_started'] = $jsonArray['time_started']->toDateTimeString();

                array_push($logArray, $jsonArray);
                file_put_contents($out_file, json_encode($logArray, JSON_PRETTY_PRINT));

                if ($jsonArray['Result'] == 'Failed') {
                    array_push($errorArray, $jsonArray);
                    file_put_contents('Failed-'.$out_file, json_encode($errorArray, JSON_PRETTY_PRINT));
                }
            }
        }

    }
}