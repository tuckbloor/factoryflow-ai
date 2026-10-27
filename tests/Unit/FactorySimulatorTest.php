<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FactorySimulator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FactorySimulatorTest extends TestCase
{
    private string $directory;
    private FactorySimulator $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/factoryflow-sync-'.bin2hex(random_bytes(8));
        $this->factory = new FactorySimulator($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        if (is_dir($this->directory)) rmdir($this->directory);
        parent::tearDown();
    }

    public function test_factory_starts_stopped_with_no_free_running_part_or_repeat(): void
    {
        $state=$this->factory->snapshot();
        self::assertFalse($state['factory_running']);
        self::assertFalse($state['auto_repeat']);
        self::assertSame('waiting_material',$state['transfer']['phase']);
        self::assertSame(0,$state['summary']['units']);
    }

    public function test_start_factory_enables_all_drives_and_first_order(): void
    {
        $state=$this->factory->dispatch('start_factory');
        self::assertTrue($state['factory_running']);
        self::assertSame('running',$state['jobs'][0]['status']);
        self::assertCount(6,$state['handling_equipment']);
        self::assertSame(3,count(array_filter($state['conveyors'],fn($b)=>$b['running'])));
    }

    public function test_material_is_delivered_and_one_part_passes_all_transfer_stages(): void
    {
        $this->factory->dispatch('start_factory');
        $phases=[];$state=null;
        for($i=0;$i<250;$i++) {
            $state=$this->tick();
            $phases[$state['transfer']['phase']]=true;
            if($state['summary']['units']>0) break;
        }
        self::assertNotNull($state);
        self::assertArrayHasKey('gantry_pick',$phases);
        self::assertArrayHasKey('laser_cut',$phases);
        self::assertArrayHasKey('drill',$phases);
        self::assertArrayHasKey('cnc_turn',$phases);
        self::assertArrayHasKey('robot1_transfer',$phases);
        self::assertArrayHasKey('inspect',$phases);
        self::assertArrayHasKey('assembly',$phases);
        self::assertArrayHasKey('jib_transfer',$phases);
        self::assertArrayHasKey('robot2_transfer',$phases);
        self::assertSame(1,$state['summary']['units']);
        self::assertSame(1,$state['material_flow']['dispatched_parts']);
        self::assertGreaterThanOrEqual(1,$state['material_flow']['machined_parts']);
    }

    public function test_units_are_not_counted_during_machining(): void
    {
        $this->factory->dispatch('start_factory');
        for($i=0;$i<50;$i++) {
            $state=$this->tick();
            if($state['transfer']['phase']==='cnc_turn') {
                self::assertSame(0,$state['jobs'][0]['produced']);
                self::assertSame('running',$this->lathe($state,$state['transfer']['lathe_id'])['status']);
                return;
            }
        }
        self::fail('CNC stage did not start');
    }

    public function test_stopped_robot_holds_part_at_the_real_handoff(): void
    {
        $this->factory->dispatch('start_factory');
        for($i=0;$i<110;$i++) {
            $state=$this->tick();
            if($state['transfer']['phase']==='robot1_pick') break;
        }
        self::assertSame('robot1_pick',$state['transfer']['phase']);
        $state=$this->factory->dispatch('stop_handling','RB-01');
        $before=$state['transfer']['elapsed'];
        for($i=0;$i<5;$i++) $state=$this->tick();
        self::assertSame('robot1_pick',$state['transfer']['phase']);
        self::assertEqualsWithDelta($before,$state['transfer']['elapsed'],.001);
        self::assertSame(0,$state['summary']['units']);
        $state=$this->factory->dispatch('start_handling','RB-01');
        self::assertTrue($state['handling_equipment'][2]['enabled']);
    }

    public function test_stop_preserves_exact_part_phase_and_resume_continues(): void
    {
        $this->factory->dispatch('start_factory');
        for($i=0;$i<6;$i++) $state=$this->tick();
        $stopped=$this->factory->dispatch('stop_factory');
        self::assertFalse($stopped['factory_running']);
        for($i=0;$i<5;$i++) $state=$this->tick();
        self::assertSame($stopped['transfer']['phase'],$state['transfer']['phase']);
        self::assertEqualsWithDelta($stopped['transfer']['elapsed'],$state['transfer']['elapsed'],.001);
        self::assertTrue($this->factory->dispatch('start_factory')['factory_running']);
    }

    public function test_machine_fault_blocks_all_transfers_until_cleared(): void
    {
        $this->factory->dispatch('start_factory');
        for($i=0;$i<6;$i++) $state=$this->tick();
        $fault=$this->factory->dispatch('inject_fault','LAT-01');
        for($i=0;$i<5;$i++) $state=$this->tick();
        self::assertSame($fault['transfer']['phase'],$state['transfer']['phase']);
        self::assertSame('fault',$this->lathe($state,'LAT-01')['status']);
        $this->factory->dispatch('clear_fault','LAT-01');
        $state=$this->tick();
        self::assertTrue($state['factory_running']);
    }

    public function test_auto_repeat_is_explicitly_opt_in(): void
    {
        self::assertFalse($this->factory->snapshot()['auto_repeat']);
        self::assertTrue($this->factory->dispatch('set_auto_repeat',null,1)['auto_repeat']);
        self::assertFalse($this->factory->dispatch('set_auto_repeat',null,0)['auto_repeat']);
    }

    public function test_restart_resets_order_not_lifetime_counter(): void
    {
        $this->factory->dispatch('start_factory');
        for($i=0;$i<130;$i++) $state=$this->tick();
        self::assertGreaterThan(0,$state['summary']['units']);
        $restart=$this->factory->dispatch('restart_production');
        self::assertSame(0,$restart['jobs'][0]['produced']);
        self::assertSame($state['summary']['units'],$restart['summary']['units']);
        self::assertSame('waiting_material',$restart['transfer']['phase']);
    }

    public function test_all_orders_complete_and_factory_stops_without_unrequested_repetition(): void
    {
        $this->factory->snapshot();
        $file=$this->directory.'/state.json';
        $saved=json_decode((string)file_get_contents($file),true);
        foreach($saved['jobs'] as &$job) $job['quantity']=1;
        unset($job);
        file_put_contents($file,json_encode($saved));
        $this->factory->dispatch('start_factory');
        for($i=0;$i<600;$i++) {
            $state=$this->tick();
            if(!$state['factory_running']) break;
        }
        self::assertFalse($state['factory_running']);
        self::assertSame(3,$state['summary']['units']);
        self::assertSame(3,$state['summary']['completed_jobs']);
        self::assertSame(0,$state['completed_cycles']);
    }

    public function test_emergency_stop_blocks_restart_until_released(): void
    {
        $this->factory->dispatch('start_factory');
        self::assertTrue($this->factory->dispatch('emergency_stop')['emergency_stop']);
        $this->expectException(InvalidArgumentException::class);
        $this->factory->dispatch('start_factory');
    }

    private function lathe(array $state,string $id): array
    {
        return array_values(array_filter($state['machines'],fn($m)=>$m['id']===$id))[0];
    }

    private function tick(): array
    {
        $path=$this->directory.'/state.json';
        $state=json_decode((string)file_get_contents($path),true);
        $state['last_tick']=microtime(true)-0.45;
        file_put_contents($path,json_encode($state));
        return $this->factory->snapshot();
    }
}
