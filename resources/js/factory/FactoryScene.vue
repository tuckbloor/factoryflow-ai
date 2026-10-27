<template>
  <div class="scene-host" ref="host" tabindex="0" @keydown="onKeyDown" @pointerdown="focusFactory">
    <div class="scene-hud"><span class="live-dot"></span> LIVE FACTORY · 2 CNC LATHES · 2 FORKLIFTS · CRANES · ROBOTS <span class="hud-separator">/</span> FULL AUTONOMY · DRAG TO ROTATE · SCROLL TO ZOOM</div>
    <div class="scene-bottom-label"><span>01</span> FORKLIFT PALLETS <span class="dash-arrow">→</span> GANTRY LOAD <span class="dash-arrow">→</span> CNC TURNING <span class="dash-arrow">→</span> ROBOT HANDLING <span class="dash-arrow">→</span> DISPATCH</div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { positiveWrap, smoothAlpha, advanceDisplayElapsed } from './motion.mjs';

const props = defineProps({ snapshot: { type: Object, default: null } });
const emit = defineEmits(['control']);
function focusFactory() { host.value?.focus({ preventScroll: true }); }
function onKeyDown() { /* Camera interaction only. Material transport is fully autonomous. */ }
const host = ref(null);
let renderer, scene, camera, controls, observer, frame;
const belts = [];
const machines = [];
const products = [];
let activePart = null;
let cncShuttle = null;
const processTools = {};
let partShape = null;
let deliveredPart = false;
const robots = [];
let gantry, jib, manualForklift, autoForklift;
const lathes = [];
const stockPallets = [];
let dockPallet, cranePallet;
const progress = { belts: [0, 0, 0], lathe: 0 };
let receivedSnapshotAt = 0;
const motionClock = { key: null, elapsed: 0, previousSerial: null };
// The stripe track is shorter than the belt deck: no moving marker can escape the floor.
const STRIPE_TRACK_START = -7.75;
const STRIPE_TRACK_LENGTH = 15.50;
const pos = (x,y,z) => new THREE.Vector3(x,y,z);
const mix = (a,b,t) => a.clone().lerp(b,Math.max(0,Math.min(1,t)));
const eased = t => t*t*(3-2*t);
const clamp01 = v => Math.max(0,Math.min(1,v));
const clock = new THREE.Clock();
const mat = (color, metalness = .3, roughness = .6) => new THREE.MeshStandardMaterial({ color, metalness, roughness });

