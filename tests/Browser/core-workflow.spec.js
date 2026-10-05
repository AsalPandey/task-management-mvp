import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const password = 'R41-browser-unique-secret-123!';
const managerEmail = 'manager@r41.example.invalid';
const pmEmail = 'pm@r41.example.invalid';
const memberEmail = 'a@r41.example.invalid';
const date = days => {
    const d = new Date(); d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
};
const snapshot = () => JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['tests/Support/r41_browser_snapshot.php'], { encoding: 'utf8' }));

async function login(page, email = managerEmail) {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login$/);
}
async function settleSuccess(page) {
    await expect(page.locator('.swal2-popup')).toBeVisible();
    await page.locator('.swal2-confirm').click();
    await page.waitForLoadState('networkidle');
}
async function addMember(page, name, email, role) {
    await page.locator('#addMemberBtn').click();
    await page.locator('#memberName').fill(name);
    await page.locator('#memberEmail').fill(email);
    await page.locator('#memberPassword').fill(password);
    await page.locator('#memberRole').selectOption({ label: role });
    const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/team-management');
    await page.locator('#createAccountBtn').click();
    expect((await response).status()).toBe(200);
    await expect(page.locator('.member-card').filter({ hasText: email })).toBeVisible();
}
function card(page, title) { return page.locator('#tasksGrid .task-card').filter({ hasText: title }); }

async function createTask(page, title, reviewer = 'R41 PM') {
    await page.goto('/tasks');
    await page.locator('#newTaskBtn').click();
    await page.locator('#taskTitle').fill(title);
    await page.locator('#taskDescription').fill('Real browser contract qualification');
    await page.locator('#taskProject').selectOption({ label: 'R41 Core Project' });
    await page.locator('#taskAssignee').selectOption({ label: 'R41 Member A' });
    await page.locator('#taskReviewer').selectOption({ label: reviewer });
    await page.locator('#taskPriority').selectOption('High');
    await page.locator('#taskStartDate').fill(date(0));
    await page.locator('#taskDueDate').fill(date(5));
    // If this control exists, even a populated value must never enter creation payload.
    const review = page.locator('#taskReviewDueDate');
    if (await review.count()) await review.fill(date(7));
    const request = page.waitForRequest(r => r.method() === 'POST' && new URL(r.url()).pathname === '/tasks');
    const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/tasks');
    await page.locator('#taskForm button[type=submit]').click();
    const sent = (await request).postDataJSON();
    expect(Object.keys(sent).sort()).toEqual(['title', 'description', 'project_id', 'assignee_id', 'reviewer_id', 'priority', 'start_date', 'due_date', 'comments'].sort());
    const result = await response;
    expect(result.status(), await result.text()).toBe(200);
    await expect(card(page, title)).toBeVisible();
    const db = snapshot();
    const tasks = db.tasks.filter(t => t.title === title);
    expect(tasks).toHaveLength(1);
    const task = tasks[0];
    expect(task.status).toBe('not_started');
    expect(task.progress).toBe(0);
    expect(task.review_due_date).toBeNull();
    expect(task.revision_due_date).toBeNull();
    expect(task.submitted_at).toBeNull();
    expect(task.assignee_id).toBe(db.users.find(u => u.email === memberEmail).id);
    expect(task.reviewer_id).toBe(db.users.find(u => u.name === reviewer).id);
    expect(task.project_id).toBe(db.projects.find(p => p.name === 'R41 Core Project').id);
    expect(db.events.filter(e => e.task_id === task.id).map(e => e.event_type)).toEqual(['task.created']);
    expect(db.history.filter(h => h.task_id === task.id)).toHaveLength(1);
    return task;
}

