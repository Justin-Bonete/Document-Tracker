# DTS Phase 3: Routing, Custody & Workflow

Adds: routing (send / acknowledge / complete / return / forward / cancel), custody that moves on
acknowledgement, decisions (approve / reject / return with reasons), release / close / reopen,
comments, inbox / outbox, a custody timeline, and one WorkflowService that owns every status change.

No new migrations: the tables were created in Phase 1.

## Install (from C:\dev\document-tracker)
1. Copy this folder over the project (app, database, routes). Say YES to replacing `routes/api.php` and `RbacSeeder.php`.
2. Seed the new permissions, office and dev users, then give all dev users one password:
   ```
   php artisan db:seed --class=RbacSeeder
   powershell -ExecutionPolicy Bypass -File .\set-dev-passwords.ps1
   ```
3. `php artisan serve` (leave running), then in a second window:
   ```
   powershell -ExecutionPolicy Bypass -File .\phase3-test.ps1
   ```

## Workflow rules
Document: registered -> routed -> in_review -> approved | rejected | returned -> released -> closed (reopen -> registered)
Route:    sent -> acknowledged -> completed | returned,  or sent -> cancelled
- One active route per document (sequential). Locks stop two people acting at once.
- Custody moves to the receiver when they ACKNOWLEDGE; it goes back to the sender on a decision or return.
- A named recipient acknowledges; an office-level route is claimed by whoever acknowledges it;
  a receiving clerk (`routes.receive_for_office`) can acknowledge on someone's behalf (recorded in the event).
- Only the assignee can complete / decide / return / forward.
- Rejecting or returning requires a reason. Release needs `approved`; close needs `released` or `rejected`.

## Endpoints
POST /documents/{id}/routes, GET /documents/{id}/routes, GET /routes?box=inbox|outbox
POST /routes/{id}/acknowledge | complete | return | forward | cancel
POST /documents/{id}/decisions | release | close | reopen
GET|POST /documents/{id}/comments