function cuboid(parent, w, h, d, x, y, z, color, metalness = .35) {
  const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), mat(color, metalness));
  mesh.position.set(x, y, z);
  mesh.castShadow = true;
  mesh.receiveShadow = true;
  parent.add(mesh);
  return mesh;
}
function cylinder(parent, radius, height, x, y, z, color, segments = 14) {
  const mesh = new THREE.Mesh(new THREE.CylinderGeometry(radius, radius, height, segments), mat(color, .65, .35));
  mesh.position.set(x, y, z);
  mesh.castShadow = true;
  parent.add(mesh);
  return mesh;
}
function floorLabel(message, x, y, z, w = 2.4) {
  const canvas = document.createElement('canvas');
  canvas.width = 512;
  canvas.height = 112;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#14283b'; ctx.fillRect(0, 0, 512, 112);
  ctx.strokeStyle = '#4a92a4'; ctx.lineWidth = 4; ctx.strokeRect(2, 2, 508, 108);
  ctx.font = 'bold 42px Arial'; ctx.textAlign = 'center'; ctx.fillStyle = '#effcff';
  ctx.fillText(message, 256, 72);
  const texture = new THREE.CanvasTexture(canvas);
  const plane = new THREE.Mesh(new THREE.PlaneGeometry(w, .6), new THREE.MeshBasicMaterial({ map: texture, side: THREE.DoubleSide }));
  plane.position.set(x, y, z); plane.rotation.x = -Math.PI / 2;
  scene.add(plane);
}
function createFloor() {
  cuboid(scene, 24.0, .3, 18.4, 0, -.3, 0, '#1b2938');
  const grid = new THREE.GridHelper(23, 24, '#536778', '#2a3c4c');
  grid.position.y = -.14; scene.add(grid);
  for (const x of [-11.25, 11.25]) cuboid(scene, .16, .05, 17.7, x, -.07, 0, '#efc55b');
  for (const z of [-8.4, 8.4]) cuboid(scene, 22.5, .05, .14, 0, -.07, z, '#efc55b');
  // Safety zones and the cross-aisle are deliberately distinct from moving conveyor lanes.
  for (const z of [-6.55, 2.2, 6.7]) {
    for (let x = -7.8; x <= 8.1; x += 1.0) {
      const dash = cuboid(scene, .48, .012, .07, x, -.12, z, '#b3c1c9');
      dash.rotation.y = -.3;
    }
  }
}
function createConveyor(index, z) {
  const group = new THREE.Group(); group.position.z = z; scene.add(group);
  cuboid(group, 16.7, .35, 1.78, 0, .65, 0, '#304756', .78);
  cuboid(group, 16.35, .06, 1.49, 0, .865, 0, '#172833', .45);
  const rollers = [];
  for (let i = 0; i < 24; i++) {
    const roller = cylinder(group, .06, 1.47, -8.0 + i * .69, .92, 0, '#819bac', 12);
    roller.rotation.x = Math.PI / 2;
    rollers.push(roller);
  }
  // Repeating yellow belt markers make motion visible even without a product on it.
  const stripes = [];
  for (let i = 0; i < 22; i++) {
    const stripe = cuboid(group, .1, .018, 1.28, -7.75 + i * .73, 1.01, 0, '#e2b458');
    stripes.push(stripe);
  }
  for (const side of [-.89, .89]) cuboid(group, 16.85, .36, .12, 0, 1.0, side, '#a7b9c6', .7);
  for (const x of [-7.25, -3, 1, 5.2, 7.25]) {
    for (const side of [-.67, .67]) cuboid(group, .15, .74, .15, x, .19, side, '#718396');
  }
  // The belt has a visible industrial drive motor and warning cap.
  cuboid(group, .67, .48, .62, 8.1, .55, .86, '#52798d', .72);
  cylinder(group, .18, .58, 8.08, .57, 1.2, '#eebf5c');
  belts.push({ index, rollers, stripes });
  // Workpieces are created by the shared transfer state, not looping belt sprites.
  floorLabel(`CV-0${index + 1}`, -6.45, -.1, z + 1.45);
}
function createMachine(id, name, x, z, tint = '#537d91') {
  cuboid(scene, 2, 1.55, 2.15, x, 1.37, z, tint, .72);
  cuboid(scene, 2.17, .22, 2.31, x, 2.22, z, '#213d50');
  cuboid(scene, 1.28, .94, .10, x, 1.37, z + 1.13, '#102938');
  const glass = cuboid(scene, 1.13, .79, .07, x, 1.38, z + 1.21, '#4fa5c7', .3);
  glass.material.emissive = new THREE.Color('#073443');
  for (const dx of [-.72, .72]) cuboid(scene, .08, 1.05, .1, x + dx, 1.39, z + 1.2, '#a9c3cc');
  const lamp = new THREE.Mesh(new THREE.SphereGeometry(.17, 12, 12), new THREE.MeshStandardMaterial({ color: '#e6af5e', emissive: '#66421a', emissiveIntensity: 1.2 }));
  lamp.position.set(x + .74, 2.49, z + .65); scene.add(lamp);
  floorLabel(name.toUpperCase(), x, 2.48, z - .2, 2.8);
  machines.push({ id, lamp });
}
function createGantryCrane() {
  const group = new THREE.Group(); scene.add(group);
  // Two elevated rails running down the factory; the entire yellow bridge can travel on them.
  for (const x of [-10.5, 10.5]) {
    cuboid(group, .24, .3, 15.6, x, 5.25, 0, '#e6b64a', .7);
    for (const z of [-7.1, -2.3, 2.3, 7.1]) {
      cuboid(group, .35, 5.1, .35, x, 2.53, z, '#526e7d', .7);
      cuboid(group, .86, .2, .9, x, .08, z, '#253f4d');
    }
  }
  const bridge = new THREE.Group(); group.add(bridge);
  cuboid(bridge, 21.3, .63, .61, 0, 5.69, 0, '#e4b146', .68);
  cuboid(bridge, 21.3, .12, .84, 0, 6.1, 0, '#f6d57a', .48);
  for (const x of [-10.4, 10.4]) {
    cuboid(bridge, .78, .4, 1.1, x, 5.33, 0, '#344c62');
    for (const dz of [-.33, .33]) cylinder(bridge, .18, .15, x, 5.12, dz, '#a6bdc9');
  }
  const trolley = new THREE.Group(); trolley.position.set(0, 5.23, 0); bridge.add(trolley);
  cuboid(trolley, 1.5, .52, 1.03, 0, .02, 0, '#31536a', .7);
  cuboid(trolley, .92, .28, .85, 0, -.37, 0, '#e9bd58');
  const cable = cylinder(trolley, .038, 1, 0, -1, 0, '#d7e2e7', 8);
  const hook = new THREE.Group(); trolley.add(hook);
  cylinder(hook, .2, .23, 0, 0, 0, '#f7cd60');
  cuboid(hook, .3, .28, .15, 0, -.2, .06, '#dce7ec');
  const load = cuboid(hook, 1.05, .67, .95, 0, -.81, 0, '#2fc8d4', .3);
  cuboid(hook, 1.12, .08, 1.03, 0, -.43, 0, '#97e5eb');
  floorLabel('GANTRY CR-01', 0, -.09, 7.6, 3.1);
  gantry = { bridge, trolley, cable, hook, load };
  load.visible = false;
}
function createJibCrane() {
  const root = new THREE.Group(); root.position.set(-8.7, 0, 2.2); scene.add(root);
  cylinder(root, .53, .28, 0, .14, 0, '#293f50');
  cylinder(root, .20, 4.35, 0, 2.27, 0, '#ecb946');
  cylinder(root, .36, .23, 0, 4.52, 0, '#2e4a5a');
  const boom = new THREE.Group(); boom.position.y = 4.47; root.add(boom);
  cuboid(boom, 3.65, .27, .35, 1.75, 0, 0, '#eabb51', .6);
  cuboid(boom, 3.5, .09, .42, 1.75, .21, 0, '#fae092');
  const hoist = new THREE.Group(); hoist.position.set(3.25, -.18, 0); boom.add(hoist);
  cuboid(hoist, .52, .35, .56, 0, 0, 0, '#46677a');
  const cable = cylinder(hoist, .035, 1, 0, -.85, 0, '#d4e3eb', 8);
  const hook = new THREE.Group(); hoist.add(hook);
  cylinder(hook, .14, .18, 0, -.06, 0, '#ffcb54');
  const load = cuboid(hook, .8, .52, .73, 0, -.54, 0, '#dba05e');
  floorLabel('JIB CR-02', -8.7, -.1, 3.7, 2.3);
  jib = { root, boom, hoist, cable, hook, load };
  load.visible = false;
}
function createRobot(id, x, z, color) {
  const root = new THREE.Group(); root.position.set(x, .12, z); scene.add(root);
  cylinder(root, .82, .28, 0, .16, 0, '#2d4353');
  const turret = new THREE.Group(); turret.position.y = .38; root.add(turret);
  cylinder(turret, .42, .54, 0, .25, 0, '#e3a83b');
  const shoulder = new THREE.Group(); shoulder.position.y = .48; turret.add(shoulder);
  cuboid(shoulder, .5, 1.65, .5, 0, .82, 0, color, .55);
  cylinder(shoulder, .31, .72, 0, 1.62, 0, '#a4b8c4').rotation.z = Math.PI / 2;
  const elbow = new THREE.Group(); elbow.position.y = 1.67; shoulder.add(elbow);
  cuboid(elbow, .38, 1.25, .39, 0, .6, 0, color, .5);
  const wrist = new THREE.Group(); wrist.position.y = 1.27; elbow.add(wrist);
  cylinder(wrist, .21, .27, 0, .04, 0, '#5a7c90');
  cuboid(wrist, .94, .17, .35, 0, -.22, 0, '#d7e5eb');
  const jaws=[];
  for (const side of [-1,1]) jaws.push({side,mesh:cuboid(wrist, .12,.48,.16,side*.42,-.48,0,'#d8e7ee')});
  const held = cuboid(wrist, .55, .5, .53, 0, -.57, 0, '#59d4e2');
  floorLabel(id, x, -.09, z - 1.25, 1.9);
  held.visible = false;
  robots.push({ id, root, turret, shoulder, elbow, wrist, held, jaws });
}
function createStorage() {
  for (let i = 0; i < 7; i++) {
    const x = -10 + (i % 2) * .92;
    const z = -6.5 + Math.floor(i / 2) * .79;
    cuboid(scene, .72, .58, .69, x, .29, z, i % 2 ? '#b88146' : '#936b40');
  }
  floorLabel('RAW MATERIAL', -9.55, -.1, -7.62, 2.8);
  for (let i = 0; i < 6; i++) {
    cuboid(scene, .73, .64, .72, 8.9 + (i % 2) * .88, .35, -7.35 + Math.floor(i / 2) * .77, '#419f98');
  }
  floorLabel('DISPATCH', 9.28, -.1, -5.23, 2.3);
  for (const x of [-5.7, -4.8, -3.9]) {
    cuboid(scene, .7, .13, .75, x, .15, 7, '#8c6c45');
    cuboid(scene, .64, .51, .68, x, .46, 7, '#558993');
  }
}
// Distinct turning centers: clear guard, spindle and chuck, rotating steel blank,
// cutting tool, and visible metal swarf. All motion is virtual.
function createLathe(id, x, z, brass = false) {
  const shell = new THREE.Group(); shell.position.set(x, 0, z); scene.add(shell);
  cuboid(shell, 3.25, 2.1, 2.12, 0, 1.17, 0, '#738fa0', .68);
  cuboid(shell, 3.45, .22, 2.27, 0, 2.32, 0, '#263c4c');
  cuboid(shell, 2.7, 1.25, .1, 0, 1.38, 1.12, '#173747');
  cuboid(shell, 2.5, .16, .18, 0, 2.02, 1.20, '#9cb9cb');
  const spindle = new THREE.Group(); spindle.position.set(-.63, 1.46, 1.23); shell.add(spindle);
  const chuck = cylinder(spindle, .40, .24, 0, 0, 0, '#576c7c', 24);
  chuck.rotation.z = Math.PI / 2;
  for (let k=0;k<3;k++) {
    const jaw = cuboid(spindle, .15, .14, .23, 0, Math.sin(k*2*Math.PI/3)*.27, Math.cos(k*2*Math.PI/3)*.27, '#d8e2ec');
    jaw.rotation.x = k*2*Math.PI/3;
  }
  const blank = cylinder(spindle, .24, 1.3, .69, 0, 0, brass?'#dcb662':'#c5d6e2', 22);
  blank.rotation.z = Math.PI/2;
  cylinder(shell, .09, .9, .33, 1.45, 1.33, '#e9ad51').rotation.z = Math.PI/2;
  cuboid(shell, .18, .57, .2, .34, 1.38, 1.41, '#f5c461'); // tool holder
  const chips=[];
  for(let i=0;i<15;i++) {
    const chip = cuboid(shell, .07,.025,.028, .50 + Math.sin(i*2.4)*.38, .78 + i*.047, 1.27 + Math.cos(i*1.7)*.23, brass?'#e7c16c':'#c4e5f2', .9);
    chip.rotation.z=i*.83; chips.push(chip);
  }
  const status = new THREE.Mesh(new THREE.SphereGeometry(.15,12,12), new THREE.MeshStandardMaterial({color:'#eeb457',emissive:'#56351a'}));
  status.position.set(1.25, 2.54, .75); shell.add(status);
  floorLabel(id + ' CNC LATHE', x, 2.67, z, 3.1);
  lathes.push({id, spindle, chips, status});
}

