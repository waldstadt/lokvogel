/* Gemeinsames Farbschema fuer alle Seiten (Startseite, Technik, Mietkatalog,
   Aktionsseiten, Kundenkonto, Backoffice-Vorschau).

   Markus waehlt im Backoffice nur zwei Farben je Seitenfamilie: Akzent ("primary")
   und Hintergrund ("bg"), dazu die Ueberschriften-Schrift. Alles andere - Karten,
   Linien, Text, gedaempfter Text, Button-Schrift, Hinweisfarben - leitet dieses
   Skript daraus ab, damit auch ein heller Hintergrund funktioniert. Jede wichtige
   Kombination wird rechnerisch auf WCAG-AA-Kontrast geprueft (4,5:1); der
   Vordergrund wird notfalls Richtung Weiss bzw. Schwarz gezogen, der Farbton bleibt.

   Verwendung:
     applyTheme({primary:'#ff6f5b',bg:'#0f1012',font:'grotesk',alt:'#3cc8b4'}, opts)
       setzt alle CSS-Variablen auf <html> (oder opts.root) und gibt die abgeleitete
       Palette samt Kontrast-Tabelle zurueck. "alt" ist der Akzent der jeweils anderen
       Seitenfamilie (Wechsel-Link "Veranstaltungstechnik" bzw. Technik-Teaser).
     applyCachedTheme('dj'|'technik', opts)
       wendet das zuletzt gesehene Schema aus localStorage synchron an, bevor der
       Browser das erste Bild malt - kein Aufblitzen der Standardfarben.
     rememberTheme('dj'|'technik', theme)  legt das Schema fuer den naechsten Aufruf ab.
     deriveTheme(theme)                    rechnet nur, ohne etwas zu setzen.

   Kein Modul, keine Fremdquellen - laeuft als einfaches Skript im <head>. */
