<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Virtual production line. State is persistent and serialized behind a single
 * flock lock; HTTP and MCP commands use identical transitions.
 */
final class FactorySimulator
{
    private const PALLET_UNITS = 20;

    public function __construct(private readonly ?string $stateDirectory = null) {}

    public function snapshot(): array
    {
        return $this->transaction(fn (array &$state): array => $state);
    }

    public function reset(): array
    {
        return $this->transaction(function (array &$state): array {
            $state = $this->initial();
            return $state;
        }, false);
    }

    public function dispatch(string $action, ?string $id = null, mixed $value = null): array
    {
        return $this->transaction(function (array &$state) use ($action, $id, $value): array {
            switch ($action) {
                case 'start_job':
                case 'pause_job':
                case 'resume_job':
                case 'cancel_job':
                case 'restart_job':
                    $job = &$this->findItem($state['jobs'], $id);
                    $allowed = [
                        'start_job' => ['queued'],
                        'pause_job' => ['running'],
                        'resume_job' => ['paused'],
                        'cancel_job' => ['queued', 'running', 'paused'],
                        'restart_job' => ['complete', 'cancelled'],
                    ];
                    if (! in_array($job['status'], $allowed[$action], true)) {
                        throw new InvalidArgumentException('Job '.$id.' cannot '.$action.' while '.$job['status'].'.');
                    }
                    if (in_array($action, ['start_job', 'resume_job'], true) && $state['emergency_stop']) {
                        throw new InvalidArgumentException('Release emergency stop first.');
                    }
                    $job['status'] = match ($action) {
                        'start_job', 'resume_job' => 'running',
                        'pause_job' => 'paused',
                        'cancel_job' => 'cancelled',
                        'restart_job' => 'queued',
                    };
                    if ($action === 'restart_job') {
                        $state['transfer'] = $this->initial()['transfer'];
                        $job['produced'] = 0;
                        $job['completed_at'] = null;
                        $state['work_seconds'] = 0;
                    }
                    if (in_array($action, ['start_job', 'resume_job'], true)) {
                        $this->startEquipment($state);
                        $state['factory_running'] = true;
                    }
                    if ($action === 'pause_job') {
                        $state['factory_running'] = false;
                        $this->stopEquipment($state);
                    }
                    $this->record($state, ucfirst(str_replace('_', ' ', $action)).': '.$id, 'job');
                    unset($job);
                    break;

                case 'reset_demo':
                    // Intentional reset. UI requests a confirmation; MCP callers must be explicit.
                    $state = $this->initial();
                    $this->record($state, 'Demonstration factory reset', 'system');
                    break;

                case 'start_conveyor':
                case 'stop_conveyor':
                    $belt = &$this->findItem($state['conveyors'], $id);
                    if ($action === 'start_conveyor') $this->ensureSafe($state);
                    $belt['running'] = $action === 'start_conveyor';
                    $this->record($state, ($belt['running'] ? 'Started ' : 'Stopped ').$id, 'control');
                    break;

                case 'set_conveyor_speed':
                    $belt = &$this->findItem($state['conveyors'], $id);
                    $belt['speed'] = $this->speed($value);
                    $this->record($state, 'Set '.$id.' speed to '.$belt['speed'].'%', 'control');
                    break;

                case 'start_handling':
                case 'stop_handling':
                    $equipment = &$this->findItem($state['handling_equipment'], $id);
                    if ($action === 'start_handling') $this->ensureSafe($state);
                    $equipment['enabled'] = $action === 'start_handling';
                    $this->record($state, ($equipment['enabled'] ? 'Started ' : 'Stopped ').$id, 'control');
                    break;

                case 'set_handling_speed':
                    $equipment = &$this->findItem($state['handling_equipment'], $id);
                    $equipment['speed'] = $this->speed($value);
                    $this->record($state, 'Set '.$id.' speed to '.$equipment['speed'].'%', 'control');
                    break;

                case 'drive_forklift':
                    $this->ensureSafe($state);
                    if ($state['factory_running']) throw new InvalidArgumentException('The forklift is under automatic control. Stop the factory to drive manually.');
                    $direction = $id ?? '';
                    if (! in_array($direction, ['left', 'right', 'forward', 'back'], true)) {
                        throw new InvalidArgumentException('Forklift direction must be left, right, forward or back.');
                    }
                    $forklift = &$state['material_flow']['manual_forklift'];
                    $steps = $value === null ? 1 : max(1, min(4, (int) $value));
                    $dx = match ($direction) { 'left' => -.55 * $steps, 'right' => .55 * $steps, default => 0.0 };
                    $dz = match ($direction) { 'forward' => -.35 * $steps, 'back' => .35 * $steps, default => 0.0 };
                    // A marked forklift aisle, separate from the conveyor and crane travel zone.
                    $forklift['x'] = round(max(-7.3, min(7.3, $forklift['x'] + $dx)), 2);
                    $forklift['z'] = round(max(-7.9, min(-6.5, $forklift['z'] + $dz)), 2);
                    $forklift['heading'] = $direction;
                    unset($forklift);
                    break;

                case 'pickup_pallet':
                    $this->ensureSafe($state);
                    if ($state['factory_running']) throw new InvalidArgumentException('The forklift is under automatic control.');
                    $flow = &$state['material_flow'];
                    if ($flow['manual_forklift']['cargo']) throw new InvalidArgumentException('Forklift is already carrying a pallet.');
                    if ($flow['yard_pallets'] < 1) throw new InvalidArgumentException('Raw metal storage is empty.');
                    if ($flow['manual_forklift']['x'] < 5.7 || abs($flow['manual_forklift']['z'] + 7.1) > .9) {
                        throw new InvalidArgumentException('Drive the manual forklift to RAW METAL (right-hand loading bay).');
                    }
                    $flow['yard_pallets']--;
                    $flow['manual_forklift']['cargo'] = true;
                    $this->record($state, 'Manual forklift picked up one pallet of '.self::PALLET_UNITS.' blanks', 'material');
                    unset($flow);
                    break;

                case 'drop_pallet':
                    $this->ensureSafe($state);
                    if ($state['factory_running']) throw new InvalidArgumentException('The forklift is under automatic control.');
                    $flow = &$state['material_flow'];
                    if (! $flow['manual_forklift']['cargo']) throw new InvalidArgumentException('Forklift has no pallet.');
                    if ($flow['manual_forklift']['x'] > -2.3 || abs($flow['manual_forklift']['z'] + 7.1) > .9) {
                        throw new InvalidArgumentException('Drive the manual forklift to the AUTO FORKLIFT DROP ZONE (left bay).');
                    }
                    $flow['dock_pallets']++;
                    $flow['manual_forklift']['cargo'] = false;
                    $this->record($state, 'Pallet delivered to automatic forklift dock', 'material');
                    unset($flow);
                    break;

                case 'inject_fault':
                    $machine = &$this->findItem($state['machines'], $id);
                    $machine['status'] = 'fault';
                    $machine['fault'] = 'Simulated sensor failure';
                    $this->record($state, $id.' fault detected', 'alert');
                    break;
                case 'clear_fault':
                    $machine = &$this->findItem($state['machines'], $id);
                    $machine['status'] = 'idle';
                    $machine['fault'] = null;
                    $this->record($state, $id.' fault cleared', 'control');
                    break;

                case 'emergency_stop':
                    $state['emergency_stop'] = true;
                    $state['factory_running'] = false;
                    $this->stopEquipment($state);
                    $this->record($state, 'EMERGENCY STOP activated: all equipment halted', 'alert');
                    break;
                case 'release_emergency':
                    $state['emergency_stop'] = false;
                    $this->record($state, 'Emergency stop released. Press Start Factory to resume.', 'control');
                    break;
                case 'start_line':
                case 'start_factory':
                    $this->ensureSafe($state);
                    $this->startEquipment($state);
                    $state['factory_running'] = true;
                    // Start always resumes an existing order, or creates a fresh run after completion.
                    foreach ($state['jobs'] as &$job) {
                        if ($job['status'] === 'paused') $job['status'] = 'running';
                    }
                    unset($job);
                    $hasPending = count(array_filter($state['jobs'], fn ($j) => in_array($j['status'], ['queued', 'running'], true))) > 0;
                    if (! $hasPending) {
                        foreach ($state['jobs'] as &$job) {
                            $job['status'] = 'queued';
                            $job['produced'] = 0;
                            $job['completed_at'] = null;
                        }
                        unset($job);
                        $state['work_seconds'] = 0;
                        $state['transfer'] = $this->initial()['transfer'];
                        $this->record($state, 'Restarted completed production orders', 'job');
                    }
                    $this->scheduleJob($state, false);
                    $this->record($state, 'START FACTORY: automated forklifts, crane, CNC, robots and production enabled', 'control');
                    break;
                case 'restart_production':
                    $this->ensureSafe($state);
                    $state['transfer'] = $this->initial()['transfer'];
                    foreach ($state['jobs'] as &$job) {
                        $job['status'] = 'queued';
                        $job['produced'] = 0;
                        $job['completed_at'] = null;
                    }
                    unset($job);
                    $state['work_seconds'] = 0;
                    $state['factory_running'] = true;
                    $this->startEquipment($state);
                    $this->scheduleJob($state, true);
                    $this->record($state, 'New production cycle started — historical output retained', 'job');
                    break;
                case 'stop_line':
                case 'stop_factory':
                    $state['factory_running'] = false;
                    $this->stopEquipment($state);
                    $this->record($state, 'Factory stopped. Production job and material positions retained.', 'control');
                    break;
                case 'set_auto_repeat':
                    $state['auto_repeat'] = in_array($value, [true, 1, '1', 'true'], true);
                    $this->record($state, 'Automatic repeat '.($state['auto_repeat'] ? 'enabled' : 'disabled'), 'control');
                    break;
                default:
                    throw new InvalidArgumentException('Unknown factory action: '.$action);
            }
            return $state;
        });
    }

