<?php
namespace Plugins\Scheduler;

use Core\Container;
use Core\Log;
use Core\Queue;

final class ScheduledTask
{
    private mixed $task;
    private array $data;
    private string $type;
    private string $expression = '* * * * *';
    private string $description = '';
    private bool $preventOverlap = false;

    public function __construct(mixed $task, array $data, string $type)
    {
        $this->task = $task;
        $this->data = $data;
        $this->type = $type;
    }

    public function cron(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    public function everyMinute(): self { return $this->cron('* * * * *'); }
    public function everyFiveMinutes(): self { return $this->cron('*/5 * * * *'); }
    public function everyFifteenMinutes(): self { return $this->cron('*/15 * * * *'); }
    public function everyThirtyMinutes(): self { return $this->cron('*/30 * * * *'); }
    public function hourly(): self { return $this->cron('0 * * * *'); }
    public function hourlyAt(int $minute): self { return $this->cron("{$minute} * * * *"); }
    public function daily(): self { return $this->cron('0 0 * * *'); }
    public function dailyAt(string $time): self
    {
        [$h, $m] = explode(':', $time) + [0, 0];
        return $this->cron("{$m} {$h} * * *");
    }
    public function weekly(): self { return $this->cron('0 0 * * 0'); }
    public function weeklyOn(int $day, string $time = '0:0'): self
    {
        [$h, $m] = explode(':', $time) + [0, 0];
        return $this->cron("{$m} {$h} * * {$day}");
    }
    public function monthly(): self { return $this->cron('0 0 1 * *'); }
    public function monthlyOn(int $day, string $time = '0:0'): self
    {
        [$h, $m] = explode(':', $time) + [0, 0];
        return $this->cron("{$m} {$h} {$day} * *");
    }

    public function withoutOverlapping(): self
    {
        $this->preventOverlap = true;
        return $this;
    }

    public function description(string $desc): self
    {
        $this->description = $desc;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        $parts = preg_split('/\s+/', trim($this->expression));
        if (count($parts) !== 5) return false;

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        return self::matchField($minute, (int) $now->format('i'))
            && self::matchField($hour, (int) $now->format('G'))
            && self::matchField($dayOfMonth, (int) $now->format('j'))
            && self::matchField($month, (int) $now->format('n'))
            && self::matchField($dayOfWeek, (int) $now->format('w'));
    }

    public function execute(): array
    {
        $lockFile = null;

        if ($this->preventOverlap) {
            $lockFile = $this->lockPath();
            if (file_exists($lockFile)) {
                return ['status' => 'skipped', 'reason' => 'overlap', 'task' => $this->describe()];
            }
            file_put_contents($lockFile, (string) getmypid());
        }

        try {
            $result = match ($this->type) {
                'job' => $this->executeJob(),
                'command' => $this->executeCommand(),
                default => $this->executeCallable(),
            };

            return ['status' => 'ok', 'task' => $this->describe(), 'result' => $result];
        } catch (\Throwable $e) {
            Log::error('Scheduled task failed', [
                'task' => $this->describe(),
                'error' => $e->getMessage(),
            ]);
            return ['status' => 'error', 'task' => $this->describe(), 'error' => $e->getMessage()];
        } finally {
            if ($lockFile && file_exists($lockFile)) unlink($lockFile);
        }
    }

    private function executeJob(): string
    {
        return Queue::dispatch($this->task, $this->data);
    }

    private function executeCallable(): mixed
    {
        if (is_callable($this->task)) {
            return ($this->task)($this->data);
        }

        $instance = Container::resolve($this->task);
        return $instance->handle($this->data);
    }

    private function executeCommand(): string
    {
        $output = [];
        $code = 0;
        exec($this->task . ' 2>&1', $output, $code);
        $out = implode("\n", $output);

        if ($code !== 0) {
            throw new \RuntimeException("Command failed ({$code}): {$out}");
        }

        return $out;
    }

    private function describe(): string
    {
        if ($this->description) return $this->description;
        return is_string($this->task) ? $this->task : 'Closure';
    }

    private function lockPath(): string
    {
        $key = md5(is_string($this->task) ? $this->task : spl_object_id((object) $this->task));
        $dir = (defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/storage/locks';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return "{$dir}/schedule_{$key}.lock";
    }

    private static function matchField(string $field, int $value): bool
    {
        if ($field === '*') return true;

        foreach (explode(',', $field) as $part) {
            if (str_starts_with($part, '*/')) {
                $step = (int) substr($part, 2);
                if ($step > 0 && $value % $step === 0) return true;
                continue;
            }

            if (str_contains($part, '-')) {
                [$min, $max] = explode('-', $part, 2);
                if ($value >= (int) $min && $value <= (int) $max) return true;
                continue;
            }

            if ((int) $part === $value) return true;
        }

        return false;
    }
}