test('clean company onboarding, team creation, project and membership through UI', async ({ page }) => {
    for (const [width, height] of [[320,568],[375,667],[390,844],[430,932],[768,1024],[844,390],[1280,720],[1440,900]]) {
        await page.setViewportSize({ width, height }); await page.goto('/setup');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        await page.getByRole('button', { name: 'Install', exact: true }).scrollIntoViewIfNeeded();
    }
    await page.setViewportSize({ width:1280, height:900 });
    await page.goto('/setup');
    await page.locator('#setup_token').fill(process.env.APP_SETUP_TOKEN || 'r41-disposable-installer-token');
    await page.locator('#company_name').fill('R41 Clean Company');
    await page.locator('#name').fill('R41 Manager');
    await page.locator('#email').fill(managerEmail);
    await page.locator('#password').fill(password);
    await page.locator('#password_confirmation').fill(password);
    await page.getByRole('button', { name: 'Install', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await login(page);
    await page.goto('/team-management');
    await addMember(page, 'R41 PM', pmEmail, 'Project Manager');
    await addMember(page, 'R41 Member A', memberEmail, 'Team Member');
    await addMember(page, 'R41 Member B', 'b@r41.example.invalid', 'Team Member');
    await page.goto('/projects');
    await page.locator('#newProjectBtn').click();
    await page.locator('#projectName').fill('R41 Core Project');
    await page.locator('#projectManager').selectOption({ label: 'R41 PM' });
    await page.locator('#projectSubmitBtn').click();
    const project = page.locator('.project-card').filter({ hasText: 'R41 Core Project' });
    await expect(project).toBeVisible();
    for (const name of ['R41 Member A', 'R41 Member B']) {
        await project.locator('.member-add-form select').selectOption({ label: name + ' (Team Member)' });
        await project.locator('.member-add-form button').click();
        await expect(project.locator('.member-row').filter({ hasText: name })).toBeVisible();
    }
    const db = snapshot();
    expect(db.users).toHaveLength(4);
    expect(db.projects).toHaveLength(1);
    expect(db.memberships).toHaveLength(3);
});

test('Team edit uses the rendered identity and survives reload', async ({ page }) => {
    await login(page); await page.goto('/team-management');
    const member = page.locator('.member-card').filter({ hasText: 'b@r41.example.invalid' });
    const id = await member.getAttribute('data-member-id');
    await member.locator('.edit-btn').click();
    await page.locator('#editMemberName').fill('R41 Member B Edited');
    const response = page.waitForResponse(r => r.request().method() === 'PUT' && r.url().includes('/team-management/'));
    await page.locator('#editMemberForm button[type=submit]').click();
    const result = await response;
    expect(new URL(result.url()).pathname).toBe('/team-management/' + id);
    expect(result.status()).toBe(200);
    await expect(member).toContainText('R41 Member B Edited');
    await page.reload();
    await expect(member).toContainText('R41 Member B Edited');
    expect(snapshot().users.find(u => u.id === Number(id)).email).toBe('b@r41.example.invalid');
});

test('duplicate email retains modal and values, correction creates exactly once', async ({ page }) => {
    await login(page); await page.goto('/team-management');
    await page.locator('#addMemberBtn').click();
    await page.locator('#memberName').fill('R41 Validation Recovery');
    await page.locator('#memberEmail').fill(memberEmail);
    await page.locator('#memberPassword').fill(password);
    await page.locator('#memberRole').selectOption({ label: 'Team Member' });
    const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/team-management');
    await page.locator('#createAccountBtn').click();
    expect((await response).status()).toBe(422);
    await expect(page.locator('#addMemberModal')).toHaveClass(/active/);
    await expect(page.locator('#addMemberForm')).toContainText('The email has already been taken.');
    await expect(page.locator('#memberName')).toHaveValue('R41 Validation Recovery');
    expect(snapshot().users.filter(u => u.email === memberEmail)).toHaveLength(1);
    await page.locator('#memberEmail').fill('recovery@r41.example.invalid');
    await page.locator('#createAccountBtn').click();
    await expect(page.locator('.member-card').filter({ hasText: 'recovery@r41.example.invalid' })).toBeVisible();
    expect(snapshot().users.filter(u => u.email === 'recovery@r41.example.invalid')).toHaveLength(1);
});

test('Manager and owner PM create canonical tasks through the rendered form', async ({ page, browser }) => {
    await login(page);
    await createTask(page, 'R41 Basic Task');
    const context = await browser.newContext(); const pm = await context.newPage();
    await login(pm, pmEmail); await createTask(pm, 'R41 Revision Task', 'R41 Manager');
    await context.close();
});

async function transition(page, title, action, prompts = []) {
    await page.goto('/tasks');
    const button = card(page, title).locator(`[data-transition="${action}"]`);
    const url = await button.getAttribute('data-url');
    const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url() === url);
    await button.click();
    if (prompts.length) {
        for (const value of prompts) {
            if (value !== null) await page.locator('.swal2-popup .swal2-input, .swal2-popup .swal2-textarea').filter({ visible: true }).fill(value);
            await page.locator('.swal2-confirm').click();
        }
    } else await page.locator('.swal2-confirm').click();
    expect((await response).status()).toBe(200);
    await settleSuccess(page);
}
async function deadline(page, title, kind) {
    await page.goto('/tasks');
    const button = card(page, title).locator('[data-action="change-deadline"]');
    await expect(button).toHaveText(`Change ${kind[0].toUpperCase() + kind.slice(1)} Deadline`);
    const url = await button.getAttribute('data-url');
    expect(url).toMatch(new RegExp(`/deadline/${kind}$`));
    const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url() === url);
    await button.click(); await page.locator('.swal2-input').fill(date(9));
    await page.locator('.swal2-confirm').click();
    await page.locator('.swal2-textarea').fill('R41 current stage qualification');
    await page.locator('.swal2-confirm').click();
    expect((await response).status()).toBe(200); await settleSuccess(page);
    const db = snapshot(); const task = db.tasks.find(t => t.title === title);
    expect(task[kind + '_due_date'].slice(0, 10)).toBe(date(9));
    const event = db.events.filter(e => e.task_id === task.id).at(-1);
    expect(event.event_type).toBe('task.deadline_changed');
    expect(event.metadata.deadline_type).toBe(kind);
}

