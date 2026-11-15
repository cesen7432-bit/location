<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid {--write : Escribe las claves en el archivo .env}';

    protected $description = 'Genera el par de claves VAPID para Web Push';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $lines = [
            'WEBPUSH_PUBLIC_KEY'  => $keys['publicKey'],
            'WEBPUSH_PRIVATE_KEY' => $keys['privateKey'],
        ];

        if (!$this->option('write')) {
            foreach ($lines as $key => $value) {
                $this->line("{$key}={$value}");
            }
            $this->warn('Copia estas líneas al .env. Cambiar las claves invalida las suscripciones existentes.');

            return self::SUCCESS;
        }

        $path = base_path('.env');
        $env  = file_exists($path) ? file_get_contents($path) : '';

        foreach ($lines as $key => $value) {
            $env = preg_match("/^{$key}=.*$/m", $env)
                ? preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $env)
                : rtrim($env) . "\n{$key}={$value}\n";
        }

        file_put_contents($path, $env);
        $this->info('Claves VAPID escritas en .env. Las suscripciones existentes dejarán de funcionar.');

        return self::SUCCESS;
    }
}
