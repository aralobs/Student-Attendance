const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/delivery_delete.js'),'utf8');
async function run({confirm=true,responses=[],script=source}={}) {
    const requests=[],dialogs=[],messages=[]; let reloads=0;
    const button={disabled:false,dataset:{deliveryId:'123'}};
    const context={window:{location:{reload(){reloads++;}}},URLSearchParams,Error,
        document:{querySelector:()=>({content:'synthetic-csrf'})},
        fetch:async(url,options)=>{
            requests.push({url,params:new URLSearchParams(options.body)});
            const result=responses.shift();
            if(result instanceof Error) throw result;
            return {ok:result?.success!==false,json:async()=>{
                if(result==='HTML') throw new SyntaxError('PRIVATE SERVER DIAGNOSTIC');
                return result;
            }};
        },
        showConfirm:async(message,options)=>{dialogs.push({message,options});return confirm;},
        showMessage:async(message)=>messages.push(message)};
    vm.runInNewContext(script,context);
    await context.window.deleteDeliverySchedule(button);
    assert.equal(button.disabled,false,'Delete action is restored after completion/cancel/error');
    for(const request of requests){assert.equal(request.params.get('id'),'123');assert.equal(request.params.get('csrf_token'),'synthetic-csrf');}
    return {requests,dialogs,messages,reloads};
}
const preview={success:true,deleted:false,token:'preview-token',label:'Modular — Whole school — 2026-10-26',fallback_message:'Sections without overrides return to Face-to-face.'};
async function confirmationTest(script=source){
    const done=await run({script,responses:[preview,{success:true,deleted:true}]});
    assert.equal(done.requests.length,2);
    assert.equal(done.requests[0].params.get('confirm_token'),null,'First request only previews');
    assert.equal(done.requests[1].params.get('confirm_token'),'preview-token','Confirmed deletion sends the bound preview token');
    assert.equal(done.reloads,1);
    assert.match(done.dialogs[0].message,/Face-to-face/,'Confirmation explains the resulting schedule');
}
(async()=>{
    await assert.rejects(confirmationTest(source.replace("params.set('confirm_token',preview.token);",'')),/Confirmed deletion sends/);
    await confirmationTest();
    const cancelled=await run({confirm:false,responses:[preview]});
    assert.equal(cancelled.requests.length,1,'Cancel never sends a delete confirmation');assert.equal(cancelled.reloads,0);
    const changed=await run({responses:[preview,{...preview,token:'new-token'}]});
    assert.equal(changed.reloads,0);assert.equal(changed.requests.length,2);assert.match(changed.messages[0],/schedule changed/i);
    const denied=await run({responses:[{success:false,message:'Attendance already exists'}]});
    assert.equal(denied.requests.length,1);assert.equal(denied.dialogs.length,0);assert.match(denied.messages[0],/Attendance/);
    const unknown=await run({responses:[preview,new Error('Network failure')]});
    assert.equal(unknown.requests.length,2,'An ambiguous confirmed request is never retried');assert.equal(unknown.reloads,0);
    const broken=await run({responses:['HTML']});
    assert.doesNotMatch(broken.messages[0],/PRIVATE SERVER DIAGNOSTIC/);
    console.log('Delivery delete UI passed: preview/confirmation, cancel, stale state, protected history, no retry and safe errors.');
})().catch(error=>{console.error(error);process.exitCode=1;});