test('basic approval and full revision lifecycle use the current deadline action', async ({ page, browser }) => {
    await login(page);
    const assigneeContext = await browser.newContext(); const assignee = await assigneeContext.newPage(); await login(assignee, memberEmail);
    const pmContext = await browser.newContext(); const pm = await pmContext.newPage(); await login(pm, pmEmail);
    await transition(assignee, 'R41 Basic Task', 'start');
    await transition(assignee, 'R41 Basic Task', 'submit', ['Ready']);
    await transition(pm, 'R41 Basic Task', 'review');
    await deadline(pm, 'R41 Basic Task', 'review');
    await transition(pm, 'R41 Basic Task', 'approve', ['Accepted']);
    await transition(assignee, 'R41 Revision Task', 'start');
    await transition(assignee, 'R41 Revision Task', 'submit', ['Ready']);
    await transition(page, 'R41 Revision Task', 'review');
    await deadline(page, 'R41 Revision Task', 'review');
    await transition(page, 'R41 Revision Task', 'revision-request', ['Please revise', date(6)]);
    await deadline(page, 'R41 Revision Task', 'revision');
    await transition(assignee, 'R41 Revision Task', 'revision-start');
    await transition(assignee, 'R41 Revision Task', 'hold', ['R52 waiting during revision']);
    await expect(card(assignee, 'R41 Revision Task')).toContainText('Revision deadline:');
    await expect(card(assignee, 'R41 Revision Task')).toContainText('On Hold');
    await deadline(page, 'R41 Revision Task', 'revision');
    const held = snapshot();
    const heldTask = held.tasks.find(task => task.title === 'R41 Revision Task');
    const holdEvent = held.events.find(event => event.task_id === heldTask.id && event.event_type === 'task.held');
    expect(holdEvent.metadata.deadline_type).toBe('revision');
    expect(holdEvent.changed_fields.active_deadline.after).toBe(heldTask.revision_due_date.slice(0, 10));
    await transition(assignee, 'R41 Revision Task', 'resume');
    await transition(assignee, 'R41 Revision Task', 'resubmit', ['Corrected']);
    await transition(page, 'R41 Revision Task', 'review');
    await deadline(page, 'R41 Revision Task', 'review');
    await transition(page, 'R41 Revision Task', 'approve', ['Accepted revision']);
    const db = snapshot();
    for (const title of ['R41 Basic Task', 'R41 Revision Task']) {
        const task = db.tasks.find(t => t.title === title);
        expect(task.status).toBe('completed');
        expect(db.approvals.filter(a => a.task_id === task.id)).toHaveLength(1);
        const events = db.events.filter(e => e.task_id === task.id);
        expect(events.map(e => e.sequence)).toEqual(events.map((_, i) => i + 1));
    }
    expect(db.notifications).toBeGreaterThan(0);
    await assigneeContext.close(); await pmContext.close();
});

