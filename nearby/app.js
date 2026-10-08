'use strict';
// 카테고리 정의: OSM 태그 → 앱 카테고리
const CATS = {
  fuel:    {label:'주유소',   icon:'⛽', q:['[amenity=fuel]','[amenity=charging_station]']},
  food:    {label:'음식점',   icon:'🍽️', q:['[amenity~"^(restaurant|fast_food)$"]']},
  cafe:    {label:'카페',     icon:'☕', q:['[amenity=cafe]']},
  stay:    {label:'숙박',     icon:'🛏️', q:['[tourism~"^(hotel|motel|hostel|guest_house|apartment)$"]']},
  rental:  {label:'렌트카',   icon:'🚗', q:['[amenity=car_rental]']},
  taxi:    {label:'택시',     icon:'🚕', q:['[amenity=taxi]']},
  subway:  {label:'지하철·역', icon:'🚇', q:['[railway=subway_entrance]','[railway=station]','[public_transport=station]']},
  sight:   {label:'랜드마크', icon:'🏛️', q:['[tourism~"^(attraction|museum|viewpoint|gallery|theme_park|zoo)$"]','[historic~"^(monument|castle|memorial|ruins|archaeological_site)$"]']},
};
const OVERPASS = ['https://overpass-api.de/api/interpreter','https://overpass.kumi.systems/api/interpreter'];

const $ = id => document.getElementById(id);
const state = {pos:null, items:[], active:new Set(Object.keys(CATS)), markers:null, me:null, radius:2000, n:0};

const map = L.map('map',{zoomControl:false}).setView([37.5665,126.978],14);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(map);
state.markers = L.layerGroup().addTo(map);

// ---------- 유틸 ----------
const esc = s => String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function dist(a,b,c,d){const R=6371e3,r=Math.PI/180,p=(c-a)*r,q=(d-b)*r;
  const h=Math.sin(p/2)**2+Math.cos(a*r)*Math.cos(c*r)*Math.sin(q/2)**2;return 2*R*Math.asin(Math.sqrt(h));}
function bearing(a,b,c,d){const r=Math.PI/180,y=Math.sin((d-b)*r)*Math.cos(c*r),
  x=Math.cos(a*r)*Math.sin(c*r)-Math.sin(a*r)*Math.cos(c*r)*Math.cos((d-b)*r);
  return ['북','북동','동','남동','남','남서','서','북서'][Math.round(((Math.atan2(y,x)/r+360)%360)/45)%8];}
const fmt = m => m<1000 ? Math.round(m/10)*10+'m' : (m/1000).toFixed(1)+'km';
const walk = m => { const min=Math.max(1,Math.round(m/75)); return min<60?`도보 ${min}분`:`차량 ${Math.max(1,Math.round(m/600))}분`; };

