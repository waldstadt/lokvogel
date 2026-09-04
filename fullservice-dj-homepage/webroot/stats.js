/* Anonyme Website-Statistik: Seitenaufruf, Verweildauer, Scrolltiefe und Klicks auf
   wichtige Buttons/Links (window.Stat.click('name')). Kein Cookie, keine gespeicherte
   IP - siehe api.php statsVisitorHash(). Auf jeder öffentlichen Seite einmal eingebunden. */
(function(){
  var API='api.php';
  function send(body){
    try{
      if(navigator.sendBeacon)navigator.sendBeacon(API+'/track',body);
      else fetch(API+'/track',{method:'POST',body:body,keepalive:true});
    }catch(e){}
  }
  var page=location.pathname.split('/').pop()||'index.html';
  /* Kampagnen-Zuordnung (z. B. Instagram-Anzeigen): utm_source/utm_campaign aus dem Link,
     fuer die Dauer des Besuchs im sessionStorage gemerkt (kein Cookie, nichts dauerhaftes -
     verschwindet mit dem Tab), damit ein spaeterer Klick auf "Anfrage senden" derselben
     Kampagne zugerechnet wird wie der Seitenaufruf, der den Besuch ausgeloest hat. */
  var utm=(function(){
    try{
      var q=new URLSearchParams(location.search);
      var src=q.get('utm_source'),camp=q.get('utm_campaign');
      if(src||camp){var u=(src||'')+(camp?'/'+camp:'');sessionStorage.setItem('lg_utm',u);return u}
      return sessionStorage.getItem('lg_utm')||'';
    }catch(e){return ''}
  })();
  send(JSON.stringify({p:page,r:document.referrer||'',u:utm}));

  var start=Date.now(),maxScroll=0,sent=false;
  function scrollPct(){
    var h=document.documentElement,se=h.scrollHeight-h.clientHeight;
    return se>0?Math.max(0,Math.min(100,Math.round((window.scrollY||h.scrollTop||0)/se*100))):100;
  }
  window.addEventListener('scroll',function(){var s=scrollPct();if(s>maxScroll)maxScroll=s},{passive:true});
  function flush(){
    if(sent)return;sent=true;
    var secs=Math.round((Date.now()-start)/1000);
    if(secs<1)return;
    send(JSON.stringify({p:page,t:secs,s:maxScroll}));
  }
  document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')flush()});
  window.addEventListener('pagehide',flush);

  document.addEventListener('click',function(ev){
    var el=ev.target.closest&&ev.target.closest('[data-stat]');
    if(el)send(JSON.stringify({k:el.getAttribute('data-stat'),u:utm}));
  },true);
  window.Stat={click:function(key){send(JSON.stringify({k:key,u:utm}))}};
})();