test('two tabs reject a stale task edit and Reload latest recovers', async ({ page, context }) => {
    await login(page); await createTask(page, 'R41 Conflict Task');
    const other = await context.newPage();
    for (const p of [page, other]) { await p.goto('/tasks'); await card(p, 'R41 Conflict Task').getByRole('button', { name: 'Edit R41 Conflict Task', exact: true }).click(); await expect(p.locator('#taskModal')).toHaveClass(/active/); }
    await page.locator('#taskTitle').fill('R41 Conflict Task Updated');
    await page.locator('#taskForm button[type=submit]').click();
    await expect(card(page, 'R41 Conflict Task Updated')).toBeVisible();
    await other.locator('#taskTitle').fill('R41 Stale overwrite');
    const response = other.waitForResponse(r => r.request().method() === 'PUT' && r.url().includes('/tasks/'));
    await other.locator('#taskForm button[type=submit]').click();
    expect((await response).status()).toBe(409);
    await expect(other.locator('.swal2-title')).toHaveText('Edit Conflict');
    await other.getByRole('button', { name: 'Reload latest task' }).click();
    await expect(other.locator('#taskTitle')).toHaveValue('R41 Conflict Task Updated');
    expect(snapshot().tasks.some(t => t.title === 'R41 Stale overwrite')).toBe(false);
});

test('Team role confirmation changes permissions after a fresh login', async ({ page, browser }) => {
    await login(page); await page.goto('/team-management');
    const member = page.locator('.member-card').filter({ hasText: 'b@r41.example.invalid' });
    const id = await member.getAttribute('data-member-id');
    await member.locator('.edit-btn').click();
    await page.locator('#editMemberRole').selectOption({ label: 'Project Manager' });
    const response = page.waitForResponse(r => r.request().method() === 'PUT' && new URL(r.url()).pathname === '/team-management/' + id);
    await page.locator('#editMemberForm button[type=submit]').click();
    await page.getByRole('button', { name: 'Yes, change!', exact: true }).click();
    expect((await response).status()).toBe(200);
    await expect(member).toContainText('Project Manager');
    const context = await browser.newContext(); const changed = await context.newPage();
    await login(changed, 'b@r41.example.invalid');
    await changed.goto('/projects');
    await expect(changed.locator('#newProjectBtn')).toBeVisible();
    await changed.goto('/team-management');
    await expect(changed.locator('#addMemberBtn')).toHaveCount(0);
    await changed.goto('/tasks');
    await expect(card(changed, 'R41 Conflict Task Updated')).toHaveCount(0);
    await context.close();
});

test('Team invalid inputs and transport failures retain form and recover controls', async ({ page }) => {
    await login(page); await page.goto('/team-management');
    await page.locator('#addMemberBtn').click();
    await page.locator('#memberName').fill('R41 Safe Recovery');
    await page.locator('#memberEmail').fill('safe@r41.example.invalid');
    await page.locator('#memberPassword').fill('weak');
    await page.locator('#memberRole').selectOption({ label: 'Team Member' });
    await expect(page.locator('#createAccountBtn')).toBeDisabled();
    await expect(page.locator('#addMemberModal')).toHaveClass(/active/);
    await page.locator('#memberPassword').fill(password);
    await page.locator('#memberRole').selectOption('');
    await expect(page.locator('#createAccountBtn')).toBeDisabled();
    await page.locator('#memberRole').selectOption({ label: 'Team Member' });
    // Adversarial form identity comes from the actual rendered control; server validates it.
    await page.locator('#memberRole').evaluate(select => select.appendChild(new Option('Invalid role', '999999')));
    await page.locator('#memberRole').selectOption('999999');
    await page.locator('#createAccountBtn').click();
    await expect(page.locator('[data-form-error="role_id"]')).toBeVisible();
    await page.locator('#memberRole').selectOption({ label: 'Team Member' });
    const endpoint = '**/team-management';
    await page.route(endpoint, route => route.continue({ postData: JSON.stringify({ ...route.request().postDataJSON(), password: 'weak' }) }));
    await page.locator('#createAccountBtn').click();
    await expect(page.locator('[data-form-error="password"]')).toBeVisible();
    await expect(page.locator('#memberPassword')).toHaveValue(password);
    await expect(page.locator('#createAccountBtn')).toBeEnabled();
    await page.unroute(endpoint);
    for (const status of [401, 403, 404, 409, 429, 500]) {
        await page.route(endpoint, route => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ message: 'INTERNAL STACK TRACE MUST NOT SHOW' }) }));
        await page.locator('#createAccountBtn').click();
        await expect(page.locator('[data-form-error="summary"]')).toBeVisible();
        await expect(page.locator('#addMemberForm')).not.toContainText('INTERNAL STACK TRACE');
        await expect(page.locator('#addMemberModal')).toHaveClass(/active/);
        await expect(page.locator('#memberName')).toHaveValue('R41 Safe Recovery');
        await expect(page.locator('#createAccountBtn')).toBeEnabled();
        await page.unroute(endpoint);
    }
    for (const type of ['html', 'malformed', 'network', 'redirect']) {
        await page.route(endpoint, route => type === 'network' ? route.abort('failed') : type === 'redirect'
            ? route.fulfill({ status: 302, headers: { Location: '/login' } }) : route.fulfill({
            status: 200, contentType: type === 'html' ? 'text/html' : 'application/json', body: '<html>PRIVATE INTERNAL DETAILS</html>',
        }));
        await page.locator('#createAccountBtn').click();
        await expect(page.locator('[data-form-error="summary"]')).toBeVisible();
        await expect(page.locator('#addMemberForm')).not.toContainText('PRIVATE INTERNAL');
        await expect(page.locator('#createAccountBtn')).toBeEnabled();
        await expect(page.locator('#addMemberModal')).toHaveClass(/active/);
        await page.unroute(endpoint);
    }
    expect(snapshot().users.some(u => u.email === 'safe@r41.example.invalid')).toBe(false);
});

