# QA Documentation
## Overview
This document provides test scenarios, expected behaviors, and instructions for manual and automated QA of the Task Management System.

---

## 1. Team Management
### Scenarios
- Add a new team member (manager/project manager only)
- Assign a role (manager, project_manager, team_member) on creation
- Edit team member details and role (with restrictions)
- Activate/deactivate team members
- Search, filter, and paginate team list
- **Add/remove team members to/from projects (with validation)**
- **Cannot remove member with active tasks in project**
- **Notifications sent on add/remove**

### Expected Behaviors
- Only managers/project managers can add/remove members to projects
- Only team members can be added as project members
- Cannot remove a member if they have active tasks in the project
- Member receives notification when added/removed
- Project manager receives notification when a new member is added

---

## 2. Task Assignment
### Scenarios
- Create/edit/delete tasks
- Assign tasks to team members
- **Only assign tasks to members of the selected project**
- **Cannot assign task to non-member (UI and backend)**

### Expected Behaviors
- Assignee dropdown only shows project members
- Backend blocks assignment to non-members

---

## 3. Dashboards & Analytics
### Scenarios
- Manager/project manager/team member dashboards
- Analytics filtered by project membership

### Expected Behaviors
- Managers see all projects and users
- Project managers see only their projects and assigned team members
- Team members see only their assigned projects and tasks

---

## 4. Notifications
### Scenarios
- Member added to project
- Member removed from project
- Project manager notified when new member added

### Expected Behaviors
- Notifications appear in-app and via email (if configured)

---

## 5. Automated Tests
- Feature tests for project membership, task assignment, and notifications
- Run: `php artisan test --filter=ProjectMembershipTest`

---

## 6. Known Issues
- None as of this release

---

## 7. Regression Checklist
- Team management, project membership, and task assignment flows
- Notifications for all membership changes
- Analytics and dashboards reflect correct membership 