<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\FactorySimulator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('factory-action')]
#[Description('CONTROL ONLY THE LOCAL FACTORY SIMULATION: start/stop full automatic production, restart all orders, toggle auto-repeat, start/pause/resume/restart jobs, start/stop conveyors, change belt speeds, simulate/clear machine faults, control cranes, robots, and forklift pallet movements, or emergency stop. This changes on-screen simulated state; no physical machinery is connected. Never call emergency, fault injection, or reset_demo without explicit user approval.')]
final class FactoryActionTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum([
                    'start_job', 'pause_job', 'resume_job', 'cancel_job',
                    'start_conveyor', 'stop_conveyor', 'set_conveyor_speed',
                    'inject_fault', 'clear_fault', 'start_line', 'stop_line',
                    'emergency_stop', 'release_emergency',
                    'start_factory', 'stop_factory', 'restart_production', 'set_auto_repeat',
                    'start_handling', 'stop_handling', 'set_handling_speed',
                    'restart_job', 'reset_demo', 'drive_forklift', 'pickup_pallet', 'drop_pallet',
                ])
                ->description('One simulation action from the enumerated list.')
                ->required(),
            'id' => $schema->string()
                ->description('Job ID (e.g. JOB-1001), conveyor ID (CV-01), machine ID (DRL-01), crane/robot/forklift ID (CR-01/RB-01/FL-AUTO), or forklift direction (left/right/forward/back) for legacy manual mode. Omit for line-wide actions.'),
            'speed' => $schema->integer()
                ->description('Required for set_conveyor_speed and set_handling_speed (0-100). For set_auto_repeat use 1 for on or 0 for off. For legacy drive_forklift use 1-4 steps.'),
        ];
    }

    public function handle(Request $request, FactorySimulator $factory): Response
    {
        // Restrict to the local Windows machine, matching the local development setup.
        if (! in_array(request()->ip(), ['127.0.0.1', '::1'], true)) {
            return Response::error('Factory simulation controls are available only on loopback.');
        }

        try {
            $action = (string) $request->get('action');
            $id = $request->get('id');
            $speed = $request->get('speed');
            $state = $factory->dispatch($action, is_string($id) ? $id : null, $speed);

            return Response::text(json_encode([
                'message' => 'Applied '.$action.' to the local simulation.',
                'state' => $state,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