test('pending Team and task form submissions send one request', async ({ page }) => {
    await login(page); await page.goto('/team-management');
    await page.locator('#addMemberBtn').click();
    await page.locator('#memberName').fill('R41 Pending');
    await page.locator('#memberEmail').fill('pending@r41.example.invalid');
    await page.locator('#memberPassword').fill(password);
    await page.locator('#memberRole').selectOption({ label: 'Team Member' });
    let calls = 0; let release;
    const wait = new Promise(resolve => { release = resolve; });
    await page.route('**/team-management', async route => { calls++; await wait; await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }); });
    await page.locator('#createAccountBtn').click();
    await expect(page.locator('#createAccountBtn')).toBeDisabled();
    await expect.poll(() => calls).toBe(1);
    await page.locator('#addMemberForm').evaluate(form => { form.dispatchEvent(new Event('submit', { cancelable: true })); form.dispatchEvent(new Event('submit', { cancelable: true })); });
    expect(calls).toBe(1); release();
    await expect(page.locator('#createAccountBtn')).toBeEnabled(); await page.unroute('**/team-management');
    await page.goto('/tasks'); await page.locator('#newTaskBtn').click();
    await page.locator('#taskTitle').fill('R41 Duplicate Task');
    await page.locator('#taskDescription').fill('Repeated submit qualification');
    await page.locator('#taskProject').selectOption({ label: 'R41 Core Project' });
    await page.locator('#taskAssignee').selectOption({ label: 'R41 Member A' });
    await page.locator('#taskReviewer').selectOption({ label: 'R41 PM' });
    await page.locator('#taskStartDate').fill(date(0)); await page.locator('#taskDueDate').fill(date(5));
    calls = 0; let releaseTask;
    const waitTask = new Promise(resolve => { releaseTask = resolve; });
    await page.route('**/tasks', async route => { calls++; await waitTask; await route.continue(); });
    await page.locator('#taskForm button[type=submit]').click();
    await expect.poll(() => calls).toBe(1);
    await page.locator('#taskForm').evaluate(form => { form.dispatchEvent(new Event('submit', { cancelable: true })); form.dispatchEvent(new Event('submit', { cancelable: true })); });
    expect(calls).toBe(1); releaseTask();
    await expect(card(page, 'R41 Duplicate Task')).toBeVisible(); await page.unroute('**/tasks');
    const db = snapshot(); const tasks = db.tasks.filter(t => t.title === 'R41 Duplicate Task');
    expect(tasks).toHaveLength(1);
    expect(db.events.filter(e => e.task_id === tasks[0].id)).toHaveLength(1);
});