(function(){
var WHITE=[255,255,255],BLACK=[0,0,0],INK=[22,17,15];
var FONT_HEADS={grotesk:"'Space Grotesk',sans-serif",outfit:"'Outfit',sans-serif",playfair:"'Playfair Display',serif",poppins:"'Poppins',sans-serif",montserrat:"'Montserrat',sans-serif",bebas:"'Bebas Neue',cursive",merriweather:"'Merriweather',serif",oswald:"'Oswald',sans-serif",caveat:"'Caveat',cursive",anton:"'Anton',sans-serif"};
var DEFAULTS={dj:{primary:'#ff6f5b',bg:'#0f1012',font:'grotesk',alt:'#3cc8b4'},technik:{primary:'#3cc8b4',bg:'#0e1213',font:'grotesk',alt:'#ff6f5b'}};
var CACHE_KEY='theme_cache';

function hexRgb(h){h=String(h||'').trim().replace('#','');if(h.length===3)h=h.replace(/(.)/g,'$1$1');
  if(!/^[0-9a-f]{6}$/i.test(h))return null;return [parseInt(h.slice(0,2),16),parseInt(h.slice(2,4),16),parseInt(h.slice(4,6),16)]}
function rgbHex(c){return '#'+c.map(function(v){v=Math.max(0,Math.min(255,Math.round(v)));return (v<16?'0':'')+v.toString(16)}).join('')}
function rgbList(c){return c.map(function(v){return Math.round(v)}).join(',')}
function lum(c){var a=c.map(function(v){v/=255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4)});return .2126*a[0]+.7152*a[1]+.0722*a[2]}
function ratio(a,b){var l1=lum(a),l2=lum(b);return (Math.max(l1,l2)+.05)/(Math.min(l1,l2)+.05)}
function mix(a,b,t){return [0,1,2].map(function(i){return a[i]+(b[i]-a[i])*t})}
/* Weiss oder Fast-Schwarz - was auf dieser Flaeche besser lesbar ist. */
function pickFg(bg){return ratio(INK,bg)>=ratio(WHITE,bg)?INK:WHITE}
/* Vordergrund in kleinen Schritten Richtung Weiss (dunkle Flaeche) bzw. Schwarz (helle
   Flaeche) ziehen, bis das Mindestverhaeltnis erreicht ist - Farbton bleibt erhalten. */
function fitTo(fg,bg,min){var target=lum(bg)<.18?WHITE:BLACK,c=fg,i=0;while(ratio(c,bg)<min&&i<60){c=mix(c,target,.06);i++}return c}
/* Flaeche, auf der weder Weiss noch Fast-Schwarz 4,5:1 erreicht (Mittelgrau): so lange
   Richtung Schwarz bzw. Weiss schieben, bis Text darauf lesbar ist. Bei den ueblichen
   dunklen oder hellen Hintergruenden aendert das nichts. */
function safeSurface(c){var i=0;while(ratio(pickFg(c),c)<4.5&&i<60){c=mix(c,lum(c)<.18?BLACK:WHITE,.06);i++}return c}

/* Aus {primary,bg,alt} die komplette Palette rechnen. Rueckgabe:
   {vars:{'--bg':'#..',...}, dark:true/false, contrast:{...}, warn:[...]}
   "warn" nennt die Paare, die ohne Nachziehen unter 4,5:1 laegen (das Backoffice
   zeigt das als Hinweis; die Seite selbst ist dank fitTo trotzdem lesbar). */
function deriveTheme(theme){
  theme=theme||{};
  var bgRaw=hexRgb(theme.bg)||hexRgb(DEFAULTS.dj.bg),acc=hexRgb(theme.primary)||hexRgb(DEFAULTS.dj.primary);
  var bg=safeSurface(bgRaw);
  var dark=pickFg(bg)===WHITE,to=dark?WHITE:BLACK;
  var bg2=safeSurface(mix(bg,to,dark?.025:.03));
  var card=safeSurface(mix(bg,to,dark?.045:.04)),card2=safeSurface(mix(bg,to,dark?.08:.075)),line=mix(bg,to,dark?.14:.16);
  var txt=fitTo(dark?[242,240,236]:INK,card,4.5);txt=fitTo(txt,bg,4.5);txt=fitTo(txt,bg2,4.5);
  var mut=fitTo(dark?[163,162,155]:[92,90,85],card,4.5);mut=fitTo(mut,bg,4.5);mut=fitTo(mut,bg2,4.5);
  var mut2=fitTo(dark?[143,142,136]:[107,105,100],card,4.5);mut2=fitTo(mut2,bg,4.5);mut2=fitTo(mut2,bg2,4.5);
  var accTxt=fitTo(fitTo(fitTo(acc,bg,4.5),card,4.5),bg2,4.5);
  var btnTxt=pickFg(acc),acc2=mix(acc,to,.15);
  /* Akzent der anderen Seitenfamilie (Wechsel-Link, Technik-Teaser auf der Startseite) */
  var alt=hexRgb(theme.alt)||acc;
  var altTxt=fitTo(fitTo(alt,bg,4.5),card,4.5),altBtn=pickFg(alt);
  var grn=fitTo(dark?[90,160,90]:[46,125,50],card,4.5);
  var okT=fitTo(dark?[159,212,159]:[31,107,31],card,4.5),errT=fitTo(dark?[224,160,160]:[143,42,42],card,4.5),warnT=fitTo(dark?[240,207,122]:[110,82,16],card,4.5);
  okT=fitTo(okT,bg,4.5);errT=fitTo(errT,bg,4.5);warnT=fitTo(warnT,bg,4.5);
  var star=fitTo([245,185,66],card,4.5);
  var v={};
  var set=function(k,c){v[k]=Array.isArray(c)?rgbHex(c):c};
  set('--bg',bg);set('--bg2',bg2);set('--card',card);set('--card2',card2);set('--line',line);
  set('--txt',txt);set('--mut',mut);set('--mut2',mut2);
  set('--acc',acc);set('--acc2',acc2);set('--acc-txt',accTxt);set('--btn-txt',btnTxt);
  set('--acc-rgb',rgbList(acc));set('--acc-soft','rgba('+rgbList(acc)+',.15)');
  set('--alt-acc',alt);set('--alt-acc-txt',altTxt);set('--alt-btn-txt',altBtn);set('--alt-acc-rgb',rgbList(alt));
  set('--grn',grn);set('--ok',okT);set('--err',errT);set('--warn',warnT);
  set('--ok-txt',okT);set('--err-txt',errT);set('--warn-txt',warnT);set('--red',errT);set('--star',star);
  set('--ok-bg',dark?'rgba(60,120,60,.12)':'rgba(46,125,50,.10)');set('--ok-line',dark?'#3d6b3d':'#8fc19a');
  set('--err-bg',dark?'rgba(150,60,60,.12)':'rgba(180,50,50,.08)');set('--err-line',dark?'#7a3535':'#d4a0a0');
  set('--warn-bg',dark?'rgba(200,160,40,.12)':'rgba(200,160,40,.14)');set('--warn-line',dark?'#8a6d1f':'#d9c27a');
  set('--line-soft',dark?'rgba(255,255,255,.06)':'rgba(0,0,0,.08)');
  set('--nav-bg','rgba('+rgbList(bg)+',.88)');
  /* Abdunkler ueber Hero-Bildern: auf heller Seite wird aufgehellt statt abgedunkelt */
  set('--scrim-rgb',dark?'0,0,0':'255,255,255');set('--scrim',dark?'rgba(0,0,0,.30)':'rgba(255,255,255,.55)');
  set('--overlay','rgba(0,0,0,'+(dark?'.78':'.55')+')');
  set('--shadow',dark?'rgba(0,0,0,.5)':'rgba(0,0,0,.18)');
  /* Alte Namen, die einzelne Regeln noch benutzen */
  set('--wh',txt);set('--pri',acc);
  set('--hf',FONT_HEADS[theme.font]||FONT_HEADS.grotesk);
  var contrast={txt_bg:ratio(txt,bg),txt_bg2:ratio(txt,bg2),txt_card:ratio(txt,card),btn_acc:ratio(btnTxt,acc),mut_card:ratio(mut,card),mut_bg:ratio(mut,bg),mut2_bg:ratio(mut2,bg),mut2_card:ratio(mut2,card),
    acc_bg:ratio(accTxt,bg),acc_card:ratio(accTxt,card),alt_bg:ratio(altTxt,bg),alt_card:ratio(altTxt,card),altbtn_alt:ratio(altBtn,alt),ok_card:ratio(okT,card),err_card:ratio(errT,card),warn_card:ratio(warnT,card),
    /* ohne Nachziehen: */raw_acc_bg:ratio(acc,bg),raw_acc_card:ratio(acc,card),raw_btn_acc:ratio(btnTxt,acc),raw_bg_changed:rgbHex(bg)!==rgbHex(bgRaw)};
  var warn=[];
  if(contrast.raw_acc_bg<4.5)warn.push('Akzent als Text auf dem Hintergrund nur '+fmt(contrast.raw_acc_bg)+' – Links und Hervorhebungen werden dafür '+(dark?'aufgehellt':'nachgedunkelt'));
  if(contrast.raw_btn_acc<4.5)warn.push('Schrift auf Akzent-Buttons nur '+fmt(contrast.raw_btn_acc)+' – Akzentfarbe ist zu mittel, besser deutlich hell oder dunkel wählen');
  if(contrast.raw_bg_changed)warn.push('Hintergrund ist ein Mittelton, auf dem weder helle noch dunkle Schrift lesbar wäre – wird zu '+rgbHex(bg)+' verschoben');
  return {vars:v,dark:dark,contrast:contrast,warn:warn,font:FONT_HEADS[theme.font]?theme.font:'grotesk'};
}
function fmt(r){return (Math.round(r*10)/10).toLocaleString('de-DE',{minimumFractionDigits:1,maximumFractionDigits:1})+':1'}

/* Palette auf das Dokument anwenden. opts:
     root          Element, dessen style die Variablen bekommt (Standard <html>)
     fontSelector  CSS-Selektor, der die gewaehlte Ueberschriften-Schrift bekommt
                   (Standard: h1-h4, .gro, .kicker, .btn, Logo-Wortmarke, Preis ...)
     favicon       false, wenn das Tab-Symbol nicht umgefaerbt werden soll */
function applyTheme(theme,opts){
  opts=opts||{};
  var d=deriveTheme(theme),root=opts.root||document.documentElement,st=root.style;
  Object.keys(d.vars).forEach(function(k){st.setProperty(k,d.vars[k])});
  if(root===document.documentElement){
    st.colorScheme=d.dark?'dark':'light';
    var sel=opts.fontSelector!==undefined?opts.fontSelector:'h1,h2,h3,h4,.gro,.kicker,.btn,.logo .wm,.badge b,.step .n,.pack .price';
    var fe=document.getElementById('themeFont');
    if(sel){
      if(!fe){fe=document.createElement('style');fe.id='themeFont';(document.head||document.documentElement).appendChild(fe)}
      fe.textContent=d.font==='grotesk'?'':sel+'{font-family:'+FONT_HEADS[d.font]+' !important}';
    }
    if(opts.favicon!==false){
      var ico=document.querySelector('link[rel=icon]');
      if(ico)ico.href=ico.href.replace(/%23[0-9a-f]{6}/i,'%23'+d.vars['--acc'].slice(1));
    }
  }
  window.THEME_CONTRAST=d.contrast;window.THEME_DARK=d.dark;
  return d;
}

/* Zuletzt gesehenes Schema je Seitenfamilie merken - sitzt in localStorage, damit der
   naechste Seitenaufruf sofort in den richtigen Farben startet. */
function readCache(){try{return JSON.parse(localStorage.getItem(CACHE_KEY)||'{}')||{}}catch(e){return {}}}
function rememberTheme(kind,theme){
  if(!theme||!theme.primary)return;
  try{var c=readCache();c[kind]={primary:theme.primary,bg:theme.bg,font:theme.font,alt:theme.alt};localStorage.setItem(CACHE_KEY,JSON.stringify(c))}catch(e){}
}
function applyCachedTheme(kind,opts){
  var t=readCache()[kind];
  if(!t||!t.primary)return null;
  window.THEME_FROM_CACHE=kind;
  return applyTheme(t,opts);
}
/* Das komplette Website-Schema aus den site_content-Zeilen ("theme", "theme_technik")
   fuer eine Seitenfamilie bauen: eigener Akzent + Hintergrund, Schrift immer aus "theme",
   "alt" ist der Akzent der anderen Familie. */
function themeFromContent(c,kind){
  c=c||{};var th=c.theme||{},tt=c.theme_technik||{};
  var own=kind==='technik'?tt:th,other=kind==='technik'?th:tt;
  var def=DEFAULTS[kind==='technik'?'technik':'dj'],odef=DEFAULTS[kind==='technik'?'dj':'technik'];
  return {primary:own.primary||def.primary,bg:own.bg||def.bg,font:th.font||'grotesk',alt:other.primary||odef.primary};
}

window.applyTheme=applyTheme;
window.applyCachedTheme=applyCachedTheme;
window.rememberTheme=rememberTheme;
window.deriveTheme=deriveTheme;
window.themeFromContent=themeFromContent;
window.themeTools={hexRgb:hexRgb,rgbHex:rgbHex,lum:lum,ratio:ratio,mix:mix,fitTo:fitTo,pickFg:pickFg,safeSurface:safeSurface,fmt:fmt,FONT_HEADS:FONT_HEADS,DEFAULTS:DEFAULTS};
})();
