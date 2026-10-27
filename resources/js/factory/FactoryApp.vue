<template>
  <div class="factory-shell">
    <aside class="sidebar">
      <div class="brand"><span class="brand-mark">F<span>›</span></span><span><b>FACTORYFLOW</b><small>AUTONOMOUS MANUFACTURING</small></span></div>
      <p class="nav-caption">OPERATIONS</p>
      <button v-for="link in menu" :key="link.name" type="button" class="nav-entry" :class="{selected: activeTab === link.name}" @click="activeTab = link.name">
        <span class="nav-icon">{{ link.icon }}</span><span>{{ link.name }}</span>
        <span v-if="link.name === 'Alerts' && data?.summary?.faults" class="nav-count">{{ data.summary.faults }}</span>
      </button>
      <div class="sidebar-footer"><span class="connection-dot"></span><span>LOCAL FACTORY SIMULATOR<br><small>MCP READY · PORT 8123</small></span></div>
    </aside>

    <main class="main-area">
      <header class="topbar">
        <div><div class="eyebrow">FACTORYFLOW / {{ activeTab.toUpperCase() }}</div><h1>{{ activeTab === 'Factory Floor' ? 'Autonomous factory floor' : activeTab }}</h1></div>
        <div class="topbar-right"><span class="system-pill" :class="{'estop-pill':data?.emergency_stop}"><span class="connection-dot"></span>{{ data?.emergency_stop ? 'EMERGENCY STOP' : data?.factory_running ? 'FACTORY RUNNING' : 'FACTORY STOPPED' }}</span><span class="clock">{{ time }}</span></div>
      </header>

      <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
      <div v-if="!data" class="loading-screen">Connecting to the factory…</div>
      <template v-else>
        <div class="factory-main-controls" role="group" aria-label="Factory operations">
          <button class="factory-start" type="button" :disabled="busy || data.factory_running || data.emergency_stop" @click="call('start_factory')">▶ START FACTORY</button>
          <button class="factory-stop" type="button" :disabled="busy || !data.factory_running" @click="call('stop_factory')">■ STOP FACTORY</button>
          <button class="factory-restart" type="button" :disabled="busy || data.emergency_stop" @click="restartProduction">↻ RESTART PRODUCTION</button>
          <button class="factory-emergency" type="button" :disabled="busy" @click="call(data.emergency_stop ? 'release_emergency' : 'emergency_stop')">{{ data.emergency_stop ? '✓ RELEASE E-STOP' : '⛔ EMERGENCY STOP' }}</button>
          <label class="auto-repeat-label"><input type="checkbox" :checked="data.auto_repeat" :disabled="busy" @change="call('set_auto_repeat',null,$event.target.checked ? 1 : 0)" /> Repeat orders automatically</label>
        </div>
        <p class="factory-activity-line">{{ activityMessage }} <span>· Production cycles {{ data.completed_cycles }}</span></p>
        <div class="factory-transfer-track" aria-live="off">
          <strong>TRACKED METAL PART #{{ data.transfer.serial || '—' }}</strong>
          <span>{{ transferLabel(data.transfer.phase) }}</span>
          <div class="transfer-meter"><div :style="{width: transferPercent + '%'}"></div></div>
          <small>{{ data.transfer.job_id || 'Waiting for a job and a pallet' }} · {{ data.transfer.lathe_id }}</small>
        </div>

        <section v-if="activeTab === 'Factory Floor' || activeTab === 'Dashboard'" class="metrics-grid">
          <div class="metric-card"><div class="metric-head"><span>UNITS MANUFACTURED</span><span class="metric-glyph">▦</span></div><strong>{{ data.summary.units.toLocaleString() }}</strong><small>{{ data.summary.rate.toLocaleString() }} units/hour estimated capacity</small><div class="metric-line"></div></div>
          <div class="metric-card"><div class="metric-head"><span>PRODUCTION ORDERS</span><span class="metric-glyph">◷</span></div><strong>{{ data.summary.active_jobs }} <span class="metric-suffix">active</span></strong><small>{{ data.summary.completed_jobs }} orders completed this cycle</small><div class="metric-line teal"></div></div>
          <div class="metric-card"><div class="metric-head"><span>MACHINES RUNNING</span><span class="metric-glyph">⚙</span></div><strong>{{ data.summary.running_machines }} <span class="metric-suffix">/ {{ data.machines.length }}</span></strong><small>{{ data.summary.faults }} machinery faults</small><div class="metric-line purple"></div></div>
          <div class="metric-card"><div class="metric-head"><span>FACTORY STATE</span><span class="metric-glyph">⌁</span></div><strong class="status-value">{{ lineStatus }}</strong><small>{{ data.conveyors.filter(b => b.running).length }} / {{ data.conveyors.length }} conveyors enabled</small><div class="metric-line amber"></div></div>
        </section>

        <div v-if="activeTab === 'Factory Floor'" class="workspace-grid">
          <div class="left-stack">
            <div class="panel scene-panel">
              <div class="panel-heading"><div><h2>Digital factory floor <span class="heading-tag">3D LIVE</span></h2><small>One workpiece transferred by actual crane hooks and robot grippers; no decorative robot loops</small></div><span class="mini-counter">{{ lineStatus }}</span></div>
              <FactoryScene :snapshot="data" />
              <div class="scene-legend"><span><i class="legend-dot good"></i> Running</span><span><i class="legend-dot idle"></i> Idle</span><span><i class="legend-dot fault"></i> Fault</span><span class="legend-note">Drag to rotate · Scroll to zoom</span></div>
            </div>
            <div class="panel jobs-panel"><div class="panel-heading"><div><h2>Production orders</h2><small>Jobs are scheduled automatically when you start the factory</small></div><button class="reset-button" type="button" :disabled="busy" @click="resetDemo">Reset entire demo</button></div><div class="table-scroller"><table class="jobs-table"><thead><tr><th>ORDER</th><th>PRODUCT</th><th>PROGRESS</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
              <tr v-for="job in data.jobs" :key="job.id"><td class="mono bright">{{ job.id }}</td><td>{{ job.product }}</td><td><div class="progress-wrap"><div class="progress-bar"><div :style="{width:Math.round(job.produced/job.quantity*100)+'%'}"></div></div><span class="mono">{{ job.produced }}/{{ job.quantity }}</span></div></td><td><span class="state-pill" :class="job.status">{{ job.status }}</span></td><td><button v-if="job.status === 'complete' || job.status === 'cancelled'" class="action-link restart" :disabled="busy" @click="call('restart_job',job.id)">↻ Queue again</button><button v-else-if="job.status === 'paused'" class="action-link" :disabled="busy" @click="call('resume_job',job.id)">Resume</button><button v-else-if="job.status === 'running'" class="action-link" :disabled="busy" @click="call('pause_job',job.id)">Pause</button><button v-else class="action-link" :disabled="busy" @click="call('start_job',job.id)">Start job</button></td></tr>
            </tbody></table></div></div>
          </div>
          <div class="right-stack">
            <div class="panel logistics-panel"><div class="panel-heading"><div><h2>Automatic material supply</h2><small>FL-01 → FL-AUTO → Gantry → CNC</small></div></div>
              <div class="logistics-metrics"><div><b>{{ data.material_flow.yard_pallets }}</b><small>RAW PALLETS</small></div><div><b>{{ data.material_flow.dock_pallets }}</b><small>AT DOCK</small></div><div><b>{{ data.material_flow.crane_pallets }}</b><small>CRANE BAY</small></div><div><b>{{ data.material_flow.dispatched_parts }}</b><small>DISPATCHED PARTS</small></div></div>
              <div class="autoforklift-status"><span>FL-01 autonomous yard forklift</span><b>{{ formatPhase(data.material_flow.manual_forklift.phase) }}</b></div>
              <div class="autoforklift-status"><span>FL-AUTO transfer forklift</span><b>{{ formatPhase(data.material_flow.auto_forklift.phase) }}</b></div>
              <div class="autoforklift-status"><span>Finished turned metal products</span><b>{{ data.material_flow.machined_parts }}</b></div>
              <div class="forklift-note"><span>Both forklifts move automatically while the factory runs. Demo raw stock is replenished by simulated inbound deliveries.</span></div>
            </div>
            <div class="panel controls-panel"><div class="panel-heading"><div><h2>Conveyor drives</h2><small>Individual speed controls</small></div></div><div class="conveyor-control" v-for="belt in data.conveyors" :key="belt.id"><div class="control-head"><strong class="mono bright">{{ belt.id }}</strong><span class="small-status" :class="{stopped:!belt.running}">{{ belt.running ? 'RUNNING' : 'STOPPED' }}</span></div><div class="speed-row"><span>Speed</span><strong>{{ belt.speed }}%</strong></div><input type="range" min="0" max="100" step="5" :value="belt.speed" :disabled="busy || data.emergency_stop" @change="call('set_conveyor_speed',belt.id,Number($event.target.value))" /><div class="control-bottom"><span>{{ belt.name }}</span><button :disabled="busy || data.emergency_stop" @click="call(belt.running?'stop_conveyor':'start_conveyor',belt.id)">{{ belt.running?'Stop':'Start' }}</button></div></div></div>
          </div>
        </div>

        <div v-if="activeTab === 'Dashboard'" class="dashboard-details">
          <section class="panel"><div class="panel-heading"><div><h2>Live production performance</h2><small>Real simulator data; no decorative figures</small></div><span class="mini-counter">{{ lineStatus }}</span></div>
            <div class="inventory-cards"><div><strong>{{ data.material_flow.machined_parts }}</strong><small>Parts machined in CNC lathes</small></div><div><strong>{{ data.material_flow.blanks_loaded }}</strong><small>Raw blanks loaded to CNC</small></div><div><strong>{{ data.summary.raw_pallets }}</strong><small>Pallets across logistics</small></div></div>
            <div v-for="job in data.jobs" :key="job.id" class="detail-row"><div class="detail-grow"><strong>{{ job.id }} — {{ job.product }}</strong><small>{{ job.status }} · {{ job.runs_completed }} previous completed runs</small></div><div class="job-detail-progress"><div class="progress-bar"><div :style="{width: Math.round(job.produced/job.quantity*100)+'%'}"></div></div><small>{{ job.produced }}/{{ job.quantity }}</small></div></div>
          </section>
          <section class="panel"><div class="panel-heading"><div><h2>Recent production events</h2><small>Crane, forklift, CNC and production activities</small></div></div>
            <div v-for="(event,i) in data.events.slice(0,12)" :key="i" class="detail-row"><span class="event-mark" :class="event.type"></span><span class="mono">{{ event.time }}</span><div class="detail-grow">{{ event.message }}</div></div>
          </section>
        </div>

        <section v-if="activeTab === 'Production Jobs'" class="panel detail-panel"><div class="panel-heading"><div><h2>Production order queue</h2><small>Automatically advances to the next order; repeats if enabled</small></div><button class="factory-restart" :disabled="busy" @click="restartProduction">↻ New production run</button></div><div v-for="job in data.jobs" :key="job.id" class="detail-row"><div><strong>{{ job.id }} — {{ job.product }}</strong><small>Priority {{ job.priority }} · {{ job.runs_completed }} completed previous runs</small></div><div class="job-detail-progress"><div class="progress-bar"><div :style="{width:Math.round(job.produced/job.quantity*100)+'%'}"></div></div><small>{{ job.produced }} / {{ job.quantity }}</small></div><span class="state-pill" :class="job.status">{{ job.status }}</span><button class="machine-action" @click="call(job.status==='complete' || job.status==='cancelled' ? 'restart_job' : job.status==='running' ? 'pause_job' : job.status==='paused' ? 'resume_job' : 'start_job',job.id)" :disabled="busy">{{ job.status === 'complete' || job.status==='cancelled' ? 'Queue again' : job.status === 'running' ? 'Pause' : job.status==='paused' ? 'Resume' : 'Start' }}</button></div></section>

        <section v-if="activeTab === 'Machines'" class="panel detail-panel"><div class="panel-heading"><div><h2>CNC machines, cutters, inspection and packing</h2><small>Simulated faults stop the line until reset</small></div></div><div v-for="machine in data.machines" :key="machine.id" class="detail-row"><div class="machine-indicator" :class="machine.status"></div><div class="detail-grow"><strong>{{ machine.name }}</strong><small>{{ machine.id }} · {{ machine.temperature.toFixed(1) }}°C · {{ machine.fault || 'No faults' }}</small></div><span class="state-pill" :class="machine.status">{{ machine.status }}</span><button class="machine-action" :disabled="busy" @click="call(machine.status==='fault'?'clear_fault':'inject_fault',machine.id)">{{ machine.status==='fault'?'Clear fault':'Simulate fault' }}</button></div>
          <div class="panel-heading"><h2>Robots, cranes &amp; forklifts</h2></div><div v-for="equipment in data.handling_equipment" :key="equipment.id" class="detail-row"><div class="detail-grow"><strong>{{ equipment.name }}</strong><small>{{ equipment.id }} · {{ equipment.type }} · {{ equipment.status }}</small></div><label class="equipment-speed">Speed {{ equipment.speed }}% <input type="range" min="0" max="100" step="5" :value="equipment.speed" :disabled="busy || data.emergency_stop" @change="call('set_handling_speed',equipment.id,Number($event.target.value))" /></label><button class="machine-action" :disabled="busy || data.emergency_stop" @click="call(equipment.enabled?'stop_handling':'start_handling',equipment.id)">{{ equipment.enabled?'Stop':'Start' }}</button></div></section>

        <section v-if="activeTab === 'Conveyors'" class="panel detail-panel"><div class="panel-heading"><div><h2>Conveyor systems</h2><small>Every drive controls belt animation and production interlocks</small></div></div><div v-for="belt in data.conveyors" :key="belt.id" class="detail-row"><div class="detail-grow"><strong>{{ belt.id }} — {{ belt.name }}</strong><small>{{ belt.running ? 'Drive running' : 'Drive stopped' }} · {{ belt.speed }}% speed</small></div><input type="range" min="0" max="100" step="5" :value="belt.speed" :disabled="busy || data.emergency_stop" @change="call('set_conveyor_speed',belt.id,Number($event.target.value))" /><button class="machine-action" :disabled="busy || data.emergency_stop" @click="call(belt.running?'stop_conveyor':'start_conveyor',belt.id)">{{ belt.running?'Stop':'Start' }}</button></div></section>

        <section v-if="activeTab === 'Inventory'" class="panel detail-panel"><div class="panel-heading"><div><h2>Autonomous logistics &amp; stock</h2><small>Raw metal enters as pallets of 20 metal blanks</small></div></div><div class="inventory-cards"><div><strong>{{ data.material_flow.yard_pallets }}</strong><small>Metal pallets in yard</small></div><div><strong>{{ data.material_flow.dock_pallets }}</strong><small>Pallets at AGV dock</small></div><div><strong>{{ data.material_flow.crane_pallets }}</strong><small>Pallets at crane pickup</small></div><div><strong>{{ data.material_flow.open_pallet_blanks }}</strong><small>Open pallet blanks</small></div><div><strong>{{ data.material_flow.dispatched_parts }}</strong><small>Packed and dispatched</small></div><div><strong>{{ data.material_flow.machined_parts }}</strong><small>Turned metal products</small></div></div><div class="detail-row"><strong>FL-01 · Automatic raw yard forklift</strong><span>{{ formatPhase(data.material_flow.manual_forklift.phase) }} · {{ data.material_flow.manual_forklift.cargo ? 'carrying a pallet' : 'unloaded' }}</span></div><div class="detail-row"><strong>FL-AUTO · Automated transfer forklift</strong><span>{{ formatPhase(data.material_flow.auto_forklift.phase) }} · {{ data.material_flow.auto_forklift.cargo ? 'carrying a pallet' : 'unloaded' }}</span></div></section>

        <section v-if="activeTab === 'Alerts'" class="panel detail-panel"><div class="panel-heading"><div><h2>Factory faults and alarms</h2><small>See active faults and recent alerts</small></div><span class="mini-counter">{{ data.summary.faults }} ACTIVE</span></div><div v-if="!data.summary.faults && !data.emergency_stop" class="empty-state">✓ No active faults or emergency stop.</div><div v-if="data.emergency_stop" class="detail-row"><strong>Emergency stop active</strong><button class="machine-action" @click="call('release_emergency')">Release E-stop</button></div><div v-for="machine in data.machines.filter(m => m.status === 'fault')" :key="machine.id" class="detail-row"><div class="detail-grow"><strong>{{ machine.id }} — {{ machine.name }}</strong><small>{{ machine.fault }}</small></div><button class="machine-action" :disabled="busy" @click="call('clear_fault', machine.id)">Clear fault</button></div><div class="panel-heading"><h2>Alert history</h2></div><div v-for="(event,i) in data.events.filter(e=>e.type==='alert')" :key="i" class="detail-row"><span>{{ event.time }}</span><strong>{{ event.message }}</strong></div></section>

        <section v-if="activeTab === 'AI Activity'" class="panel detail-panel"><div class="panel-heading"><div><h2>Factory events &amp; MCP activity</h2><small>UI and MCP controls both write to the same simulation state</small></div></div><div v-for="(event,i) in data.events" :key="i" class="detail-row"><span class="event-mark" :class="event.type"></span><span class="mono">{{ event.time }}</span><div class="detail-grow">{{ event.message }}</div><span class="state-pill">{{ event.type }}</span></div></section>
      </template>
    </main>
  </div>