function createProcessTools() {
  const laser=new THREE.Mesh(new THREE.CylinderGeometry(.025,.025,.7,10),
    new THREE.MeshStandardMaterial({color:'#a5f9ff',emissive:'#2bd6ef',emissiveIntensity:3}));
  laser.position.set(-3.0,1.72,-4.45);scene.add(laser);
  const drillBit=new THREE.Group();drillBit.position.set(3.1,1.87,-4.45);scene.add(drillBit);
  cylinder(drillBit,.095,.75,0,0,0,'#d8e5ea',16);
  for(let i=0;i<6;i++){
    const tooth=cuboid(drillBit,.06,.12,.06,Math.cos(i)*.09,-.33+i*.13,Math.sin(i)*.09,'#7f9ba9');
    tooth.rotation.y=i;
  }
  const qc=new THREE.Mesh(new THREE.PlaneGeometry(1.15,.48),
    new THREE.MeshBasicMaterial({color:'#74e7d2',transparent:true,opacity:.33,side:THREE.DoubleSide}));
  qc.position.set(3.1,1.58,0);scene.add(qc);
  const finisher=new THREE.Group();finisher.position.set(-3.1,1.76,0);scene.add(finisher);
  const wheel=cylinder(finisher,.32,.13,0,0,0,'#8db0c4',24);wheel.rotation.x=Math.PI/2;
  const press=cuboid(scene,.80,.22,.86,9.2,2.1,6.15,'#edbc52');
  processTools.laser=laser;processTools.drill=drillBit;
  processTools.qc=qc;processTools.finisher=finisher;processTools.press=press;
  laser.visible=drillBit.visible=qc.visible=finisher.visible=press.visible=false;
}
function makePallet(parent, x, y, z, scale=1) {
  const pallet=new THREE.Group(); pallet.position.set(x,y,z); pallet.scale.setScalar(scale); parent.add(pallet);
  for(const a of [-.34,.34]) cuboid(pallet,1.16,.12,.18,0,.05,a,'#8a6545');
  for(const a of [-.38,0,.38]) cuboid(pallet,.18,.18,.72,a,-.06,0,'#bb9057');
  for(let i=0;i<4;i++){
    const billet=cylinder(pallet,.12,.87,-.28+i*.18,.29,0,i%2?'#c8d7df':'#e5bb71',16);
    billet.rotation.z=Math.PI/2;
  }
  return pallet;
}
function createForklift(color) {
  const truck=new THREE.Group(); scene.add(truck);
  cuboid(truck,1.50,.44,.84,0,.49,0,color,.4);
  cuboid(truck,.70,1.05,.75,.22,1.14,0,'#35495d');
  cuboid(truck,.61,.12,.78,.25,1.77,0,color);
  for(const x of [-.50,.54]) for(const z of [-.45,.45]){
    const wheel=cylinder(truck,.24,.15,x,.30,z,'#151f2a',14);wheel.rotation.x=Math.PI/2;
  }
  cuboid(truck,.12,1.45,.12,-.87,1.18,-.30,'#dbe3e6');
  cuboid(truck,.12,1.45,.12,-.87,1.18,.30,'#dbe3e6');
  for(const z of [-.26,.26]) cuboid(truck,1.0,.08,.13,-1.32,.26,z,'#bbcbd4');
  const load=makePallet(truck,-1.3,.28,0,.72);
  return {group:truck,load};
}
function createMetalStock() {
  floorLabel('RAW METAL YARD', 5.6,-.09,-8.05,3.2);
  floorLabel('AGV TRANSFER DOCK',-3.15,-.09,-8.06,3.3);
  floorLabel('CRANE PICKUP',-7.55,-.09,-8.06,2.5);
  // Yellow safety rectangles make forklift zones unmistakable.
  for(const x of [6.25,-3.15,-7.55]) {
    for(const z of [-6.48,-7.95]) cuboid(scene,2.2,.015,.06,x,-.12,z,'#f2ca62');
    for(const dx of [-1.1,1.1]) cuboid(scene,.06,.015,1.55,x+dx,-.12,-7.2,'#f2ca62');
  }
  for(let i=0;i<8;i++) stockPallets.push(makePallet(scene,5.65+(i%4)*.43,.12,-7.45+Math.floor(i/4)*.63,.38));
  dockPallet = makePallet(scene,-3.15,.13,-7.17,.8);
  cranePallet = makePallet(scene,-7.55,.13,-7.17,.8);
  manualForklift = createForklift('#edb840');
  autoForklift = createForklift('#44bdb4');
  floorLabel('FL-01 AUTO',4.75,-.10,-6.20,2.3);
  floorLabel('FL-AUTO',-4.72,-.10,-6.20,2.0);
}
function createTurnedPart(color, index) {
  // A deliberately recognisable stepped machined shaft, coupling or bushing.
  const profiles=[
    [[.17,-.54],[.17,-.31],[.27,-.29],[.27,.05],[.37,.07],[.37,.20],[.20,.22],[.20,.53]],
    [[.26,-.43],[.26,-.2],[.39,-.19],[.39,.19],[.26,.2],[.26,.46]],
    [[.32,-.36],[.32,-.21],[.25,-.18],[.25,.18],[.32,.21],[.32,.36]],
  ];
  const points=profiles[index].map(([r,y])=>new THREE.Vector2(r,y));
  const part=new THREE.Mesh(new THREE.LatheGeometry(points,24),mat(color,.94,.19));
  part.rotation.z=Math.PI/2;
  part.castShadow=true;
  scene.add(part);
  return part;
}

