<?php
namespace Plugins\Scheduler;

use Core\Plugin;

final class SchedulerPlugin extends Plugin
{
    public function description(): string
    {
        return 'Tareas programadas con expresiones cron (php scheduler run)';
    }

    public function aliases(): array
    {
        return [
            'Core\Scheduler' => Scheduler::class,
            'Core\ScheduledTask' => ScheduledTask::class,
        ];
    }

    public function boot(): void
    {
        if ($config = $this->appConfig('schedule')) {
            require_once $config;
        }
    }
}
