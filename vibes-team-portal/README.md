# Vibes Team Portal 0.3.0

Private WordPress application for `team.vibesagency.co`.

## 0.3.0 workflow

- Quotations use the supplied Vibes A4 design for portal preview, printing and secure client review.
- An approved quotation becomes a project only after every quoted service is assigned to an employee.
- Each quotation line creates its own task, so services can be distributed across specialists.
- Employees see only their assigned tasks and submit completed work with a note or Google Drive link.
- When every quoted service is submitted, the portal creates one draft invoice from the approved quotation.
- Invoice numbers remain unique through a database unique key and collision-checked Vibes references.

## Included in the first build

- Branded private login and responsive employee portal
- Administrator, manager and employee roles
- Employee account creation with `@vibesagency.co` validation
- One-time random temporary passwords and forced password change
- Employee directory and account deletion
- Projects and project leads
- Task assignment, status updates and completion notifications
- Company announcements
- Team Hub posts
- Leave requests with administrator approval or decline
- Shared calendar populated by task deadlines and approved leave
- Creative Review and Files & Brand foundations
- Noindex protection for login and portal pages

## Added in 0.1.5

- English-only login and portal interface
- Complete mobile navigation and responsive employee/leave records
- Employee gender, phone, joining date and birth date
- Administrator-managed company holidays on the shared calendar
- Profile-image menu with profile and logout actions
- Google Drive task links that keep task files out of WordPress storage and open the Vibes Active Projects folder
- Task number, database ID, client, assignee, description, file attachments, status and calendar date
- Employee-specific task visibility and administrator completion notifications

## Added in 0.1.9

- Website and staff-created client inquiries, including services, budget, deadline, notes and source
- Sales workflow from inquiry to branded quotation, client approval and active project
- Reusable Vibes quotation design with SAR/USD line items, VAT, terms and print/PDF output
- Secure client quotation links with direct email delivery, online approval, change requests and administrator notifications
- Website package requests carry service labels, quantities and prices into quotation preparation
- Post-delivery invoices and payment-status tracking, matching the agency's pay-after-delivery model
- Dashboard indicators for new inquiries and unpaid invoices
- Protected sales access for administrators and managers
- Public inquiry endpoint for the Vibes Build Your Package calculator, with origin checks, a honeypot and rate limiting

## Created pages

Activation creates `/team-login/` and `/team-portal/` automatically.

## Security notes

Portal endpoints require an authenticated WordPress session and REST nonce. Administrative actions are protected by custom WordPress capabilities. The public inquiry endpoint accepts requests only from `vibesagency.co`, applies spam and rate-limit checks, and sends a notification to the agency addresses. Temporary passwords are returned only once and are never stored as plaintext.