test('logout and login retain canonical completed state and history', async ({ page }) => {
    await login(page); await page.getByRole('button', { name: 'Logout', exact: true }).click();
    await expect(page.locator('#email')).toBeVisible(); await login(page);
    await page.goto('/history');
    await expect(page.getByText('R41 Basic Task', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('R41 Revision Task', { exact: true }).first()).toBeVisible();
    await page.screenshot({ path: `${process.env.BROWSER_OUTPUT_DIR || 'output/playwright/r41'}/r41-completed-history.png`, fullPage: true });
    await page.locator('tr').filter({ hasText: 'R41 Revision Task' }).getByRole('button', { name: 'Timeline', exact: true }).click();
    await expect(page.locator('.swal2-popup')).toContainText('Deadline changed');
    await expect(page.locator('.swal2-popup')).toContainText('Task created');
    await page.screenshot({ path: `${process.env.BROWSER_OUTPUT_DIR || 'output/playwright/r41'}/r41-revision-timeline.png`, fullPage: true });
});
import { mkdirSync, writeFileSync } from 'node:fs';

test('R5.1 delayed approval cannot approve a newer submission', async ({ page, context, browser }) => {
    await login(page);
    const title = `R51 Delayed Approval ${Date.now()}`;
    await createTask(page, title);
    const memberContext = await browser.newContext();
    const member = await memberContext.newPage(); await login(member, memberEmail);
    const pmContext = await browser.newContext();
    const pm = await pmContext.newPage(); await login(pm, pmEmail);
    await transition(member, title, 'start');
    await transition(member, title, 'submit', ['Original work']);
    await transition(pm, title, 'review');
    await pm.goto('/tasks');
    const button = card(pm, title).locator('[data-transition="approve"]');
    const url = await button.getAttribute('data-url');
    const rendered = snapshot().tasks.find(t => t.title === title).lock_version;
    // Keep the original reviewer's rendered page and approval dialog while another tab revises.
    await button.click(); await pm.locator('.swal2-textarea').fill('Intent on original work');
    const other = await pmContext.newPage();
    await transition(other, title, 'revision-request', ['Replace original work', date(6)]);
    await transition(member, title, 'revision-start');
    await transition(member, title, 'resubmit', ['New work']);
    await transition(other, title, 'review');
    const before = snapshot();
    const current = before.tasks.find(t => t.title === title);
    const response = pm.waitForResponse(r => r.request().method() === 'POST' && r.url() === url);
    await pm.locator('.swal2-confirm').click();
    const result = await response;
    const after = snapshot();
    const observed = { rendered, current: current.lock_version, payload: result.request().postDataJSON(), status: result.status(), after: after.tasks.find(t => t.id === current.id), approvals: after.approvals.filter(a => a.task_id === current.id), eventsBefore: before.events.filter(e => e.task_id === current.id).length, eventsAfter: after.events.filter(e => e.task_id === current.id).length, historyBefore: before.history.filter(h => h.task_id === current.id).length, historyAfter: after.history.filter(h => h.task_id === current.id).length, notificationsBefore: before.notifications, notificationsAfter: after.notifications };
    const evidenceDir = process.env.R51_EVIDENCE_DIR || 'output/r5-1';
    mkdirSync(evidenceDir, { recursive: true });
    writeFileSync(evidenceDir + '/delayed-browser-approval.json', JSON.stringify(observed, null, 2));
    expect(result.status()).toBe(409);
    expect(observed.payload.expected_version).toBe(rendered);
    expect(observed.after.status).toBe('in_review');
    expect(observed.after.lock_version).toBe(current.lock_version);
    expect(observed.approvals).toHaveLength(0);
    expect(observed.eventsAfter).toBe(observed.eventsBefore);
    expect(observed.historyAfter).toBe(observed.historyBefore);
    expect(observed.notificationsAfter).toBe(observed.notificationsBefore);
    await expect(pm.locator('.swal2-title')).toHaveText('Task changed');
    await pm.screenshot({ path: (process.env.BROWSER_OUTPUT_DIR || 'output/playwright/r5-1') + '/stale-approval.png', fullPage: true });
    await pm.getByRole('button', { name: 'Reload latest task' }).click();
    await pm.waitForLoadState('networkidle');
    await transition(pm, title, 'approve', ['Reviewed new work']);
    expect(snapshot().tasks.find(t => t.id === current.id).status).toBe('completed');
    await memberContext.close(); await pmContext.close();
});
