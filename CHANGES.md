## [1.1.7-wp] - 2026-11-12

**Compatibility note:** This version is compatible only with **Moodle Workplace 4.5**.

Brings release 1.1.7 (and the 1.1.4 to 1.1.6 changes of the Moodle 4.5 branch) to Moodle Workplace and keeps the per-tenant configuration: tenant settings, per-tenant defaults, the provider switch per tenant of the user and the licence failure handling are unchanged.

### Security

- FAI-PRIV-001-R1: every request to the AI service goes through a single outbound boundary (`\local_forum_ai\local\outbound_payload`) that keeps only allowlisted fields at every nesting level and drops anything else, so the outbound contract cannot grow silently. The discussions and their content are still sent, because they are what the AI grades. Fields and justification in `_docs/privacy.md` (`outbound_payload_test::test_for_chat_keeps_content_and_drops_identifiers`, `outbound_payload_test::test_for_grade_keeps_content_and_drops_identifiers`)
- FAI-PRIV-001-R1: the reply request no longer sends `discussion_id` or `postid`, and the `id` of each `thread_history` and `own_main_posts` entry is its position in the list instead of the Moodle post id (`payload_contract_test::test_chat_payload_matches_contract`, `utils_own_main_posts_test::test_returns_own_main_posts_of_other_discussions`)
- FAI-PRIV-001-R1: the grading request no longer sends `discussion_id`, and `forum_id` is the request-local reference `"1"` instead of the Moodle forum id; discussions are told apart by name (`payload_contract_test::test_grade_payload_uses_whole_forum_scale_not_post_scale`, `group_isolation_test::test_review_payload_excludes_posts_from_other_group_discussions`)
- FAI-PRIV-001-R1: the evaluated student in the grading request (`forum_participations[].userid`) was sent with the real Moodle user id, because the provider only pseudonymises the top-level `userid`. Every user id now travels as the suite's shared site-scoped pseudonym, with the same mechanism as Assign AI: the `userid_pseudonymiser` port, bound through the DI container (`\local_forum_ai\hook\di_callbacks`) to `provider_pseudonymiser`, which delegates to `\aiprovider_datacurso\local\outbound_privacy`; the plugin derives no token of its own (`provider_pseudonymiser_test::test_container_resolves_the_provider_adapter_by_default`, `provider_pseudonymiser_test::test_adapter_returns_the_shared_suite_token`)
- FAI-PRIV-001-R1: new test that captures the exact JSON body that goes on the wire, built by the real task or web service and the real provider client with only cURL replaced, and checks that it carries the discussion content and no real identifier, name or e-mail address. Written before the fix and confirmed to fail without it: the grading request carried the student's real user id (`outbound_request_body_test::test_chat_request_body_carries_content_and_no_real_identifier`, `outbound_request_body_test::test_grade_request_body_carries_content_and_no_real_identifier`)
- FAI-PRIV-001-R1: privacy metadata of the AI service updated in every language: user ids are sent as pseudonyms and no forum, discussion or post id is sent (`privacy_provider_test::test_metadata_declares_pseudonymised_identifiers_for_the_ai_service`)
- FAI-PRIV-001-R1, service side (python_plugins-ai, branch `fix/mindfree-v3`): the forum endpoints process requests in memory and store nothing; their logs carry operational data only (no texts, names, pseudonyms, ids or grades), error messages are neither logged nor returned, the thread is no longer echoed in the response and discussion ids no longer reach the model prompt. Documented in the service's `docs/privacy/forum.md`, with the model provider (Gemini API, paid tier) (`tests/test_forum_privacy.py`)

### Fixed

- No finding code: updating Forum AI on a site whose `aiprovider_datacurso` is older than 1.7.0-wp (2026110901) no longer breaks every page. The footer hook, the navigation and form callbacks of `lib.php` and the event observers called `aiprovider_datacurso\local\plugin_settings`, a class that only exists from that provider version, so every page threw `Class "aiprovider_datacurso\local\plugin_settings" not found`, including the upgrade page that reports the missing dependency, and the administrator could not reach the upgrade. Every read of the plugin state now goes through `\local_forum_ai\local\provider_state` (same approach as Tutor-IA 2.0.16), which reports Forum AI as disabled while that class does not exist; the plugin's own pages and web services refuse with the new message `error_providerupgraderequired` until the provider is upgraded (`provider_state_test::test_missing_provider_class_reports_forum_ai_disabled`, `provider_state_test::test_missing_provider_class_refuses_with_the_upgrade_message`, `provider_state_test::test_follows_the_provider_state`, `provider_state_test::test_only_provider_state_reads_the_provider_plugin_settings`, `plugin_disabled_guard_test::test_page_requires_enabled_after_login`)

### Removed

