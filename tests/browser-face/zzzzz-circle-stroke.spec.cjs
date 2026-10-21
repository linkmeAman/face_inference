const {test,expect}=require('@playwright/test');
// Verifies the visual circle change WITHOUT touching detection geometry: the white
// guide outline and the green progress ring must render at ~3 CSS px (thicker than
// before) and remain coincident on guide.radius, which is itself unchanged.
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});

test('white guide outline measures ~3px thick and does not move guide.radius',async({page,context})=>{
 const login=await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);
 await page.goto('/tests/browser-face/embedded.php?pending=1');await expect(page.locator('#enroll-1')).toBeVisible();
 await page.locator('#enroll-1').click();
 await expect.poll(()=>page.evaluate(()=>testFlow.state.guide&&testFlow.state.busy?1:0),{timeout:20000}).toBe(1);
 await page.waitForTimeout(600);
 // Scan a horizontal line through the guide centre on the overlay canvas and measure
 // the white rim width where it crosses guide.x +/- guide.radius.
 const m=await page.evaluate(()=>{
  const guide=testFlow.state.guide,canvas=document.getElementById('automaticFaceOverlay');
  const x=canvas.getContext('2d');const row=Math.round(guide.y);
  const data=x.getImageData(0,row,canvas.width,1).data;
  // white pixels: high rgb AND some alpha
  const white=i=>data[i*4]>200&&data[i*4+1]>200&&data[i*4+2]>200&&data[i*4+3]>120;
  // rightmost rim near guide.x+radius
  let runs=[],cur=0;
  for(let i=0;i<canvas.width;i++){if(white(i))cur++;else{if(cur)runs.push(cur);cur=0;}}
  if(cur)runs.push(cur);
  return {radius:guide.radius,canvasW:canvas.width,rimRuns:runs,maxRun:Math.max(0,...runs)};
 });
 // Two rim crossings (left+right) at the centre row; each ~3px (allow 2-6 for AA/scaling).
 expect(m.rimRuns.length).toBeGreaterThanOrEqual(2);
 for(const run of m.rimRuns){expect(run).toBeGreaterThanOrEqual(2);expect(run).toBeLessThanOrEqual(6);}
 await page.getByRole('button',{name:'Close',exact:true}).click();
});

test('green ring stroke renders thicker (~3px) via non-scaling-stroke and matches the white outline',async({page,context})=>{
 await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});
 await page.goto('/tests/browser-face/embedded.php?pending=1');await expect(page.locator('#enroll-1')).toBeVisible();
 await page.locator('#enroll-1').click();
 await expect(page.locator('.browser-face-progress-ring')).toBeVisible({timeout:20000});
 await expect.poll(()=>page.evaluate(()=>testFlow.state.progress),{timeout:20000}).toBeGreaterThan(0);
 const ring=await page.evaluate(()=>{
  const el=document.querySelector('.browser-face-progress-ring circle');
  const cs=getComputedStyle(el);
  const box=el.getBoundingClientRect();
  // Rendered stroke width in CSS px: non-scaling-stroke => stroke-width attr value in px.
  const svg=el.closest('svg').getBoundingClientRect();
  return {strokeWidthAttr:el.getAttribute('stroke-width'),vectorEffect:el.getAttribute('vector-effect'),linecap:el.getAttribute('stroke-linecap'),svgW:svg.width,guideDiameter:2*testFlow.state.guide.radius};
 });
 expect(ring.vectorEffect).toBe('non-scaling-stroke');
 expect(ring.strokeWidthAttr).toBe('3');
 expect(ring.linecap).toBe('round');
 // SVG spans the full diameter so the ring sits exactly on guide.radius (coincident with white).
 expect(Math.abs(ring.svgW-ring.guideDiameter)).toBeLessThan(1.5);
 await page.getByRole('button',{name:'Close',exact:true}).click();
});
