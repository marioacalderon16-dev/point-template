<?php
namespace Plugins\Health;

use Core\Plugin;

final class HealthPlugin extends Plugin
{
    public function description(): string
    {
        return 'Chequeo de estado: base de datos, disco y cola de jobs';
    }

    public function aliases(): array
    {
        return [
            'Core\Health' => Health::class,
        ];
    }
}
