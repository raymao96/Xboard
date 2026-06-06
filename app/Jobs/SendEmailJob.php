<?php

namespace App\Jobs;

use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 3;
    public $timeout = 10;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->params = $params;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
	// --- 1. 新增攔截邏輯 ---
        if (isset($this->params['email'])) {
            $isBanned = \Illuminate\Support\Facades\DB::table('v2_user')
                ->where('email', $this->params['email'])
                ->where('banned', 1)
                ->exists();

            if ($isBanned) {
                // 如果是被封禁用戶，記錄一下並直接結束任務
                error_log("!! [MAIL_BLOCK] User {$this->params['email']} is banned. Job canceled.");
                return; 
            }
        }

	// --- 2. 原有的發信邏輯 ---
	$mailLog = MailService::sendEmail($this->params);

	// 如果 MailService 回傳了錯誤，則重新放回隊列重試
        if (isset($mailLog['error']) && $mailLog['error']) {
            $this->release();
        }
    }
}
