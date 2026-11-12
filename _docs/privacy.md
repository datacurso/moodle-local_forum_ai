# Privacy: data sent to the AI service

This document describes what leaves the Moodle site when Forum AI asks the Datacurso AI
service to reply to a post or to grade a student's participation, why each field is needed,
and how personal data and identifiers are minimised before the transfer (FAI-PRIV-001-R1).
It complements the Privacy API metadata declared in `classes/privacy/provider.php`. What the
service does with the data (no storage, logs without personal data, model provider) is
documented in the service repository, `docs/privacy/forum.md`.

## Purpose of the transfer

| Request | When | Purpose |
| --- | --- | --- |
| `POST /forum/chat/v2` | A student posts in a forum with AI enabled | Draft a reply (and, when per-post rating is on, a grade) for that post |
| `POST /forum/grade` | A teacher asks for an AI review of a student's participation | Propose a whole-forum grade and feedback |

No other purpose exists: nothing is sent for training, analytics or marketing.

## Outbound boundary

Every payload goes through `\local_forum_ai\local\outbound_payload` inside
`\local_forum_ai\ai_service`, the only class that calls the HTTP client. The boundary:

1. keeps only the allowlisted fields below, at every nesting level; **any other key is dropped**,
   so the outbound contract cannot grow silently when a payload builder changes;
2. drops every Moodle id of a forum, discussion or post. The service schema still requires an
   `id` per thread entry and a `forum_id` per participation, so those carry references that only
   mean something inside the request: the entry position (1, 2, 3...) and `"1"`;
3. replaces every user id with the suite's shared site-scoped pseudonym.

### `/forum/chat/v2`

| Field | Content | Justification |
| --- | --- | --- |
| `course`, `forum` | Course full name, forum name | Topic context for the reply |
| `discussion` | Discussion name, people's names replaced | Topic context for the reply |
| `post.subject`, `post.message` | The post being answered, names replaced, e-mails masked | Object of the reply and grade |
| `post.author` | `[STUDENT_NAME]` | Address the student without their name |
| `thread_history[]` | `id` (position), `order`, `author` (`[PARTICIPANT_N]`), `message` | Previous posts of the thread |
| `own_main_posts[]` | `id` (position), `order`, `author`, `message` | The student's opening posts in other discussions, so the AI never asks for one that exists |
| `userid` | Pseudonym of the post author | Per-user consumption and rate limiting |
| `prompt`, `allow_followup_question`, `grading_enabled`, `scale`, `lang` | Forum AI settings | How to reply and grade |

### `/forum/grade`

| Field | Content | Justification |
| --- | --- | --- |
| `forum_participations[].userid` | Pseudonym of the evaluated student | Lets the service tell participations apart |
| `participation.forum_id` | Always `"1"` | Required by the service schema; request-local |
| `participation.forum`, `scale`, `rubric`, `assessment_guide` | Forum name and grading method | Grade with the forum's own method |
| `participation.discussions[]` | `discussion` (name) and `answer` (text), names replaced, e-mails masked | Object of the grade |

### Added by the provider

`aiprovider_datacurso` adds `site_id` (random site UUID), `site_url`, `timezone`, `lang`
and, when the payload has none, the pseudonym of the current user as `userid`. These are
declared in the provider's own privacy metadata (AIP-PRIV-001-R1).

## Pseudonyms

**User ids.** The plugin derives no token of its own. It resolves the
`\local_forum_ai\local\userid_pseudonymiser` port through the DI container, bound by
`\local_forum_ai\hook\di_callbacks` to `\local_forum_ai\local\provider_pseudonymiser`, which
delegates to `\aiprovider_datacurso\local\outbound_privacy`. Assign AI and the rest of the suite
use the same mechanism: an HMAC-SHA256 of the user id keyed with a secret generated on the site
that is never transmitted. The token is stable for one person on one site, cannot be reversed
without the secret and cannot be correlated across sites.

**Names in texts.** `\local_forum_ai\local\payload_pseudonymizer` replaces the evaluated student
with `[STUDENT_NAME]`, every other author with `[PARTICIPANT_N]` and every e-mail address with
`[EMAIL]`, and restores the names in the AI output before it is stored or shown.

## Verification

- `tests/outbound_request_body_test.php` runs the real task or web service and the real provider
  client, replacing only cURL, and checks the exact JSON that would go on the wire: it carries the
  discussions and their content, every id-like field is a position, `"1"` or a 32-character
  pseudonym different from every real user id, and no name or e-mail address is present.
- `tests/local/outbound_payload_test.php` covers the allowlists.
- `tests/local/provider_pseudonymiser_test.php` covers the production binding of the pseudonym.
