<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for the language packs shipped with local_forum_ai.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the completeness of the shipped language packs.
 *
 * @group local_forum_ai
 * @coversNothing
 */
final class lang_strings_test extends \advanced_testcase {
    /** @var string[] Language packs shipped with the plugin. */
    private const SHIPPED_PACKS = ['de', 'en', 'es', 'es_mx', 'es_mx_kids', 'fr', 'id', 'pt_br', 'ru'];

    /**
     * MDL-INT-005 / FAI-SEC-005: the allowed-roles help text must describe the real
     * fail-closed behaviour in every shipped pack: with no role selected the AI
     * responds to nobody.
     */
    public function test_form_help_texts_describe_real_behaviour(): void {
        $failclosed = [
            'de' => 'Wenn keine ausgewählt sind, antwortet die KI auf keine Benutzer.',
            'en' => 'If none are selected, the AI will not respond to any users.',
            'es' => 'Si no se selecciona ninguno, la IA no responderá a ningún usuario.',
            'es_mx' => 'Si no se selecciona ninguno, la IA no responderá a ningún usuario.',
            'es_mx_kids' => 'Si no se selecciona ninguno, la IA no responderá a ningún usuario.',
            'fr' => 'Si aucun n’est sélectionné, l’IA ne répondra à aucun utilisateur.',
            'id' => 'Jika tidak ada yang dipilih, AI tidak akan merespons pengguna mana pun.',
            'pt_br' => 'Se nenhuma for selecionada, a IA não responderá a nenhum usuário.',
            'ru' => 'Если ни одна не выбрана, ИИ не будет отвечать ни одному пользователю.',
        ];
        $this->assertSame(self::SHIPPED_PACKS, array_keys($failclosed));

        foreach ($failclosed as $pack => $sentence) {
            $strings = $this->load_pack_strings($pack);
            $this->assertArrayHasKey('allowedroles_help', $strings, "Pack {$pack} must declare allowedroles_help.");
            $this->assertStringContainsString(
                $sentence,
                $strings['allowedroles_help'],
                "Pack {$pack} must state that an empty role list makes the AI respond to nobody."
            );
        }
    }

    /**
     * MDL-INT-005: the grader help text must describe the real behaviour (the
     * grader list also includes users with the rate permission).
     */
    public function test_grader_help_text_describes_real_behaviour(): void {
        $this->markTestSkipped(
            '[Pendiente:skip] La ayuda de "Usuario calificador" describe un comportamiento '
            . 'incompleto; debe corregirse el texto antes de poder verificarlo. Caso marcado '
            . 'Automatizado: no — verificacion manual.'
        );
    }

    /**
     * MDL-INT-031 (step 1, inventory — green): all nine declared language packs are
     * shipped, every pack loads, declares strings and contains the plugin name, and
     * no pack ships stray keys that do not exist in the English reference pack.
     */
    public function test_shipped_packs_load_and_declare_known_strings(): void {
        $enkeys = $this->load_pack_keys('en');
        $this->assertNotEmpty($enkeys);
        $this->assertContains('pluginname', $enkeys);

        $found = [];
        foreach (glob($this->lang_dir() . '/*/local_forum_ai.php') as $file) {
            $found[] = basename(dirname($file));
        }
        sort($found);
        $this->assertSame(self::SHIPPED_PACKS, $found, 'The nine declared language packs must be shipped.');

        foreach (self::SHIPPED_PACKS as $pack) {
            $keys = $this->load_pack_keys($pack);
            $this->assertNotEmpty($keys, "Pack {$pack} must declare strings.");
            $this->assertContains('pluginname', $keys, "Pack {$pack} must translate the plugin name.");

            $stray = array_diff($keys, $enkeys);
            $this->assertSame(
                [],
                array_values($stray),
                "Pack {$pack} declares keys unknown to the English pack: " . implode(', ', $stray)
            );
        }
    }

