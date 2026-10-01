## [1.1.3] - 2026-10-01

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.2**.

### Changed

- Declared support for Moodle 4.5 to 5.2 (`supported = [405, 502]`, `requires` unchanged at Moodle 4.5); the Jenkins and GitHub CI matrices now also run on Moodle 5.2 (`MOODLE_502_STABLE`, MariaDB)

### Fixed

- The pending responses list and the history page work again on Moodle 5.2: their windows are now created with `core/modal` instead of the removed `core/modal_factory`, so the "Details", "Approve" and "Reject" buttons respond again

## [1.1.2] - 2026-10-01

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.0**.

### Added

- Jenkins CI pipeline (`Jenkinsfile.moodleplugin`) for Moodle 4.5 (MariaDB, PostgreSQL) and Moodle 5.0 (MariaDB); the GitHub `plugin-ci.yml` workflow now runs only on manual dispatch
- Forum settings validation: automatic mode (no AI review) cannot be saved without a grader
- Upgrade step that repairs existing automatic-mode history rows, moving the grader to the manager field and restoring the student as creator

### Changed

- AI responses are edited as plain text in the pending details window and on the review page; paragraphs are rebuilt on save and typed HTML is published as visible text
- A single deadline rule (cut-off date, or due date when there is no cut-off date) now applies to expiry, approval, the review page and the automatic flow; in forums whose due date has passed, automatic replies stop
- Approval notifications are sent to every active user with the `local/forum_ai:approveresponses` capability who can reply in the forum, instead of three fixed role names

### Fixed

- Disabling the global "Enable AI" setting now disables AI in every forum
- Saving a forum while global AI is disabled no longer overwrites its stored AI enabled value
- The grader field has a real "None" option and no longer submits the first listed user by default
- Automatic mode records the student as creator and the grader as manager in the history
- Automatic publishing falls back to manual approval when the grader cannot reply in the student's group (separate groups)
- The "Review with AI" notice is cleared when the grader switches student, and late AI results for a previous student are discarded
- Double-clicking "Review with AI" sends a single request
