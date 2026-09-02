/* Renderer für alle Aktionsseiten: lädt den Inhalt der Seite (Slug = Dateiname)
   aus der Datenbank (Tabelle campaign_pages, im Backoffice unter "Aktionsseiten"
   pflegbar) und baut die komplette Seite auf. Ist die Seite im Backoffice
   ausgeschaltet oder unbekannt, geht es kommentarlos zur Startseite. */
(function(){
var API='api.php';
var SLUG=(location.pathname.split('/').pop()||'').replace(/\.html$/,'');
/* Farben und Schrift kommen aus theme.js (gemeinsam mit allen anderen Seiten). Ist es
   nicht schon im <head> eingebunden, wird es hier nachgeladen; das zuletzt gesehene
   Schema dieser Aktionsseite liegt im localStorage und gilt sofort - kein Aufblitzen. */
var FONT_SEL='h1,h2,h3,h4,.kicker,.btn,.logo .wm',THEME_KEY='camp:'+SLUG;
function loadTheme(){
  if(window.applyTheme)return Promise.resolve();
  return new Promise(function(res){var sc=document.createElement('script');sc.src='theme.js';sc.onload=res;sc.onerror=res;document.head.appendChild(sc)})
    .then(function(){if(window.applyCachedTheme)applyCachedTheme(THEME_KEY,{fontSelector:FONT_SEL})});
}
if(window.applyCachedTheme)applyCachedTheme(THEME_KEY,{fontSelector:FONT_SEL});
/* Betreiber-Basisdaten aus dem Backoffice (public/company): Marke, Inhaber, Telefon,
   WhatsApp - nichts davon steht mehr fest im Code. */
var CO={};
function coCity(){return String(CO.zip_city||'').replace(/^\d{4,5}\s*/,'')}
function coWordmark(){var h=String(CO.website||'').replace(/^https?:\/\//,'').replace(/\/.*$/,'').replace(/^www\./,'');return h?h.replace(/\.[a-z]+$/i,''):(CO.name||'')}

function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
/* Nur für die Preis-Notiz: erlaubt [Text](seite.html) als internen Link, sonst reiner Text */
function noteHtml(s){return esc(s).replace(/\[([^\]]+)\]\(([a-z0-9\-]+\.html(?:#[a-z0-9\-]*)?)\)/g,'<a href="$2">$1</a>')}

/* Icon-Satz im Linienstil - Schlüssel wählbar im Backoffice-Editor */
var ICONS={
  mic:'<path d="M12 1a3 3 0 0 1 3 3v7a3 3 0 0 1-6 0V4a3 3 0 0 1 3-3z"/><path d="M19 10v1a7 7 0 0 1-14 0v-1"/><line x1="12" y1="18" x2="12" y2="22"/>',
  music:'<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
  users:'<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  money:'<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
  shield:'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
  chat:'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
  sun:'<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
  home:'<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
  cloud:'<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9z"/>',
  gear:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
  zap:'<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
  light:'<line x1="9" y1="18" x2="15" y2="18"/><line x1="10" y1="22" x2="14" y2="22"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"/>',
  monitor:'<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
  cart:'<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
  calendar:'<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
  check:'<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
  doc:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
  search:'<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
  clock:'<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'
};
function ic(k){return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">'+(ICONS[k]||ICONS.check)+'</svg> '}

/* Schema der Aktionsseite: Hintergrund, Schrift und Zweit-Akzent von der Website-Seite,
   zu der die Seite gehoert (footer_target), der Akzent von der Aktionsseite selbst.
   Button-Schrift und Akzent-als-Text rechnet theme.js kontrastsicher aus. */
function render(pg,site){
  var base=themeFromContent(site||{},pg.footer_target==='technik'?'technik':'dj');
  var th={primary:pg.accent||base.primary,bg:base.bg,font:base.font,alt:base.alt};
  var d=applyTheme(th,{fontSelector:FONT_SEL,favicon:false});rememberTheme(THEME_KEY,th);
  var acc=d.vars['--acc'];
  if(pg.page_title)document.title=pg.page_title;
  if(pg.meta_desc){
    var md=document.querySelector('meta[name="description"]');
    if(md)md.setAttribute('content',pg.meta_desc);
  }
  var fav=document.querySelector('link[rel="icon"]');
  if(fav)fav.href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cg fill='%23"+acc.replace('#','')+"'%3E%3Crect x='3' y='11' width='5' height='10' rx='2.5'/%3E%3Crect x='10' y='5' width='5' height='22' rx='2.5'/%3E%3Crect x='17' y='9' width='5' height='14' rx='2.5'/%3E%3Crect x='24' y='13' width='5' height='6' rx='2.5'/%3E%3C/g%3E%3C/svg%3E";

  var cfg=pg.form_cfg||{};
  var cards=(pg.cards||[]).map(function(c){
    return '<div class="pt"><h3>'+ic(c.icon)+esc(c.title)+'</h3><p>'+esc(c.text)+'</p></div>';
  }).join('');
  var feats=(pg.features||[]).map(function(f){return '<li>'+esc(f)+'</li>'}).join('');
  var types=cfg.event_types||['Sonstiges'];
  var typeField=types.length>1
    ?'<div><label for="cpType">'+esc(cfg.type_label||'Was braucht ihr?')+'</label><select id="cpType">'+types.map(function(t){return '<option>'+esc(t)+'</option>'}).join('')+'</select></div>'
    :'';
  var homeHref=pg.footer_target==='technik'?'technik.html':'index.html';
  var homeLabel=pg.footer_target==='technik'?'Zur Technik-Seite':'Zur Hauptseite';
  var brand=CO.name||'';
  var wa=(cfg.wa_text||'Hallo {inhaber}, ').replace(/\{inhaber\}/g,CO.owner_first||'').replace(/^Hallo\s*,/,'Hallo,');
  var waDigits=CO.whatsapp_digits||'',tel=CO.phone||'';

  document.getElementById('app').innerHTML=
  '<nav><div class="nav-in">'+
    '<a class="logo" href="'+homeHref+'" aria-label="'+esc(brand)+'">'+
      '<svg viewBox="0 0 32 32" aria-hidden="true"><g fill="var(--acc)"><rect x="3" y="11" width="5" height="10" rx="2.5"/><rect x="10" y="5" width="5" height="22" rx="2.5"/><rect x="17" y="9" width="5" height="14" rx="2.5"/><rect x="24" y="13" width="5" height="6" rx="2.5"/></g></svg>'+
      '<span class="wm">'+esc(coWordmark())+'<i>.</i></span></a>'+
    '<a href="#anfrage" class="btn" style="padding:9px 18px;font-size:12px">Anfragen</a>'+
  '</div></nav>'+

  '<header class="hero"><div class="wrap">'+
    (pg.badge?'<div class="badge">'+esc(pg.badge)+'</div>':'')+
    '<h1>'+esc(pg.h1_line1)+'<br><em>'+esc(pg.h1_line2)+'</em></h1>'+
    '<p class="sub">'+esc(pg.sub)+'</p>'+
    '<a href="#anfrage" class="btn">'+esc(cfg.cta_label||'Unverbindlich anfragen')+'</a>'+
  '</div></header>'+

  '<section><div class="wrap">'+
    '<div class="kicker">'+esc(pg.kicker1)+'</div><h2>'+esc(pg.h2_1)+'</h2>'+
    '<div class="pts">'+cards+'</div>'+
  '</div></section>'+

  '<section class="inc"><div class="wrap">'+
    '<div class="kicker">'+esc(pg.kicker2)+'</div><h2>'+esc(pg.h2_2)+'</h2>'+
    '<ul>'+feats+'</ul>'+
    (pg.pricenote?'<p class="pricenote">'+noteHtml(pg.pricenote)+'</p>':'')+
  '</div></section>'+

  '<section id="anfrage"><div class="wrap">'+
    '<div class="kicker">'+esc(pg.form_kicker||'Jetzt anfragen')+'</div>'+
    '<h2>'+esc(pg.form_h2||'Wann ist es so weit?')+'</h2>'+
    '<p class="lead">'+esc(pg.form_lead||'')+'</p>'+
    (function(){
      /* Felder je nach Seiten-Konfiguration einsammeln und paarweise in Zeilen legen */
      var fields=[
        '<div><label for="cpName">'+esc(cfg.name_label||'Name *')+'</label><input type="text" id="cpName" required></div>'
      ];
      if(cfg.company_label)fields.push('<div><label for="cpCompany">'+esc(cfg.company_label)+'</label><input type="text" id="cpCompany"></div>');
      fields.push('<div><label for="cpEmail">E-Mail *</label><input type="email" id="cpEmail" required></div>');
      fields.push('<div><label for="cpPhone">Telefon</label><input type="tel" id="cpPhone"></div>');
      if(cfg.show_date!==false)fields.push('<div><label for="cpDate">Wunschtermin</label><input type="date" id="cpDate"></div>');
      if(typeField)fields.push(typeField);
      if(cfg.show_guests)fields.push('<div><label for="cpGuests">'+esc(cfg.guests_label||'Gäste (ca.)')+'</label><input type="number" id="cpGuests" min="1" placeholder="'+esc(cfg.guests_ph||'z. B. 80')+'"></div>');
      var rows='';
      for(var i=0;i<fields.length;i+=2)rows+='<div class="row">'+fields[i]+(fields[i+1]||'')+'</div>';
      return '<form id="inqForm"><div class="form-msg" id="formMsg"></div>'+rows;
    })()+
      '<label for="cpLocation">'+esc(cfg.location_label||'Ort / Location')+'</label>'+
      '<input type="text" id="cpLocation" placeholder="'+esc(cfg.location_ph||'')+'">'+
      '<label for="cpMsg">'+esc(cfg.msg_label||'Worum geht es?')+'</label>'+
      '<textarea id="cpMsg" maxlength="4000" placeholder="'+esc(cfg.msg_ph||'')+'"></textarea>'+
      /* Honigtopf gegen Bots: für Menschen unsichtbar, bleibt leer */
      '<div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">'+
        '<label for="cpWebsite">Website (bitte frei lassen)</label>'+
        '<input type="text" id="cpWebsite" tabindex="-1" autocomplete="off"></div>'+
      '<button class="btn" type="submit" id="sendBtn">Anfrage senden</button>'+
    '</form>'+
    ((waDigits||tel)?'<div style="margin-top:18px;font-size:13px;color:var(--mut2)">Lieber direkt schreiben? '+
      (waDigits?'<a href="https://wa.me/'+waDigits+'?text='+encodeURIComponent(wa)+'" target="_blank" rel="noopener">'+ic('chat')+'WhatsApp</a>':'')+
      (waDigits&&tel?' &nbsp;·&nbsp; ':'')+(tel?'<a href="tel:'+esc(tel.replace(/[^\d+]/g,''))+'">'+esc(tel)+'</a>':'')+'</div>':'')+
  '</div></section>'+

  '<footer><div class="wrap">'+
    '<div>'+esc([brand,CO.owner,coCity()].filter(Boolean).join(' · '))+'</div>'+
    '<div><a href="index.html?legal=impressum">Impressum</a> &nbsp;·&nbsp; <a href="index.html?legal=datenschutz">Datenschutz</a> &nbsp;·&nbsp; <a href="'+homeHref+'">'+esc(homeLabel)+'</a></div>'+
  '</div></footer>';

  /* Kein Termin in der Vergangenheit auswählbar */
  var dEl=document.getElementById('cpDate');
  if(dEl)dEl.min=new Date().toISOString().slice(0,10);
  document.getElementById('inqForm').addEventListener('submit',function(ev){
    ev.preventDefault();
    var v=function(id){var el=document.getElementById(id);return el?el.value.trim():''};
    var msg=document.getElementById('formMsg'),btn=document.getElementById('sendBtn');
    var extra=[];
    if(cfg.company_label&&v('cpCompany'))extra.push(cfg.company_label.replace(/\s*\*.*$/,'')+': '+v('cpCompany'));
    var data={
      name:v('cpName'),email:v('cpEmail'),phone:v('cpPhone'),
      event_type:types.length>1?v('cpType'):types[0],
      event_date:v('cpDate')||null,guests:v('cpGuests')||null,
      location:v('cpLocation'),
      website:v('cpWebsite'),
      message:(extra.length?extra.join('\n')+'\n':'')+v('cpMsg')
    };
    if(!data.name||!data.email){msg.className='form-msg err';msg.textContent='Bitte Name und E-Mail angeben.';return}
    btn.disabled=true;btn.textContent='Wird gesendet …';
    fetch(API+'/rest/inquiries',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})
    .then(function(r){
      if(!r.ok)throw new Error('HTTP '+r.status);
      return r.json().catch(function(){return null});
    })
    .then(function(antwort){
      /* Kurzfristige Termine bekommen dieselbe ehrliche Ansage wie auf der Startseite */
      var tage=data.event_date?Math.round((new Date(data.event_date)-new Date())/86400000):null;
      msg.className='form-msg ok';
      var text=(tage!=null&&tage>=0&&tage<=10)
        ?'Danke! Euer Termin ist ja bald – ich melde mich so schnell wie möglich, meist noch am selben Tag. Wenn es eilig ist, schreibt mir gern zusätzlich per WhatsApp.'
        :(cfg.success_text||'Danke! Eure Anfrage ist angekommen – ich melde mich innerhalb von 24 Stunden mit einer ehrlichen Antwort.');
      /* Der Technik-Check-Text verspricht den Fragebogen "gleich im Postfach". Kommt vom
         Server kein Link (Bogen-Vorlage fehlt, Kunde ohne Mail), darf die Seite das nicht
         versprechen - dann schickt Markus ihn von Hand. */
      if(!(antwort&&antwort.form_link)&&/Fragebogen|Postfach/.test(text))
        text='Danke! Eure Anfrage ist angekommen. Den kurzen Vorab-Fragebogen bekommt ihr von mir per Mail, sobald ich die Anfrage gesehen habe – ich melde mich innerhalb von 24 Stunden.';
      msg.textContent=text;
      document.getElementById('inqForm').reset();
      msg.scrollIntoView({block:'center'});
      /* Beim Technik-Check kommt der Fragebogen-Link mit: direkt anzeigen, damit das
         Versprechen auch hält, wenn die Mail im Spamfilter hängt. */
      if(antwort&&antwort.form_link){
        var box=document.createElement('div');
        box.style.cssText='margin-top:14px;padding:16px 18px;border:1px solid var(--acc);border-radius:12px;background:var(--card)';
        box.innerHTML='<div style="font-weight:600;margin-bottom:6px">Euer Vorab-Fragebogen</div>'+
          '<div style="color:var(--mut);font-size:14px;line-height:1.6">Er ist auch per Mail unterwegs – hier könnt ihr ihn direkt ausfüllen:</div>'+
          '<a class="btn" style="margin-top:12px" href="'+esc(antwort.form_link)+'">Fragebogen öffnen</a>';
        msg.parentNode.insertBefore(box,msg.nextSibling);
      }
      showRegHint(data);
    }).catch(function(){
      msg.className='form-msg err';
      msg.textContent='Senden fehlgeschlagen – bitte versucht es später erneut'+(CO.phone?' oder ruft direkt an: '+CO.phone:'')+'.';
    }).finally(function(){btn.disabled=false;btn.textContent='Anfrage senden'});
  });
}

/* Hinweis aufs Kundenkonto nach dem Absenden. Bewusst als ruhiger Kasten direkt unter
   der Bestaetigung statt als Overlay: kein Zwang, keine Tastaturfalle, nichts zum Wegklicken. */
function showRegHint(data){
  if(document.getElementById('regHint'))return;
  var msg=document.getElementById('formMsg');
  if(!msg)return;
  var box=document.createElement('div');
  box.id='regHint';
  box.style.cssText='margin:0 0 18px;padding:18px 20px;border:1px solid var(--line);border-radius:14px;background:var(--card)';
  box.innerHTML='<div style="font-family:\'Space Grotesk\',sans-serif;font-weight:700;margin-bottom:6px">Wenn ihr mögt: Kundenkonto anlegen</div>'+
    '<div style="color:var(--mut);font-size:14px;line-height:1.7">Ihr müsst nicht – ich melde mich so oder so. Mit Konto seht ihr aber jederzeit, was schon geplant ist, habt alle Unterlagen an einem Ort und tragt Adresse und Eckdaten selbst ein, statt sie am Telefon zu diktieren. Kostenlos, kein Abo.</div>'+
    '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:14px"><a class="btn" href="portal.html?register=1&email='+encodeURIComponent(data.email)+'&name='+encodeURIComponent(data.name)+'">Kostenlos registrieren</a>'+
    '<button type="button" class="btn ghost" onclick="document.getElementById(\'regHint\').remove()">Vielleicht später</button></div>';
  msg.parentNode.insertBefore(box,msg.nextSibling);
}

/* Anonyme Reichweiten-Zählung: nur Seitenname + Referrer-Domain, keine Cookies, keine IDs */
try{var _tp=JSON.stringify({p:SLUG+'.html',r:document.referrer||''});
navigator.sendBeacon?navigator.sendBeacon(API+'/track',_tp):fetch(API+'/track',{method:'POST',body:_tp,keepalive:true});}catch(e){}

Promise.all([
  fetch(API+'/rest/campaign_pages?slug=eq.'+encodeURIComponent(SLUG)).then(function(r){return r.json()}),
  fetch(API+'/rest/site_content?select=key,value').then(function(r){return r.json()}).catch(function(){return[]}),
  fetch(API+'/public/company').then(function(r){return r.json()}).catch(function(){return{}}),
  loadTheme()
]).then(function(res){
  var rows=res[0]||[],pg=rows[0];
  CO=res[2]||{};
  if(!pg||!Number(pg.enabled)){location.replace('index.html');return}
  var site={};(res[1]||[]).forEach(function(row){site[row.key]=row.value});
  render(pg,site);
}).catch(function(){location.replace('index.html')});
})();
