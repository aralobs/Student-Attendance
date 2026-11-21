// Run with the bundled Playwright runtime on NODE_PATH. Uses synthetic records only.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch({channel:'msedge', headless:true});
    try {
        const page = await browser.newPage();
        await page.route('http://list.test/**', route=>route.fulfill({body:'<!doctype html><html><body></body></html>',contentType:'text/html'}));
        await page.goto('http://list.test/users.php?role=teacher');
        await page.setContent(`<div class="card"><div class="table-responsive"><table data-list-title="User List" data-list-live="1"><thead><tr><th>Name</th><th>Action</th></tr></thead><tbody>${Array.from({length:31},(_,i)=>`<tr><td>Person ${i+1}</td><td><button>Edit</button></td></tr>`).join('')}</tbody></table></div></div><table id="unrelated"><tbody><tr><td>Report</td></tr></tbody></table>`);
        await page.addStyleTag({path:path.join(__dirname,'../assets/css/analytics_template.css')});
        await page.addScriptTag({path:path.join(__dirname,'../assets/js/list_view.js')});
        const rows = page.locator('table[data-list-title] tbody tr:visible');
        assert.equal(await rows.count(),15);
        await page.getByRole('link',{name:'2',exact:true}).click();
        assert.equal(await rows.first().innerText(),'Person 16\tEdit');
        assert.match(await page.locator('.list-pagination').innerText(),/Showing 16–30 of 31/);
        assert.match(page.url(),/role=teacher/);
        const search = page.getByRole('searchbox');
        await search.fill('Person 31');
        assert.equal(await rows.count(),1);
        assert.match(await rows.first().innerText(),/Person 31/);
        await search.fill('<img src=x onerror=alert(1)>');
        assert.equal(await rows.count(),0);
        assert.equal(await page.locator('img').count(),0);
        assert.equal(await page.getByText('No matching records found.').isVisible(),true);
        await page.emulateMedia({media:'print'});
        assert.equal(await rows.count(),31,'Print must include all records even after search');
        await page.emulateMedia({media:'screen'});
        await search.fill('');
        await page.locator('table[data-list-title] tbody').evaluate(body=>{
            body.replaceChildren();
            for (let i=0;i<17;i++) { const row=body.insertRow(); row.insertCell().textContent='New '+i; row.insertCell().textContent='Edit'; }
        });
        await page.waitForFunction(()=>document.querySelector('.list-card .badge').textContent==='17');
        assert.equal(await rows.count(),15,'Live refresh reapplies pagination');
        await page.setViewportSize({width:375,height:800});
        assert.equal(await search.isVisible(),true);
        assert.equal(await page.locator('#unrelated tr').count(),1);
        assert.equal(await page.locator('#unrelated').getAttribute('class'),null,'Non-opted-in report tables are unchanged');
        // Manual attendance embeds the list in a POST form; Enter cannot submit it.
        await page.locator('.list-card').evaluate(card=>{
            const form=document.createElement('form'); card.before(form); form.append(card);
            form.addEventListener('submit',event=>{event.preventDefault();form.dataset.submitted='1';});
            const submit=document.createElement('button'); submit.textContent='Save'; form.append(submit);
        });
        await search.press('Enter');
        assert.equal(await page.locator('form').getAttribute('data-submitted'),null);
        console.log('Browser checks passed: pagination, full-list search, escaping, printing, scanner refresh and mobile controls.');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