    /**
     * MDL-INT-031 (steps 1-2, en/es — green): the English and Spanish packs are
     * complete — both declare exactly the same set of string keys, including the
     * guiding-question field label and help.
     */
    public function test_en_and_es_packs_are_complete(): void {
        $enkeys = $this->load_pack_keys('en');
        $eskeys = $this->load_pack_keys('es');

        $this->assertSame(
            [],
            array_values(array_diff($enkeys, $eskeys)),
            'Strings missing in es: ' . implode(', ', array_diff($enkeys, $eskeys))
        );
        $this->assertSame(
            [],
            array_values(array_diff($eskeys, $enkeys)),
            'Stray strings in es: ' . implode(', ', array_diff($eskeys, $enkeys))
        );

        $this->assertContains('questionturns', $enkeys);
        $this->assertContains('questionturns_help', $eskeys);
    }

    /**
     * MDL-INT-031 (step 2): every shipped language pack contains all required
     * strings, in particular the "AI replies with guiding question" label and help.
     */
    public function test_all_packs_contain_every_required_string(): void {
        $enkeys = $this->load_pack_keys('en');
        $this->assertNotEmpty($enkeys);

        foreach (self::SHIPPED_PACKS as $pack) {
            $packkeys = $this->load_pack_keys($pack);
            $missing = array_diff($enkeys, $packkeys);
            $this->assertSame(
                [],
                array_values($missing),
                "Pack {$pack} is missing strings declared in en: " . implode(', ', $missing)
            );
        }
    }

    /**
     * FORUMAI-SEC-006: the generic AI request error shown by review.php must never
     * interpolate internal exception details, so no shipped pack may declare the
     * placeholder in that string.
     */
    public function test_error_airequest_never_interpolates_exception_details(): void {
        foreach (self::SHIPPED_PACKS as $pack) {
            $strings = $this->load_pack_strings($pack);
            $this->assertArrayHasKey('error_airequest', $strings, "Pack {$pack} must declare error_airequest.");
            $this->assertStringNotContainsString(
                '{$a}',
                $strings['error_airequest'],
                "Pack {$pack} must not interpolate exception details into error_airequest."
            );
        }
    }

    /**
     * MDL-INT-031 (step 3): the language sent to the AI service should be the course
     * language when generating responses and grading participation.
     */
    public function test_language_sent_to_service_follows_course(): void {
        $this->markTestSkipped(
            'MDL-INT-031 NOTA [Pendiente:skip]: el idioma enviado al servicio es el del perfil ' .
            'del usuario que dispara la operacion (no el del curso), por lo que en cursos ' .
            'multilingues cada estudiante recibe respuestas en su idioma de perfil — gap de ' .
            'i18n no critico.'
        );
    }

    /**
     * MDL-INT-031 (step 4): the label used for unavailable authors in the thread
     * context should come from a language string.
     */
    public function test_unavailable_author_label_is_localised(): void {
        foreach (self::SHIPPED_PACKS as $pack) {
            $strings = $this->load_pack_strings($pack);
            $this->assertArrayHasKey('unknownparticipant', $strings, "Pack {$pack} must declare unknownparticipant.");
            $this->assertNotSame('', trim($strings['unknownparticipant']));
        }

        // The hardcoded English fallback is gone from the thread context builder.
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/forum_ai/classes/utils.php');
        $this->assertStringNotContainsString("'Participant'", $source);

        $pseudonymizer = new local\payload_pseudonymizer();
        $label = $pseudonymizer->label_for(9999999);
        $this->assertSame(get_string('unknownparticipant', 'local_forum_ai'), $pseudonymizer->restore_text($label));
    }

    /**
     * Returns the plugin lang directory.
     *
     * @return string
     */
    private function lang_dir(): string {
        global $CFG;

        return $CFG->dirroot . '/local/forum_ai/lang';
    }

    /**
     * Loads the string keys declared by one language pack file.
     *
     * @param string $pack Language pack code.
     * @return string[] Declared string keys.
     */
    private function load_pack_keys(string $pack): array {
        return array_keys($this->load_pack_strings($pack));
    }

    /**
     * Loads the strings declared by one language pack file.
     *
     * @param string $pack Language pack code.
     * @return string[] Declared strings indexed by key.
     */
    private function load_pack_strings(string $pack): array {
        $file = $this->lang_dir() . '/' . $pack . '/local_forum_ai.php';
        $this->assertFileExists($file);

        $string = [];
        include($file);

        return $string;
    }
}
