<?php

/** Job de prueba: registra ejecuciones y simula un worker concurrente. */
class QueueProbeJob
{
    public static array $runs = [];
    public static ?array $concurrent = null;
    public static array $pendingDuring = [];
    public int $maxAttempts = 2;
    public array $backoff = [0];

    public function handle(array $data): void
    {
        self::$runs[] = $data['n'] ?? null;
        // Segundo worker mientras este job está reclamado: no debe tomarlo
        self::$concurrent = \Core\Queue::processNext();
        self::$pendingDuring = \Core\Queue::pending();
        if (!empty($data['fail'])) {
            throw new \RuntimeException('fallo intencional');
        }
    }
}
