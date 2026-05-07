# System Documentation

## Table of Contents
1. [Overview](#overview)
2. [Roles & Permissions](#roles--permissions)
3. [Project Membership](#project-membership)
4. [Project Manager Assignment](#project-manager-assignment)
5. [Task Assignment & Restrictions](#task-assignment--restrictions)
6. [Dashboards & Analytics](#dashboards--analytics)
7. [Notifications](#notifications)
8. [Troubleshooting & Edge Cases](#troubleshooting--edge-cases)
9. [Performance & Scalability](#performance--scalability)
10. [Testing & QA](#testing--qa)
11. [Versioning & Changelog](#versioning--changelog)

---

## Overview
This system is a comprehensive task and project management platform built with Laravel. It supports role-based access, project membership, advanced analytics, notifications, and robust validation. The system is designed for organizations with managers, project managers, and team members, supporting both granular control and ease of use.

---

## Roles & Permissions
- **Manager**: Full access to all projects, users, analytics, and membership management. Can assign project managers and manage all team members.
- **Project Manager**: Assigned to one or more projects. Can manage only their assigned projects and the team members within them. Can create tasks and add/remove team members for their projects.
- **Team Member**: Assigned to projects by managers/project managers. Can only see and interact with projects and tasks they are assigned to. Cannot manage membership or projects.

**Role Assignment:**
- Only managers can assign or change the role of a user to manager or project manager.
- Project managers can only assign team members to their projects.

---

## Project Membership
### What is Project Membership?
Project membership is a many-to-many relationship between users and projects. Only members of a project can be assigned tasks for that project, and only project members appear in analytics and dashboards for that project.

### Managing Membership
- **Add Members:**
  - Go to the Projects page or open a project details page.
  - Click “Manage Members” or scroll to the “Project Members” section.
  - Select a team member (must have the `team_member` role) and click “Add”.
  - The member is added and notified.
- **Remove Members:**
  - Click “Remove” next to a member in the “Project Members” section.
  - If the member has active (not completed) tasks in the project, you must reassign or complete those tasks before removal.
  - Upon removal, the member is notified.

### Example Flow
1. Manager creates a new project and assigns a project manager.
2. Project manager adds team members to the project.
3. Project manager assigns tasks to those members.
4. If a member leaves the project, all their active tasks must be reassigned or completed first.

---

## Project Manager Assignment
- Each project can have only one project manager, but a project manager can manage multiple projects.
- When creating or editing a project, select a project manager from the dropdown (only users with the `project_manager` role are shown).
- Project managers have full control over their assigned projects and team members.

---

## Task Assignment & Restrictions
- When creating or editing a task, the “Assign To” dropdown only shows members of the selected project.
- The backend enforces that only project members can be assigned tasks for that project (even if the UI is bypassed).
- If a user is removed from a project, they cannot be assigned new tasks for that project.
- If a member has active tasks, they cannot be removed from the project until those tasks are completed or reassigned.

### Example Task Assignment Flow
1. Project manager selects a project in the task creation form.
2. The “Assign To” dropdown is dynamically populated with only the members of that project.
3. If the project is changed, the assignee list updates accordingly.
4. Attempting to assign a task to a non-member (via API or UI manipulation) will be blocked by backend validation.

---

## Dashboards & Analytics
- **Manager Dashboard:**
  - Shows all projects, all team members, and all analytics.
  - Can view and manage any project or user.
- **Project Manager Dashboard:**
  - Shows only projects where the user is the project manager.
  - Team analytics and workload are limited to members of those projects.
- **Team Member Dashboard:**
  - Shows only projects the user is assigned to.
  - Only tasks assigned to the user are visible.

**Analytics Filtering:**
- All analytics (charts, performance, trends) are filtered by project membership and role.
- Managers see global analytics; project managers see only their projects; team members see only their own data.

---

## Notifications
- **When a member is added to a project:**
  - The member receives an in-app and email notification (if configured).
  - The project manager receives a notification when a new member is added (unless they add themselves).
- **When a member is removed from a project:**
  - The member receives a notification.
- **Task assignment, completion, and reminders** are also notified as per existing logic.

---

## Troubleshooting & Edge Cases
- **Cannot remove member:**
  - If a member has active tasks in a project, you must reassign or complete those tasks before removal.
- **Cannot assign task to user:**
  - Ensure the user is a member of the selected project.
- **Notifications not received:**
  - Check notification configuration (mail/database) and user email settings.
- **Role assignment errors:**
  - Only managers can assign privileged roles.
- **Performance issues with large teams/projects:**
  - Use pagination and search in team management and project member lists.
  - Eager loading is used for related data to minimize queries.

---

## Performance & Scalability
- **Eager Loading:**
  - All project, member, and task queries use eager loading to reduce N+1 query issues.
- **Pagination:**
  - Team management and project member lists are paginated for large datasets.
- **Search & Filtering:**
  - Real-time search and filtering are available for teams and projects.
- **Best Practices:**
  - For very large organizations, consider database indexing on `project_user`, `tasks`, and `users` tables.
  - Monitor query performance and optimize as needed.

---

## Testing & QA
- **Automated Feature Tests:**
  - Project membership (add/remove, validation, notifications)
  - Task assignment restrictions
  - Role-based dashboards and analytics
- **Manual QA:**
  - See QA_DOCUMENTATION.md for detailed test scenarios and regression checklist.
- **How to Run Tests:**
  - `php artisan test --filter=ProjectMembershipTest`

---

## Versioning & Changelog
- See VERSION_HISTORY.md for a summary of all major updates, features, and bug fixes.
- All changes are documented for developer handover and onboarding.

---

## Developer Best Practices
- **Validation:** Use Form Requests for all input validation.
- **Authorization:** Use policies or manual role checks for sensitive actions.
- **Error Handling:** Use try/catch in controllers, return consistent JSON responses.
- **Frontend:** Use Blade for structure, custom JS for interactivity, CSS for styling.
- **Testing:** Write feature and unit tests for all new features and edge cases.
- **Documentation:** Keep this and QA docs up to date with all changes.

---

*This documentation is intended for developers, admins, and QA to ensure smooth onboarding, maintenance, and scaling of the Task Management System.* 