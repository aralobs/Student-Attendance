const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/calendar_entry.js'), 'utf8');
function harness(script = source) {
    const elements = new Map();
    function element(id, value = '') {
        const el = {id, value, initialValue:value, disabled:false, required:false, hidden:false, textContent:'', className:'', dataset:{}, listeners:{},
            addEventListener(type,fn) { this.listeners[type] = fn; }, querySelectorAll() { return this.controls || []; }};
        elements.set(id, el); return el;
    }
    const modal = element('calendarModal'); modal.dataset = {defaultKind:'event', defaultDate:'2026-10-12'};
    const form = element('calendarEntryForm'); form.reportValidity = () => true;
    form.reset = () => elements.forEach(el => { el.value = el.initialValue; });
    const kind = element('entryKind','event'); Object.defineProperty(kind,'selectedOptions',{get:()=>[{disabled:false}]});
    const eventFields = element('eventFields'), deliveryFields = element('deliveryFields');
    eventFields.controls = ['entryDate','entryTitle','entryType','entryDescription'].map(id=>element(id,id==='entryType'?'holiday':''));
    deliveryFields.controls = ['deliveryFrom','deliveryTo','deliverySection','deliveryMode','deliveryDeadline'].map(id=>element(id,id==='deliveryMode'?'face_to_face':id==='deliverySection'?'0':''));
    for (const id of ['deliveryDeadlineGroup','deliveryPreview','calendarEntrySubmit','modalTitle','modalMsg']) element(id);
    element('entryCsrf','app-token'); element('calendarCsrf','event-token');
    modal.controls = [...eventFields.controls,...deliveryFields.controls,kind,elements.get('calendarEntrySubmit')];
    const requests = [], navigations = [];
    const context = {document:{getElementById:id=>elements.get(id)},URL,URLSearchParams,
        window:{location:{href:'http://localhost/calendar/calendar.php',assign:url=>navigations.push(url)}},
        bootstrap:{Modal:{getOrCreateInstance:()=>({show(){}})}},
        fetch:(url, options)=>new Promise((resolve,reject)=>requests.push({url,params:options.body,resolve,reject}))};
    vm.runInNewContext(script,context);
    return {elements,requests,navigations,open:context.window.openAddModal,
        change:()=>form.listeners.change?.(),input:()=>form.listeners.input?.(),
        submit:()=>form.listeners.submit({preventDefault(){}}),
        respond:(data,ok=true)=>requests.at(-1).resolve({ok,json:async()=>data})};
}
async function run(script) {
    const h = harness(script), el = id=>h.elements.get(id);
    await h.open('2026-10-12','delivery');
    assert.equal(el('eventFields').hidden,true);
    assert.equal(el('entryTitle').disabled,true,'Hidden event title must not block delivery submission');
    assert.equal(el('deliveryFrom').value,'2026-10-12');
    assert.equal(el('deliveryTo').value,'2026-10-12','Clicked date populates both range bounds');
    el('deliveryMode').value='modular'; h.change();
    assert.equal(el('deliveryDeadline').required,true);
    assert.equal(el('deliveryDeadline').disabled,false);
    el('deliveryDeadline').value='2026-10-16T17:00';
    let pending=h.submit();
    assert.equal(h.requests.at(-1).params.get('action'),'delivery');
    assert.equal(h.requests.at(-1).params.get('csrf_token'),'app-token');
    assert.equal(h.requests.at(-1).params.get('confirm_token'),'');
    assert.equal(el('entryKind').disabled,true,'Prevent switching modes while a request is pending');
    h.respond({success:true,saved:false,token:'preview-1',dates:['2026-10-12'],skipped:['2026-10-14'],existing:[]}); await pending;
    assert.equal(el('calendarEntrySubmit').textContent,'Confirm Schedule');
    assert.match(el('deliveryPreview').textContent,/2026-10-14/,'Preview shows skipped dates');
    el('deliverySection').value='10'; h.input();
    assert.equal(el('deliveryPreview').hidden,true,'Changed inputs discard stale preview');
    pending=h.submit();
    assert.equal(h.requests.at(-1).params.get('confirm_token'),'','Changed scope must request a new preview');
    h.respond({success:true,saved:false,token:'preview-2',dates:['2026-10-12'],skipped:[],existing:[]}); await pending;
    pending=h.submit();
    assert.equal(h.requests.at(-1).params.get('confirm_token'),'preview-2');
    h.respond({success:true,saved:true}); await pending;
    assert.match(h.navigations[0],/month=10&year=2026/);

    const first=h.open('2026-10-12','event');
    const firstLookup=h.requests.at(-1);
    const second=h.open('2026-10-13','event');
    h.respond({success:true,entry:{title:'Current event',type:'special_event',description:''}}); await second;
    firstLookup.resolve({ok:true,json:async()=>({success:true,entry:{title:'Stale event',type:'holiday'}})}); await first;
    assert.equal(el('entryTitle').value,'Current event','Old event lookup must not overwrite a newly opened date');
    const typingLookup=h.open('2026-10-13','event');
    assert.equal(el('entryTitle').disabled,true,'Existing event fields stay locked until all original values load');
    const requestsBefore=h.requests.length; await h.submit();
    assert.equal(h.requests.length,requestsBefore,'Cannot submit an event before its type and description load');
    h.respond({success:true,entry:{title:'Saved title',type:'special_event',description:'Saved description'}}); await typingLookup;
    el('entryTitle').value='My new title'; h.input();
    assert.equal(el('deliveryFrom').disabled,true);
    assert.equal(el('entryTitle').disabled,false);
    pending=h.submit();
    assert.equal(h.requests.at(-1).params.get('action'),'add');
    assert.equal(h.requests.at(-1).params.get('csrf_token'),'event-token');
    assert.equal(h.requests.at(-1).params.get('type'),'special_event','Editing a title preserves the loaded event type');
    assert.equal(h.requests.at(-1).params.get('description'),'Saved description','Editing a title preserves the loaded description');
    h.respond({success:false,message:'<img src=x onerror=alert(1)>'}); await pending;
    assert.equal(el('modalMsg').textContent,'<img src=x onerror=alert(1)>','Server messages use text content');
    el('entryKind').value='delivery'; el('deliveryMode').value='online'; h.change();
    assert.equal(el('deliveryDeadline').required,false);
    assert.equal(el('deliveryDeadline').disabled,true,'Non-modular modes do not validate hidden deadlines');
    const failedLookup=h.open('2026-10-13','event');
    h.respond({success:false,message:'Unable to load'},false); await failedLookup;
    assert.equal(el('calendarEntrySubmit').disabled,true,'Failed lookup cannot save default values over an existing event');
    const beforeFailureSubmit=h.requests.length; await h.submit();
    assert.equal(h.requests.length,beforeFailureSubmit);
    await h.open(null,'delivery');
    assert.equal(el('calendarEntrySubmit').disabled,false,'Reopening the editor clears the failed lookup state');
}
(async()=>{
    // Prove the test detects loss of the preview invalidation listener.
    await assert.rejects(run(source.replace("form.addEventListener('input', () => { ++lookupVersion; clearPreview(); });",'')),/Changed inputs discard stale preview/);
    await run(source);
    console.log('Calendar entry UI passed: switching, preview/confirm, stale-input invalidation, lookup races, CSRF and safe messages.');
})().catch(error=>{console.error(error);process.exitCode=1;});
