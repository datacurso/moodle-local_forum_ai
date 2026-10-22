## [1.1.3-wp] - 2026-10-13

**Compatibility note:** Moodle Workplace 4.5 branch (`-wp` release). The plugin still loads on a plain Moodle LMS, where the site settings page and site values keep working as before.

### Added

- Moodle Workplace: every tenant has a completely independent configuration, stored in the new `local_forum_ai_tenant_config` table. A setting resolves to the value the tenant saved, or otherwise to the plugin built-in default; it never reads the site value and never the value of another tenant. Per-forum configuration is unchanged
- Moodle Workplace: when the AI licence of a tenant is missing or invalid, the student reply is no longer lost silently. The failed attempt is listed in the history with the status "Failed" and the users who approve responses in that forum are notified (new message provider `ai_failure`); the notification carries a fixed text, never the provider error message
- New capability `local/forum_ai:managetenantsettings`, granted to the manager archetype and to the Workplace Tenant administrator role, both on a fresh install and on upgrade
- Moodle Workplace: the upgrade moves the former site settings of the plugin to the Workplace default tenant only, so its users keep the configuration they had; a value the default tenant already stores is kept, the site rows are removed from `config_plugins` and every other tenant starts from the plugin built-in defaults. A plain Moodle LMS keeps its site settings untouched

### Changed

- Moodle Workplace: the nine plugin settings are read through one resolver using the tenant of the forum course (its course category, otherwise the tenant of the user). Nothing is stored at site level, no site defaults are seeded and nothing at site level can switch on, switch off or change all tenants at once
- Moodle Workplace: the switches "Enable Forum AI" and "Enable AI by default" only gate their own tenant in the observers, the ad hoc tasks, the delayed queue, the manual review service and button, the forum settings form and the navigation. The delayed queue items of a tenant with the AI disabled are dropped, and the other tenants are not affected
- Moodle Workplace: turning off "Enable AI by default" disables the existing forum configurations of the active tenant only, never those of other tenants. A plain Moodle LMS still disables every forum
- Moodle Workplace: the configuration of each tenant is the standard Forum AI settings page of Moodle LMS (`/admin/settings.php?section=local_forum_ai_settings`, same fields and help), now read and written per tenant. Site administrators find it in Site administration > Plugins > Local plugins > "Forum AI"; tenant administrators, who cannot see Local plugins, find the same page under Site administration > AI. A notice names the tenant being edited, which is the tenant selected with the Workplace tenant switcher or the own tenant of a tenant administrator
- Moodle Workplace: the AI service client is created for the resolved tenant, so cron and ad hoc tasks use the licence of the tenant of the forum

### Fixed

- Course restore keeps the status "Failed" of restored history rows instead of turning them into pending responses
- Moodle Workplace: when a backup carries no delay value, course restore uses the default delay of the tenant that owns the target course instead of the plugin built-in default

## [1.1.3] - 2026-10-06

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.2**.

Security release answering the MindFree re-evaluation of version 2026083100 (1.1.1). Each entry names the MindFree finding code, the equivalent internal code and the tests that cover it.

### Added

- Upgrade step that assigns the student-archetype roles to forums whose "Allowed roles for AI responses" list was empty; course restore applies the same migration to backups made before this version (FAI-SEC-005)
- Forum settings validation: the AI cannot be enabled without at least one allowed role (FAI-SEC-005)

### Changed

- An empty "Allowed roles for AI responses" list now means the AI responds to nobody, as the help text always stated; forums that relied on "empty means everyone" now respond only to students after the upgrade (FAI-SEC-005, tests: `role_trigger_test`, `form_validation_test`, `upgrade_test`, `backup_restore_test::test_restore_migrates_empty_allowedroles_to_student_roles`)
- Approval notifications are only sent to approvers who can access the discussion's group and approve the response (FAI-SEC-001-R1)

### Fixed

