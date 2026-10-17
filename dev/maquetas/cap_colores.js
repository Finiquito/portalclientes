const {chromium}=require('playwright');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1180,height:1000},deviceScaleFactor:1.4});
for(const t of ['claro','oscuro']){await p.goto('file:///home/user/portalclientes/dev/maquetas/colores.html?tema='+t);await p.waitForTimeout(500);
await p.screenshot({path:`/home/user/portalclientes/entregas/maqueta-onboarding/colores-${t}.png`,fullPage:true});}
await b.close();})();
