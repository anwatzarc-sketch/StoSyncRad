<?php

use App\Domains\Inventory\Services\ExpiryMonitor;
use App\Domains\Inventory\Services\ReconciliationService;
use App\Domains\Inventory\Services\ReservationService;
use App\Domains\Messaging\Services\OutboxRelay;
use App\Domains\Messaging\Services\WebhookService;
use App\Domains\Platform\Services\PlatformHealth;
use App\Domains\Platform\Services\SetupState;
use App\Domains\ReportingEngine\Services\ReportRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Operational jobs (Chapter 09 §9.8, Chapter 11 §11.7, Chapter 14 §14.9) and their schedule.
| Run `php artisan schedule:run` every minute (cron, or the hosting panel's scheduled task).
| Nothing but the heartbeat runs before Platform Setup is locked.
*/

Artisan::command('outbox:relay {--limit=200}', function (OutboxRelay $relay) {
    $this->line(json_encode($relay->relay(max(1, (int) $this->option('limit')))));
})->purpose('Relay pending outbox events to notifications and webhooks');

Artisan::command('webhooks:deliver {--limit=100}', function (WebhookService $webhooks) {
    $this->line(json_encode($webhooks->deliverDue(max(1, (int) $this->option('limit')))));
})->purpose('Deliver due webhook attempts (signed, with retries and backoff)');

Artisan::command('reservations:expire {--limit=500}', function (ReservationService $reservations) {
    $this->line('Released '.$reservations->expireStale(max(1, (int) $this->option('limit'))).' expired reservations.');
})->purpose('Release reservations past their expiry');

Artisan::command('inventory:expiry-monitor', function (ExpiryMonitor $monitor) {
    $this->line(json_encode($monitor->run()));
})->purpose('Move expired lots to the expired condition and raise expiry alerts (idempotent per day)');

Artisan::command('inventory:reconcile', function (ReconciliationService $reconciliation) {
    $run = $reconciliation->run(null);
    $this->line("Reconciliation {$run->status->value}: {$run->findings_count} findings.");
})->purpose('Recompute balances from the ledger and record any drift as findings');

Artisan::command('reports:purge', function (ReportRunner $runner) {
    $this->line('Purged '.$runner->purgeExpired().' expired export files.');
})->purpose('Delete expired report exports and import error files');

$whenLive = fn (): bool => app(SetupState::class)->isComplete() && ! app()->isDownForMaintenance();

Schedule::call(fn () => app(PlatformHealth::class)->beat())->everyMinute()->name('scheduler-heartbeat');

Schedule::command('outbox:relay')->everyMinute()->when($whenLive)->withoutOverlapping(5)->onOneServer();
Schedule::command('webhooks:deliver')->everyMinute()->when($whenLive)->withoutOverlapping(5)->onOneServer();
Schedule::command('reservations:expire')->everyFiveMinutes()->when($whenLive)->withoutOverlapping(10)->onOneServer();
Schedule::command('reports:purge')->hourly()->when($whenLive)->withoutOverlapping()->onOneServer();
Schedule::command('inventory:expiry-monitor')->dailyAt('00:30')->when($whenLive)->withoutOverlapping(60)->onOneServer();
Schedule::command('inventory:reconcile')->dailyAt('02:00')->when($whenLive)->withoutOverlapping(120)->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily()->when($whenLive);