- Approving or opening an already handled response now shows a clear message instead of a database error, and the review page no longer fails for handled responses with developer debugging enabled (tests: `approve_response_concurrency_test`, `cleanup_expired_test`, `backup_restore_test`)

### Security

- FAI-SEC-001-R1 / FORUMAI-SEC-001: separate-groups isolation is enforced on every entry point through a single access rule: pending and history listings (also at course level, which now honour module capability overrides and visibility), the review page, the detail, edit, approve and reject services, manual publication, approval notifications and the manual AI review payload (tests: `group_isolation_test`, `group_mode_publish_test`)
- FAI-SEC-006: the manual "Review with AI" service and button are refused while Forum AI or the global AI switch is disabled; every call to the AI service checks both switches (tests: `process_review_test::test_execute_refused_when_forum_ai_disabled`, `test_execute_refused_when_global_ai_disabled`, `ai_service_test::test_calls_refused_when_ai_disabled`, `hook/button_ai_review_test`)
- FAI-SEC-010 / FORUMAI-SEC-002: approving, rejecting and editing a pending response are serialised with a lock and use conditional field-level writes, so concurrent requests can no longer publish duplicates or revert an approved response; bulk expiry no longer expires approved responses (tests: `external/approve_response_concurrency_test`, `cleanup_expired_test::test_cleanup_does_not_expire_row_approved_after_select`)
- FAI-SEC-009: approval tokens, including those issued on course restore, come from a single CSPRNG factory instead of `md5(uniqid())` (tests: `approval_token_test`, `backup_restore_test::test_restored_pending_gets_fresh_csprng_token`)
- FAI-PRIV-001-R1 / FORUMAI-SEC-005: participant names sent to the AI service are replaced by labels (`[STUDENT_NAME]`, `[PARTICIPANT_N]`), including names written inside messages, and email addresses are masked; real names are restored in the AI reply before it is stored. The Privacy API declares the pseudonymised third-party authors and the grading request contents (tests: `local/payload_pseudonymizer_test`, `payload_contract_test`, `privacy/provider_test`)

## [1.1.2] - 2026-10-01

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.2**.

### Added

- Jenkins CI pipeline (`Jenkinsfile.moodleplugin`) for Moodle 4.5 (MariaDB, PostgreSQL) and Moodle 5.0 (MariaDB); the GitHub `plugin-ci.yml` workflow now runs only on manual dispatch
- Forum settings validation: automatic mode (no AI review) cannot be saved without a grader
- Upgrade step that repairs existing automatic-mode history rows, moving the grader to the manager field and restoring the student as creator

### Changed

- AI responses are edited as plain text in the pending details window and on the review page; paragraphs are rebuilt on save and typed HTML is published as visible text
- A single deadline rule (cut-off date, or due date when there is no cut-off date) now applies to expiry, approval, the review page and the automatic flow; in forums whose due date has passed, automatic replies stop
- Approval notifications are sent to every active user with the `local/forum_ai:approveresponses` capability who can reply in the forum, instead of three fixed role names
- Declared support for Moodle 4.5 to 5.2 (`supported = [405, 502]`, `requires` unchanged at Moodle 4.5); the Jenkins and GitHub CI matrices now also run on Moodle 5.2 (`MOODLE_502_STABLE`, MariaDB)

### Fixed

- Disabling the global "Enable AI" setting now disables AI in every forum
- Saving a forum while global AI is disabled no longer overwrites its stored AI enabled value
- The grader field has a real "None" option and no longer submits the first listed user by default
- Automatic mode records the student as creator and the grader as manager in the history
- Automatic publishing falls back to manual approval when the grader cannot reply in the student's group (separate groups)
- The "Review with AI" notice is cleared when the grader switches student, and late AI results for a previous student are discarded
- Double-clicking "Review with AI" sends a single request
- The pending responses list and the history page work again on Moodle 5.2: their windows are now created with `core/modal` instead of the removed `core/modal_factory`, so the "Details", "Approve" and "Reject" buttons respond again
