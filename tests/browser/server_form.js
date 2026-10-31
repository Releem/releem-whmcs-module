(async function(){
if(document.readyState==='loading')await new Promise(r=>document.addEventListener('DOMContentLoaded',r,{once:true}));
const results=[],errors=[];window.addEventListener('error',e=>errors.push(e.message));
const fixture=document.querySelector('#fixture');
// Each case executes the hook in isolation, including document-level observers/listeners.
const activeObservers=[];
const activeListeners=[];
const NativeObserver=window.MutationObserver;
window.MutationObserver=class extends NativeObserver {
    constructor(callback){super(callback);activeObservers.push(this);}
};
const addListener=document.addEventListener.bind(document);
document.addEventListener=function(type,callback,options){
    activeListeners.push([type,callback,options]);
    addListener(type,callback,options);
};
function resetHook(){
    activeObservers.splice(0).forEach(observer=>observer.disconnect());
    activeListeners.splice(0).forEach(args=>document.removeEventListener(...args));
}

const $=s=>fixture.querySelector(s);
const tick=()=>new Promise(r=>setTimeout(r,0));
function eq(a,b,msg=''){if(JSON.stringify(a)!==JSON.stringify(b))throw Error(msg+' expected '+JSON.stringify(b)+' got '+JSON.stringify(a));}
function ok(v,msg='assertion failed'){if(!v)throw Error(msg);}
async function test(name,fn){resetHook();try{await fn();results.push({name,status:'PASS'});}catch(e){results.push({name,status:'FAIL',error:e.message});}}
function markup(type='cpanel'){return `<table id="server-table"><tbody><tr><td>Type</td><td><select id="inputServerType"><option value="cpanel" ${type==='cpanel'?'selected':''}>cPanel</option><option value="releem" ${type==='releem'?'selected':''}>Releem</option><option value="other">Other</option></select></td></tr><tr id="hostname-row"><td>Hostname</td><td><input id="inputHostname" value="original.example"></td></tr><tr id="username-row"><td>Username</td><td><input id="inputUsername" value="admin"></td></tr><tr id="password-row"><td class="fieldlabel"><label for="inputPassword">Password <strong>original</strong></label></td><td><input id="inputPassword" value="dummy-test-secret" type="password"></td></tr><tr id="hash-row"><td>Access Hash</td><td><textarea id="serverHash">dummy-test-hash</textarea></td></tr></tbody></table>`;}
function mount(type='cpanel',before){fixture.innerHTML=markup(type);if(before)before();Function(SOURCE.hook)();}
function switchType(type){$('#inputServerType').value=type;$('#inputServerType').dispatchEvent(new Event('change',{bubbles:true}));}
function isReleem(){eq($('#username-row').style.display,'none');eq($('#hash-row').style.display,'none');eq($('#password-row .fieldlabel').textContent,'Partner API Token');eq($('#inputPassword').title,'Releem partner API token');}
const originalLabel='<label for="inputPassword">Password <strong>original</strong></label>';
await test('initial non-Releem form keeps normal default labels and visibility',()=>{mount();eq($('#username-row').style.display,'');eq($('#hash-row').style.display,'');eq($('#password-row .fieldlabel').innerHTML,originalLabel);ok(!$('#inputHostname').hasAttribute('placeholder'));ok(!$('#inputPassword').hasAttribute('title'));});
await test('initial Releem form hides username/hash, sets token label/title and default hostname placeholder',()=>{mount('releem');isReleem();eq($('#inputHostname').placeholder,'api2.releem.com');});
await test('non-Releem to Releem change applies immediately',()=>{mount();switchType('releem');isReleem();});
await test('Releem to other server restores original label HTML and default visibility',()=>{mount('releem');switchType('other');eq($('#username-row').style.display,'');eq($('#hash-row').style.display,'');eq($('#password-row .fieldlabel').innerHTML,originalLabel);ok(!$('#inputHostname').hasAttribute('placeholder'));ok(!$('#inputPassword').hasAttribute('title'));});
await test('repeated Releem/non-Releem cycles preserve original label markup',()=>{mount();for(let i=0;i<5;i++){switchType('releem');isReleem();switchType('cpanel');eq($('#password-row .fieldlabel').innerHTML,originalLabel);}});
await test('field values and password type survive switches',()=>{mount();switchType('releem');switchType('cpanel');eq($('#inputUsername').value,'admin');eq($('#inputPassword').value,'dummy-test-secret');eq($('#serverHash').value,'dummy-test-hash');eq($('#inputHostname').value,'original.example');eq($('#inputPassword').type,'password');});
await test('existing custom hostname placeholder preserved throughout switches',()=>{mount('cpanel',()=>$('#inputHostname').placeholder='host.example.net');switchType('releem');eq($('#inputHostname').placeholder,'host.example.net');switchType('other');eq($('#inputHostname').placeholder,'host.example.net');});
await test('pre-existing placeholder equal to Releem default preserved on non-Releem init',()=>{mount('cpanel',()=>$('#inputHostname').placeholder='api2.releem.com');eq($('#inputHostname').placeholder,'api2.releem.com');});
await test('pre-existing placeholder equal to Releem default restored after Releem switch',()=>{mount('releem',()=>$('#inputHostname').placeholder='api2.releem.com');switchType('cpanel');eq($('#inputHostname').placeholder,'api2.releem.com');});
await test('existing password title restored after Releem switch',()=>{mount('releem',()=>$('#inputPassword').title='Original password help');switchType('cpanel');eq($('#inputPassword').title,'Original password help');});
await test('pre-existing hidden rows remain hidden on non-Releem initialization',()=>{mount('cpanel',()=>{$('#username-row').style.display='none';$('#hash-row').style.display='none';});eq($('#username-row').style.display,'none');eq($('#hash-row').style.display,'none');});
await test('pre-existing explicit row displays restored after Releem switch',()=>{mount('releem',()=>{$('#username-row').style.display='table-row';$('#hash-row').style.display='none';});switchType('cpanel');eq($('#username-row').style.display,'table-row');eq($('#hash-row').style.display,'none');});
await test('MutationObserver reapplies Releem behavior after fields replaced within same table',async()=>{mount('releem');const table=$('#server-table');$('#password-row').outerHTML='<tr id="password-row"><td class="fieldlabel">Password</td><td><input id="inputPassword" type="password"></td></tr>';$('#username-row').outerHTML='<tr id="username-row"><td>Username</td><td><input id="inputUsername"></td></tr>';$('#hash-row').outerHTML='<tr id="hash-row"><td>Hash</td><td><textarea id="serverHash"></textarea></td></tr>';await tick();isReleem();});
await test('MutationObserver does not loop on its own edits',async()=>{const Original=window.MutationObserver;let calls=0;window.MutationObserver=class extends Original{constructor(fn){super((...a)=>{calls++;fn(...a);});}};try{mount('releem');$('#server-table tbody').appendChild(document.createElement('tr'));await tick();const count=calls;ok(count>=1,'no mutation callback');await tick();await tick();eq(calls,count);ok(calls<4,'observer repeatedly triggered '+calls);}finally{window.MutationObserver=Original;}});
await test('full table replacement automatically adapts new Releem fields',async()=>{mount('releem');$('#server-table').outerHTML=markup('releem');await tick();isReleem();});
await test('new type selector after table replacement keeps change behavior',async()=>{mount('cpanel');$('#server-table').outerHTML=markup('cpanel');await tick();switchType('releem');isReleem();});
await test('replacement type selector within existing table retains change behavior',async()=>{mount();$('#inputServerType').outerHTML='<select id="inputServerType"><option value="cpanel">cPanel</option><option value="releem">Releem</option></select>';await tick();switchType('releem');isReleem();});
await test('late optional fields inside observed table are adapted',async()=>{mount('releem',()=>{$('#password-row').remove();$('#username-row').remove();$('#hash-row').remove();});const replacement=document.createElement('tbody');replacement.innerHTML=markup('releem');const rows=[...replacement.querySelectorAll('tr')].filter(r=>['password-row','username-row','hash-row'].includes(r.id));rows.forEach(r=>$('#server-table tbody').appendChild(r));await tick();isReleem();});
await test('password label from dynamically replaced row is restored to that row original',async()=>{mount('releem');$('#password-row').outerHTML='<tr id="password-row"><td class="fieldlabel">New dynamic password label</td><td><input id="inputPassword"></td></tr>';await tick();eq($('#password-row .fieldlabel').textContent,'Partner API Token');switchType('cpanel');eq($('#password-row .fieldlabel').textContent,'New dynamic password label');});
for(const id of ['inputUsername','inputPassword','serverHash','inputHostname']){await test('missing '+id+' is tolerated during both transitions',()=>{mount('releem',()=>$('#'+id).remove());switchType('cpanel');switchType('releem');});}
await test('missing password label cell is tolerated',()=>{mount('releem',()=>$('#password-row .fieldlabel').remove());switchType('cpanel');switchType('releem');});
await test('missing all optional fields is tolerated',()=>{fixture.innerHTML='<table><tr><td><select id="inputServerType"><option value="releem">Releem</option><option value="other">Other</option></select></td></tr></table>';Function(SOURCE.hook)();switchType('other');switchType('releem');});
await test('missing server type selector is tolerated',()=>{fixture.innerHTML='<input id="inputPassword">';Function(SOURCE.hook)();});
await test('server type selector outside a table is tolerated',()=>{fixture.innerHTML='<select id="inputServerType"><option value="releem">Releem</option><option value="other">Other</option></select><input id="inputPassword">';Function(SOURCE.hook)();eq($('#inputPassword').title,'Releem partner API token');switchType('other');eq($('#inputPassword').title,'');});
await test('missing MutationObserver still allows normal change behavior',()=>{const Original=window.MutationObserver;window.MutationObserver=undefined;try{mount();switchType('releem');isReleem();switchType('cpanel');eq($('#password-row .fieldlabel').innerHTML,originalLabel);}finally{window.MutationObserver=Original;}});
await test('DOMContentLoaded initialization path',async()=>{fixture.innerHTML='';const frame=document.createElement('iframe');const loaded=new Promise(r=>frame.onload=r);frame.srcdoc=markup('releem')+'<script>window.readyAtLoad=document.readyState;<'+String.fromCharCode(47)+'script><script>'+SOURCE.hook+'<'+String.fromCharCode(47)+'script>';fixture.appendChild(frame);await loaded;eq(frame.contentWindow.readyAtLoad,'loading');eq(frame.contentDocument.querySelector('#username-row').style.display,'none');eq(frame.contentDocument.querySelector('#password-row .fieldlabel').textContent,'Partner API Token');});
await test('unexpected browser errors',()=>eq(errors,[]));
const summary={browser:navigator.userAgent,viewport:{width:innerWidth,height:innerHeight},total:results.length,passed:results.filter(r=>r.status==='PASS').length,failed:results.filter(r=>r.status==='FAIL').length,results,errors};document.querySelector('#results').textContent=JSON.stringify(summary,null,2);document.title='DONE '+summary.passed+'/'+summary.total;
})();
