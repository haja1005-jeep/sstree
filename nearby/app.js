'use strict';
// 카카오 로컬 API (JS SDK services): 카테고리 코드 또는 키워드로 검색
const CATS = {
  fuel:   {label:'주유소',   icon:'⛽', src:[['c','OL7']]},
  food:   {label:'음식점',   icon:'🍽️', src:[['c','FD6']]},
  cafe:   {label:'카페',     icon:'☕', src:[['c','CE7']]},
  stay:   {label:'숙박',     icon:'🛏️', src:[['c','AD5']]},
  rental: {label:'렌트카',   icon:'🚗', src:[['k','렌터카']]},
  taxi:   {label:'택시',     icon:'🚕', src:[['k','택시']]},
  subway: {label:'지하철·역', icon:'🚇', src:[['c','SW8'],['k','지하철 출구']]},
  sight:  {label:'랜드마크', icon:'🏛️', src:[['c','AT4'],['c','CT1']]},
};
const PAGES = 2; // 검색 1회당 최대 15건 × 2페이지

const $ = id => document.getElementById(id);
const state = {pos:null, items:[], active:new Set(Object.keys(CATS)), radius:2000, n:0, overlays:[], me:null, iw:null};
let map, ps, geo;

// ---------- 유틸 ----------
const esc = s => String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function dist(a,b,c,d){const R=6371e3,r=Math.PI/180,p=(c-a)*r,q=(d-b)*r;
  const h=Math.sin(p/2)**2+Math.cos(a*r)*Math.cos(c*r)*Math.sin(q/2)**2;return 2*R*Math.asin(Math.sqrt(h));}
function bearing(a,b,c,d){const r=Math.PI/180,y=Math.sin((d-b)*r)*Math.cos(c*r),
  x=Math.cos(a*r)*Math.sin(c*r)-Math.sin(a*r)*Math.cos(c*r)*Math.cos((d-b)*r);
  return ['북','북동','동','남동','남','남서','서','북서'][Math.round(((Math.atan2(y,x)/r+360)%360)/45)%8];}
const fmt = m => m<1000 ? Math.round(m/10)*10+'m' : (m/1000).toFixed(1)+'km';
const walk = m => { const min=Math.max(1,Math.round(m/75)); return min<60?`도보 ${min}분`:`차량 ${Math.max(1,Math.round(m/600))}분`; };

// ---------- 카카오 SDK ----------
function loadKakao(){
  return new Promise((ok,fail)=>{
    if(!window.KAKAO_JS_KEY)return fail(new Error('NO_KEY'));
    const s=document.createElement('script');
    s.src=`https://dapi.kakao.com/v2/maps/sdk.js?appkey=${encodeURIComponent(KAKAO_JS_KEY)}&libraries=services&autoload=false`;
    s.onload=()=>kakao.maps.load(ok);
    s.onerror=()=>fail(new Error('SDK_LOAD'));
    document.head.appendChild(s);
  });
}
const P = (fn)=>new Promise(res=>fn((data,status)=>res(status===kakao.maps.services.Status.OK?data:[])));
function search(kind,v,lat,lon,r,page){
  const opt={location:new kakao.maps.LatLng(lat,lon),radius:r,sort:kakao.maps.services.SortBy.DISTANCE,size:15,page};
  return P(cb=>kind==='c'?ps.categorySearch(v,cb,opt):ps.keywordSearch(v,cb,opt));
}
async function fetchAll(lat,lon,r){
  const jobs=[];
  for(const [cat,c] of Object.entries(CATS))
    for(const [kind,v] of c.src)
      for(let p=1;p<=PAGES;p++)
        jobs.push(search(kind,v,lat,lon,r,p).then(rows=>rows.map(x=>({cat,x}))));
  const seen=new Set(),out=[];
  for(const {cat,x} of (await Promise.all(jobs)).flat()){
    if(seen.has(x.id))continue;seen.add(x.id);
    const la=+x.y,lo=+x.x,d=x.distance?+x.distance:dist(lat,lon,la,lo);
    if(d>r)continue;
    out.push({id:x.id,cat,name:x.place_name,lat:la,lon:lo,d,dir:bearing(lat,lon,la,lo),url:x.place_url,
      sub:[x.road_address_name||x.address_name,x.phone].filter(Boolean).join(' · ')});
  }
  return out.sort((a,b)=>a.d-b.d);
}

