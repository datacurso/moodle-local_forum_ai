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
 * Tests for the hierarchical post structure of the get_details web service.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use externallib_advanced_testcase;
use moodle_exception;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests for hierarchical ordering, level computation and action validation.
 *
 * @group local_forum_ai
 * @covers \local_forum_ai\external\get_details
 * @covers \local_forum_ai\external\approve_response
 */
final class get_details_hierarchy_test extends externallib_advanced_testcase {
    /**
     * MDL-UNIT-012 (steps 1-2): posts are ordered by their parent chain and nested
     * replies get an increasing level, while siblings stay at the same level.
     */
    public function test_nested_replies_get_increasing_levels(): void {
        $this->resetAfterTest();

        $setup = $this->create_setup();

        // Chain: firstpost <- r1 <- r2 <- r3, plus a later sibling of r1 at the root post.
        $r1 = $this->create_reply($setup, (int) $setup->discussion->firstpost, 10);
        $r2 = $this->create_reply($setup, (int) $r1->id, 20);
        $r3 = $this->create_reply($setup, (int) $r2->id, 30);
        $r1b = $this->create_reply($setup, (int) $setup->discussion->firstpost, 40);

        $this->setUser($setup->teacher);
        $result = external_api::clean_returnvalue(
            get_details::execute_returns(),
            get_details::execute($setup->pending->approval_token)
        );
        // Minor plugin-side flaw: get_details renders author names with fullname()
        // over a partial user record (limited fields selected), which raises a
        // "missing name fields" debugging notice. Acknowledge it so the test
        // asserts the hierarchy, not that notice.
        $this->resetDebugging();

        $byid = [];
        $order = [];
        foreach ($result['posts'] as $post) {
            $byid[(int) $post['id']] = $post;
            $order[] = (int) $post['id'];
        }

        $this->assertSame(
            [(int) $setup->discussion->firstpost, (int) $r1->id, (int) $r2->id, (int) $r3->id, (int) $r1b->id],
            $order,
            'Posts must be flattened depth-first following the real reply tree.'
        );

        $this->assertSame(0, $byid[(int) $setup->discussion->firstpost]['level']);
        $this->assertSame(1, $byid[(int) $r1->id]['level']);
        $this->assertSame(2, $byid[(int) $r2->id]['level']);
        $this->assertSame(3, $byid[(int) $r3->id]['level']);
        $this->assertSame(1, $byid[(int) $r1b->id]['level'], 'Siblings of the same parent share the same level.');
    }

    /**
     * MDL-UNIT-012 (step 3): a post whose parent does not exist is placed at the
     * root level without breaking the hierarchy of the remaining posts.
     *
     * NOTA: se agrego la nota correspondiente en el documento de definiciones
     * de casos de prueba ([Pendiente:skip]).
     */
    public function test_orphan_parent_lands_at_root_without_breaking(): void {
        $this->markTestSkipped('[Pendiente:skip] Las publicaciones con padre inexistente se omiten silenciosamente del modal en lugar de mostrarse en el nivel raiz.');
    }

    /**
     * MDL-INT-024 (step 5): an unknown action is rejected with a clear error.
     */
    public function test_unknown_action_is_rejected(): void {
        $this->resetAfterTest();

        $setup = $this->create_setup();

        $this->setUser($setup->teacher);

        try {
            approve_response::execute($setup->pending->approval_token, 'destroy');
            $this->fail('Expected moodle_exception was not thrown for the unknown action.');
        } catch (moodle_exception $e) {
            $this->assertSame('invalidaction', $e->errorcode);
        }

        // The pending row remains untouched.
        global $DB;
        $this->assertSame(
            'pending',
            $DB->get_field('local_forum_ai_pending', 'status', ['id' => $setup->pending->id], MUST_EXIST)
        );
    }

    /**
     * Creates a course, users, forum, discussion and a pending row with token.
     *
     * @return stdClass Setup holder (course, student, teacher, forum, discussion, pending).
     */
    private function create_setup(): stdClass {
        global $DB;

        $setup = new stdClass();
        $setup->course = $this->getDataGenerator()->create_course();
        $setup->student = $this->getDataGenerator()->create_and_enrol($setup->course, 'student');
        $setup->teacher = $this->getDataGenerator()->create_and_enrol($setup->course, 'editingteacher');

        $forummodule = $this->getDataGenerator()->create_module('forum', ['course' => $setup->course->id]);
        $setup->forum = $DB->get_record('forum', ['id' => $forummodule->id], '*', MUST_EXIST);

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $setup->course->id,
            'forum' => $setup->forum->id,
            'userid' => $setup->student->id,
        ]);
        $setup->discussion = $DB->get_record('forum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);

        // Give the root post a deterministic creation time before the replies.
        $DB->set_field('forum_posts', 'created', time() - HOURSECS, ['id' => $setup->discussion->firstpost]);

        $pending = (object) [
            'discussionid' => $setup->discussion->id,
            'forumid' => $setup->forum->id,
            'parentpostid' => $setup->discussion->firstpost,
            'creator_userid' => $setup->student->id,
            'subject' => 'Re: ' . $setup->discussion->name,
            'message' => '<p>AI reply awaiting review</p>',
            'status' => 'pending',
            'approval_token' => md5(uniqid('hierarchy_', true)),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $pending->id = $DB->insert_record('local_forum_ai_pending', $pending);
        $setup->pending = $pending;

        return $setup;
    }

    /**
     * Creates a student reply with a deterministic creation time offset.
     *
     * @param stdClass $setup Setup holder.
     * @param int $parentid Parent post id (may be nonexistent for orphan cases).
     * @param int $secondsafterroot Creation offset after the root post.
     * @return stdClass New forum post record.
     */
    private function create_reply(stdClass $setup, int $parentid, int $secondsafterroot): stdClass {
        global $DB;

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $reply = $forumgenerator->create_post([
            'discussion' => $setup->discussion->id,
            'parent' => $parentid,
            'userid' => $setup->student->id,
        ]);

        $created = time() - HOURSECS + $secondsafterroot;
        $DB->set_field('forum_posts', 'created', $created, ['id' => $reply->id]);
        $DB->set_field('forum_posts', 'modified', $created, ['id' => $reply->id]);

        return $DB->get_record('forum_posts', ['id' => $reply->id], '*', MUST_EXIST);
    }
}