function equipment(id) { return props.snapshot?.handling_equipment?.find(item => item.id === id); }
function applyMachineIndicators() {
  for (const item of machines) {
    const state = props.snapshot?.machines?.find(machine => machine.id === item.id);
    const color = state?.status === 'fault' ? '#fa6666' : state?.status === 'running' ? '#39dc9d' : '#eeb457';
    item.lamp.material.color.set(color);
    item.lamp.material.emissive.set(state?.status === 'fault' ? '#962c2c' : state?.status === 'running' ? '#0c7048' : '#674417');
  }
}
function init() {
  if (!host.value) return;
  scene = new THREE.Scene();
  scene.background = new THREE.Color('#091725');
  scene.fog = new THREE.Fog('#091725', 32, 57);
  const width = host.value.clientWidth || 750;
  const height = host.value.clientHeight || 465;
  camera = new THREE.PerspectiveCamera(39, width / height, .1, 100);
  camera.position.set(18, 22, 27);
  renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.75));
  renderer.setSize(width, height);
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  host.value.appendChild(renderer.domElement);
  controls = new OrbitControls(camera, renderer.domElement);
  controls.target.set(0, 1.55, 0);
  controls.enableDamping = true;
  controls.minDistance = 13;
  controls.maxDistance = 65;
  controls.maxPolarAngle = Math.PI / 2.08;
  controls.update();
  scene.add(new THREE.HemisphereLight('#d4f2ff', '#223849', 2.4));
  const sunlight = new THREE.DirectionalLight('#fff4d8', 3.0);
  sunlight.position.set(-9, 20, 12);
  sunlight.castShadow = true;
  sunlight.shadow.mapSize.set(2048, 2048);
  sunlight.shadow.camera.left = -18;
  sunlight.shadow.camera.right = 18;
  sunlight.shadow.camera.top = 18;
  sunlight.shadow.camera.bottom = -18;
  scene.add(sunlight);
  createFloor();
  [-4.45, 0, 4.45].forEach((z, index) => createConveyor(index, z));
  createMachine('CUT-01', 'LASER CUTTER', -3, -4.45);
  createMachine('DRL-01', 'CNC DRILL', 3.1, -4.45);
  createLathe('LAT-01', 4.8, -2.16);
  createLathe('LAT-02', 7.05, -2.16, true);
  createMachine('ASM-01', 'ASSEMBLY', -3.1, 0);
  createMachine('QC-01', 'INSPECTION', 3.1, 0);
  createMachine('PKG-01', 'PACKING', 9.35, 6.2);
  createGantryCrane();
  createJibCrane();
  createRobot('RB-01', 6.45, -1.10, '#edae3c');
  createRobot('RB-02', 8.65, 5.05, '#41afc3');
  createStorage();
  createMetalStock();
  createProcessTools();
  activePart = new THREE.Mesh(new THREE.CylinderGeometry(.23,.23,1.05,24),mat('#b6cbd8',.94,.17));
  activePart.rotation.z = Math.PI/2;
  activePart.castShadow = true;
  scene.add(activePart);
  activePart.visible = false;
  // Metal transfer shuttle moves an actual blank from the drill into CNC; no teleport.
  cncShuttle = new THREE.Group();
  cuboid(cncShuttle,1.0,.13,.9,0,1.10,0,'#b8c8d1',.85);
  for(const dz of [-.35,.35]) cuboid(cncShuttle,1.08,.14,.12,0,1.19,dz,'#4b6577');
  scene.add(cncShuttle);
  cncShuttle.visible=false;
  applyMachineIndicators();
  observer = new ResizeObserver(() => {
    if (!host.value || !renderer) return;
    const w = Math.max(host.value.clientWidth, 1);
    const h = Math.max(host.value.clientHeight, 1);
    camera.aspect = w / h; camera.updateProjectionMatrix(); renderer.setSize(w, h);
  });
  observer.observe(host.value);
  animate();
}

