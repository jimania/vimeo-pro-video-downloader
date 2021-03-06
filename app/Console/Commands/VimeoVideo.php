<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Vimeo\Laravel\Facades\Vimeo;


class VimeoVideo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vimeo:download_old {client_id} {video_id}';

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

//
    private function getFromDb(){
        $video = DB::table("videos_feed")
            ->where("status", "=", "0")
            ->first();

        if (!is_null($video)) {
            DB::table("videos_feed")
                ->where("video_id", "=", $video->video_id)
                ->update(['status' => 1, 'no_of_times' => ($video->no_of_times + 1)]);

            $dataV['name'] = $video->name;
            $dataV['size'] = $video->size;
            $dataV['client_id'] = $video->client_id;
            $dataV['vimeo_id'] = $video->video_id;
            $dataV['time_started'] = Carbon::now();
            $dataV['time_ended'] = 'in-process';
            $dataV['elapsed_time'] = 'in-process';
            $dataV['status'] = '0';
            DB::table('vimeo_videos')
                ->insert($dataV);

            $fromUrl = $video->video_main_url;
            try {
                // lets make a directory.
                try{
                    exec(mkdir('/var/www/example/'.$video->client_id, 0777));
                }catch (\Exception $ex){
                    //if already created
                    Log::info($ex->getMessage());
                }
                // start downloading the exact video.
                exec('wget "' . $fromUrl . '" -O /var/www/example/'.$video->client_id.'/'. $video->video_id.'.mp4', $output);
                \Log::info($output);
                $ended_time = Carbon::now();
                // now time to update ended time and elapsed time.

                echo $totalDuration = $ended_time->diffInSeconds($dataV['time_started']);
                DB::table('vimeo_videos')
                    ->where("vimeo_id","=",$video->video_id)->update(['time_ended' => $ended_time, 'elapsed_time' => $totalDuration, 'status' => 1]);

                DB::table("videos_feed")
                    ->where("video_id", "=", $video->video_id)
                    ->update(['status' => 2]);

            } catch (\Exception $ex) {
                Log::info($ex->getMessage());
                DB::table('vimeo_videos')
                    ->where("vimeo_id","=",$video->video_id)->update(['fail_reason' => $ex->getMessage()]);

                DB::table("videos_feed")
                    ->where("video_id", "=", $video->video_id)
                    ->update(['status' => 3]);
            }

        }

    }

    private function getFromInput($video_id){
        $video = DB::table("videos_feed")
            ->where("video_id","=",$video_id)
            ->first();

        if (!is_null($video)) {
            //update the video feed status as it has been started and in progress mode
            DB::table("videos_feed")
                ->where("video_id", "=", $video->video_id)
                ->update(['status' => 1, 'no_of_times' => ($video->no_of_times + 1)]);

            $dataV['name'] = $video->name;
            $dataV['size'] = $video->size;
            $dataV['client_id'] = $video->client_id;
            $dataV['vimeo_id'] = $video->video_id;
            $dataV['time_started'] = Carbon::now();
            $dataV['time_ended'] = 'in-process';
            $dataV['elapsed_time'] = 'in-process';
            $dataV['status'] = '0';
            DB::table('vimeo_videos')
                ->insert($dataV);

            $fromUrl = $video->video_main_url;
            try {
                // lets make a directory.
                try{
                    exec(mkdir('/var/www/example/'.$video->client_id, 0777));
                }catch (\Exception $ex){
                    //if already created
                    Log::info($ex->getMessage());
                }
                // start downloading the exact video.
                exec('wget "' . $fromUrl . '" -O /var/www/example/'.$video->client_id.'/'. $video->video_id.'.mp4', $output);
                \Log::info($output);
                $ended_time = Carbon::now();
                // now time to update ended time and elapsed time.

                $totalDuration = $ended_time->diffInSeconds($dataV['time_started']);
                DB::table('vimeo_videos')
                    ->where("vimeo_id","=",$video->video_id)->update(['time_ended' => $ended_time, 'elapsed_time' => $totalDuration, 'status' => 2]);

                DB::table("videos_feed")
                    ->where("video_id", "=", $video->video_id)
                    ->update(['status' => 2]);

            } catch (\Exception $ex) {
                Log::info($ex->getMessage());
                DB::table('vimeo_videos')
                    ->where("vimeo_id","=",$video->video_id)->update(['fail_reason' => $ex->getMessage()]);

                DB::table("videos_feed")
                    ->where("video_id", "=", $video->video_id)
                    ->update(['status' => 3]);
            }

        }

    }

    public function handle()
    {
        $video_id = $this->argument('video_id');
        if($video_id == '0'){
            $this->getFromDb();
        }else{
            $this->getFromInput($video_id);
        }
    }

}
