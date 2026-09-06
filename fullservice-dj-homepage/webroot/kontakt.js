/* Kontaktwege auf den öffentlichen Seiten (Startseite, Technik, Mieten, Aktionsseiten).
   Markus bekam auf der Telefonnummer zu viele Werbeanrufe – deshalb steht auf der Website
   standardmäßig keine Nummer mehr, sondern zwei gleichwertige Knöpfe: "Per WhatsApp
   schreiben" (wa.me mit der WhatsApp-Nummer aus public/company) und "Rückruf anfordern"
   (kleines Inline-Formular, POST public/callback). Die Nummer erscheint nur, wenn im
   Backoffice "Telefonnummer öffentlich zeigen" an ist (phone_public).
   Aufruf:  var KW=Kontakt.mount(el,{thema:'zu unserer Feier',page:'Startseite',fallbackWa:'4917...'});
            KW.update(CO)  – sobald public/company da ist (optional zweites Argument: Nummer
            aus den CMS-Kontaktdaten als Rückfall, falls die Firmendaten keine haben). */
(function(){
var WA='<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';
var PH='<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
var CSS='.kw-title{font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--mut2);margin-bottom:4px;font-weight:600}'+
  '.kw-lead{font-size:15px;line-height:1.65;color:var(--mut);margin:0 0 14px}'+
  '.kw-btns{display:flex;flex-wrap:wrap;gap:10px}.kw-btns .btn{display:inline-flex;align-items:center;gap:8px;text-decoration:none}'+
  '.kw-btns .btn .ic{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}'+
  '.kw-phone{margin-top:14px;font-size:15px}.kw-phone a{font-weight:600}'+
  '.kw-form{margin-top:16px;padding:18px 18px 6px;border:1px solid var(--line);border-radius:6px;background:var(--card)}'+
  '.kw-form label{margin-top:0}.kw-form input,.kw-form select,.kw-form textarea{margin-bottom:14px}.kw-form textarea{min-height:70px}'+
  '.kw-form .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:560px){.kw-form .row{grid-template-columns:1fr}}'+
  '.kw-ds{font-size:12.5px;line-height:1.6;color:var(--mut);margin:0 0 14px}'+
  '.kw-form .btn{margin:0 8px 12px 0}.kw-form .form-msg{margin-bottom:14px}'+
  '.kw-ok{padding:14px 16px;border:1px solid var(--ok-line,#3d6b3d);background:var(--ok-bg,rgba(60,120,60,.12));color:var(--ok,#9fd49f);border-radius:6px;font-size:15px;line-height:1.6}';
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
function style(){
  if(document.getElementById('kwStyle'))return;
  var s=document.createElement('style');s.id='kwStyle';s.textContent=CSS;document.head.appendChild(s);
}
function waText(co,thema,fix){return fix?fix:'Hallo'+(co.owner_first?' '+co.owner_first:'')+', ich habe eine Frage '+thema+': '}
function waHref(digits,text){return digits?'https://wa.me/'+digits+'?text='+encodeURIComponent(text):null}
function telHref(p){return 'tel:'+String(p).replace(/[^\d+]/g,'')}
/* Suchmaschinen-Angabe (JSON-LD) nur mit Nummer, wenn sie öffentlich sein darf. */
function ldTelephone(co){
  var s=document.querySelector('script[type="application/ld+json"]');if(!s)return;
  try{
    var j=JSON.parse(s.textContent);
    if(co.phone_public&&co.phone){var d=String(co.phone).replace(/\D/g,'');if(d.charAt(0)==='0')d='49'+d.slice(1);j.telephone='+'+d}
    else delete j.telephone;
    s.textContent=JSON.stringify(j);
  }catch(e){}
}
function mount(el,o){
  if(!el)return{update:function(){}};
  style();o=o||{};
  var thema=o.thema||'zu eurer Feier',api=o.api||'api.php',page=o.page||document.title||'',fix=o.waText||'';
  var co={whatsapp_digits:o.fallbackWa||''};
  el.innerHTML=
    '<div class="kw-title">'+esc(o.title||'So erreichst du mich')+'</div>'+
    '<p class="kw-lead">'+esc(o.lead||'Am schnellsten per WhatsApp. Oder ihr sagt mir, wann ich anrufen soll.')+'</p>'+
    '<div class="kw-btns">'+
      '<a class="btn kw-wa" id="cWa" href="#" target="_blank" rel="noopener" data-stat="whatsapp">'+WA+'<span>Per WhatsApp schreiben</span></a>'+
      '<button type="button" class="btn ghost kw-cb" id="cCallback">'+PH+'<span>Rückruf anfordern</span></button>'+
    '</div>'+
    '<div class="kw-phone" id="cPhoneWrap" style="display:none">Oder direkt anrufen: <a id="cPhone" href="#"></a></div>'+
    '<form class="kw-form" id="cbForm" style="display:none" novalidate>'+
      '<div class="form-msg" id="cbMsg"></div>'+
      '<div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"><label for="cbWebsite">Website (bitte frei lassen)</label><input type="text" id="cbWebsite" name="website" tabindex="-1" autocomplete="off"></div>'+
      '<div class="row">'+
        '<div><label for="cbName">Name *</label><input type="text" id="cbName" name="name" autocomplete="name" required></div>'+
        '<div><label for="cbPhone">Telefonnummer *</label><input type="tel" id="cbPhone" name="phone" autocomplete="tel" placeholder="mit Vorwahl" required></div>'+
      '</div>'+
      '<div class="row">'+
        '<div><label for="cbWhen">Wann passt es euch?</label><select id="cbWhen" name="when">'+
          '<option value="egal">Egal, wann es passt</option><option value="nachmittag">Heute Nachmittag</option><option value="abend">Heute Abend</option><option value="morgen">Morgen</option></select></div>'+
        '<div><label for="cbWhenNote">Genauer <span style="text-transform:none;letter-spacing:0;font-weight:400">(optional)</span></label><input type="text" id="cbWhenNote" name="when_note" placeholder="z. B. ab 17 Uhr, nicht vor 20 Uhr"></div>'+
      '</div>'+
      '<label for="cbNote">Worum geht es? <span style="text-transform:none;letter-spacing:0;font-weight:400">(optional)</span></label>'+
      '<textarea id="cbNote" name="note" maxlength="1000" placeholder="z. B. Hochzeit im Juni, Technik fürs Vereinsfest …"></textarea>'+
      '<p class="kw-ds">Name und Nummer nutze ich nur für diesen Rückruf. Sie liegen auf meinem eigenen Server und gehen nicht an Dritte (Art. 6 Abs. 1 lit. b DSGVO).</p>'+
      '<button class="btn" type="submit" id="cbSend">Rückruf anfordern</button><button class="btn ghost" type="button" id="cbCancel">Abbrechen</button>'+
    '</form>';
  var wa=el.querySelector('#cWa'),cb=el.querySelector('#cCallback'),pw=el.querySelector('#cPhoneWrap'),ph=el.querySelector('#cPhone'),
      form=el.querySelector('#cbForm'),msg=el.querySelector('#cbMsg');
  function render(){
    var h=waHref(co.whatsapp_digits||'',waText(co,thema,fix));
    if(h){wa.href=h;wa.style.display=''}else wa.style.display='none';
    var nr=co.phone_public?(co.phone||co.contact_phone||''):'';
    if(nr){ph.textContent=nr;ph.href=telHref(nr);pw.style.display=''}else pw.style.display='none';
  }
  render();
  cb.addEventListener('click',function(){
    var open=form.style.display==='none';
    form.style.display=open?'':'none';
    if(open){var n=el.querySelector('#cbName');if(n)setTimeout(function(){n.focus()},50)}
  });
  el.querySelector('#cbCancel').addEventListener('click',function(){form.style.display='none'});
  form.addEventListener('submit',function(ev){
    ev.preventDefault();
    var v=function(id){var x=el.querySelector('#'+id);return x?x.value.trim():''};
    var data={name:v('cbName'),phone:v('cbPhone'),when:v('cbWhen'),when_note:v('cbWhenNote'),note:v('cbNote'),page:page,website:v('cbWebsite')};
    if(!data.name||!data.phone){msg.className='form-msg err';msg.textContent='Bitte Name und Telefonnummer angeben – sonst kann ich nicht zurückrufen.';return}
    if(data.phone.replace(/\D/g,'').length<8){msg.className='form-msg err';msg.textContent='Die Nummer sieht nicht vollständig aus – bitte mit Vorwahl.';return}
    var btn=el.querySelector('#cbSend');btn.disabled=true;btn.textContent='Wird gesendet …';
    fetch(api+'/public/callback',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})
      .then(function(r){return r.json().catch(function(){return{}}).then(function(j){if(!r.ok)throw new Error(j.error||'HTTP '+r.status);return j})})
      .then(function(){
        if(window.Stat)Stat.click('rueckruf');
        var WHEN={egal:'',nachmittag:' heute Nachmittag',abend:' heute Abend',morgen:' morgen'};
        form.innerHTML='<div class="kw-ok"><b>Danke, ich rufe zurück.</b>'+(WHEN[data.when]||'')+' Ihr hört von mir.</div>';
        form.style.marginBottom='0';form.style.padding='0';form.style.border='0';form.style.background='transparent';
        cb.style.display='none';
      })
      .catch(function(e){
        msg.className='form-msg err';msg.textContent=e.message||'Senden fehlgeschlagen – bitte versucht es später noch einmal oder schreibt mir per WhatsApp.';
        btn.disabled=false;btn.textContent='Rückruf anfordern';
      });
  });
  return {
    update:function(c,contactPhone){co=Object.assign({},c||{});if(contactPhone)co.contact_phone=contactPhone;render();ldTelephone(co)},
    waHref:function(text){return waHref(co.whatsapp_digits||'',text||waText(co,thema,fix))}
  };
}
window.Kontakt={mount:mount,ldTelephone:ldTelephone};
})();