// ---------- 데이터 ----------
function buildQuery(lat,lon,r){
  const parts=[];
  for(const c of Object.values(CATS)) for(const t of c.q) parts.push(`nwr${t}(around:${r},${lat},${lon});`);
  return `[out:json][timeout:25];(${parts.join('')});out tags center 400;`;
}
function classify(t){
  if(t.amenity==='fuel'||t.amenity==='charging_station')return'fuel';
  if(t.amenity==='restaurant'||t.amenity==='fast_food')return'food';
  if(t.amenity==='cafe')return'cafe';
  if(t.amenity==='car_rental')return'rental';
  if(t.amenity==='taxi')return'taxi';
  if(t.railway||t.public_transport==='station')return'subway';
  if(/^(hotel|motel|hostel|guest_house|apartment)$/.test(t.tourism))return'stay';
  if(t.tourism||t.historic)return'sight';
  return null;
}
async function fetchOverpass(lat,lon,r){
  const body='data='+encodeURIComponent(buildQuery(lat,lon,r));
  let err;
  for(const url of OVERPASS){
    try{
      const res=await fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
      if(!res.ok)throw new Error('HTTP '+res.status);
      return (await res.json()).elements;
    }catch(e){err=e}
  }
  throw err;
}
function normalize(els,lat,lon){
  const seen=new Set(),out=[];
  for(const e of els){
    const t=e.tags||{},cat=classify(t);if(!cat)continue;
    const la=e.lat??e.center?.lat,lo=e.lon??e.center?.lon;if(la==null)continue;
    let name=t['name:ko']||t.name||t.brand||t.operator||'';
    if(!name){ if(cat==='taxi')name='택시 승강장'; else if(cat==='subway'&&t.railway==='subway_entrance')name=(t.ref?`출구 ${t.ref}`:'지하철 출입구'); else continue; }
    // 지하철: 역 단위 중복(같은 이름·카테고리 120m 이내) 제거는 입구 번호가 다르면 유지
    const key=cat+name+(t.ref||'')+la.toFixed(3)+lo.toFixed(3);
    if(seen.has(key))continue;seen.add(key);
    const d=dist(lat,lon,la,lo);
    out.push({id:e.type+e.id,cat,name,lat:la,lon:lo,d,dir:bearing(lat,lon,la,lo),
      sub:[t['addr:full']||t['addr:street']&&(t['addr:street']+' '+(t['addr:housenumber']||'')),t.cuisine,t.brand,t.opening_hours&&('🕒 '+t.opening_hours)].filter(Boolean).join(' · ')});
  }
  return out.sort((a,b)=>a.d-b.d);
}

// ---------- 렌더 ----------
function renderChips(){
  const counts={};state.items.forEach(i=>counts[i.cat]=(counts[i.cat]||0)+1);
  $('chips').innerHTML=Object.entries(CATS).map(([k,c])=>
    `<button class="chip ${state.active.has(k)?'on':''}" data-k="${k}">${c.icon} ${c.label} ${counts[k]||0}</button>`).join('');
}
function renderAll(){
  renderChips();
  const shown=state.items.filter(i=>state.active.has(i.cat));
  // 카테고리별 가장 가까운 3곳을 먼저 보여주고 나머지는 거리순
  const top=new Set(),first=[];const per={};
  for(const i of shown){per[i.cat]=(per[i.cat]||0)+1;if(per[i.cat]<=3){top.add(i.id);first.push(i)}}
  const ordered=first.concat(shown.filter(i=>!top.has(i.id)));
  $('status').textContent=`반경 ${fmt(state.radius)} · ${shown.length}곳 (가까운 순)`;
  $('list').innerHTML=ordered.length?ordered.slice(0,150).map(i=>{
    const c=CATS[i.cat],nav=`https://www.google.com/maps/dir/?api=1&destination=${i.lat},${i.lon}&travelmode=walking`;
    return `<li data-id="${i.id}"><div class="ic">${c.icon}</div><div class="tx"><div class="nm">${esc(i.name)}</div>
      <div class="sub">${c.label}${i.sub?' · '+esc(i.sub):''}</div></div>
      <div class="dist">${fmt(i.d)} ${i.dir}<br><span class="sub">${walk(i.d)}</span><br><a href="${nav}" target="_blank" rel="noopener">길찾기</a></div></li>`;
  }).join(''):'<div class="empty">이 반경에는 결과가 없어요. 반경을 넓혀 보세요.</div>';
  state.markers.clearLayers();
  ordered.slice(0,150).forEach(i=>{
    const m=L.marker([i.lat,i.lon],{icon:L.divIcon({className:'',html:`<div class="pin">${CATS[i.cat].icon}</div>`,iconSize:[26,26],iconAnchor:[13,13]})});
    m.bindPopup(`<b>${esc(i.name)}</b><br>${CATS[i.cat].label} · ${fmt(i.d)} ${i.dir}`);
    i.marker=m;state.markers.addLayer(m);
  });
}

