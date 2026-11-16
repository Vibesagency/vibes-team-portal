(()=>{
const C=window.VCS,app=document.getElementById('app');
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function api(p,m='GET',b){const r=await fetch(C.root+p,{method:m,headers:{'X-WP-Nonce':C.nonce,'Content-Type':'application/json'},body:b?JSON.stringify(b):undefined,credentials:'same-origin'});let j={};try{j=await r.json()}catch(e){}if(!r.ok)throw new Error(j.message||'Something went wrong.');return j}
const toast=m=>{document.querySelectorAll('.toast').forEach(x=>x.remove());const t=document.createElement('div');t.className='toast';t.textContent=m;document.body.appendChild(t);setTimeout(()=>t.remove(),3500)};
const S={view:'home',tab:'plans',state:null,client:null,plan:null,settings:null,usage:null,poll:null};
const ASPECTS=[['social_post_4_5','Post 4:5'],['square_1_1','Square 1:1'],['social_story_9_16','Story / Reel 9:16'],['widescreen_16_9','Wide 16:9'],['classic_4_3','Classic 4:3']];
const monthNow=()=>{const d=new Date();d.setMonth(d.getMonth()+1);return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')};

function shell(inner){
  const mode=S.state&&S.state.mock?'<span class="pill warn">TEST MODE · no credits used</span>':'';
  const pct=S.state&&S.state.cap?Math.min(100,Math.round(S.state.used/S.state.cap*100)):0;
  app.innerHTML=`<header class="top"><a class="brand" href="#" data-go="home"><b>vibes</b> studio</a><div class="meta">${mode}<span class="use" title="Images used this month">${S.state?S.state.used:0} / ${S.state?S.state.cap:0} images <i><u style="width:${pct}%"></u></i></span>${C.admin?'<a href="#" data-go="settings">Settings</a>':''}<span class="who">${esc(C.user)}</span><a href="${esc(C.logout)}">Log out</a></div></header><main class="wrap">${inner}</main>`;
}
async function refreshState(){S.state=await api('state')}

/* ---------- home ---------- */
function home(){
  const cs=S.state.clients;
  shell(`<h1>Clients</h1><p class="sub">Every client has its own workspace: brand profile, monthly content plans and generated images.</p>
  <form class="inline" id="newclient"><input name="name" placeholder="New client name" maxlength="150" required><button class="btn">Add client</button></form>
  <div class="grid">${cs.map(c=>`<a href="#" class="card" data-client="${c.id}"><h3>${esc(c.name)}</h3><p>${c.plans} plan${c.plans==1?'':'s'} · ${c.images} image${c.images==1?'':'s'}</p></a>`).join('')||'<p class="empty">No clients yet. Add the first one above.</p>'}</div>`);
}
async function openClient(id,tab){
  S.client=await api('clients/'+id);S.view='client';S.tab=tab||S.tab;S.plan=null;render();startPoll();
}

/* ---------- client workspace ---------- */
function brandForm(b){
  const f=(k,l,ph,rows)=>`<label>${l}${rows?`<textarea name="${k}" rows="${rows}" placeholder="${esc(ph)}">${esc(b[k]||'')}</textarea>`:`<input name="${k}" value="${esc(b[k]||'')}" placeholder="${esc(ph)}">`}</label>`;
  return `<form id="brandform" class="form"><label>Client name<input name="client_name" value="${esc(S.client.name)}"></label>
  ${f('industry','Industry','e.g. Specialty coffee, Riyadh')}${f('audience','Target audience','Who are we talking to?',2)}${f('tone','Tone of voice','e.g. warm, confident, short sentences',2)}${f('language','Language','e.g. Arabic captions, English hashtags')}${f('colors','Brand colors','e.g. #1F3A2E deep green, #D9B26F gold')}${f('image_style','Image style (added to every image prompt)','Describe the look: lighting, materials, mood, composition…',4)}${f('avoid','Avoid','Things never to show or say',2)}${f('notes','Other notes','Offers, product names, hashtags to reuse…',3)}
  <button class="btn">Save brand profile</button></form>`;
}
function aiPrompt(f){const b=S.client.brand||{};return `You are a senior social media content strategist at Vibes Agency. Create a monthly Instagram content plan for ONE client.\n\nClient: ${S.client.name}\nMonth: ${f.month}\nNumber of posts: ${f.count}\nBrand profile: ${JSON.stringify(b)}\nBrief: ${f.brief||'none'}\n\nReply with JSON only (no prose, no code fences) in exactly this shape:\n{"posts":[{"n":1,"date":"YYYY-MM-DD","format":"Carousel|Single|Reel","pillar":"","topic":"","headline":"","caption":"","hashtags":"","image_prompt":""}]}\n\nWrite captions in the requested language and tone. image_prompt must be an English prompt for an AI image generator for the cover image, following the client's image style. Never include readable Arabic text inside the image.`}
function plansTab(){
  if(S.plan)return planView();
  const ps=S.client.plans,ai=S.state.ai;
  const common=`<div class="row"><label>Month<input type="month" name="month" value="${monthNow()}" required></label><label>Posts<input type="number" name="count" value="8" min="1" max="14"></label></div><label>Brief (optional)<textarea name="brief" rows="3" placeholder="Campaigns, launches, events or topics to include this month…"></textarea></label>`;
  const hist=`<h3>Previous plans</h3><div class="list">${ps.map(p=>`<a href="#" class="item" data-plan="${p.id}"><b>${esc(p.month)}</b><span>${esc((p.brief||'No brief').slice(0,90))}</span></a>`).join('')||'<p class="empty">No plans yet.</p>'}</div>`;
  if(ai)return `<form id="planform" class="form"><h3>Generate a monthly plan</h3>${common}<button class="btn" id="genplan">Generate plan</button><p class="hint">Takes 20–60 seconds. Review and edit the image prompts before generating images.</p></form>${hist}`;
  return `<form id="freeform" class="form"><h3>1 · Get the plan from Claude (free)</h3>${common}<button type="button" class="btn" data-copyprompt>Copy prompt for Claude</button><p class="hint">Open a new chat in Claude, paste the prompt, and send it. Then copy Claude's whole reply.</p><h3>2 · Paste Claude's reply here</h3><label>Claude's reply<textarea name="json" rows="8" placeholder='{"posts":[ ... ]}'></textarea></label><button class="btn">Import plan</button></form>${hist}`;
}
function imgsFor(pid,n){return S.client.images.filter(i=>i.plan_id==pid&&i.post_n==n)}
function thumb(i){
  if(i.status==='done')return `<a href="${esc(i.url)}" target="_blank" rel="noopener" class="th"><img src="${esc(i.url)}" alt=""></a>`;
  if(i.status==='failed')return `<span class="th bad" title="${esc(i.error)}">Failed<br><small>${esc(i.error)}</small></span>`;
  return `<span class="th wait">Generating…</span>`;
}
function planView(){
  const p=S.plan,posts=(p.data&&p.data.posts)||[];
  return `<p><a href="#" data-back>← All plans</a></p><h3>${esc(p.month)} plan</h3>${p.data&&p.data.mock?'<p class="pill warn">Sample plan (test mode)</p>':''}
  <div class="posts">${posts.map((x,k)=>{const im=imgsFor(p.id,x.n);return `<article class="post" data-n="${esc(x.n)}"><div class="ph"><span class="pill">${esc(x.format||'')}</span><span>${esc(x.date||'')}</span><span>${esc(x.pillar||'')}</span></div><h4>${esc(x.headline||x.topic||'')}</h4>
  <p class="cap">${esc(x.caption||'')}</p><p class="tags">${esc(x.hashtags||'')}</p><button class="mini" data-copy="${k}">Copy caption + hashtags</button>
  <label>Image prompt<textarea rows="4" data-prompt>${esc(x.image_prompt||'')}</textarea></label>
  <div class="row">${S.state.img?`<select data-aspect>${ASPECTS.map(a=>`<option value="${a[0]}"${(x.format==='Reel'&&a[0]==='social_story_9_16')?' selected':''}>${a[1]}</option>`).join('')}</select><button class="btn" data-genimg>Generate image</button>`:''}<button type="button" class="mini" data-copyimg>Copy image prompt</button><label class="up">Upload image<input type="file" accept="image/*" hidden data-upfile></label></div>
  <div class="thumbs">${im.map(thumb).join('')}</div></article>`}).join('')}</div>`;
}
function libraryTab(){
  const im=S.client.images.filter(i=>i.status==='done');
  return `<label class="up">Upload image<input type="file" accept="image/*" hidden data-upfile></label><div class="gallery">${im.map(i=>`<a href="${esc(i.url)}" target="_blank" rel="noopener" class="g"><img loading="lazy" src="${esc(i.url)}" alt=""><small>${esc(i.prompt.slice(0,60))}</small></a>`).join('')||'<p class="empty">No images yet.</p>'}</div>`;
}
function clientView(){
  const t=S.tab;
  shell(`<p><a href="#" data-go="home">← All clients</a></p><h1>${esc(S.client.name)}</h1><nav class="tabs">${[['plans','Content plans'],['brand','Brand profile'],['library','Image library']].map(x=>`<a href="#" data-tab="${x[0]}" class="${t===x[0]?'on':''}">${x[1]}</a>`).join('')}</nav>
  ${t==='brand'?brandForm(S.client.brand||{}):t==='library'?libraryTab():plansTab()}`);
}

/* ---------- settings (admin) ---------- */
async function settingsView(){
  const [s,u]=await Promise.all([api('settings'),api('usage')]);S.settings=s;S.usage=u;
  shell(`<p><a href="#" data-go="home">← Back</a></p><h1>Settings</h1>
  <form id="setform" class="form"><label class="chk"><input type="checkbox" name="mock" ${s.mock?'checked':''}> Test mode (fake plans and placeholder images, no credits used)</label>
  <label>Claude API key ${s.claude_key_set?'<em>(saved)</em>':'<em>(not set)</em>'}<input name="claude_key" type="password" autocomplete="off" placeholder="${s.claude_key_set?'Leave empty to keep the saved key':'Paste key'}"></label>
  <label>Magnific API key ${s.magnific_key_set?'<em>(saved)</em>':'<em>(not set)</em>'}<input name="magnific_key" type="password" autocomplete="off" placeholder="${s.magnific_key_set?'Leave empty to keep the saved key':'Paste key'}"></label>
  <div class="row"><label>Claude model<input name="claude_model" value="${esc(s.claude_model)}"></label><label>Magnific image model<input name="img_model" value="${esc(s.img_model)}"></label><label>Monthly image limit<input type="number" name="cap" value="${s.cap}" min="0"></label></div>
  <button class="btn">Save settings</button></form>
  <h3>Usage log</h3><div class="tbl"><table><tr><th>When</th><th>Who</th><th>What</th><th>Units</th></tr>${u.rows.map(r=>`<tr><td>${esc(r.created_at)}</td><td>${esc(r.who)}</td><td>${esc(r.action)} ${esc(r.note)}</td><td>${esc(r.units)}</td></tr>`).join('')||'<tr><td colspan="4">Nothing yet.</td></tr>'}</table></div>`);
}

function render(){S.view==='home'?home():S.view==='client'?clientView():null}

/* ---------- polling ---------- */
function startPoll(){clearInterval(S.poll);S.poll=setInterval(async()=>{
  if(S.view!=='client'||!S.client)return;
  const pend=S.client.images.filter(i=>i.status==='generating'||i.status==='queued');if(!pend.length)return;
  let ch=false;for(const i of pend){try{const n=await api('images/'+i.id+'/poll');if(n.status!==i.status){Object.assign(i,n);ch=true}}catch(e){}}
  if(ch){await refreshState();if(S.tab==='plans'||S.tab==='library')clientView()}
},4000)}

/* ---------- events ---------- */
app.addEventListener('click',async e=>{
  const a=e.target.closest('[data-go],[data-client],[data-tab],[data-plan],[data-back],[data-copy],[data-genimg],[data-copyprompt],[data-copyimg]');if(!a)return;
  if(a.tagName==='A')e.preventDefault();
  try{
    if(a.dataset.go==='home'){await refreshState();S.view='home';S.client=null;home()}
    else if(a.dataset.go==='settings'){S.view='settings';await settingsView()}
    else if(a.dataset.client){await openClient(a.dataset.client,'plans')}
    else if(a.dataset.tab){S.tab=a.dataset.tab;S.plan=null;clientView()}
    else if(a.dataset.plan){S.plan=await api('plans/'+a.dataset.plan);clientView()}
    else if('back' in a.dataset){S.plan=null;clientView()}
    else if(a.dataset.copy!==undefined){const x=S.plan.data.posts[+a.dataset.copy];await navigator.clipboard.writeText((x.caption||'')+'\n\n'+(x.hashtags||''));toast('Copied')}
    else if('copyprompt' in a.dataset){const f=Object.fromEntries(new FormData(a.closest('form')));await navigator.clipboard.writeText(aiPrompt(f));toast('Prompt copied — paste it into Claude')}
    else if('copyimg' in a.dataset){const t=a.closest('.post').querySelector('[data-prompt]').value;const st=(S.client.brand&&S.client.brand.image_style)||'';await navigator.clipboard.writeText(t+(st?' Style: '+st:''));toast('Image prompt copied')}
    else if('genimg' in a.dataset){
      const post=a.closest('.post');const n=+post.dataset.n;const prompt=post.querySelector('[data-prompt]').value;const aspect=post.querySelector('[data-aspect]').value;
      a.disabled=true;const im=await api('images','POST',{client_id:S.client.id,plan_id:S.plan.id,post_n:n,prompt,aspect});
      S.client.images.unshift(im);await refreshState();clientView();toast(im.status==='failed'?('Failed: '+im.error):(im.status==='done'?'Image ready':'Generating — it will appear here'))}
  }catch(err){toast(err.message);if(a.disabled)a.disabled=false}
});
app.addEventListener('submit',async e=>{
  e.preventDefault();const f=e.target,fd=Object.fromEntries(new FormData(f));const btn=f.querySelector('button');
  try{
    if(f.id==='newclient'){const r=await api('clients','POST',{name:fd.name});await refreshState();await openClient(r.id,'brand')}
    else if(f.id==='brandform'){btn.disabled=true;await api('clients/'+S.client.id+'/brand','POST',fd);S.client=await api('clients/'+S.client.id);S.client.brand=S.client.brand;await refreshState();clientView();toast('Brand profile saved')}
    else if(f.id==='planform'){btn.disabled=true;btn.textContent='Generating… please wait';const r=await api('clients/'+S.client.id+'/plan','POST',fd);S.client=await api('clients/'+S.client.id);S.plan=await api('plans/'+r.id);await refreshState();clientView();toast('Plan ready')}
    else if(f.id==='freeform'){btn.disabled=true;const r=await api('clients/'+S.client.id+'/plan-import','POST',{month:fd.month,brief:fd.brief,json:fd.json});S.client=await api('clients/'+S.client.id);S.plan=await api('plans/'+r.id);await refreshState();clientView();toast('Plan imported ('+r.posts+' posts)')}
    else if(f.id==='setform'){btn.disabled=true;await api('settings','POST',{...fd,mock:f.mock.checked});await refreshState();await settingsView();toast('Settings saved')}
  }catch(err){toast(err.message);if(btn){btn.disabled=false;if(f.id==='planform')btn.textContent='Generate plan'}}
});

app.addEventListener('change',async e=>{const inp=e.target.closest('[data-upfile]');if(!inp||!inp.files[0])return;const post=inp.closest('.post');const fd=new FormData();fd.append('file',inp.files[0]);fd.append('client_id',S.client.id);if(post&&S.plan){fd.append('plan_id',S.plan.id);fd.append('post_n',post.dataset.n);fd.append('prompt',post.querySelector('[data-prompt]').value)}
  try{toast('Uploading…');const r=await fetch(C.root+'images/upload',{method:'POST',headers:{'X-WP-Nonce':C.nonce},body:fd,credentials:'same-origin'});const j=await r.json();if(!r.ok)throw new Error(j.message||'Upload failed');S.client.images.unshift(j);await refreshState();clientView();toast('Image saved')}catch(err){toast(err.message)}});

(async()=>{try{await refreshState();home()}catch(e){app.innerHTML='<p style="padding:40px;color:#f88">Could not load the studio: '+esc(e.message)+'</p>'}})();
})();
