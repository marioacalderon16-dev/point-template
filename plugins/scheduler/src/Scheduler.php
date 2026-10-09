<?php
namespace Plugins\Scheduler;

final class Scheduler
{
    private static array $tasks = [];

    public static function job(string $jobClass, array $data = []): ScheduledTask
    {
        $entry = new ScheduledTask($jobClass, $data, 'job');
        self::$tasks[] = $entry;
        return $entry;
    }

    public static function call(mixed $task, array $data = []): ScheduledTask
    {
        $entry = new ScheduledTask($task, $data, 'callable');
        self::$tasks[] = $entry;
        return $entry;
    }

    public static function command(string $command): ScheduledTask
    {
        $entry = new ScheduledTask($command, [], 'command');
        self::$tasks[] = $entry;
        return $entry;
    }

    public static function due(): array
    {
        $now = new \DateTimeImmutable();
        return array_filter(self::$tasks, fn(ScheduledTask $t) => $t->isDue($now));
    }

    public static function run(): array
    {
        $results = [];
        foreach (self::due() as $task) {
            $results[] = $task->execute();
        }
        return $results;
    }

    public static function all(): array
    {
        return self::$tasks;
    }

    public static function clear(): void
    {
        self::$tasks = [];
    }
}
