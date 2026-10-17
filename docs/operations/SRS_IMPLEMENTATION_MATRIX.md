# SRS Implementation Matrix — GoTrainee

Source of truth: *GoTrainee — Final Reference SRS v1.0*, plus the agreed workflow amendment (Admin → Team Leader → Team → Plan Agreement).
Status legend:
- **Verified**: complete, with automated tests and, where noted, a deployed API or browser check.
- **Implemented**: implemented but not sufficiently verified.
- **Partial**: partially implemented.
- **Missing**: not implemented.
- **Blocked**: waiting on an external dependency.

Last updated: 2026-10-17. Backend `43575db`, Frontend `777cc26`, both on branch `feat/render-migration-and-agreement-workflow` (see AUTONOMOUS_PROGRESS.md for later commits).

## Functional requirements

| ID | Requirement | Status | Evidence / gap |
|---|---|---|---|
| FR-01 | Student registration | Verified | `RegistrationTest`; staging API register 201 |
| FR-02 | Organization registration | Verified | `RegistrationTest` (role `organization`); staging E2E |
| FR-03 | Authentication | Verified | Sanctum bearer tokens; login/me on staging; Google SSO tested (`GoogleAuthTest`). Google on Render is **Blocked**: the redirect URI must be added in Google Cloud Console |
| FR-04 | Logout | Implemented | `/api/logout` deletes the current token |
| FR-05 | Role-based authorization | Verified | Server middleware `admin` and `active`, project-scoped `ProjectAccess` (leader/organization/admin); `AdminWorkflowTest`, `ProjectTeamFormationTest` (IDOR → 404) |
| FR-06 | Profile management | Implemented | Student/organization profile endpoints (pre-existing tests) |
| FR-07 | Student skills | Verified | `StudentOnboardingTest` |
| FR-08 | Initial skill assessment | Verified | Gemini coding-task assessments (`FreeResponseAssessmentTest`). 2026-10-17: a staging session was graded by **live Gemini on Render** (REST API and Git passed), which unlocked the member's role eligibility |
| FR-09 | Evidence profile | Verified | `student_evidence` issued on project completion for team members and the leader only; leader evaluation and client score attached later. `ProjectExecutionTest`; staging API E2E; browser `/student/evidence` (desktop + 390 px) |
| FR-10 | Opportunity listing | Verified | Only admin-approved (`published`) projects are listed; paginated |
| FR-11 | Opportunity application | Verified | Eligibility, duplicate prevention (unique key + lock), leader cannot apply to own project |
| FR-12 | Application status | Verified | `/student/applications` |
| FR-13 | Organization problem intake | Verified | Staging E2E: request created |
| FR-14 | AI agent questions | Verified | Staging E2E with **real Gemini**: adaptive discovery completed |
| FR-15 | AI intake responses stored | Verified | `ProjectDiscoveryPersistenceTest` |
| FR-16 | Specification generation | Verified | Staging E2E with real Gemini: `proposal_ready` with staffing |
| FR-17 | Proposal contents | Implemented | Proposal schema includes cost, duration, milestones, payment, operational cost, team |
| FR-18 | Proposal review | Verified | Organization proposal page |
| FR-19 | Proposal decision | Verified | Organization approval submits the proposal to **admin review** (agreed workflow). Admin approves, rejects or requests changes; all audited |
| FR-20 | Proposal revision | Verified | Revision flow; an admin "request changes" returns the request to the organization |
| FR-21 | Project creation | Verified | Opportunity is created on approval and published only after admin approval |
| FR-22 | Basic matching | Verified | `CandidateRanking`, explainable factors. Visible **only to the assigned leader** |
| FR-23 | Team formation | Verified | Leader forms and confirms the team from eligible applicants; headcount/role checks; locks |
| FR-24 | Team leader assignment | Verified | Admin assigns, replaces and revokes a project-scoped leader; eligibility and justified override; one active leader per project (unique key); history |
| FR-25 | Project dashboard | Verified | `/projects/{id}/execution`: status, milestones, tasks, team, progress %. Role-shaped payload (leader, member, organization, admin). Staging E2E + browser |
| FR-26 | Task management | Verified | Leader creates, updates, assigns, prioritizes and tracks tasks; assignee must be a team member; due date inside the agreed timeline; manual vs. review-driven statuses enforced server-side |
| FR-27 | Deliverable submission | Verified | Assignee only (others get 404); reference URL (http/https only) + notes; numbered revisions; task moves to `in_review`; leader notified |
| FR-28 | Deliverable review | Verified | Leader accepts or requests revision (feedback required); double review → 409; member notified. UI flow verified in browser |
| FR-29 | Milestone progress | Verified | Milestones copied from the agreed plan at execution start (backfilled for older projects); leader updates status; a milestone cannot complete with open tasks; organization notified |
| FR-30 | Student evaluation | Verified | One leader evaluation per member after completion (4 criteria 1–5 + comments, unique key); hidden from the organization |
| FR-31 | Client feedback | Verified | One organization feedback per project after completion (satisfaction, optional leader score, recommendation, comments) |
| FR-32 | Evidence update | Verified | Completion writes role, skills used (role skills), completed tasks; evaluation and client score update the record |
| FR-33 | Notifications | Verified | In-app notifications for application decisions, team confirmation, task assignment, submissions, reviews, milestones, completion, evaluations, plus the earlier review/leader/plan events. Working bell with unread count, links and mark-as-read |
| FR-34 | Admin user management | Verified | List/search, activate/deactivate (revokes tokens; blocks login, Google exchange and existing tokens) |
| FR-35 | Admin project management | Verified | Review queue, decisions, suspend/withdraw/republish, leader management |
| FR-36 | Admin monitoring | Implemented | `/api/admin/overview` stats and recent audit activity |
| FR-37 | Audit of critical actions | Verified | `audit_logs` covers proposal submission, review decisions, leader changes, plan submit/approve/changes, execution start, user (de)activation and promotion |

