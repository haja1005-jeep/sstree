const C='nearby-v1',F=['./','index.html','style.css','app.js'];
self.addEventListener('install',e=>e.waitUntil(caches.open(C).then(c=>c.addAll(F))));
self.addEventListener('fetch',e=>{
  const u=new URL(e.request.url);
  if(u.origin!==location.origin)return; // 지도/API는 항상 네트워크
  e.respondWith(fetch(e.request).catch(()=>caches.match(e.request)));
});