- Repository maintenance, no finding code: declared compatibility narrowed to Moodle Workplace 4.5 (`$plugin->supported` is now `[405, 405]`, it was `[405, 502]`), the only version the Jenkins pipeline tests. No test applies
- Repository maintenance, no finding code: the `.github` folder (GitHub `plugin-ci.yml` and `moodle-release.yml` workflows and issue templates) is removed; CI runs in Jenkins (`Jenkinsfile.moodleplugin`). No test applies

### Changed

- Moodle Workplace: `\local_forum_ai\local\provider_state::is_enabled()` and `require_enabled()` take an optional tenant id (null for the tenant of the current user), so every reader of the per-tenant provider switch goes through `provider_state`; the upgrade that moves the former "Enable Forum AI" values into the provider (`provider_switch_migration`) writes through `provider_state::set_enabled()` as well (`provider_state_test::test_follows_the_provider_state_of_each_tenant`, `provider_state_test::test_missing_provider_class_is_unavailable_and_refuses_writes`)
- Moodle Workplace: the pending approvals reminder task skips the responses of a tenant with Forum AI disabled in the provider (tenant of the user the response was generated for) instead of reading the switch of the cron user
- Moodle Workplace: the built-in tenant default of "Enable AI response to the discussion topic" is now on, as on Moodle LMS
- Upgrade (2026111201): a site already on 1.1.3-wp or 1.1.4-wp gets the reminder column, the approval capability of the non-editing teacher archetype and the tenant defaults of "Enable AI response to the discussion topic" moved from off to on, which the lower 1.1.4 steps would otherwise skip (`upgrade_test::test_upgrade_from_workplace_114_adds_reminder_column_and_teacher_capability`, `upgrade_test::test_upgrade_from_workplace_114_enables_initconversation_tenant_defaults`)
- Upgrade (2026110600): on Moodle Workplace the former "Enable Forum AI" values (site and tenants) are copied into the provider before the site value is removed, so a deliberately disabled switch is kept even when the provider did not copy it first (`settings_and_defaults_test::test_upgrade_moves_disabled_feature_switch_to_provider`)
- Tests adapted to Moodle Workplace: the request body test resolves the licence region for the site and every tenant and accepts the `tenant_id` the Workplace provider adds to each request (`outbound_request_body_test`); the new forum default test sets the default of the tenant the form reads (`initconversation_visibility_test::test_new_forum_form_follows_global_default_when_turned_off`); the public reply test expects the recorded licence failure instead of a thrown exception under tenancy (`utils_private_reply_test::test_process_ai_post_processes_public_reply`)
- The minimum required version of `aiprovider_datacurso` is raised to 2026111101 (1.7.2-wp), the first Workplace provider release that ships both `plugin_settings` and `outbound_privacy`

## [1.1.4-wp] - 2026-11-09

**Compatibility note:** Moodle Workplace 4.5 branch (`-wp` release). The plugin still loads on a plain Moodle LMS, where the site settings page and site values keep working as before.

### Changed

- Forum AI is switched on and off only from the Datacurso AI provider (the Enabled switch of Forum AI in Site administration > AI > Datacurso plugins list), per tenant on Moodle Workplace, read through `\aiprovider_datacurso\local\plugin_settings::is_enabled('local_forum_ai', $tenantid)`. The provider switch always follows the tenant of the user, never the tenant of the course: the current user in the pages, web services, navigation, forum form and review button; the post or discussion author in the observers, ad hoc tasks, delayed queue and AI service calls; the forum creator in the single simple discussion forum observer and task. Turning Forum AI off for one tenant never affects another tenant, and a user of a disabled tenant is refused even in a course of an enabled tenant. The plugin settings still use the tenant of the forum course (its course category, otherwise the tenant of the user)
- AI calls are charged to the tenant of the user, matching the provider switch: the provider client (licence, region, credits) is built for the tenant of the current user in the review service and for the tenant of the post or discussion author in the ad hoc tasks, never for the tenant of the course. `ai_service::get_provider_client_arguments()` takes the user tenant (null for the current user)
- New `\local_forum_ai\utils::get_user_tenant_id()`; `utils::is_ai_available()`, `utils::require_ai_available()`, `ai_service::call_ai_service()` and `ai_service::call_ai_service_global()` take an optional user tenant for the provider switch (null for the current user)
- The single simple discussion forum task stores the user who created the forum (`userid` custom data), keeps it in its retries and gates on that user's tenant instead of the cron user (FORUMAI-WP-INFO-001)
- Removed the plugin's own "Enable Forum AI" switch (`enableforumai`): the checkbox and its hide rules on the settings page, the per-tenant value, the install default, the `enableforumai` and `enableforumai_desc` strings and `\local_forum_ai\utils::is_feature_enabled()`. `utils::is_ai_available()` and `utils::require_ai_available()` now combine the provider state of the tenant with the per-tenant "Enable AI by default" switch (`default_enabled`), which is kept
- Upgrade: the former "Enable Forum AI" values are copied into the provider (each tenant value to that tenant, a remaining site value to the provider site default) without overwriting a value the provider already holds, and are then removed from the plugin
- The minimum required version of `aiprovider_datacurso` is raised to 2026110901, the first release that keeps the state of the suite plugins per tenant and shows the shared "plugin disabled" message
- Show the Datacurso AI provider message when Forum AI is disabled in the provider for the tenant: the pending, history and review pages and the `get_details`, `approve_response`, `update_response` and `get_discussion_data` web services are refused, creating a single simple discussion forum no longer queues its AI task, and that task skips before queuing any retry, logging a translatable message (`task_skipped_plugindisabled`); cleanup on course module deletion still runs
- The `process_review` web service and the AI service calls refuse with the provider message (`plugindisabled`, or `plugindisabled_admin` for site administrators) when Forum AI is disabled in the provider; `error_aidisabled` is kept for the per-tenant "Enable AI by default" switch
- Tests skip with `aiprovider_datacurso is not installed.` when the provider is missing, as the CI pipeline does not install declared dependencies