## Agreed workflow amendment

| Rule | Status | Evidence |
|---|---|---|
| Org submits request; AI discovery; draft proposal | Verified | Staging E2E (real Gemini) |
| Admin accepts, rejects or requests changes | Verified | `AdminWorkflowTest`; staging E2E |
| Admin assigns a project-scoped Team Leader; org cannot appoint | Verified | API: org → 403 on admin routes; old `/teams/{id}/leader` removed |
| Org cannot see the ranked pool or assessment scores | Verified | Org gets 404 on `/projects/{id}/matches`; overview payload contains no score, ranking or contact fields |
| Leader may apply to other projects, not their own | Verified | `test_a_leader_can_still_apply_to_other_projects_but_not_the_one_they_lead` |
| Leader reviews applicants and forms the team | Verified | `ProjectTeamFormationTest` |
| Plan workspace: scope, deliverables, acceptance criteria, budget, payment schedule, timeline, milestones, sprints | Verified | `ProjectPlanAgreementTest`; staging E2E |
| Leader submits a specific immutable version (= leader approval) | Verified | Frozen after submit; SHA-256 canonical content hash |
| Org comments, requests changes or approves that exact version | Verified | Hash must match; stale version/content → 409 |
| Execution only after both approve the same version | Verified | `/projects/{id}/start` requires an agreed plan and a confirmed team |
| Execution budget, platform commission and requirements fee kept separate | Verified | Computed server-side (`config/gotrainee.php`) |

## Non-functional requirements (summary)

| ID | Status | Notes |
|---|---|---|
| NFR-01 Performance | Partial | Pagination on lists; free Render instance cold-starts take about 30–60 s |
| NFR-02 Security | Implemented | Server authorization, validation, hashed passwords, HTTPS on Render, `APP_DEBUG=false`, generic health/error responses, no secrets in repo or bundle |
| NFR-04 Reliability | Implemented | Transactions plus row locks on review, leader, team and plan operations; unique constraints |
| NFR-05 Availability | Blocked (cost) | Free tier sleeps; a 99 % target needs a paid instance |
| NFR-08 Compatibility | Partial | Responsive layouts verified in Chrome at 1366 px and 390 px for the workflow and execution screens (no horizontal scroll); mobile bottom bar scrolls instead of breaking labels. Other browsers not tested |
| NFR-09 Data integrity | Implemented | FKs, unique keys, immutable agreed plans |
| NFR-10 Privacy | Verified | Organizations see roster names and roles only |
| NFR-11 Backup/recovery | Blocked (cost) | Free Postgres has no backups and expires on 2026-11-15; production needs a paid database |
| NFR-12 Accessibility | Partial | Labels, focus-visible, `role=alert/status`, reduced motion on new screens; no full WCAG audit |