    private function startEquipment(array &$state): void
    {
        foreach ($state['conveyors'] as &$belt) {
            $belt['running'] = true;
            if ($belt['speed'] === 0) $belt['speed'] = 75;
        }
        unset($belt);
        foreach ($state['handling_equipment'] as &$equipment) {
            $equipment['enabled'] = true;
            if ($equipment['speed'] === 0) $equipment['speed'] = 75;
        }
        unset($equipment);
    }

    private function stopEquipment(array &$state): void
    {
        foreach ($state['conveyors'] as &$belt) $belt['running'] = false;
        unset($belt);
        foreach ($state['handling_equipment'] as &$equipment) $equipment['enabled'] = false;
        unset($equipment);
    }

    private function scheduleJob(array &$state, bool $allowNewCycle = false): void
    {
        if (! $state['factory_running'] || $state['emergency_stop']) return;
        foreach ($state['jobs'] as $job) {
            if ($job['status'] === 'running') return;
        }
        foreach ($state['jobs'] as &$job) {
            if ($job['status'] === 'queued') {
                $job['status'] = 'running';
                $this->record($state, 'Automatically started '.$job['id'].' — '.$job['product'], 'job');
                unset($job);
                return;
            }
        }
        unset($job);
        if (! $allowNewCycle || ! $state['auto_repeat']) {
            $state['factory_running'] = false;
            $this->stopEquipment($state);
            $this->record($state, 'All production orders finished. Factory waiting for Start.', 'job');
            return;
        }
        // A complete run has finished. Requeue a fresh cycle without losing total units or run history.
        $state['completed_cycles']++;
        foreach ($state['jobs'] as &$job) {
            if (in_array($job['status'], ['complete', 'cancelled'], true)) {
                $job['status'] = 'queued';
                $job['produced'] = 0;
                $job['completed_at'] = null;
            }
        }
        unset($job);
        $state['work_seconds'] = 0;
        $this->record($state, 'Automatic production cycle '.$state['completed_cycles'].' complete; scheduling next run', 'job');
        foreach ($state['jobs'] as &$job) {
            if ($job['status'] === 'queued') {
                $job['status'] = 'running';
                $this->record($state, 'Automatically started '.$job['id'], 'job');
                break;
            }
        }
        unset($job);
    }

