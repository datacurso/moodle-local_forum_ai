<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_forum_ai\local;

/**
 * Pseudonymises the people named in a payload sent to the AI service.
 *
 * One instance covers one request. The student the request is about becomes
 * [STUDENT_NAME]; every other author becomes [PARTICIPANT_1..N] in order of first
 * appearance, stable for the same user within the request. Their full names,
 * first names and last names are replaced as whole words inside free text, and
 * e-mail addresses are masked as [EMAIL]. The markers in the AI output are turned
 * back into the real display names before anything is stored or shown.
 *
 * Teachers are deliberately not given a separate [TEACHER_N] label: telling them
 * apart needs a capability lookup per author and would disclose roles, while the
 * AI only needs to distinguish speakers.
 *
 * This is pseudonymisation, not anonymisation: the site keeps the mapping and the
 * request still carries internal ids.
 *
 * Design based on local_coursedynamicrules\local\payload_anonymizer.
 *
 * @package    local_forum_ai
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class payload_pseudonymizer {
    /** @var string Marker for the student the request is about. */
    public const STUDENT_MARKER = '[STUDENT_NAME]';

    /** @var string Mask for e-mail addresses (never restored). */
    public const EMAIL_MARKER = '[EMAIL]';

    /** @var string Response key whose values hold free-text feedback to restore. */
    private const REPLY_KEY = 'reply';

    /** @var int Shortest single name part replaced on its own, to avoid wiping out short words. */
    private const MIN_PART_LENGTH = 2;

    /** @var string Pattern for e-mail addresses inside free text. */
    private const EMAIL_PATTERN = '/[\pL\pN._%+\-]+@[\pL\pN\-]+(?:\.[\pL\pN\-]+)+/u';

    /** @var array<int, string> Marker assigned to each user id. */
    private array $labels = [];

    /** @var array<string, string> Real display name restored for each marker. */
    private array $displaynames = [];

    /** @var array<string, string> Name variant (lowercase) to its marker; the first registration wins. */
    private array $variants = [];

    /** @var int Number of [PARTICIPANT_N] labels handed out. */
    private int $participantcount = 0;

    /**
     * Registers the student the request is about as [STUDENT_NAME].
     *
     * @param int $userid Student user id.
     * @return string|null The marker, or null when the user cannot be resolved.
     */
    public function set_student(int $userid): ?string {
        $user = \core_user::get_user($userid);
        $this->labels[$userid] = self::STUDENT_MARKER;
        if (!$user) {
            return null;
        }

        $this->register_names($user, self::STUDENT_MARKER);
        return self::STUDENT_MARKER;
    }

    /**
     * Returns the stable label of an author, assigning the next [PARTICIPANT_N] when new.
     *
     * @param int $userid Author user id.
     * @return string The marker sent instead of the author's name.
     */
    public function label_for(int $userid): string {
        if (isset($this->labels[$userid])) {
            return $this->labels[$userid];
        }

        $this->participantcount++;
        $marker = '[PARTICIPANT_' . $this->participantcount . ']';
        $this->labels[$userid] = $marker;

        $user = \core_user::get_user($userid);
        if ($user) {
            $this->register_names($user, $marker);
        } else {
            $this->displaynames[$marker] = get_string('unknownparticipant', 'local_forum_ai');
        }

        return $marker;
    }

    /**
     * Masks e-mail addresses and replaces every registered name with its marker.
     *
     * @param string $text Free text that leaves the site.
     * @return string The pseudonymised text.
     * @throws \moodle_exception When the replacement cannot be performed, since returning the
     *                           text would send the names.
     */
    public function pseudonymize_text(string $text): string {
        if ($text === '') {
            return $text;
        }

        $text = self::valid_utf8($text);

        // E-mail addresses go first: their local part often carries the name itself.
        $text = self::check_result(preg_replace(self::EMAIL_PATTERN, self::EMAIL_MARKER, $text));

        if ($this->variants === []) {
            return $text;
        }

        // Longest variants first, so a full name is replaced as a unit before its parts.
        $variants = array_keys($this->variants);
        usort($variants, fn(string $a, string $b): int => \core_text::strlen($b) <=> \core_text::strlen($a));

        // One capturing group per variant, in a single pass: inserted markers are never rescanned.
        // Markers already present in the text are matched first and kept as they are.
        $groups = array_map(fn(string $variant): string => '(' . preg_quote($variant, '/') . ')', $variants);
        $pattern = '/(\[(?:STUDENT_NAME|EMAIL|PARTICIPANT_\d+)\])|(?<![\pL\pN])(?:' . implode('|', $groups) .
            ')(?![\pL\pN])/iu';

        $result = preg_replace_callback(
            $pattern,
            function (array $match) use ($variants): string {
                if ($match[1] !== null) {
                    return $match[0];
                }
                foreach ($variants as $index => $variant) {
                    if (($match[$index + 2] ?? null) !== null) {
                        return $this->variants[$variant];
                    }
                }
                return $match[0];
            },
            $text,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL
        );

        return self::check_result($result);
    }

    /**
     * Turns the markers in an AI output text back into the real display names.
     *
     * @param string $text AI output.
     * @return string The text with the names restored.
     */
    public function restore_text(string $text): string {
        if ($text === '' || $this->displaynames === []) {
            return $text;
        }

        return strtr($text, $this->displaynames);
    }

    /**
     * Restores the markers inside the free-text feedback of a grading response.
     *
     * Only values stored under 'reply' (a string or a list of strings) are touched.
     * Criterion and level texts and array keys are left as they are, since the
     * browser maps the result back to the grading form by those texts.
     *
     * @param array $data Decoded grading response.
     * @return array The response with the feedback restored.
     */
    public function restore_replies(array $data): array {
        foreach ($data as $key => $value) {
            if ($key === self::REPLY_KEY && is_string($value)) {
                $data[$key] = $this->restore_text($value);
            } else if ($key === self::REPLY_KEY && is_array($value)) {
                $data[$key] = array_map(
                    fn($item) => is_string($item) ? $this->restore_text($item) : $item,
                    $value
                );
            } else if (is_array($value)) {
                $data[$key] = $this->restore_replies($value);
            }
        }

        return $data;
    }

    /**
     * Registers the display name to restore and the name variants to hide for a user.
     *
     * @param \stdClass $user User record.
     * @param string $marker Marker that replaces the user's names.
     * @return void
     */
    private function register_names(\stdClass $user, string $marker): void {
        $fullname = self::valid_utf8(trim(fullname($user)));
        $this->displaynames[$marker] = $fullname;

        $firstname = trim((string) ($user->firstname ?? ''));
        $lastname = trim((string) ($user->lastname ?? ''));
        $candidates = [
            $fullname,
            trim($firstname . ' ' . $lastname),
            trim($lastname . ' ' . $firstname),
            $firstname,
            $lastname,
            trim((string) ($user->middlename ?? '')),
            trim((string) ($user->alternatename ?? '')),
        ];

        foreach ($candidates as $candidate) {
            $candidate = self::valid_utf8($candidate);
            if (\core_text::strlen($candidate) < self::MIN_PART_LENGTH) {
                continue;
            }
            foreach (self::match_variants($candidate) as $variant) {
                $key = \core_text::strtolower($variant);
                // The first registration wins: the student keeps a name shared with a classmate.
                if (!isset($this->variants[$key])) {
                    $this->variants[$key] = $marker;
                }
            }
        }
    }

    /**
     * The forms of a name to look for in the text.
     *
     * A repaired name carries U+FFFD where the stored bytes were damaged, while the
     * text usually has the name typed correctly, so the form with the damage taken
     * out is looked for too.
     *
     * @param string $value A repaired name.
     * @return string[] The forms to search for.
     */
    private static function match_variants(string $value): array {
        $variants = [$value];

        $stripped = str_replace("\u{FFFD}", '', $value);
        if ($stripped !== $value && \core_text::strlen($stripped) >= self::MIN_PART_LENGTH) {
            $variants[] = $stripped;
        }

        return $variants;
    }

    /**
     * Returns the text as valid UTF-8, repairing it only when it is not.
     *
     * Invalid bytes become U+FFFD instead of being deleted, so two names separated
     * by a stray byte stay two recognisable words. The Unicode pattern refuses
     * invalid UTF-8, and refusing must never mean sending the text unchanged.
     *
     * @param string $text Text to check.
     * @return string The same text, or a repaired copy.
     * @throws \moodle_exception When the text cannot be made valid.
     */
    private static function valid_utf8(string $text): string {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            $repaired = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }

        if (!is_string($repaired) || !mb_check_encoding($repaired, 'UTF-8')) {
            throw new \moodle_exception(
                'error_pseudonymisation_failed',
                'local_forum_ai',
                '',
                null,
                'the text could not be converted to valid UTF-8'
            );
        }

        return $repaired;
    }

    /**
     * Fails closed when a replacement could not be performed.
     *
     * @param string|null $result Result of preg_replace or preg_replace_callback.
     * @return string The result.
     * @throws \moodle_exception When the regular expression engine failed.
     */
    private static function check_result(?string $result): string {
        if ($result === null) {
            throw new \moodle_exception(
                'error_pseudonymisation_failed',
                'local_forum_ai',
                '',
                null,
                preg_last_error_msg()
            );
        }

        return $result;
    }
}