// ---------- 렌더 ----------
function renderChips(){
  const counts={};state.items.forEach(i=>counts[i.cat]=(counts[i.cat]||0)+1);
  $('chips').innerHTML=Object.entries(CATS).map(([k,c])=>
    `<button class="chip ${state.active.has(k)?'on':''}" data-k="${k}">${c.icon} ${c.label} ${counts[k]||0}</button>`).join('');
}
function showInfo(it){
  if(state.iw)state.iw.close();
  state.iw=new kakao.maps.InfoWindow({position:new kakao.maps.LatLng(it.lat,it.lon),yAnchor:1.6,
    content:`<div class="iw"><b>${esc(it.name)}</b><br>${CATS[it.cat].label} · ${fmt(it.d)} ${it.dir}</div>`});
  state.iw.open(map);
}
function renderAll(){
  renderChips();
  const shown=state.items.filter(i=>state.active.has(i.cat));
  // 카테고리별 가장 가까운 3곳을 먼저, 나머지는 거리순
  const top=new Set(),first=[],per={};
  for(const i of shown){per[i.cat]=(per[i.cat]||0)+1;if(per[i.cat]<=3){top.add(i.id);first.push(i)}}
  const ordered=first.concat(shown.filter(i=>!top.has(i.id))).slice(0,150);
  $('status').textContent=`반경 ${fmt(state.radius)} · ${shown.length}곳 (가까운 순)`;
  $('list').innerHTML=ordered.length?ordered.map(i=>{
    const c=CATS[i.cat],nav=`https://map.kakao.com/link/to/${encodeURIComponent(i.name)},${i.lat},${i.lon}`;
    return `<li data-id="${i.id}"><div class="ic">${c.icon}</div><div class="tx"><div class="nm">${esc(i.name)}</div>
      <div class="sub">${c.label}${i.sub?' · '+esc(i.sub):''}</div></div>
      <div class="dist">${fmt(i.d)} ${i.dir}<br><span class="sub">${walk(i.d)}</span><br><a href="${nav}" target="_blank" rel="noopener">길찾기</a></div></li>`;
  }).join(''):'<div class="empty">이 반경에는 결과가 없어요. 반경을 넓혀 보세요.</div>';
  state.overlays.forEach(o=>o.setMap(null));
  state.overlays=ordered.map(i=>{
    const el=document.createElement('div');el.className='pin kpin';el.textContent=CATS[i.cat].icon;
    el.onclick=()=>showInfo(i);
    return new kakao.maps.CustomOverlay({map,position:new kakao.maps.LatLng(i.lat,i.lon),content:el,yAnchor:0.5});
  });
}
function fitRadius(lat,lon,r){
  r=Math.min(r,2500);const dl=r/111000,dn=r/(111000*Math.cos(lat*Math.PI/180));
  map.setBounds(new kakao.maps.LatLngBounds(new kakao.maps.LatLng(lat-dl,lon-dn),new kakao.maps.LatLng(lat+dl,lon+dn)));
}