    private function ensureSafe(array $state): void
    {
        if ($state['emergency_stop']) throw new InvalidArgumentException('Release emergency stop first.');
    }

    private function speed(mixed $value): int
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            throw new InvalidArgumentException('Speed must be between 0 and 100.');
        }
        return (int) round((float) $value);
    }

    private function transaction(callable $operation, bool $advance = true): array
    {
        $directory = $this->stateDirectory ?? storage_path('app/factoryflow');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create factory state directory.');
        }
        $path = $directory.'/state.json';
        $handle = fopen($directory.'/state.lock', 'c+');
        if ($handle === false) throw new RuntimeException('Cannot open factory state lock.');
        try {
            if (! flock($handle, LOCK_EX)) throw new RuntimeException('Cannot lock factory state.');
            $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (! is_array($state)) $state = $this->initial();
            $this->upgrade($state);
            if ($advance) $this->advance($state);
            $result = $operation($state);
            if (file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Cannot persist factory state.');
            }
            return $this->present($result);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function upgrade(array &$state): void
    {
        $initial = $this->initial();
        $state['factory_running'] ??= count(array_filter($state['jobs'] ?? [], fn ($j) => $j['status'] === 'running')) > 0;
        $state['auto_repeat'] ??= false;
        // Previous builds enabled endless repetition by default. Switch it off
        // once when upgrading so production stops at the end of the order queue.
        if (! ($state['_sync_v5'] ?? false)) {
            $state['auto_repeat'] = false;
            $state['_sync_v5'] = true;
        }
        $state['completed_cycles'] ??= 0;
        $state['handling_equipment'] ??= $initial['handling_equipment'];
        foreach ($initial['handling_equipment'] as $handler) {
            if (! in_array($handler['id'], array_column($state['handling_equipment'], 'id'), true)) {
                $state['handling_equipment'][] = $handler;
            }
        }
        $state['material_flow'] ??= $initial['material_flow'];
        foreach ($initial['material_flow'] as $key => $value) $state['material_flow'][$key] ??= $value;
        $state['material_flow']['auto_forklift'] = array_merge($initial['material_flow']['auto_forklift'], $state['material_flow']['auto_forklift'] ?? []);
        $state['material_flow']['manual_forklift'] = array_merge($initial['material_flow']['manual_forklift'], $state['material_flow']['manual_forklift'] ?? []);
        if ($state['factory_running'] && $state['emergency_stop']) $state['factory_running'] = false;
        $state['transfer'] ??= $initial['transfer'];
        foreach ($initial['transfer'] as $key => $value) $state['transfer'][$key] ??= $value;
        $state['material_flow']['dispatched_parts'] ??= 0;
        $state['material_flow']['manual_forklift']['phase'] ??= 'at_yard';
        $state['material_flow']['manual_forklift']['progress'] ??= 0.0;
        $state['work_seconds'] ??= 0.0;
        foreach ($initial['machines'] as $machine) {
            if (! in_array($machine['id'], array_column($state['machines'], 'id'), true)) $state['machines'][] = $machine;
        }
        foreach ($state['jobs'] as &$job) {
            $job['runs_completed'] ??= ($job['status'] === 'complete' ? 1 : 0);
            $product = match ($job['id']) {
                'JOB-1001' => 'Turned Steel Shaft',
                'JOB-1002' => 'Aluminium Coupling',
                'JOB-1003' => 'Brass Bush',
                default => $job['product'],
            };
            $job['product'] = $product;
        }
        unset($job);
    }

    private function lineReady(array $state): bool
    {
        return $state['factory_running'] && ! $state['emergency_stop']
            && count(array_filter($state['conveyors'], fn ($b) => $b['running'] && $b['speed'] > 0)) === 3
            && count(array_filter($state['machines'], fn ($m) => $m['status'] === 'fault')) === 0;
    }

    private function enabled(array $state, string $id): bool
    {
        foreach ($state['handling_equipment'] as $equipment) {
            if ($equipment['id'] === $id) return $equipment['enabled'] && $equipment['speed'] > 0;
        }
        return false;
    }

    /**
     * Server-authoritative handling cycle. Only ONE visible workpiece is in flight.
     * Forklifts replenish pallet queues, the crane lifts a specific blank, the CNC
     * processes it, RB-01 loads conveyor 2, the jib transfers to conveyor 3,
     * and RB-02 takes it to packing. A unit counts only after its final handoff.
     * This replaces the old free-running sine-wave animations and fake output rate.
     */
    private const TRANSFER_STEPS = [
        'gantry_pick' => 0.9, 'gantry_lift' => 0.7,
        'gantry_travel' => 1.5, 'gantry_place' => 0.8,
        'gantry_retract' => 0.6, 'gantry_return' => 1.1,
        'belt1_cut' => 1.0, 'laser_cut' => 1.2,
        'belt1_drill' => 1.1, 'drill' => 1.3,
        'cnc_infeed' => 0.9, 'cnc_turn' => 2.4,
        'robot1_pick' => 0.9, 'robot1_transfer' => 1.5, 'robot1_return' => 0.7,
        'belt2_qc' => 1.0, 'inspect' => 1.0,
        'belt2_finish' => 1.0, 'assembly' => 1.2, 'belt2_jib' => 1.0, 'jib_pick' => 0.9,
        'jib_lift' => 0.6, 'jib_transfer' => 1.3, 'jib_place' => 0.7,
        'jib_retract' => 0.6, 'jib_return' => 1.1,
        'belt3' => 1.7, 'robot2_pick' => 0.9,
        'robot2_transfer' => 1.5, 'pack_cycle' => 1.0, 'robot2_return' => 0.7,
    ];

    private function advance(array &$state): void
    {
        $now = microtime(true);
        $dt = min(3.0, max(0.0, $now - (float) ($state['last_tick'] ?? $now)));
        $state['last_tick'] = $now;
        $this->scheduleJob($state, true);
        $jobIndex = null;
        foreach ($state['jobs'] as $i => $job) {
            if ($job['status'] === 'running') { $jobIndex = $i; break; }
        }
        $moving = $this->lineReady($state) && $jobIndex !== null;
        $flow = &$state['material_flow'];

        // Forklifts are task-driven. They wait at their bays when downstream
        // buffers are full; they never circulate just for decoration.
        if ($moving && $this->enabled($state, 'FL-01')) {
            $truck = &$flow['manual_forklift']; // Retain v4 state key for migrations.
            $phase = $truck['phase'];
            $canCollect = ($flow['dock_pallets'] < 2 && $flow['crane_pallets'] < 2);
            if ($phase !== 'at_yard' || $canCollect) {
                $truck['progress'] += $dt * $this->equipmentSpeed($state, 'FL-01') / 100;
                $durations = ['at_yard' => 0.5, 'to_dock' => 4.0, 'at_dock' => 0.5, 'to_yard' => 4.0];
                for ($i=0; $i<6; $i++) {
                    $phase = $truck['phase'];
                    $duration = $durations[$phase];
                    if ($truck['progress'] < $duration) break;
                    if ($phase === 'at_yard') {
                        if ($flow['yard_pallets'] <= 0) {
                            $flow['yard_pallets'] += 8;
                            $this->record($state, '8 pallets of metal blanks replenished in virtual warehouse', 'material');
                        }
                        $flow['yard_pallets']--;
                        $truck['cargo'] = true;
                        $truck['phase'] = 'to_dock';
                        $this->record($state, 'FL-01 loaded pallet of 20 metal blanks', 'material');
                    } elseif ($phase === 'to_dock') {
                        $truck['phase'] = 'at_dock';
                    } elseif ($phase === 'at_dock') {
                        $flow['dock_pallets']++;
                        $truck['cargo'] = false;
                        $truck['phase'] = 'to_yard';
                        $this->record($state, 'FL-01 deposited its pallet in AGV dock', 'material');
                    } else {
                        $truck['phase'] = 'at_yard';
                    }
                    $truck['progress'] -= $duration;
                }
            }
            $phase=$truck['phase'];
            $truck['x'] = round(match ($phase) {
                'at_yard' => 6.4, 'to_dock' => 6.4-9.55*min(1,$truck['progress']/4),
                'at_dock' => -3.15, 'to_yard' => -3.15+9.55*min(1,$truck['progress']/4),
            },2);
            $truck['z'] = -7.1;
            $truck['heading'] = in_array($phase, ['to_dock','at_dock'],true) ? 'left' : 'right';
            unset($truck);
        }
        if ($moving && $this->enabled($state, 'FL-AUTO')) {
            $truck = &$flow['auto_forklift'];
            $phase=$truck['phase'];
            $canCollect = $flow['dock_pallets'] > 0 && $flow['crane_pallets'] < 2;
            if ($phase !== 'at_dock' || $canCollect) {
                $truck['progress'] += $dt * $this->equipmentSpeed($state,'FL-AUTO')/100;
                $durations=['at_dock'=>0.5,'to_crane'=>2.5,'unload'=>0.5,'returning'=>2.5];
                for($i=0;$i<8;$i++) {
                    $phase=$truck['phase'];
                    $duration=$durations[$phase];
                    if ($truck['progress'] < $duration) break;
                    if ($phase === 'at_dock') {
                        if ($flow['dock_pallets'] === 0 || $flow['crane_pallets'] >= 2) {
                            $truck['progress'] = 0;
                            break;
                        }
                        $flow['dock_pallets']--;
                        $truck['cargo']=true;
                        $truck['phase']='to_crane';
                    } elseif ($phase === 'to_crane') {
                        $truck['phase']='unload';
                    } elseif ($phase === 'unload') {
                        $flow['crane_pallets']++;
                        $truck['cargo']=false;
                        $truck['phase']='returning';
                        $this->record($state, 'FL-AUTO deposited pallet at gantry pickup', 'material');
                    } else {
                        $truck['phase']='at_dock';
                    }
                    $truck['progress'] -= $duration;
                }
            }
            unset($truck);
        }

        $work = &$state['transfer'];
        if ($moving) {
            if ($work['phase'] === 'waiting_material') {
                if ($flow['open_pallet_blanks'] < 1 && $flow['crane_pallets'] > 0) {
                    $flow['crane_pallets']--;
                    $flow['open_pallet_blanks'] = self::PALLET_UNITS;
                    $this->record($state, 'Gantry ready: one new pallet opened', 'material');
                }
                if ($flow['open_pallet_blanks'] > 0 && $this->enabled($state, 'CR-01')) {
                    $flow['open_pallet_blanks']--;
                    $work['serial']++;
                    $work['phase']='gantry_pick';
                    $work['elapsed']=0.0;
                    $work['job_id']=$state['jobs'][$jobIndex]['id'];
                    $work['lathe_id'] = $work['serial'] % 2 ? 'LAT-01' : 'LAT-02';
                    $this->record($state, 'Part #'.$work['serial'].' assigned: crane pickup → '.$work['lathe_id'], 'material');
                }
            } else {
                $speed = $this->transferSpeed($state,$work['phase']);
                if ($speed > 0) {
                    // Allow a capped time slice; preserve exact part custody when stages change.
                    $remaining = $dt*$speed;
                    $order=array_keys(self::TRANSFER_STEPS);
                    for($i=0;$i<20 && $remaining>0.000001 && $work['phase']!=='waiting_material';$i++) {
                        $phase=$work['phase'];
                        $duration=self::TRANSFER_STEPS[$phase];
                        $advance=min($remaining,max(0.0,$duration-$work['elapsed']));
                        $work['elapsed'] += $advance;
                        $remaining -= $advance;
                        if ($work['elapsed'] + 0.000001 < $duration) break;
                        $index=array_search($phase,$order,true);
                        $work['phase']=$order[$index+1]??'packed';
                        $work['elapsed']=0.0;
                        if ($phase==='gantry_place') $flow['blanks_loaded']++;
                        if ($phase==='cnc_turn') $flow['machined_parts']++;
                        if ($work['phase']==='packed') {
                            $job=&$state['jobs'][$jobIndex];
                            $job['produced']++;
                            $state['units_produced']++;
                            $flow['dispatched_parts']++;
                            $this->record($state, 'RB-02 packed part #'.$work['serial'].' for '.$job['id'], 'material');
                            if ($job['produced'] >= $job['quantity']) {
                                $job['status']='complete';
                                $job['runs_completed']++;
                                $job['completed_at']=Carbon::now()->toIso8601String();
                                $this->record($state,$job['id'].' completed: '.$job['quantity'].' actual part handoffs', 'job');
                            }
                            unset($job);
                            $work['phase']='waiting_material';
                            $work['elapsed']=0.0;
                            $work['job_id']=null;
                            break;
                        }
                    }
                }
            }
        }
        foreach ($state['machines'] as &$machine) {
            if ($machine['status'] === 'fault') continue;
            $stage = $work['phase'];
            $machine['status'] = $moving && (
                (($machine['id'] === $work['lathe_id']) && $stage === 'cnc_turn') ||
                ($machine['id'] === 'CUT-01' && $stage === 'laser_cut') ||
                ($machine['id'] === 'DRL-01' && $stage === 'drill') ||
                ($machine['id'] === 'ASM-01' && $stage === 'assembly') ||
                ($machine['id'] === 'PKG-01' && $stage === 'pack_cycle') ||
                ($machine['id'] === 'QC-01' && $stage === 'inspect')
            ) ? 'running' : 'idle';
            $target=$machine['status']==='running'?58:24;
            $machine['temperature']=round($machine['temperature']+($target-$machine['temperature'])*min(1,$dt/35),1);
        }
        unset($machine,$work,$flow);
        if ($state['factory_running'] && ! $state['emergency_stop']) $this->scheduleJob($state,true);
    }

    private function transferSpeed(array $state,string $phase): float
    {
        if (str_starts_with($phase,'gantry_')) return $this->enabled($state,'CR-01') ? $this->equipmentSpeed($state,'CR-01')/75 : 0.0;
        if (str_starts_with($phase,'jib_')) return $this->enabled($state,'CR-02') ? $this->equipmentSpeed($state,'CR-02')/75 : 0.0;
        if (str_starts_with($phase,'robot1_')) return $this->enabled($state,'RB-01') ? $this->equipmentSpeed($state,'RB-01')/75 : 0.0;
        if (str_starts_with($phase,'robot2_')) return $this->enabled($state,'RB-02') ? $this->equipmentSpeed($state,'RB-02')/75 : 0.0;
        $belt = match ($phase) {
            'belt1_cut','belt1_drill','cnc_infeed'=>0,
            'belt2_qc','belt2_finish','belt2_jib'=>1,
            'belt3'=>2, default=>null
        };
        if ($belt!==null) return $state['conveyors'][$belt]['running'] ? $state['conveyors'][$belt]['speed']/75 : 0.0;
        return 1.0;
    }

    private function equipmentSpeed(array $state, string $id): int
    {
        foreach ($state['handling_equipment'] as $equipment) if ($equipment['id'] === $id) return (int) $equipment['speed'];
        return 0;
    }

    private function present(array $state): array
    {
        $flow = $state['material_flow'];
        $hasJob = count(array_filter($state['jobs'], fn ($j) => $j['status'] === 'running')) > 0;
        $lineReady = $this->lineReady($state);
        foreach ($state['handling_equipment'] as &$equipment) {
            $phase = $state['transfer']['phase'];
            $busy = match ($equipment['id']) {
                'CR-01' => str_starts_with($phase,'gantry_'),
                'CR-02' => str_starts_with($phase,'jib_'),
                'RB-01' => str_starts_with($phase,'robot1_'),
                'RB-02' => str_starts_with($phase,'robot2_'),
                'FL-01' => in_array($flow['manual_forklift']['phase'],['to_dock','at_dock','to_yard'],true),
                'FL-AUTO' => in_array($flow['auto_forklift']['phase'],['to_crane','unload','returning'],true),
                default => false,
            };
            $equipment['status'] = $equipment['enabled'] && $equipment['speed'] > 0 && $hasJob && $lineReady && $busy
                ? 'working' : ($equipment['enabled'] ? 'standby' : 'stopped');
        }
        unset($equipment);
        $state['transfer']['duration'] = self::TRANSFER_STEPS[$state['transfer']['phase']] ?? 0.0;
        $secondsPerPart=0.0;
        foreach (self::TRANSFER_STEPS as $phase => $duration) {
            $speed=$this->transferSpeed($state,$phase);
            if ($speed<=0.0) { $secondsPerPart=0.0; break; }
            $secondsPerPart+=$duration/$speed;
        }
        $state['summary'] = [
            'units' => $state['units_produced'],
            'active_jobs' => count(array_filter($state['jobs'], fn ($j) => $j['status'] === 'running')),
            'completed_jobs' => count(array_filter($state['jobs'], fn ($j) => $j['status'] === 'complete')),
            'running_machines' => count(array_filter($state['machines'], fn ($m) => $m['status'] === 'running')),
            'faults' => count(array_filter($state['machines'], fn ($m) => $m['status'] === 'fault')),
            'rate' => $lineReady && $hasJob && $secondsPerPart>0 ? (int) round(3600/$secondsPerPart) : 0,
            'raw_pallets' => $flow['yard_pallets'] + $flow['dock_pallets'] + $flow['crane_pallets'] + ($flow['manual_forklift']['cargo'] ? 1 : 0) + ($flow['auto_forklift']['cargo'] ? 1 : 0),
            'factory_running' => $state['factory_running'],
            'cycles' => $state['completed_cycles'],
        ];
        return $state;
    }

    private function &findItem(array &$items, ?string $id): array
    {
        foreach ($items as &$item) if ($item['id'] === $id) return $item;
        throw new InvalidArgumentException('Unknown factory item: '.($id ?? '(none)'));
    }

    private function record(array &$state, string $message, string $type): void
    {
        array_unshift($state['events'], ['time' => Carbon::now()->format('H:i:s'), 'type' => $type, 'message' => $message]);
        $state['events'] = array_slice($state['events'], 0, 32);
    }

    private function initial(): array
    {
        $machines = [
            ['CUT-01', 'Laser Cutter'], ['DRL-01', 'CNC Drilling'],
            ['LAT-01', 'CNC Turning Centre A'], ['LAT-02', 'CNC Turning Centre B'],
            ['ASM-01', 'Robot Assembly'], ['QC-01', 'Quality Inspection'], ['PKG-01', 'Auto Packing'],
        ];
        return [
            'last_tick' => microtime(true),
            'factory_running' => false,
            'auto_repeat' => false,
            '_sync_v5' => true,
            'completed_cycles' => 0,
            'work_seconds' => 0.0,
            'transfer' => ['serial'=>0,'phase'=>'waiting_material','elapsed'=>0.0,'job_id'=>null,'lathe_id'=>'LAT-01'],
            'units_produced' => 0,
            'emergency_stop' => false,
            'handling_equipment' => [
                ['id' => 'CR-01', 'name' => 'Gantry loading crane', 'type' => 'crane', 'enabled' => false, 'speed' => 75],
                ['id' => 'CR-02', 'name' => 'Jib crane', 'type' => 'crane', 'enabled' => false, 'speed' => 55],
                ['id' => 'RB-01', 'name' => 'Assembly robot', 'type' => 'robot', 'enabled' => false, 'speed' => 70],
                ['id' => 'RB-02', 'name' => 'Packing robot', 'type' => 'robot', 'enabled' => false, 'speed' => 70],
                ['id' => 'FL-01', 'name' => 'Autonomous yard forklift', 'type' => 'forklift', 'enabled' => false, 'speed' => 85],
                ['id' => 'FL-AUTO', 'name' => 'Autonomous transfer forklift', 'type' => 'forklift', 'enabled' => false, 'speed' => 85],
            ],
            'material_flow' => [
                'yard_pallets' => 8, 'dock_pallets' => 1, 'crane_pallets' => 0,
                'open_pallet_blanks' => 0, 'infeed_blanks' => 0,
                'blanks_loaded' => 0, 'machined_parts' => 0, 'dispatched_parts' => 0,
                'crane_seconds' => 0.0,
                'manual_forklift' => ['x' => 6.4, 'z' => -7.1, 'heading' => 'left', 'cargo' => false, 'phase' => 'at_yard', 'progress' => 0.0],
                'auto_forklift' => ['phase' => 'at_dock', 'progress' => 0.0, 'cargo' => false],
            ],
            'conveyors' => array_map(fn ($n) => ['id' => 'CV-0'.$n, 'name' => 'Conveyor 0'.$n, 'speed' => 75, 'running' => false], [1, 2, 3]),
            'machines' => array_map(fn ($m) => ['id' => $m[0], 'name' => $m[1], 'status' => 'idle', 'temperature' => 24.0, 'fault' => null], $machines),
            'jobs' => [
                ['id' => 'JOB-1001', 'product' => 'Turned Steel Shaft', 'quantity' => 50, 'produced' => 0, 'priority' => 'High', 'status' => 'queued', 'completed_at' => null, 'runs_completed' => 0],
                ['id' => 'JOB-1002', 'product' => 'Aluminium Coupling', 'quantity' => 30, 'produced' => 0, 'priority' => 'Medium', 'status' => 'queued', 'completed_at' => null, 'runs_completed' => 0],
                ['id' => 'JOB-1003', 'product' => 'Brass Bush', 'quantity' => 100, 'produced' => 0, 'priority' => 'Low', 'status' => 'queued', 'completed_at' => null, 'runs_completed' => 0],
            ],
            'events' => [['time' => Carbon::now()->format('H:i:s'), 'type' => 'system', 'message' => 'Factory ready. Press START FACTORY to run full autonomous production']],
        ];
    }
}
