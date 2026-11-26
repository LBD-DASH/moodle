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

namespace core_comment;

use context_course;

/**
 * Comment deletion must act only on a comment that matches the manager it was authorized against.
 *
 * comment_ajax.php builds the manager from the caller's context, component, area and itemid,
 * then deletes whatever comments.id the caller sends. These tests fail while that id is not
 * bound to the manager, including when the row changes after the permission check and before
 * the delete statement.
 *
 * @package    core_comment
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unbound_comment_delete_test extends \advanced_testcase {
    /**
     * Roles that hold moodle/comment:delete in their own course.
     *
     * @return array<string, array{0: string}>
     */
    public static function attacker_role_provider(): array {
        return [
            'editing teacher' => ['editingteacher'],
            'course manager' => ['manager'],
        ];
    }

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        global $CFG;
        $CFG->usecomments = true;
    }

    /**
     * Build the manager comment_ajax.php builds for a comments block on a course.
     *
     * @param \stdClass $course
     * @return manager
     */
    private function block_comments_manager(\stdClass $course): manager {
        $args = new \stdClass();
        $args->context = context_course::instance($course->id);
        $args->course = $course;
        $args->area = 'page_comments';
        $args->itemid = 0;
        $args->component = 'block_comments';
        $args->client_id = 'testclient';
        return new manager($args);
    }

    /**
     * Store a comment through the comment API.
     *
     * @param \stdClass $course
     * @param \stdClass $author
     * @param string $content
     * @return \stdClass
     */
    private function add_page_comment(\stdClass $course, \stdClass $author, string $content): \stdClass {
        $this->setUser($author);
        $manager = $this->block_comments_manager($course);
        $comment = $manager->add($content);
        $this->assertNotEmpty($comment->id);
        return $comment;
    }

    /**
     * Insert a comments row that does not match a block_comments page comment.
     *
     * @param int $contextid
     * @param int $userid
     * @param string $component
     * @param string $area
     * @param int $itemid
     * @param string $content
     * @return \stdClass
     */
    private function insert_comment(
        int $contextid,
        int $userid,
        string $component,
        string $area,
        int $itemid,
        string $content,
    ): \stdClass {
        global $DB;
        $record = (object) [
            'contextid' => $contextid,
            'component' => $component,
            'commentarea' => $area,
            'itemid' => $itemid,
            'content' => $content,
            'format' => FORMAT_MOODLE,
            'userid' => $userid,
            'timecreated' => time(),
        ];
        $record->id = $DB->insert_record('comments', $record);
        return $record;
    }

    /**
     * The comment row is still the one that was stored.
     *
     * @param int $id
     * @param string $content
     * @param string $message
     */
    private function assert_comment_unchanged(int $id, string $content, string $message = ''): void {
        global $DB;
        $stored = $DB->get_record('comments', ['id' => $id]);
        $this->assertNotFalse($stored, $message);
        $this->assertSame($content, $stored->content, $message);
    }

    /**
     * @param int $commentid
     * @param string $field
     * @param int|string $newvalue
     */
    private function swap_comment_field_on_delete(int $commentid, string $field, int|string $newvalue): void {
        global $DB;
        uopz_set_hook(
            get_class($DB),
            'delete_records_select',
            function ($table, $select, $params = null) use ($commentid, $field, $newvalue) {
                if ($table === 'comments') {
                    $this->set_field('comments', $field, $newvalue, ['id' => $commentid]);
                }
            },
        );
    }

    private function clear_delete_hook(): void {
        global $DB;
        if (function_exists('uopz_unset_hook')) {
            uopz_unset_hook(get_class($DB), 'delete_records_select');
        }
    }

    /**
     * The AJAX delete request described in the finding.
     *
     * @param int $contextid Attacker's own context.
     * @param int $commentid Any comment id.
     */
    private function ajax_delete(int $contextid, int $commentid): string {
        require_once(__DIR__ . '/fixtures/unbound_comment_ajax_runner.php');
        return unbound_comment_ajax_run([
            'contextid' => $contextid,
            'action' => 'delete',
            'sesskey' => sesskey(),
            'client_id' => 'attackclient',
            'area' => 'page_comments',
            'commentid' => $commentid,
            'itemid' => 0,
            'component' => 'block_comments',
        ]);
    }

    /**
     * Editing teacher and course manager can delete arbitrary comments from the AJAX endpoint
     * by naming their own course and any comment id.
     *
     * @param string $roleshortname
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('attacker_role_provider')]
    public function test_ajax_delete_keeps_comment_outside_request_context(string $roleshortname): void {
        global $DB;

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $attacker = $this->getDataGenerator()->create_and_enrol($coursea, $roleshortname);
        $localauthor = $this->getDataGenerator()->create_and_enrol($coursea, 'student');
        $victimauthor = $this->getDataGenerator()->create_and_enrol($courseb, 'student');

        $local = $this->add_page_comment($coursea, $localauthor, 'Comment in the attacker course');
        $victim = $this->add_page_comment($courseb, $victimauthor, 'Comment in another course');
        $assignmentcomment = $this->insert_comment(
            context_course::instance($courseb->id)->id,
            $victimauthor->id,
            'assignsubmission_comments',
            'submission_comments',
            42,
            'Assignment submission comment',
        );

        $this->setUser($attacker);
        $contextid = context_course::instance($coursea->id)->id;

        // Same request shape must still delete a comment that actually belongs to this manager,
        // and must not wipe every comment while doing so.
        $this->ajax_delete($contextid, $local->id);
        $this->assertFalse($DB->record_exists('comments', ['id' => $local->id]));
        $this->assertTrue($DB->record_exists('comments', ['id' => $victim->id]));
        $this->assertTrue($DB->record_exists('comments', ['id' => $assignmentcomment->id]));

        try {
            $this->ajax_delete($contextid, $victim->id);
        } catch (\Throwable $e) {
            // A refusal may throw. The comment must already be unchanged at that point.
            unset($e);
        }
        $this->assert_comment_unchanged(
            (int) $victim->id,
            'Comment in another course',
            'A comment from another course must survive AJAX delete',
        );

        try {
            $this->ajax_delete($contextid, $assignmentcomment->id);
        } catch (\Throwable $e) {
            unset($e);
        }
        $this->assert_comment_unchanged(
            (int) $assignmentcomment->id,
            'Assignment submission comment',
            'An assignment comment must survive a comments-block AJAX delete',
        );
    }

    /**
     * can_delete() and delete() must refuse a comment whose stored scope is not the manager's.
     *
     * @param string $roleshortname
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('attacker_role_provider')]
    public function test_manager_delete_binds_comment_to_manager_scope(string $roleshortname): void {
        global $DB;

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $attacker = $this->getDataGenerator()->create_and_enrol($coursea, $roleshortname);
        $author = $this->getDataGenerator()->create_and_enrol($courseb, 'student');
        $contexta = context_course::instance($coursea->id)->id;
        $contextb = context_course::instance($courseb->id)->id;

        $foreigncontext = $this->add_page_comment($courseb, $author, 'Foreign context');
        $foreigncomponent = $this->insert_comment(
            $contexta,
            $author->id,
            'assignsubmission_comments',
            'page_comments',
            0,
            'Foreign component',
        );
        $foreignarea = $this->insert_comment(
            $contexta,
            $author->id,
            'block_comments',
            'glossary_entry',
            0,
            'Foreign area',
        );
        $foreignitem = $this->insert_comment(
            $contexta,
            $author->id,
            'block_comments',
            'page_comments',
            9,
            'Foreign item',
        );

        $this->setUser($attacker);
        $manager = $this->block_comments_manager($coursea);

        $samples = [$foreigncontext, $foreigncomponent, $foreignarea, $foreignitem];
        foreach ($samples as $comment) {
            $loaded = $DB->get_record('comments', ['id' => $comment->id], '*', MUST_EXIST);
            $this->assertFalse(
                $manager->can_delete($loaded),
                'can_delete must reject comment ' . $loaded->content . ' when given the stored row',
            );
            $this->assertFalse(
                $manager->can_delete((int) $loaded->id),
                'can_delete must reject comment ' . $loaded->content . ' when given only the id',
            );

            try {
                $manager->delete((int) $loaded->id);
            } catch (\Throwable $e) {
                // Refusal may be an exception. Deleting the row first is still a failure.
                unset($e);
            }
            $this->assert_comment_unchanged(
                (int) $loaded->id,
                $loaded->content,
                $loaded->content . ' was deleted by a manager that was not authorized for it',
            );
        }
    }

    /**
     * core_comment_delete_comments loads context from the stored comment, so a teacher
     * of another course cannot delete it.
     */
    public function test_external_delete_uses_stored_comment_context(): void {
        global $DB;

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($coursea, 'editingteacher');
        $author = $this->getDataGenerator()->create_and_enrol($courseb, 'student');
        $victim = $this->add_page_comment($courseb, $author, 'Stored in course B');

        $this->setUser($teacher);
        $removed = false;
        try {
            \core_comment_external::delete_comments([(int) $victim->id]);
            $removed = true;
        } catch (\Throwable $e) {
            unset($e);
        }
        $this->assertFalse($removed);
        $this->assert_comment_unchanged((int) $victim->id, 'Stored in course B');
    }

    /**
     * A student who can post in course A must not delete their own comment stored in course B.
     */
    public function test_student_cannot_delete_own_comment_outside_manager_context(): void {
        global $DB;

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $coursea->id, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $courseb->id, 'student');

        $ownedelsewhere = $this->add_page_comment($courseb, $student, 'Own comment in course B');

        $plugin = enrol_get_plugin('manual');
        $instances = enrol_get_instances($courseb->id, true);
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $plugin->unenrol_user($instance, $student->id);
            }
        }

        $this->setUser($student);
        $manager = $this->block_comments_manager($coursea);
        $loaded = $DB->get_record('comments', ['id' => $ownedelsewhere->id], '*', MUST_EXIST);

        // Posting is allowed in course A. That must not authorize this student's comment in course B.
        $this->assertTrue($manager->can_post());
        $this->assertFalse($manager->can_delete($loaded));
        $this->assertFalse($manager->can_delete((int) $loaded->id));
        try {
            $manager->delete((int) $loaded->id);
        } catch (\Throwable $e) {
            unset($e);
        }
        $this->assert_comment_unchanged((int) $loaded->id, 'Own comment in course B');
    }

    /**
     * Changing the row after the in-memory check and before the delete statement must not
     * delete a comment that no longer matches the authorized scope.
     */
    public function test_delete_statement_matches_authorized_comment_fields(): void {
        global $DB;

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($coursea, 'editingteacher');
        $author = $this->getDataGenerator()->create_and_enrol($coursea, 'student');
        $contextb = context_course::instance($courseb->id)->id;

        $bycontext = $this->add_page_comment($coursea, $author, 'Swap context before delete');
        $bycomponent = $this->add_page_comment($coursea, $author, 'Swap component before delete');
        $byarea = $this->add_page_comment($coursea, $author, 'Swap area before delete');
        $byitem = $this->add_page_comment($coursea, $author, 'Swap item before delete');

        $this->setUser($teacher);
        $manager = $this->block_comments_manager($coursea);

        $cases = [
            [$bycontext, 'contextid', $contextb],
            [$bycomponent, 'component', 'assignsubmission_comments'],
            [$byarea, 'commentarea', 'glossary_entry'],
            [$byitem, 'itemid', 9],
        ];
        foreach ($cases as [$comment, $field, $newvalue]) {
            $this->swap_comment_field_on_delete((int) $comment->id, $field, $newvalue);
            try {
                $manager->delete((int) $comment->id);
            } catch (\Throwable $e) {
                unset($e);
            } finally {
                $this->clear_delete_hook();
            }
            $stored = $DB->get_record('comments', ['id' => $comment->id]);
            $this->assertNotFalse($stored, 'Comment was deleted after ' . $field . ' no longer matched');
            $this->assertSame($comment->content, $stored->content);
        }
    }

    /**
     * A comment that matches the manager is still deleted.
     */
    public function test_matching_comment_is_deleted(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $author = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $comment = $this->add_page_comment($course, $author, 'Local page comment');

        $this->setUser($teacher);
        $manager = $this->block_comments_manager($course);
        $loaded = $DB->get_record('comments', ['id' => $comment->id], '*', MUST_EXIST);
        $this->assertTrue($manager->can_delete($loaded));
        $this->assertTrue($manager->can_delete((int) $loaded->id));
        $this->assertTrue($manager->delete((int) $loaded->id));
        $this->assertFalse($DB->record_exists('comments', ['id' => $comment->id]));
    }
}
