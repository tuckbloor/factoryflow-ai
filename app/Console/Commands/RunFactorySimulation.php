<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FactorySimulator;
use Illuminate\Console\Command;
use Throwable;

/**
 * A single local simulation clock. Dashboard polling is only for presentation.
 * Parallel HTTP/MCP reads are safe because FactorySimulator locks its JSON state.
 */
final class RunFactorySimulation extends Command
{
    protected $signature = 'factory:run {--interval=500 : Milliseconds between simulation ticks}';

    protected $description = 'Continuously advance the autonomous FactoryFlow virtual factory';

    public function handle(FactorySimulator $factory): int
    {
        $interval = max(100, min(2000, (int) $this->option('interval')));
        $this->info('FactoryFlow simulation clock running. Press Ctrl+C to stop.');

        while (true) {
            try {
                $factory->snapshot();
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());
                return self::FAILURE;
            }
            usleep($interval * 1000);
        }
    }
}
