const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
function element(dataset = {}, value = '') {
    return {dataset, value, style:{}, scrollHeight:30, hidden:true, textContent:'', listeners:{},
        classList:{toggle(){},remove(){}}, addEventListener(type,fn){this.listeners[type]=fn;}};
}
const status=element({endpoint:'/save',section:'10',month:'10',year:'2026'});
const retry=element(); const fields=[element({studentId:'1',version:'0'}),element({studentId:'2',version:'0'})];
const requests=[]; const windowEvents={}; const timers=new Map(); let nextTimer=0;
const context={
    document:{getElementById(id){return id==='sf2RemarkStatus'?status:id==='sf2RemarkRetry'?retry:null;},
        querySelectorAll(){return fields;},querySelector(){return {content:'csrf-token'};}},
    window:{addEventListener(type,fn){windowEvents[type]=fn;}},URLSearchParams,
    setTimeout(fn){timers.set(++nextTimer,fn);return nextTimer;},clearTimeout(id){timers.delete(id);},
    fetch(url,options){return new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}));}
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/js/sf2_remarks.js'),'utf8'),context);
const settle=()=>new Promise(resolve=>setImmediate(resolve));
const response=(request,code,data)=>request.resolve({ok:code===200,status:code,json:async()=>data});
(async()=>{
    fields[0].value='First note';fields[0].listeners.input();fields[0].listeners.blur();
    assert.equal(requests.length,1);
    let body=requests[0].options.body;
    assert.equal(body.get('csrf_token'),'csrf-token');assert.equal(body.get('student_id'),'1');assert.equal(body.get('version'),'0');
    fields[0].value='Edited while saving';fields[0].listeners.input();fields[0].listeners.blur();
    assert.equal(requests.length,1,'Concurrent saves were not serialized');
    response(requests[0],200,{success:true,version:1});await settle();
    assert.equal(requests.length,2);body=requests[1].options.body;
    assert.equal(body.get('version'),'1');assert.equal(body.get('remark'),'Edited while saving');
    response(requests[1],200,{success:true,version:2});await settle();
    assert.equal(status.textContent,'Remarks saved to the server.');
    fields[1].value='Keep unsaved note';fields[1].listeners.input();fields[1].listeners.blur();
    response(requests[2],503,{success:false,message:'Storage unavailable'});await settle();
    assert.equal(fields[1].value,'Keep unsaved note');assert.equal(status.textContent,'Storage unavailable');assert.equal(retry.hidden,false);
    retry.listeners.click();assert.equal(requests.length,4);
    assert.equal(requests[3].options.body.get('version'),'0');
    response(requests[3],200,{success:true,version:1});await settle();assert.equal(retry.hidden,true);
    fields[0].value='Stale edit';fields[0].listeners.input();fields[0].listeners.blur();
    response(requests[4],409,{success:false,message:'Reload the report'});await settle();
    assert.equal(status.textContent,'Reload the report');assert.equal(retry.hidden,true);
    fields[0].listeners.blur();assert.equal(requests.length,5,'Conflict was retried with a stale version');
    let warned=false;windowEvents.beforeunload({preventDefault(){warned=true;}});assert.equal(warned,true);
    fields[1].value='Network failure';fields[1].listeners.input();fields[1].listeners.blur();
    requests[5].reject(Error('Network offline'));await settle();
    assert.equal(fields[1].value,'Network failure');assert.equal(retry.hidden,false);
    console.log('SF2 remark UI checks passed (serialized saves, CSRF, versions, retries, conflicts and unsaved changes).');
})().catch(error=>{console.error(error);process.exitCode=1;});