// ---------- 흐름 ----------
async function load(lat,lon,label){
  const n=++state.n;
  state.pos={lat,lon};
  $('place').textContent='📍 '+(label||'내 위치');
  $('status').textContent='주변 검색 중…';
  if(state.me)map.removeLayer(state.me);
  state.me=L.marker([lat,lon],{icon:L.divIcon({className:'',html:'<div class="me"></div>',iconSize:[16,16]})}).addTo(map);
  try{
    let r=state.radius,els=await fetchOverpass(lat,lon,r);
    if(n!==state.n)return;
    let items=normalize(els,lat,lon);
    // 결과가 너무 적으면 자동으로 반경 확대 (최대 10km)
    while(items.length<8&&r<10000&&n===state.n){
      r=r<2000?2000:r<5000?5000:10000;state.radius=r;$('radius').value=r;
      $('status').textContent=`결과가 적어 반경 ${fmt(r)}로 확대 중…`;
      items=normalize(await fetchOverpass(lat,lon,r),lat,lon);
    }
    if(n!==state.n)return;
    state.items=items;
    map.fitBounds(L.latLng(lat,lon).toBounds(Math.min(state.radius,2500)*2),{padding:[10,10]});
    renderAll();
  }catch(e){
    $('status').textContent='검색 실패: 네트워크를 확인하고 🎯를 눌러 다시 시도하세요';
    console.error(e);
  }
}
async function reverse(lat,lon){
  try{
    const j=await (await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&accept-language=ko&zoom=16&lat=${lat}&lon=${lon}`)).json();
    const a=j.address||{};
    return [a.city||a.county||a.province,a.suburb||a.neighbourhood||a.quarter||a.town||a.village].filter(Boolean).join(' ')||j.display_name;
  }catch{return null}
}
function locate(){
  $('place').textContent='📍 위치 확인 중…';
  if(!navigator.geolocation){$('place').textContent='📍 위치 사용 불가 – 검색창을 이용하세요';return}
  navigator.geolocation.getCurrentPosition(async p=>{
    const {latitude:la,longitude:lo}=p.coords;
    load(la,lo);
    const name=await reverse(la,lo);if(name&&state.pos.lat===la)$('place').textContent='📍 '+name;
  },()=>{$('place').textContent='📍 위치 권한이 없어요 – 검색창에 장소를 입력하세요'},{enableHighAccuracy:true,timeout:10000,maximumAge:30000});
}

// ---------- 이벤트 ----------
$('chips').onclick=e=>{const k=e.target.dataset.k;if(!k)return;
  if(state.active.size===Object.keys(CATS).length)state.active=new Set([k]); // 처음 누르면 해당 카테고리만
  else state.active.has(k)?state.active.delete(k):state.active.add(k);
  if(!state.active.size)state.active=new Set(Object.keys(CATS));
  renderAll();};
$('list').onclick=e=>{if(e.target.tagName==='A')return;const li=e.target.closest('li');if(!li)return;
  const it=state.items.find(i=>i.id===li.dataset.id);if(it?.marker){map.setView([it.lat,it.lon],17);it.marker.openPopup()}};
$('btnLocate').onclick=locate;
$('radius').onchange=e=>{state.radius=+e.target.value;if(state.pos)load(state.pos.lat,state.pos.lon,$('place').textContent.slice(2))};
$('searchForm').onsubmit=async e=>{
  e.preventDefault();const q=$('q').value.trim();if(!q)return;
  $('status').textContent='장소 찾는 중…';
  try{
    const r=await (await fetch(`https://nominatim.openstreetmap.org/search?format=json&limit=1&accept-language=ko&q=${encodeURIComponent(q)}`)).json();
    if(!r.length){$('status').textContent='장소를 찾지 못했어요';return}
    load(+r[0].lat,+r[0].lon,r[0].display_name.split(',').slice(0,2).join(' '));
  }catch{$('status').textContent='검색 실패'}
};
if('serviceWorker' in navigator&&location.protocol.startsWith('http'))navigator.serviceWorker.register('sw.js').catch(()=>{});
renderChips();
locate(); // 앱을 켜면 자동으로 현재 위치 기준 검색
