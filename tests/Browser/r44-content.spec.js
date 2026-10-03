import { test, expect } from '@playwright/test';
import fs from 'node:fs';
const evidenceDir = process.env.R44_EVIDENCE_DIR || 'output/r44';
fs.mkdirSync(evidenceDir, { recursive: true });

test('R44 persisted long Unicode task, project, member and email retain reachable controls', async ({ page }) => {
    const taskTitle='R44 '+ '界'.repeat(251), projectName='R44 '+ 'P'.repeat(251);
    const memberName='R44 '+ 'किरण'.repeat(40), email='employee'.repeat(7)+'@'+'office'.repeat(8)+'.example.invalid';
    await page.goto('/login'); await page.locator('#email').fill('manager@r41.example.invalid');
    await page.locator('#password').fill('R41-browser-unique-secret-123!'); await page.getByRole('button',{name:'Log in',exact:true}).click();
    await page.goto('/team-management'); await page.locator('#addMemberBtn').click();
    await page.locator('#memberName').fill(memberName); await page.locator('#memberEmail').fill(email);
    await page.locator('#memberPassword').fill('R41-browser-unique-secret-123!'); await page.locator('#memberRole').selectOption({label:'Team Member'});
    await page.locator('#createAccountBtn').click(); await expect(page.getByRole('button',{name:`Edit ${memberName}`,exact:true})).toBeVisible();
    await page.goto('/projects'); await page.locator('#newProjectBtn').click(); await page.locator('#projectName').fill(projectName);
    await page.locator('#projectManager').selectOption({label:'R41 PM'}); await page.locator('#projectSubmitBtn').click();
    await expect(page.getByRole('heading',{name:projectName,exact:true})).toBeVisible();
    await page.goto('/tasks'); await page.locator('#newTaskBtn').click(); await page.locator('#taskTitle').fill(taskTitle);
    await page.locator('#taskDescription').fill('Persisted 255-character title with complete Unicode graphemes.');
    await page.locator('#taskProject').selectOption({label:'R41 Core Project'}); await page.locator('#taskAssignee').selectOption({label:'R41 Member A'});
    const due=new Date(); due.setDate(due.getDate()+8);
    await page.locator('#taskReviewer').selectOption({label:'R41 PM'}); await page.locator('#taskDueDate').fill(due.toISOString().slice(0,10));
    await page.locator('#taskForm button[type=submit]').click(); await expect(page.getByRole('button',{name:`Edit ${taskTitle}`,exact:true})).toBeVisible();
    const results=[];
    for(const width of [320,720,1440]) {
        await page.setViewportSize({width,height:900});
        for(const route of ['/tasks','/projects','/team-management']) {
            await page.goto(route); results.push({width,route,...await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,viewport:innerWidth}))});
            const action=route==='/tasks' ? page.getByRole('button',{name:`Edit ${taskTitle}`,exact:true}) : route==='/projects' ? page.getByRole('heading',{name:projectName,exact:true}).locator('..').locator('..').locator('..').getByRole('button',{name:'Edit',exact:true}) : page.getByRole('button',{name:`Edit ${memberName}`,exact:true});
            await action.scrollIntoViewIfNeeded(); await expect(action).toBeVisible();
        }
    }
    fs.writeFileSync(`${evidenceDir}/long-content.json`,JSON.stringify(results,null,2));
    expect(results.filter(row=>row.scroll>row.viewport+1)).toEqual([]);
});