function transferPlaybackSpeed(state, phase) {
  // Match Laravel's per-stage speed and interlocks; paused equipment stays still.
  const lineReady = state?.factory_running && !state?.emergency_stop &&
    !state?.machines?.some(machine => machine.status === 'fault') &&
    state?.conveyors?.length === 3 && state.conveyors.every(belt => belt.running && belt.speed > 0);
  if (!lineReady) return 0;
  const handler = phase.startsWith('gantry_') ? 'CR-01' :
    phase.startsWith('jib_') ? 'CR-02' :
    phase.startsWith('robot1_') ? 'RB-01' :
    phase.startsWith('robot2_') ? 'RB-02' : null;
  if (handler) {
    const item = equipment(handler);
    return item?.enabled && item?.speed > 0 ? item.speed / 75 : 0;
  }
  const beltIndex = ['belt1_cut','belt1_drill','cnc_infeed'].includes(phase) ? 0 :
    ['belt2_qc','belt2_finish','belt2_jib'].includes(phase) ? 1 :
    phase === 'belt3' ? 2 : -1;
  if (beltIndex >= 0) return (state.conveyors[beltIndex]?.speed || 0) / 75;
  return 1;
}
function transferProgress(transfer, state, dt) {
  if (!transfer?.duration) { motionClock.key = null; return 0; }
  const key = `${transfer.serial}:${transfer.phase}`;
  const authoritative = clamp01((transfer.elapsed || 0) / transfer.duration) * transfer.duration;
  if (motionClock.key !== key) {
    // Stage or workpiece has changed. Laravel exclusively owns this transition.
    motionClock.key = key;
    motionClock.elapsed = authoritative;
  } else {
    const age = (performance.now() - receivedSnapshotAt) / 1000;
    const speed = transferPlaybackSpeed(state, transfer.phase);
    motionClock.elapsed = advanceDisplayElapsed(
      motionClock.elapsed, authoritative, age, speed, dt, transfer.duration
    );
  }
  return clamp01(motionClock.elapsed / transfer.duration);
}
function configureGantry(world, hookHeight) {
  gantry.bridge.position.z=world.z;
  gantry.trolley.position.x=world.x;
  const rope=Math.max(.25,5.23-hookHeight);
  gantry.hook.position.y=-rope;
  gantry.cable.position.y=-rope/2;
  gantry.cable.scale.y=rope;
  gantry.load.visible=false;
}
function configureJib(world, hookHeight) {
  const dx=world.x-jib.root.position.x, dz=world.z-jib.root.position.z;
  const radial=Math.sqrt(dx*dx+dz*dz);
  jib.boom.rotation.y=-Math.atan2(dz,dx);
  jib.hoist.position.x=radial;
  const rope=Math.max(.25,4.29-hookHeight);
  jib.hook.position.y=-rope;
  jib.cable.position.y=-rope/2;
  jib.cable.scale.y=rope;
  jib.load.visible=false;
}
// Two-link inverse kinematics: shoulder/elbow rotate toward a real pickup or
// placement point. The gripper does not perform continuous decorative waving.
function pointRobot(robot,target,gripping=false) {
  const dx=target.x-robot.root.position.x;
  const dz=target.z-robot.root.position.z;
  const r=Math.sqrt(dx*dx+dz*dz);
  const h=target.y+.50-(robot.root.position.y+.38+.48);
  const L1=1.67,L2=1.27;
  const distance=Math.max(.15,Math.min(L1+L2-.03,Math.hypot(r,h)));
  const theta=Math.atan2(h,r);
  const alpha=Math.acos(clamp01((distance*distance+L1*L1-L2*L2)/(2*distance*L1)));
  const upper=theta+alpha;
  const lower=Math.atan2(distance*Math.sin(theta)-L1*Math.sin(upper),distance*Math.cos(theta)-L1*Math.cos(upper));
  robot.turret.rotation.y=-Math.atan2(dz,dx);
  robot.shoulder.rotation.z=upper-Math.PI/2;
  robot.elbow.rotation.z=lower-upper;
  robot.wrist.rotation.x=0;
  robot.held.visible=false;
  for(const jaw of robot.jaws) jaw.mesh.position.x=jaw.side*(gripping?.27:.42);
}
function updateCranesAndRobots(state,phase,t,dt) {
  const pallet=pos(-7.55,.83,-7.17);
  const belt1=pos(-7.3,1.36,-4.45);
  const cutter=pos(-3.0,1.36,-4.45);
  const drill=pos(3.1,1.36,-4.45);
  const latheX=state?.transfer?.lathe_id==='LAT-02'?7.05:4.8;
  const spindle=pos(latheX,1.46,-2.16);
  const belt2=pos(7.3,1.36,0);
  const inspection=pos(3.1,1.36,0);
  const assembly=pos(-3.1,1.36,0);
  const belt2End=pos(-7.3,1.36,0);
  const belt3Start=pos(-7.3,1.36,4.45);
  const belt3End=pos(7.25,1.36,4.45);
  const packing=pos(9.2,1.36,6.15);
  const idleGan=pos(-7.55,3.55,-7.17);
  let part=pallet.clone();
  let gantryHook=idleGan.clone();
  // Each stage defines one physical object's custody; non-active equipment parks.
  switch(phase){
    case 'gantry_pick':
      gantryHook.y=3.55-2.5*eased(t);
      if(t>.98)part=gantryHook.clone().add(pos(0,-.22,0));
      break;
    case 'gantry_lift':
      gantryHook.y=1.05+2.5*eased(t);
      part=gantryHook.clone().add(pos(0,-.22,0));
      break;
    case 'gantry_travel':
      gantryHook=mix(idleGan,pos(-7.3,3.55,-4.45),eased(t));
      part=gantryHook.clone().add(pos(0,-.22,0));
      break;
    case 'gantry_place':
      gantryHook=pos(-7.3,3.55-(3.55-1.58)*eased(t),-4.45);
      part=gantryHook.clone().add(pos(0,-.22,0));
      break;
    case 'gantry_retract':
      gantryHook=pos(-7.3,1.58+(3.55-1.58)*eased(t),-4.45);
      part=belt1.clone();
      break;
    case 'gantry_return':
      gantryHook=mix(pos(-7.3,3.55,-4.45),idleGan,eased(t));
      part=belt1.clone();
      break;
    case 'belt1_cut':part=mix(belt1,cutter,eased(t));break;
    case 'laser_cut':part=cutter.clone();break;
    case 'belt1_drill':part=mix(cutter,drill,eased(t));break;
    case 'drill':part=drill.clone();break;
    case 'cnc_infeed':part=mix(drill,spindle,eased(t));break;
    case 'cnc_turn':part=spindle.clone();break;
    case 'robot1_pick':part=spindle.clone();break;
    case 'robot1_transfer':case 'robot1_return':part=belt2.clone();break;
    case 'belt2_qc':part=mix(belt2,inspection,eased(t));break;
    case 'inspect':part=inspection.clone();break;
    case 'belt2_finish':part=mix(inspection,assembly,eased(t));break;
    case 'assembly':part=assembly.clone();break;
    case 'belt2_jib':part=mix(assembly,belt2End,eased(t));break;
    case 'jib_pick':case 'jib_lift':part=belt2End.clone();break;
    case 'jib_transfer':case 'jib_place':case 'jib_retract':case 'jib_return':part=belt3Start.clone();break;
    case 'belt3':part=mix(belt3Start,belt3End,eased(t));break;
    case 'robot2_pick':part=belt3End.clone();break;
    case 'robot2_transfer':case 'pack_cycle':case 'robot2_return':part=packing.clone();break;
    default:break;
  }
  configureGantry(gantryHook,gantryHook.y);
  if(cncShuttle){
    cncShuttle.visible=phase==='cnc_infeed';
    if(cncShuttle.visible)cncShuttle.position.set(part.x,0,part.z);
  }
  let robot1Target=pos(6.4,2.0,-1.0);
  if(phase==='robot1_pick')robot1Target=mix(pos(6.4,2.2,-1.0),spindle,eased(t));
  if(phase==='robot1_transfer'){
    robot1Target=mix(spindle,belt2,eased(t));
    robot1Target.y+=Math.sin(Math.PI*t)*.7;
    part=robot1Target.clone();
  }
  if(phase==='robot1_return')robot1Target=mix(belt2,pos(6.4,2.0,-1.0),eased(t));
  pointRobot(robots[0],robot1Target,phase==='robot1_transfer'||(phase==='robot1_pick'&&t>.8));
  let jibTarget=belt2End.clone(),jibHook=3.45;
  if(phase==='jib_pick')jibHook=3.45-(3.45-1.58)*eased(t);
  if(phase==='jib_lift'){
    jibHook=1.58+(3.45-1.58)*eased(t);
    part=pos(belt2End.x,jibHook-.22,belt2End.z);
  }
  if(phase==='jib_transfer'){
    jibTarget=mix(belt2End,belt3Start,eased(t));
    part=pos(jibTarget.x,jibHook-.22,jibTarget.z);
  }
  if(phase==='jib_place'){
    jibTarget=belt3Start.clone();
    jibHook=3.45-(3.45-1.58)*eased(t);
    part=pos(jibTarget.x,jibHook-.22,jibTarget.z);
  }
  if(phase==='jib_retract'){
    jibTarget=belt3Start.clone();
    jibHook=1.58+(3.45-1.58)*eased(t);
  }
  if(phase==='jib_return')jibTarget=mix(belt3Start,belt2End,eased(t));
  configureJib(jibTarget,jibHook);
  let robot2Target=pos(9.2,2.2,5.35);
  if(phase==='robot2_pick')robot2Target=mix(pos(9.2,2.2,5.35),belt3End,eased(t));
  if(phase==='robot2_transfer'){
    robot2Target=mix(belt3End,packing,eased(t));
    robot2Target.y+=Math.sin(Math.PI*t)*.7;
    part=robot2Target.clone();
  }
  if(phase==='pack_cycle')robot2Target=packing.clone();
  if(phase==='robot2_return')robot2Target=mix(packing,pos(9.2,2.2,5.35),eased(t));
  pointRobot(robots[1],robot2Target,phase==='robot2_transfer'||phase==='pack_cycle'||(phase==='robot2_pick'&&t>.8));
  const work=state?.transfer;
  const previouslyVisible = activePart.visible;
  activePart.visible=Boolean(work&&phase!=='waiting_material'&&phase!=='packed');
  if(activePart.visible){
    if (!previouslyVisible || motionClock.previousSerial !== work.serial) {
      // Never fly a finished product back to raw stock when a new part starts.
      activePart.position.copy(part);
    } else {
      // Damp small server polling corrections so the workpiece never teleports.
      activePart.position.lerp(part, smoothAlpha(dt, 18));
    }
    motionClock.previousSerial = work.serial;
    const machined=['robot1_pick','robot1_transfer','robot1_return','belt2_qc','inspect','belt2_finish','assembly','belt2_jib','jib_pick','jib_lift','jib_transfer','jib_place','jib_retract','jib_return','belt3','robot2_pick','robot2_transfer','pack_cycle','robot2_return'].includes(phase)||(phase==='cnc_turn'&&t>.85);
    const desired=machined?'finished':'raw';
    if(partShape!==desired){
      activePart.geometry.dispose();
      activePart.geometry=machined?new THREE.LatheGeometry([
        new THREE.Vector2(.16,-.52),new THREE.Vector2(.16,-.32),new THREE.Vector2(.25,-.30),
        new THREE.Vector2(.25,.10),new THREE.Vector2(.34,.12),new THREE.Vector2(.34,.25),
        new THREE.Vector2(.18,.27),new THREE.Vector2(.18,.52)
      ],24):new THREE.CylinderGeometry(.23,.23,1.05,24);
      partShape=desired;
    }
    activePart.rotation.z=Math.PI/2;
    if(phase==='cnc_turn')activePart.rotation.x=progress.lathe;
    else activePart.rotation.x=0;
  }
}
function animate() {
  frame=requestAnimationFrame(animate);
  const dt=Math.min(clock.getDelta(),.05);
  const state=props.snapshot;
  const running=Boolean(state?.factory_running && !state?.emergency_stop && !state?.machines?.some(m=>m.status==='fault'));
  for(let i=0;i<belts.length;i++){
    const belt=state?.conveyors?.[i];
    const speed=running&&belt?.running?Math.max(0,belt.speed)/100:0;
    progress.belts[i] = positiveWrap(progress.belts[i] + dt * 2.8 * speed * (i===1 ? -1 : 1), 0, STRIPE_TRACK_LENGTH);
    for(let j=0;j<belts[i].stripes.length;j++){
      const original=STRIPE_TRACK_START+j*.73;
      // Correctly normalises negative travel on the middle, reversing conveyor.
      belts[i].stripes[j].position.x=positiveWrap(original+progress.belts[i], STRIPE_TRACK_START, STRIPE_TRACK_LENGTH);
    }
    for(const roller of belts[i].rollers) roller.rotateY(-dt*5*speed);
  }
  const work=state?.transfer;
  const phase=work?.phase||'waiting_material';
  const t=transferProgress(work, state, dt);
  if(gantry&&jib&&robots.length===2&&activePart) updateCranesAndRobots(state,phase,t,dt);
  const flow=state?.material_flow;
  if(manualForklift&&flow?.manual_forklift){
    const truck=flow.manual_forklift;
    manualForklift.group.position.lerp(pos(truck.x,0,truck.z),Math.min(1,dt*8));
    manualForklift.group.rotation.y={left:0,right:Math.PI,forward:-Math.PI/2,back:Math.PI/2}[truck.heading]??0;
    manualForklift.load.visible=Boolean(truck.cargo);
  }
  if(autoForklift&&flow?.auto_forklift){
    const truck=flow.auto_forklift;
    const amount=Math.min(1,truck.progress/2.5);
    const x=truck.phase==='to_crane'?-3.15-4.4*amount:truck.phase==='returning'?-7.55+4.4*amount:truck.phase==='unload'?-7.55:-3.15;
    autoForklift.group.position.lerp(pos(x,0,-7.10),Math.min(1,dt*8));
    autoForklift.group.rotation.y=truck.phase==='returning'?Math.PI:0;
    autoForklift.load.visible=Boolean(truck.cargo);
  }
  stockPallets.forEach((p,i)=>{p.visible=Boolean(flow&&i<flow.yard_pallets);});
  if(dockPallet) dockPallet.visible=Boolean(flow?.dock_pallets>0);
  if(cranePallet) cranePallet.visible=Boolean(flow?.crane_pallets>0||flow?.open_pallet_blanks>0);
  const turning=running&&phase==='cnc_turn';
  if(turning) progress.lathe+=dt*12;
  if(processTools.laser){
    processTools.laser.visible=running&&phase==='laser_cut';
    processTools.drill.visible=running&&phase==='drill';
    processTools.qc.visible=running&&phase==='inspect';
    processTools.finisher.visible=running&&phase==='assembly';
    processTools.press.visible=running&&phase==='pack_cycle';
    if(processTools.laser.visible) processTools.laser.scale.y=.75+Math.sin(progress.lathe*2+performance.now()*.015)*.16;
    if(processTools.drill.visible){processTools.drill.rotation.y+=dt*18;processTools.drill.position.y=1.87-.20*Math.sin(Math.PI*t);}
    if(processTools.qc.visible)processTools.qc.position.x=3.1+.35*Math.sin(Math.PI*2*t);
    if(processTools.finisher.visible)processTools.finisher.rotation.z+=dt*16;
    if(processTools.press.visible)processTools.press.position.y=2.1-.28*Math.sin(Math.PI*t);
  }
  lathes.forEach(lathe=>{
    const machining=turning&&lathe.id===work?.lathe_id;
    if(machining) lathe.spindle.rotation.x+=dt*14;
    lathe.chips.forEach((chip,j)=>{
      chip.visible=machining;
      if(machining) chip.position.y=.85+((j*.14-progress.lathe*.035)%1.03+1.03)%1.03;
    });
    const status=state?.machines?.find(m=>m.id===lathe.id)?.status;
    lathe.status.material.color.set(status==='fault'?'#f06b6b':machining?'#42e3a6':'#edb95f');
  });
  controls.update();
  renderer.render(scene,camera);
}
watch(() => props.snapshot, () => { receivedSnapshotAt=performance.now(); applyMachineIndicators(); });
onMounted(init);
onBeforeUnmount(() => {
  cancelAnimationFrame(frame);
  observer?.disconnect();
  controls?.dispose();
  scene?.traverse(object => {
    if (object.isMesh || object.isLineSegments) {
      object.geometry?.dispose();
      if (Array.isArray(object.material)) object.material.forEach(m => m.dispose());
      else object.material?.dispose();
    }
  });
  renderer?.dispose();
  renderer?.domElement.remove();
});
</script>
