// Piezas de ejemplo de «Panadería Ruiz» para los videos (render HTML → JPG).
const {chromium}=require('playwright');const fs=require('fs');
const F='file://'+__dirname+'/../../theme/prisma/assets/fonts/schibsted-grotesk-latin-wght-normal.woff2';
const base=(w,h,cuerpo)=>`<!doctype html><meta charset=utf-8><style>@font-face{font-family:S;src:url(${F})}
*{box-sizing:border-box}body{margin:0;width:${w}px;height:${h}px;overflow:hidden;font-family:S,sans-serif;position:relative}
.marca{position:absolute;left:70px;bottom:60px;font-weight:700;font-size:30px;letter-spacing:.08em;text-transform:uppercase;opacity:.85}
.n{position:absolute;right:70px;top:60px;font-size:28px;font-weight:600;opacity:.7}
.miga{position:absolute;border-radius:50%}
</style>${cuerpo}`;
const pan=(x,y,s,c1,c2)=>`<div style="position:absolute;left:${x}px;top:${y}px;width:${s}px;height:${s*.62}px;border-radius:48% 52% 44% 46%/60% 60% 40% 40%;background:radial-gradient(ellipse at 40% 30%,${c1},${c2});box-shadow:0 30px 60px rgba(60,30,10,.35)"><div style="position:absolute;left:18%;top:28%;width:64%;height:8%;border-radius:40px;background:rgba(255,235,200,.55);transform:rotate(-8deg)"></div><div style="position:absolute;left:22%;top:48%;width:56%;height:8%;border-radius:40px;background:rgba(255,235,200,.45);transform:rotate(-8deg)"></div></div>`;
const piezas={
 '1-1':base(1080,1350,`<div style="position:absolute;inset:0;background:#f3e6cf"></div>${pan(240,640,620,'#d99a4e','#8a4b1c')}
  <h1 style="position:absolute;left:70px;top:140px;margin:0;font-size:132px;line-height:.95;color:#3b2412;letter-spacing:-.03em">48 horas<br>de paciencia</h1>
  <p style="position:absolute;left:74px;top:430px;margin:0;font-size:36px;color:#7a4a25">Así hacemos nuestro pan.</p><div class="n" style="color:#3b2412">1/3</div><div class="marca" style="color:#3b2412">Panadería Ruiz</div>`),
 '1-2':base(1080,1350,`<div style="position:absolute;inset:0;background:#3b2412"></div>
  <div style="position:absolute;left:330px;top:560px;width:420px;height:560px;border-radius:40px 40px 60px 60px;background:linear-gradient(#f6ead2,#e8cf9f);box-shadow:inset 0 -40px 60px rgba(120,70,20,.25)"></div>
  ${[...Array(14)].map((_,i)=>`<div class="miga" style="left:${360+((i*73)%360)}px;top:${640+((i*131)%420)}px;width:${18+(i%4)*10}px;height:${18+(i%4)*10}px;background:rgba(160,110,50,.35)"></div>`).join('')}
  <h1 style="position:absolute;left:70px;top:140px;margin:0;font-size:112px;line-height:.98;color:#f3e6cf;letter-spacing:-.03em">Nuestra masa<br>madre tiene<br><span style="color:#f0a24a">6 años</span></h1><div class="n" style="color:#f3e6cf">2/3</div><div class="marca" style="color:#f3e6cf">Panadería Ruiz</div>`),
 '1-3':base(1080,1350,`<div style="position:absolute;inset:0;background:#c8553d"></div>${pan(180,560,720,'#f0c27a','#b06a2c')}
  <h1 style="position:absolute;left:70px;top:150px;margin:0;font-size:150px;line-height:.92;color:#fff3e0;letter-spacing:-.03em">Por eso<br>dura más.</h1><div class="n" style="color:#fff3e0">3/3</div><div class="marca" style="color:#fff3e0">Panadería Ruiz</div>`),
 'story':base(1080,1920,`<div style="position:absolute;inset:0;background:linear-gradient(#f3e6cf,#e9d2a8)"></div>
  <h1 style="position:absolute;left:80px;top:300px;margin:0;font-size:120px;line-height:.95;color:#3b2412;letter-spacing:-.03em">¿Dulce<br>o salado?</h1>
  <div style="position:absolute;left:80px;right:80px;top:820px;display:grid;gap:40px">${['🥐  Dulce','🥖  Salado'].map(t=>`<div style="background:#fff;border-radius:32px;padding:46px 56px;font-size:60px;font-weight:700;color:#3b2412;box-shadow:0 20px 40px rgba(60,30,10,.15)">${t}</div>`).join('')}</div>
  <div class="marca" style="color:#3b2412;bottom:140px">Panadería Ruiz</div>`),
 'reel':base(1080,1920,`<div style="position:absolute;inset:0;background:#1f140b"></div>${pan(130,980,820,'#e3a356','#7c3f15')}
  <h1 style="position:absolute;left:80px;top:320px;margin:0;font-size:128px;line-height:.95;color:#f3e6cf;letter-spacing:-.03em">5:00 am.<br><span style="color:#f0a24a">Ya estamos<br>amasando.</span></h1><div class="marca" style="color:#f3e6cf;bottom:140px">Panadería Ruiz</div>`),
};
(async()=>{const b=await chromium.launch();for(const[k,html]of Object.entries(piezas)){const [w,h]=k.includes('-')?[1080,1350]:[1080,1920];
const p=await b.newPage({viewport:{width:w,height:h}});await p.setContent(html);await p.waitForTimeout(300);await p.screenshot({path:__dirname+'/arte/'+k+'.jpg',type:'jpeg',quality:88});await p.close();}
await b.close();console.log('ok');})();
