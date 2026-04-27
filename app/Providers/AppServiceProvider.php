<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
// app/Providers/AppServiceProvider.php の boot() に追記
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\Request;
use App\Events\TaskCreated;
use App\Events\TaskUpdated;
use App\Events\TaskDeleted;
use App\Listeners\LogTaskActivity;
use App\Models\Task;
use App\Observers\TaskObserver;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by($request->user()->id) // 認証済み：120回/分
                : Limit::perMinute(30)->by($request->ip());       // 未認証：30回/分
        });

        $listener = LogTaskActivity::class;

        Event::listen(TaskCreated::class, [$listener, 'handleTaskCreated']);
        Event::listen(TaskUpdated::class, [$listener, 'handleTaskUpdated']);
        Event::listen(TaskDeleted::class, [$listener, 'handleTaskDeleted']);

        Task::observe(TaskObserver::class);
    }
}