// ---------- 흐름 ----------
async function load(lat,lon,label){
  const n=++state.n;
  state.pos={lat,lon,label};
  $('place').textContent='📍 '+(label||'내 위치');
  $('status').textContent='주변 검색 중…';
  if(state.me)state.me.setMap(null);
  const dot=document.createElement('div');dot.className='me';
  state.me=new kakao.maps.CustomOverlay({map,position:new kakao.maps.LatLng(lat,lon),content:dot,zIndex:10});
  try{
    let r=state.radius,items=await fetchAll(lat,lon,r);
    if(n!==state.n)return;
    // 결과가 너무 적으면 자동으로 반경 확대 (최대 10km)
    while(items.length<8&&r<10000&&n===state.n){
      r=r<2000?2000:r<5000?5000:10000;state.radius=r;$('radius').value=r;
      $('status').textContent=`결과가 적어 반경 ${fmt(r)}로 확대 중…`;
      items=await fetchAll(lat,lon,r);
    }
    if(n!==state.n)return;
    state.items=items;
    fitRadius(lat,lon,state.radius);
    renderAll();
  }catch(e){
    $('status').textContent='검색 실패: 네트워크를 확인하고 🎯를 눌러 다시 시도하세요';
    console.error(e);
  }
}
function reverse(lat,lon){
  return new Promise(res=>geo.coord2RegionCode(lon,lat,(r,st)=>{
    const h=st===kakao.maps.services.Status.OK&&(r.find(x=>x.region_type==='H')||r[0]);
    res(h?[h.region_2depth_name,h.region_3depth_name].filter(Boolean).join(' '):null);
  }));
}
function locate(){
  $('place').textContent='📍 위치 확인 중…';
  if(!navigator.geolocation){$('place').textContent='📍 위치 사용 불가 – 검색창을 이용하세요';return}
  navigator.geolocation.getCurrentPosition(async p=>{
    const {latitude:la,longitude:lo}=p.coords;
    load(la,lo);
    const name=await reverse(la,lo);if(name&&state.pos.lat===la){state.pos.label=name;$('place').textContent='📍 '+name}
  },()=>{$('place').textContent='📍 위치 권한이 없어요 – 검색창에 장소를 입력하세요'},{enableHighAccuracy:true,timeout:10000,maximumAge:30000});
}

// ---------- 시작 ----------
function bind(){
  $('chips').onclick=e=>{const k=e.target.dataset.k;if(!k)return;
    if(state.active.size===Object.keys(CATS).length)state.active=new Set([k]); // 처음 누르면 해당 카테고리만
    else state.active.has(k)?state.active.delete(k):state.active.add(k);
    if(!state.active.size)state.active=new Set(Object.keys(CATS));
    renderAll();};
  $('list').onclick=e=>{if(e.target.tagName==='A')return;const li=e.target.closest('li');if(!li)return;
    const it=state.items.find(i=>i.id===li.dataset.id);
    if(it){map.setLevel(3);map.panTo(new kakao.maps.LatLng(it.lat,it.lon));showInfo(it)}};
  $('btnLocate').onclick=locate;
  $('radius').onchange=e=>{state.radius=+e.target.value;if(state.pos)load(state.pos.lat,state.pos.lon,state.pos.label)};
  $('searchForm').onsubmit=e=>{
    e.preventDefault();const q=$('q').value.trim();if(!q)return;
    $('status').textContent='장소 찾는 중…';
    ps.keywordSearch(q,(d,st)=>{
      if(st===kakao.maps.services.Status.OK)return load(+d[0].y,+d[0].x,d[0].place_name);
      geo.addressSearch(q,(a,s2)=>s2===kakao.maps.services.Status.OK
        ?load(+a[0].y,+a[0].x,a[0].address_name):($('status').textContent='장소를 찾지 못했어요'));
    });
  };
}
function setup(msg){
  $('place').textContent='📍 카카오 키 설정 필요';
  $('list').innerHTML=`<div class="setup">${msg}<br>1. <code>developers.kakao.com</code>에서 앱 생성<br>
  2. <b>JavaScript 키</b>를 <code>nearby/config.js</code>의 <code>KAKAO_JS_KEY</code>에 입력<br>
  3. 플랫폼 &gt; Web에 현재 주소(<code>${esc(location.origin)}</code>) 등록<br>
  4. 제품 설정에서 <b>카카오맵</b> 사용 설정 ON</div>`;
  $('status').textContent='';
}
(async()=>{
  renderChips();
  try{await loadKakao()}catch(e){
    return setup(e.message==='NO_KEY'?'카카오 JavaScript 키가 아직 없어요.':'카카오 SDK를 불러오지 못했어요. 키/도메인 등록을 확인하세요.');
  }
  map=new kakao.maps.Map($('map'),{center:new kakao.maps.LatLng(37.5665,126.978),level:4});
  ps=new kakao.maps.services.Places();geo=new kakao.maps.services.Geocoder();
  bind();
  locate(); // 앱을 켜면 자동으로 현재 위치 기준 검색
})();
if('serviceWorker' in navigator&&location.protocol.startsWith('http'))navigator.serviceWorker.register('sw.js').catch(()=>{});
