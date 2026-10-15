const {chromium}=require('playwright');const fs=require('fs');
const B='http://127.0.0.1:8081', W=864, H=1080, ESC=1.25;
const DOCX={name:'Grilla noviembre - Panadería Ruiz.docx',mimeType:'application/vnd.openxmlformats-officedocument.wordprocessingml.document',buffer:fs.readFileSync(__dirname+'/grilla-noviembre.docx')};
const LOGO=fs.readFileSync(__dirname+'/logo.svg.txt','utf8');
const esperar=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
  const b=await chromium.launch();
  // Preparación (no se graba): sesión y entrega vacía.
  const pre=await b.newContext({viewport:{width:W,height:H}}); const pp=await pre.newPage();
  await pp.goto(B+'/dev/equipo?email=camila@estudiopampa.cl');
  await pp.goto(B+'/equipo/entregas/nuevo'); await pp.fill('input[name=titulo]','Grilla noviembre · Instagram');
  await Promise.all([pp.waitForNavigation(),pp.click('button:has-text("Crear")')]);
  const URL_E=pp.url(); const estado=await pre.storageState(); await pre.close();

  const ctx=await b.newContext({viewport:{width:W,height:H},deviceScaleFactor:ESC,storageState:estado});
  await ctx.addInitScript({path:__dirname+'/overlay.js'});
  const p=await ctx.newPage(); const t0=Date.now();
  fs.rmSync(__dirname+'/cuadros',{recursive:true,force:true}); fs.mkdirSync(__dirname+'/cuadros');
  const cdp=await ctx.newCDPSession(p); const cuadros=[]; let nC=0;
  cdp.on('Page.screencastFrame',async f=>{const nom='c'+String(nC++).padStart(5,'0')+'.jpg';fs.writeFileSync(__dirname+'/cuadros/'+nom,Buffer.from(f.data,'base64'));cuadros.push([nom,f.metadata.timestamp]);try{await cdp.send('Page.screencastFrameAck',{sessionId:f.sessionId});}catch(e){}});
  let grabando=false; async function grabar(){if(grabando)return;grabando=true;await cdp.send('Page.startScreencast',{format:'jpeg',quality:92,maxWidth:1080,maxHeight:1350,everyNthFrame:1});}
  p.on('pageerror',e=>console.log('ERR',e.message));
  const dm=(fn,...a)=>p.evaluate(([fn,a])=>window.__dm[fn](...a),[fn,a]);
  async function ir(x,y,ms=900){await dm('mover',x,y,ms);await esperar(ms+80);}
  async function a(loc,dx=0.5,dy=0.5,ms=900){const r=await loc.boundingBox();await ir(r.x+r.width*dx,r.y+r.height*dy,ms);}
  async function globo(t,s,ms,lado){await dm('globo',t+(s?'<small>'+s+'</small>':''),lado||'');await esperar(ms);await dm('sinGlobo');await esperar(250);}
  async function bajar(loc,top=150){const r=await loc.boundingBox();await p.evaluate(([y])=>window.scrollBy({top:y,behavior:'smooth'}),[r.y-top]);await esperar(900);}
  async function escribir(loc,txt){await loc.click();for(const ch of txt){await p.keyboard.type(ch);await esperar(70);}}

  await p.goto(URL_E); await p.waitForLoadState('networkidle'); const tInicio=(Date.now()-t0)/1000;
  await grabar(); await esperar(700);
  await bajar(p.locator('[data-dz]'),330);
  // 1. Soltar la grilla
  const dz=p.locator('[data-dz]');
  await a(dz,0.5,0.2,1100);
  await globo('Me llegó la grilla de noviembre en Word.','La suelto tal cual en la entrega.',2600);
  const r=await dz.boundingBox();
  await dm('archivo',DOCX.name,W+40,r.y+r.height*0.25,r.x+r.width*0.28,r.y+r.height*0.35);
  await ir(r.x+r.width*0.28+40,r.y+r.height*0.35+30,1100); await esperar(250); await dm('clic');
  await Promise.all([p.waitForURL(/grilla/),p.setInputFiles('[data-dz-input]',DOCX)]);
  // 2. La IA lee
  await esperar(300);
  const tarj=p.locator('text=Leyendo la grilla con IA');
  if(await tarj.count()){await a(tarj,0.9,1.4,700);await dm('globo','La IA separa cada pieza: tipo, objetivo, láminas, copy y notas.<small>Sin reescribir el copy.</small>','');}
  await p.locator('button:has-text("Crear contenidos")').waitFor({timeout:30000}); await esperar(500); await dm('sinGlobo');
  // 3. Revisar
  const cuenta=p.locator('input[placeholder="@tumarca"]');
  await a(cuenta,0.3,0.5,900);
  await globo('4 piezas listas para revisar.','Nada se crea hasta que yo lo diga.',2600);
  await dm('clic'); await escribir(cuenta,'@panaderiaruiz'); await esperar(500);
  const lam=p.locator('label:has-text("Láminas")');
  await bajar(lam.nth(0),420); await a(lam.nth(0),0.4,3.2,900);
  await globo('Carrusel de 3 láminas: qué se ve y qué texto lleva cada una.','',2800);
  const notas=p.locator('label:has-text("Notas del equipo")');
  await bajar(notas.nth(1),520); await a(lam.nth(1),0.4,3,800);
  await globo('El reel, con su guion escena por escena.','',2400);
  await a(notas.nth(1),0.3,2.4,800);
  await globo('El «Audio:» va a notas, no se mezcla con el copy.','',2400);
  await bajar(notas.nth(2),520); await a(notas.nth(2),0.3,2.4,900);
  await globo('«Validar el precio» queda como nota para el cliente.','',2600);
  const crear=p.locator('button:has-text("Crear contenidos")');
  await bajar(crear,700); await a(crear,0.5,0.5,1000);
  await globo('Reviso, ajusto si quiero… y creo todo de una vez.','',2400,'arriba');
  await dm('clic'); await esperar(200);
  await Promise.all([p.waitForNavigation(),crear.click()]);
  // 4. Resultado
  await p.waitForLoadState('networkidle'); await esperar(500);
  await p.evaluate(()=>document.querySelectorAll('body *').forEach(e=>{const cs=getComputedStyle(e);if(cs.position==='fixed'&&e.textContent.includes('creados')&&!e.id.startsWith('dm-'))e.remove();}));
  await bajar(p.locator('h2:has-text("Contenidos")').first(),90);
  const pieza=p.locator('text=Post 1 · Masa madre').first();
  if(await pieza.count()){await a(pieza,0.5,0.5,1000);}
  await globo('Listo: 4 piezas en la entrega, con lo que le falta a cada una.','Ahora suelto las imágenes y van solas a su lámina.',3800,'abajo');
  await dm('fin','<div style="text-align:center;color:#141c40;font-family:Schibsted Grotesk,system-ui,sans-serif">'+LOGO+'<p style="font-size:44px;font-weight:800;margin:40px 0 0;line-height:1.1">De la grilla al portal,<br>en un minuto.</p><p style="font-size:28px;margin:28px 0 0;color:#3b4366">usaprisma.com · Beta gratis</p></div>');
  await esperar(3200);
  const dur=(Date.now()-t0)/1000;
  const tFin=Date.now()/1000; await cdp.send('Page.stopScreencast'); await esperar(300);
  const fin=Math.max(cuadros[cuadros.length-1][1]+0.5,tFin);
  let txt='';cuadros.forEach(([n,t],i)=>{const sig=i+1<cuadros.length?cuadros[i+1][1]:fin;txt+="file 'cuadros/"+n+"'\nduration "+Math.max(0.001,sig-t).toFixed(4)+"\n";});
  txt+="file 'cuadros/"+cuadros[cuadros.length-1][0]+"'\n";
  fs.writeFileSync(__dirname+'/lista.txt',txt); await ctx.close(); await b.close();
  console.log('cuadros',cuadros.length,'dur',(fin-cuadros[0][1]).toFixed(1));
})();
