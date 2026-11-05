<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Errors\ErrorRecorder;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Wires the central application error log into the logging and queue lifecycles. */
class ErrorLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One "is the table there?" answer per request/job, never process-wide.
        $this->app->scoped(ErrorRecorder::class);
    }

    public function boot(): void
    {
        Event::listen(JobProcessing::class, fn () => ErrorRecorder::markJob(true));
        Event::listen(JobProcessed::class, fn () => ErrorRecorder::markJob(false));
        Event::listen(JobFailed::class, fn () => ErrorRecorder::markJob(false));
        Event::listen(JobExceptionOccurred::class, fn () => ErrorRecorder::markJob(false));
    }
}
