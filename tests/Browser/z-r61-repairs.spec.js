import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const password = 'R41-browser-unique-secret-123!';
let fixture;
test.beforeAll(() => { fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['tests/Support/r61_browser_fixture.php'], {encoding: 'utf8'})); });
async function login(page, email='manager@r41.example.invalid') {
    await page.goto('/login'); await page.locator('#email').fill(email); await page.locator('#password').fill(password);
    await page.getByRole('button',{name:'Log in',exact:true}).click(); await expect(page).not.toHaveURL(/\/login$/);
}
async function closeMessage(page) { await page.locator('.swal2-confirm').click(); }
async function api(page, method, path, data={}) {
    return page.evaluate(async ({method,path,data}) => {
        const response=await fetch(window.AppClient.appUrl(path),{method,headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify(data)});
        return {status:response.status,body:await response.json()};
    },{method,path,data});
}
async function roleEdit(page, person, expected) {
    await page.goto(`/team-management?search=${encodeURIComponent(person.email)}`);
    await page.locator('.member-card').filter({hasText:person.email}).locator('.edit-btn').click();
    await page.locator('#editMemberRole').selectOption({label:'Project Manager'});
    const response=page.waitForResponse(r=>r.request().method()==='PUT' && r.url().includes(`/team-management/${person.id}`));
    await page.locator('#editMemberForm button[type=submit]').click();
    await page.getByRole('button',{name:'Yes, change!',exact:true}).click();
    expect((await response).status()).toBe(expected);
}
test('R61 Manager sees responsibility warnings and can resolve promotion and PM handover', async ({page}) => {
    await login(page);
    await roleEdit(page,fixture.employee,409);
    await expect(page.locator('#editMemberForm [role="alert"]')).toContainText('unfinished task assignments');
    const cancelled=await api(page,'POST',`/tasks/${fixture.promotion_task}/cancel`,{expected_version:2,cancellation_reason:'Resolve before promotion'});
    expect(cancelled.status).toBe(200);
    await roleEdit(page,fixture.employee,200);
    await page.goto('/projects?search=R61');
    const card=page.locator('.project-card').filter({hasText:fixture.project_name});
    await card.locator('.project-edit-btn').click();
    await page.getByRole('searchbox',{name:'Search project manager',exact:true}).fill(fixture.next.name);
    await page.locator('#projectManager').selectOption(String(fixture.next.id));
    let response=page.waitForResponse(r=>r.request().method()==='PUT'&&r.url().includes(`/projects/${fixture.project}`));
    await page.locator('#projectForm button[type=submit]').click(); expect((await response).status()).toBe(409);
    await expect(page.locator('.swal2-popup')).toContainText('unfinished task assignments'); await closeMessage(page);
    expect((await api(page,'POST',`/tasks/${fixture.pm_task}/cancel`,{expected_version:2,cancellation_reason:'Resolve before replacement'})).status).toBe(200);
    response=page.waitForResponse(r=>r.request().method()==='PUT'&&r.url().includes(`/projects/${fixture.project}`));
    await page.locator('#projectForm button[type=submit]').click(); expect((await response).status()).toBe(200);
});
test('R61 inactive historical contributor remains in HTML print and CSV', async ({page}) => {
    await login(page); await page.goto(`/team-management?search=${encodeURIComponent(fixture.former.email)}`);
    await page.locator('.member-card').filter({hasText:fixture.former.email}).locator('.deactivate-btn').click();
    const response=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes(`/team-management/${fixture.former.id}/deactivate`));
    await page.locator('.swal2-confirm').click(); expect((await response).status()).toBe(200); await closeMessage(page);
    await page.goto('/analytics');
    const row=page.locator('.team-performance-card, .team-performance-item').filter({hasText:fixture.former.name}).last();
    await expect(page.locator('#teamPerformanceSection')).toContainText(fixture.former.name); await expect(page.locator('#teamPerformanceSection')).toContainText('Inactive');
    await page.goto('/analytics/print'); await expect(page.locator('tr').filter({hasText:fixture.former.name})).toContainText('Inactive');
    const csv=await page.request.get('/analytics/export/csv'); expect(csv.status()).toBe(200); expect(await csv.text()).toContain(fixture.former.name); expect(await csv.text()).toContain('Inactive');
});
test('R61 recovered notice is historical with safe task link and no private reason', async ({page}) => {
    await login(page); await page.goto('/notifications/all');
    const notice=page.locator(`.notification-page-item[data-id="${fixture.notice}"]`);
    await expect(notice).toContainText('Current status: Cancelled'); await expect(notice).toContainText('Event recorded');
    expect(await notice.textContent()).not.toContain('R61_PRIVATE_BROWSER_REASON');
    await notice.getByRole('link').click(); await expect(page.locator('#tasksGrid')).toContainText('R61 Delayed Historical Notice');
});
test('R61 actual Manager session rejects the next request after demotion', async ({browser}) => {
    const oldContext=await browser.newContext(); const adminContext=await browser.newContext();
    try {
        const old=await oldContext.newPage(); const admin=await adminContext.newPage();
        await login(old,fixture.admin.email); await login(admin);
        await admin.goto('/team-management');
        const result=await api(admin,'PUT',`/team-management/${fixture.admin.id}`,{name:fixture.admin.name,email:fixture.admin.email,role_id:3});
        expect(result.status).toBe(200);
        await old.goto('/team-management'); await expect(old).toHaveURL(/\/login$/);
    } finally { await oldContext.close(); await adminContext.close(); }
});