</template>

<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import FactoryScene from './FactoryScene.vue';
const data = ref(null);
const error = ref('');
const busy = ref(false);
const activeTab = ref('Factory Floor');
const time = ref('');
const menu = [
  {name:'Factory Floor',icon:'◫'}, {name:'Dashboard',icon:'▤'},
  {name:'Production Jobs',icon:'▦'}, {name:'Machines',icon:'⚙'},
  {name:'Conveyors',icon:'⇢'}, {name:'Inventory',icon:'▣'},
  {name:'Alerts',icon:'⚠'}, {name:'AI Activity',icon:'✦'},
];
const transferPercent = computed(() => data.value?.transfer?.duration
  ? Math.min(100,Math.round(data.value.transfer.elapsed / data.value.transfer.duration * 100)) : 0);
function transferLabel(phase) {
  return ({waiting_material:'Waiting for a physical pallet',gantry_pick:'Gantry lowering hook to metal blank',
    gantry_lift:'Gantry lifting captured blank',gantry_travel:'Gantry travelling with blank',
    gantry_place:'Gantry lowering blank onto infeed',gantry_retract:'Gantry withdrawing unloaded hook',gantry_return:'Gantry returning to pallet area',belt1_cut:'Blank moves to cutter on CV-01',laser_cut:'Laser cutter sizes the blank',belt1_drill:'Blank travels to drilling station',drill:'CNC drill bores the blank',cnc_infeed:'Blank entering CNC turning centre',
    cnc_turn:'CNC chuck turning the actual blank',robot1_pick:'RB-01 grips finished metal part',
    robot1_transfer:'RB-01 places part on conveyor 2',robot1_return:'RB-01 retracts gripper after release',belt2_qc:'Part approaches inspection on CV-02',inspect:'Inspection verifies machined dimensions',belt2_finish:'Part moves to finishing station',assembly:'Finishing machine deburrs part',belt2_jib:'Part travels to jib crane pickup',
    jib_pick:'Jib crane lowers hook to part',jib_lift:'Jib crane lifts captured part',
    jib_transfer:'Jib crane carries part to conveyor 3',jib_place:'Jib crane releases part',jib_retract:'Jib crane raises empty hook',jib_return:'Jib crane returns to pickup',
    belt3:'Part travelling along conveyor 3',robot2_pick:'RB-02 grips inspected part',
    robot2_transfer:'RB-02 places part in packing station',pack_cycle:'Packing machine seals finished component',robot2_return:'RB-02 releases part and retracts'})[phase] || 'Waiting';
}
const lineStatus = computed(() => {
  if (!data.value) return 'LOADING';
  if (data.value.emergency_stop) return 'E-STOP';
  if (data.value.summary.faults) return 'FAULT';
  return data.value.factory_running ? 'RUNNING' : 'STOPPED';
});
const activityMessage = computed(() => {
  if (!data.value) return '';
  if (data.value.emergency_stop) return 'Emergency stop engaged — release then press START FACTORY.';
  if (data.value.summary.faults) return 'Machine fault — clear the fault to resume production.';
  if (!data.value.factory_running) return 'Factory is stationary. Press START FACTORY to run everything automatically.';
  const running = data.value.jobs.find(j => j.status==='running');
  return running ? `Automatic production: ${running.id} · ${running.produced}/${running.quantity} finished` : 'Autonomous material handling active.';
});
function formatPhase(value) {return (value || 'standby').replaceAll('_',' ').toUpperCase();}
function restartProduction() {
  if (window.confirm('Restart all production orders at zero? Lifetime manufactured totals will be kept.')) call('restart_production');
}
function resetDemo() {
  if (window.confirm('Reset all production history, inventory, equipment and jobs? This cannot be undone.')) call('reset_demo');
}
let interval, inFlight = false;
async function refresh() {
  if (busy.value || inFlight) return;
  inFlight = true;
  try {
    const response = await fetch('/factory/api/status',{headers:{Accept:'application/json'},cache:'no-store'});
    if (!response.ok) throw new Error(`Factory status HTTP ${response.status}`);
    const json = await response.json();
    if (!busy.value) {data.value=json;error.value='';}
  } catch(e) {error.value=e.message;} finally {inFlight=false;}
}
async function call(action,id=null,value=null) {
  if (busy.value) return;
  busy.value = true;
  try {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const response = await fetch('/factory/api/action', {
      method:'POST', headers:{'Content-Type':'application/json',Accept:'application/json',...(csrf?{'X-CSRF-TOKEN':csrf}:{})},
      body:JSON.stringify({action,id,value}),
    });
    const json = await response.json();
    if (!response.ok) throw new Error(json.message || `Action failed: HTTP ${response.status}`);
    data.value=json;
    error.value='';
  } catch(e) {error.value=e.message;} finally {busy.value=false;}
}
onMounted(() => {
  refresh();
  interval = setInterval(() => {time.value=new Date().toLocaleTimeString('en-GB');refresh();},200);
  time.value=new Date().toLocaleTimeString('en-GB');
});
onBeforeUnmount(() => clearInterval(interval));
</script>
