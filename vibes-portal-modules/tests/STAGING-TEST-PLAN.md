# Vibes Portal Modules — staging test plan

These tests require a staging WordPress copy with the core portal plugin, a database backup, and at least one administrator, one finance-authorized user, one CRM-authorized user, and two ordinary employees.

## Workflow preservation

1. Submit a public inquiry.
2. Confirm it appears once in the existing inquiries route.
3. Create and send a quotation.
4. Approve the quotation through the existing client review URL.
5. Confirm one project and one task per quoted service.
6. Submit task work from the employee account.
7. Confirm Drive/approval state transitions and client review are unchanged.
8. Approve the project as the client and confirm invoice generation still occurs.

## Connect

- Create a direct conversation between employee A and employee B; confirm employee C and administrators who are not members receive 403.
- Post a message, reply, react, mark read, upload an allowed file, and download it as a member.
- Confirm the polling request uses `after` and slows when the tab is hidden.

## Analytics

- Confirm employee A sees only assigned tasks and own metrics.
- Confirm a manager without team capability cannot request team scope.
- Change a complexity value, verify a new effective-dated row, and ensure historical task points do not change.
- Submit/revise/complete a task and verify it is counted once.
- Export CSV and verify no salaries or private chat data appear.

## Finance

- Confirm ordinary employees and clients receive 403 for every finance route.
- Record a partial payment, verify the outstanding balance, then record the remainder and verify paid status.
- Add an expense, recurring expense, salary history row, salary payment, and FX rate.
- Verify USD, SAR, and LBP totals remain separated.
- Download authenticated CSV and PDF exports; verify finance audit events.

## CRM / Lead Hunter

- Create a manual lead twice and verify duplicate rejection.
- Add a public RSS source and run a scan.
- Verify reachable evidence is stored, expired/government/irrelevant items are rejected, and verified demand is separated from potential opportunity.
- Approve a lead, generate an outreach draft, and confirm no email is sent automatically.
- Convert a lead with and without an existing inquiry; verify no duplicate client inquiry is created.

## Responsive/security checks

- Exercise module pages at desktop and mobile widths.
- Verify every new REST route has a capability or membership check.
- Verify private attachment paths cannot be fetched directly and only the membership-checked download route serves them.

