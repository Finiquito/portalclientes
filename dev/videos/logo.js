const {chromium}=require('playwright');(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1200,height:1200}});
await p.setContent(`<body style="margin:0;width:1200px;height:1200px;display:grid;place-items:center;background:#f3e6cf;font-family:Georgia,serif">
<div style="text-align:center;color:#3b2412"><div style="width:360px;height:360px;margin:0 auto;border-radius:50%;background:#3b2412;display:grid;place-items:center;color:#f3e6cf;font-size:170px;font-weight:700;font-style:italic">R</div>
<div style="font-size:110px;font-weight:700;margin-top:60px;letter-spacing:.02em">Panadería Ruiz</div><div style="font-size:44px;letter-spacing:.4em;margin-top:18px;color:#a0602c">DESDE 1987</div></div></body>`);
await p.screenshot({path:__dirname+'/arte/logo-panaderia-ruiz.png'});await b.close();})();
