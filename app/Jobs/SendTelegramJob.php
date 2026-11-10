<?php

namespace App\Jobs;

use App\Services\TelegramService;
use App\Models\TicketHiddenUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $telegramId;
    protected $text;
    protected ?int $ticketUserId = null;
    protected string $parseMode = 'markdown';

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $telegramId, string $text, ?int $ticketUserId = null, string $parseMode = 'markdown')
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->text = $text;
        $this->ticketUserId = $ticketUserId;
        $this->parseMode = $parseMode;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->ticketUserId !== null && TicketHiddenUser::where('user_id', $this->ticketUserId)->exists()) {
            return;
        }
        $telegramService = new TelegramService();
        $telegramService->sendMessage($this->telegramId, $this->text, $this->parseMode);
    }
}
