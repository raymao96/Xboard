<?php

namespace Tests\Feature;

use App\Http\Controllers\V2\Admin\TicketController;
use App\Http\Controllers\V2\Admin\StatController;
use App\Http\Controllers\V1\User\TicketController as UserTicketController;
use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramJob;
use App\Models\Ticket;
use App\Models\TicketHiddenUser;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TicketService;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
            $app['config']->set([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'cache.default' => 'array',
                'session.driver' => 'array',
                'queue.default' => 'sync',
                'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            ]);
        });
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    private function user(string $email = 'customer@example.test', bool $admin = false): User
    {
        $id = DB::table('v2_user')->insertGetId([
            'email' => $email, 'password' => 'test', 'token' => bin2hex(random_bytes(16)),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'is_admin' => $admin, 'telegram_id' => $admin ? 123456 : null,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        return User::findOrFail($id);
    }

    private function ticket(User $user, int $status = 0): Ticket
    {
        return Ticket::create([
            'user_id' => $user->id, 'subject' => 'Test ticket', 'level' => 1,
            'status' => $status, 'reply_status' => 0,
        ]);
    }

    private function fetch(array $data = []): array
    {
        $response = app(TicketController::class)->fetch(Request::create('/', 'POST', $data));
        return json_decode($response->getContent(), true);
    }

    private function visibility(User $user, bool $hidden): void
    {
        app(TicketController::class)->visibility(Request::create('/', 'POST', [
            'user_id' => $user->id, 'hidden' => $hidden,
        ]));
    }

    public function test_hide_applies_to_old_and_future_tickets_and_restore_is_idempotent(): void
    {
        $user = $this->user();
        $old = $this->ticket($user);
        $this->visibility($user, true);
        $this->visibility($user, true);
        $this->ticket($user, 1);
        $this->assertSame(1, TicketHiddenUser::count());
        $this->assertSame(0, $this->fetch()['total']);
        $this->assertSame(2, $this->fetch(['visibility' => 'hidden'])['total']);
        $this->assertSame(2, $this->fetch(['visibility' => 'all', 'email' => $user->email])['total']);
        $this->assertTrue($this->fetch(['id' => $old->id])['data']['is_hidden']);
        $this->visibility($user, false);
        $this->visibility($user, false);
        $this->assertSame(2, $this->fetch()['total']);
        $this->assertSame(0, $this->fetch(['visibility' => 'hidden'])['total']);
        $this->assertFalse($this->fetch(['id' => $old->id])['data']['is_hidden']);
    }

    public function test_normal_and_hidden_filters_pagination_and_both_dashboard_counts(): void
    {
        $normal = $this->user('normal@example.test');
        $hidden = $this->user();
        $this->ticket($normal);
        $this->ticket($hidden);
        $this->ticket($hidden, 1);
        $this->visibility($hidden, true);
        $this->assertSame(1, $this->fetch()['total']);
        $page = $this->fetch(['visibility' => 'hidden', 'pageSize' => 1]);
        $this->assertSame(2, $page['total']);
        $this->assertCount(1, $page['data']);
        $this->assertSame(1, $this->fetch(['visibility' => 'hidden', 'filter' => [['id' => 'status', 'value' => [1]]]])['total']);
        $this->assertSame(0, $this->fetch(['email' => $hidden->email])['total']);
        $stat = new StatController(\Mockery::mock(\App\Services\StatisticalService::class));
        $this->assertSame(1, $stat->getOverride(Request::create('/'))['data']['ticket_pending_total']);
        $this->assertSame(1, $stat->getStats()['data']['ticketPendingTotal']);
        $this->visibility($hidden, false);
        $this->assertSame(2, $stat->getOverride(Request::create('/'))['data']['ticket_pending_total']);
        $this->assertSame(2, $stat->getStats()['data']['ticketPendingTotal']);
    }

    public function test_customer_payload_and_status_do_not_change_when_hidden(): void
    {
        $user = $this->user();
        $ticket = $this->ticket($user);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $controller = app(UserTicketController::class);
        $before = $controller->fetch($request)->getData(true);
        $this->visibility($user, true);
        $after = $controller->fetch($request)->getData(true);
        $this->assertSame($before, $after);
        $this->assertArrayNotHasKey('is_hidden', $after['data'][0]);
        $this->assertArrayNotHasKey('hidden_user', $user->fresh()->toArray());
        $this->assertSame(0, $ticket->fresh()->status);
    }

    public function test_hidden_ticket_can_be_replied_closed_reopened_and_customer_notified(): void
    {
        Queue::fake();
        $user = $this->user();
        $admin = $this->user('admin@example.test', true);
        $ticket = $this->ticket($user);
        $this->visibility($user, true);
        $service = app(TicketService::class);
        $service->replyByAdmin($ticket->id, 'Admin reply', $admin->id);
        Queue::assertPushed(SendEmailJob::class);
        $request = Request::create('/', 'POST', ['id' => $ticket->id]);
        app(TicketController::class)->close($request);
        $this->assertSame(1, $ticket->fresh()->status);
        $service->openByAdmin($ticket->id);
        $this->assertSame(0, $ticket->fresh()->status);
        $service->reply($ticket->fresh(), 'Customer reply', $user->id);
        $this->assertSame(0, $ticket->fresh()->reply_status);
        $this->assertSame(0, $this->fetch()['total']);
        $this->assertSame(1, $this->fetch(['visibility' => 'hidden'])['total']);
    }

    public function test_telegram_hooks_suppress_hidden_create_and_reply_then_resume_without_replay(): void
    {
        Queue::fake();
        $user = $this->user();
        $this->user('admin@example.test', true);
        require_once base_path('plugins-core/Telegram/Plugin.php');
        $plugin = new \Plugin\Telegram\Plugin('telegram');
        HookManager::reset();
        $plugin->boot();
        $ticket = app(TicketService::class)->createTicket($user->id, 'Test', 1, 'First message');
        $this->visibility($user, true);
        HookManager::call('ticket.create.after', $ticket);
        HookManager::call('ticket.reply.user.after', $ticket);
        Queue::assertNotPushed(SendTelegramJob::class);
        $this->visibility($user, false);
        Queue::assertNotPushed(SendTelegramJob::class);
        $this->app->instance('request', Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '']));
        HookManager::call('ticket.reply.user.after', $ticket);
        Queue::assertPushed(SendTelegramJob::class, 1);
        HookManager::reset();
    }

    public function test_queued_telegram_ticket_notification_is_checked_again_before_sending(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);
        $user = $this->user();
        $job = new SendTelegramJob(123456, 'Ticket notification', $user->id);
        $this->visibility($user, true);
        $job->handle();
        Http::assertNothingSent();
        // Payment and other Telegram notifications are not affected by ticket hiding.
        (new SendTelegramJob(123456, 'Payment notification'))->handle();
        Http::assertSentCount(1);
        $this->visibility($user, false);
        $job->handle();
        Http::assertSentCount(2);
    }

    public function test_visibility_rejects_invalid_input(): void
    {
        $this->expectException(ValidationException::class);
        app(TicketController::class)->visibility(Request::create('/', 'POST', [
            'user_id' => 999999, 'hidden' => 'invalid',
        ]));
    }

    public function test_visibility_route_requires_admin(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())->first(
            fn ($route) => $route->getActionName() === TicketController::class . '@visibility'
        );
        $this->assertNotNull($route);
        $uri = '/' . $route->uri();
        $user = $this->user();
        $this->postJson($uri, ['user_id' => $user->id, 'hidden' => true])->assertForbidden();
        Sanctum::actingAs($user);
        $this->postJson($uri, ['user_id' => $user->id, 'hidden' => true])->assertForbidden();
        Sanctum::actingAs($this->user('admin@example.test', true));
        $this->postJson($uri, ['user_id' => $user->id, 'hidden' => true])->assertOk();
        $this->assertSame(1, TicketHiddenUser::count());
    }
}