## [1.1.6] - 2026-11-06

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.3**.

### Changed

- The "Enable Forum AI" switch moved to the Datacurso AI provider: it is now the Enabled switch of Forum AI in Site administration > AI > Datacurso plugins list, and the plugin reads it through `\aiprovider_datacurso\local\plugin_settings::is_enabled('local_forum_ai')`. Disabling it still hides the forum settings section and pauses automatic processing, and now also stops the plugin's pages and web services (below) (`provider_plugin_state_test::test_feature_follows_the_provider_state`, `provider_plugin_state_test::test_former_setting_is_ignored`)
- The former `local_forum_ai/enableforumai` setting is removed from the plugin settings page and deleted on upgrade (2026110600), with its strings `enableforumai` and `enableforumai_desc`; its value is preserved, because the provider copies it into its own setting before the plugin upgrades (`provider_plugin_state_test::test_settings_page_no_longer_defines_the_setting`, `provider_plugin_state_test::test_upgrade_removes_the_former_setting`)
- The per-forum default "Enable AI" (`default_enabled`) is unchanged and stays in the plugin settings
- The minimum required version of `aiprovider_datacurso` is raised to 1.7.0 (2026110400), the first release that keeps the state of the suite plugins and shows the shared "plugin disabled" message
- Show the Datacurso AI provider message when Forum AI is disabled: the pending, history and review pages and the `get_details`, `approve_response`, `update_response` and `get_discussion_data` web services are refused, creating a single simple discussion forum no longer queues its AI task, and that task skips before queuing any retry; cleanup on course module deletion still runs. The single simple discussion forum task and the pending approvals reminder task log a translatable message (`task_skipped_plugindisabled`) when they skip (`plugin_disabled_guard_test`, `remind_pending_approvals_test::test_disabled_feature_sends_nothing`)
- The `process_review` web service and the AI service calls now refuse with the provider message (`plugindisabled`, or `plugindisabled_admin` for site administrators) when Forum AI is disabled in the provider; `error_aidisabled` is kept for the per-forum default "Enable AI" switch (`ai_service_test::test_calls_refused_when_ai_disabled`, `process_review_test::test_execute_refused_when_forum_ai_disabled`)
- `\local_forum_ai\utils::is_feature_enabled()` is removed; code that called it uses `plugin_settings::is_enabled('local_forum_ai')`

## [1.1.5] - 2026-11-05

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.3**.

### Changed

- Declared compatibility with Moodle 5.3 (`$plugin->supported` is now `[405, 503]`). Manual and automated checks on Moodle 5.3 found no regressions caused by the new core version.

## [1.1.4] - 2026-10-19

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.2**.

### Added

- Scheduled task `remind_pending_approvals` (daily, 07:30) that reminds approvers of AI responses still pending approval, at most once every three days per response; new `timeremindered` column on `local_forum_ai_pending` (tests: `remind_pending_approvals_test`, `upgrade_test`, `privacy_provider_test`)
- Warning on the forum settings form when a graded forum has "Enable AI response to the discussion topic" turned off, and a developer-level debugging message when the opening post of a graded forum is skipped (tests: `initconversation_visibility_test`)

### Changed

- "Enable AI response to the discussion topic" is now on by default for new forums, so the opening post of a graded discussion is no longer skipped; the upgrade moves the stored site default from off to on and leaves existing forums untouched (tests: `settings_and_defaults_test`, `upgrade_test`, `initconversation_visibility_test`)
- The `teacher` archetype now holds `local/forum_ai:approveresponses`; the upgrade grants it on existing sites unless the role already has a decision at system level (tests: `notifications_test`, `upgrade_test`)

### Fixed

- A reply from a student who had already published their own main post in another discussion of the forum no longer asks them to publish one: the reply request now carries the student's own main posts (tests: `utils_own_main_posts_test`, `payload_contract_test`)
- Non-editing teachers now receive the approval request notification, and a pending response with no eligible approver is logged instead of failing silently (tests: `notifications_test`)

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
