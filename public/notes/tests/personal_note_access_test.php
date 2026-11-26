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

namespace core_notes;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_notes_external;

/**
 * Personal notes are visible only to their author. These tests pin that rule on every
 * entry point that loads a note by id: the edit page, the delete page, and the get,
 * update, and delete notes web services.
 *
 * @package    core_notes
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class personal_note_access_test extends \advanced_testcase {

    /** @var string Text that must not be disclosed to the other teacher. */
    private string $secret = 'personal-note-secret-token';

    /**
     * Load the note libraries once.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/notes/lib.php');
        require_once($CFG->dirroot . '/notes/externallib.php');
        require_once($CFG->dirroot . '/notes/edit_form.php');
    }

    /**
     * Teacher and non-editing teacher both receive the notes capabilities by default.
     */
    public function test_teacher_roles_receive_notes_capabilities(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        foreach (['teacher', 'editingteacher'] as $shortname) {
            $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);
            $this->setUser($user);
            $this->assertTrue(has_capability('moodle/notes:view', $context));
            $this->assertTrue(has_capability('moodle/notes:manage', $context));
        }
    }

    /**
     * The notes list and get_course_notes already hide another teacher's personal note.
     */
    public function test_course_notes_list_hides_another_teachers_personal_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_personal_note($fx);

        $this->setUser($fx->attacker);
        $result = core_notes_external::get_course_notes($fx->course->id, $fx->student->id);
        $result = external_api::clean_returnvalue(core_notes_external::get_course_notes_returns(), $result);
        $encoded = json_encode($result);
        $this->assertStringNotContainsString($this->secret, $encoded);
        $this->assertSame([], $result['personalnotes']);

        [$output, $exception] = $this->execute_notes_script('index.php', [
            'course' => $fx->course->id,
            'user' => $fx->student->id,
        ], []);
        $this->assertNull($exception);
        $this->assertStringNotContainsString($this->secret, $output);
        $this->assert_note_unchanged($note);
    }

    /**
     * Opening the edit page must not reveal another teacher's personal note.
     */
    public function test_edit_page_refuses_other_teachers_personal_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_personal_note($fx);

        $this->setUser($fx->attacker);
        [$output, $exception] = $this->execute_notes_script('edit.php', ['id' => $note->id], []);

        $this->assert_script_refused($output, $exception);
        $this->assert_note_unchanged($note);
    }

    /**
     * Saving the edit form must not overwrite or republish another teacher's personal note.
     */
    public function test_edit_page_save_refuses_other_teachers_personal_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_personal_note($fx);
        $this->attach_note_file($note);

        $this->setUser($fx->attacker);
        \note_edit_form::mock_submit([
            'id' => $note->id,
            'courseid' => $fx->course->id,
            'userid' => $fx->student->id,
            'publishstate' => NOTES_STATE_PUBLIC,
            'content_editor' => [
                'text' => 'attacker overwrite of personal note',
                'format' => (string) FORMAT_HTML,
                'itemid' => 0,
            ],
        ]);
        [$output, $exception] = $this->execute_notes_script('edit.php', [], $_POST);

        $this->assert_note_unchanged($note);
        $this->assert_script_refused($output, $exception);
    }

    /**
     * The delete confirmation screen prints the note body. It must not print a personal note
     * the current user did not write.
     */
    public function test_delete_page_refuses_to_show_other_teachers_personal_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_personal_note($fx);
        $this->attach_note_file($note);

        $this->setUser($fx->attacker);
        [$output, $exception] = $this->execute_notes_script('delete.php', ['id' => $note->id], []);

        $this->assert_script_refused($output, $exception);
        $this->assert_note_unchanged($note);
    }

    /**
     * Confirming deletion with the attacker's own session key must not delete the note.
     */
    public function test_delete_page_confirm_refuses_other_teachers_personal_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_personal_note($fx);
        $this->attach_note_file($note);

        $this->setUser($fx->attacker);
        $_POST = [
            'id' => $note->id,
            'sesskey' => sesskey(),
        ];
        [$output, $exception] = $this->execute_notes_script('delete.php', [], $_POST);

        $this->assert_note_unchanged($note);
        $this->assert_script_refused($output, $exception);
    }

    /**
     * get_notes must not return another teacher's personal note.
     */
    public function test_get_notes_refuses_other_teachers_personal_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_personal_note($fx);

        $this->setUser($fx->attacker);
        $this->assert_external_refused(function () use ($note) {
            return core_notes_external::get_notes([$note->id]);
        });
        $this->assert_note_unchanged($note);
    }

    /**
     * A batch that starts with a note the attacker may read must still refuse a later personal note.
     */
    public function test_get_notes_refuses_foreign_personal_note_after_an_authorised_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $victim = $this->create_personal_note($fx);
        $this->setUser($fx->attacker);
        $owned = $this->create_personal_note($fx, $fx->attacker->id, 'attacker own personal note');

        $this->assert_external_refused(function () use ($owned, $victim) {
            return core_notes_external::get_notes([$owned->id, $victim->id]);
        });
        $this->assert_note_unchanged($victim);
    }

    /**
     * update_notes must not overwrite or republish another teacher's personal note.
     */
    public function test_update_notes_refuses_other_teachers_personal_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_personal_note($fx);

        $this->setUser($fx->attacker);
        $returned = $this->call_external(function () use ($note) {
            return core_notes_external::update_notes([[
                'id' => $note->id,
                'publishstate' => 'site',
                'text' => 'attacker republished personal note',
                'format' => FORMAT_HTML,
            ]]);
        });
        $this->assert_note_unchanged($note);
        $this->assert_external_result_refused($returned);
    }

    /**
     * Authorisation of a course note in the same call must not cover a personal note later in the batch.
     */
    public function test_update_notes_refuses_foreign_personal_note_after_an_authorised_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $victim = $this->create_personal_note($fx);
        $course = $this->create_state_note($fx, NOTES_STATE_PUBLIC, 'shared course note');

        $this->setUser($fx->attacker);
        $returned = $this->call_external(function () use ($course, $victim) {
            return core_notes_external::update_notes([
                [
                    'id' => $course->id,
                    'publishstate' => 'course',
                    'text' => 'attacker updated the shared course note',
                    'format' => FORMAT_HTML,
                ],
                [
                    'id' => $victim->id,
                    'publishstate' => 'course',
                    'text' => 'attacker republished personal note',
                    'format' => FORMAT_HTML,
                ],
            ]);
        });
        $this->assert_note_unchanged($victim);
        $this->assert_external_result_refused($returned);
    }

    /**
     * delete_notes must not delete another teacher's personal note.
     */
    public function test_delete_notes_refuses_other_teachers_personal_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_personal_note($fx);
        $this->attach_note_file($note);

        $this->setUser($fx->attacker);
        $returned = $this->call_external(function () use ($note) {
            return core_notes_external::delete_notes([$note->id]);
        });
        $this->assert_note_unchanged($note);
        $this->assert_external_result_refused($returned);
    }

    /**
     * Deleting an authorised course note in the same call must not delete a personal note.
     */
    public function test_delete_notes_refuses_foreign_personal_note_after_an_authorised_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $victim = $this->create_personal_note($fx);
        $this->attach_note_file($victim);
        $course = $this->create_state_note($fx, NOTES_STATE_PUBLIC, 'shared course note to delete');

        $this->setUser($fx->attacker);
        $returned = $this->call_external(function () use ($course, $victim) {
            return core_notes_external::delete_notes([$course->id, $victim->id]);
        });
        $this->assert_note_unchanged($victim);
        $this->assert_external_result_refused($returned);
    }

    /**
     * A course teacher who did not write a site note cannot edit or delete it.
     * Site notes require the system notes:manage capability, matching the note footer links.
     */
    public function test_course_teacher_cannot_edit_or_delete_site_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_state_note($fx, NOTES_STATE_SITE, $this->secret);
        $this->attach_note_file($note);

        $this->setUser($fx->attacker);
        $this->assertFalse(has_capability('moodle/notes:manage', \context_system::instance()));

        [$output, $exception] = $this->execute_notes_script('edit.php', ['id' => $note->id], []);
        $this->assert_script_refused($output, $exception);
        $this->assert_note_unchanged($note);

        $this->assert_external_refused(function () use ($note) {
            return core_notes_external::update_notes([[
                'id' => $note->id,
                'publishstate' => 'course',
                'text' => 'attacker rewrote site note',
                'format' => FORMAT_HTML,
            ]]);
        });
        $this->assert_note_unchanged($note);

        $this->assert_external_refused(function () use ($note) {
            return core_notes_external::delete_notes([$note->id]);
        });
        $this->assert_note_unchanged($note);

        [$output, $exception] = $this->execute_notes_script('delete.php', ['id' => $note->id], []);
        $this->assert_script_refused($output, $exception);
        $this->assert_note_unchanged($note);
    }

    /**
     * The author can still read, update, and delete their own personal note.
     */
    public function test_author_can_access_own_personal_note(): void {
        global $DB;

        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_personal_note($fx);

        $this->setUser($fx->author);
        $result = core_notes_external::get_notes([$note->id]);
        $result = external_api::clean_returnvalue(core_notes_external::get_notes_returns(), $result);
        $this->assertSame($this->secret, $result['notes'][0]['text']);

        $updated = core_notes_external::update_notes([[
            'id' => $note->id,
            'publishstate' => 'personal',
            'text' => 'author revised personal note',
            'format' => FORMAT_HTML,
        ]]);
        $updated = external_api::clean_returnvalue(core_notes_external::update_notes_returns(), $updated);
        $this->assertSame([], $updated);
        $stored = $DB->get_record('post', ['id' => $note->id], '*', MUST_EXIST);
        $this->assertSame('author revised personal note', $stored->content);
        $this->assertSame(NOTES_STATE_DRAFT, $stored->publishstate);

        $deleted = core_notes_external::delete_notes([$note->id]);
        $deleted = external_api::clean_returnvalue(core_notes_external::delete_notes_returns(), $deleted);
        $this->assertSame([], $deleted);
        $this->assertFalse($DB->get_record('post', ['id' => $note->id]));
    }

    /**
     * A second teacher can still read, update, and delete a course note.
     */
    public function test_other_teacher_can_access_course_note(): void {
        global $DB;

        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_state_note($fx, NOTES_STATE_PUBLIC, 'shared course note body');

        $this->setUser($fx->attacker);
        $result = core_notes_external::get_notes([$note->id]);
        $result = external_api::clean_returnvalue(core_notes_external::get_notes_returns(), $result);
        $this->assertSame('shared course note body', $result['notes'][0]['text']);

        $updated = core_notes_external::update_notes([[
            'id' => $note->id,
            'publishstate' => 'course',
            'text' => 'second teacher revised course note',
            'format' => FORMAT_HTML,
        ]]);
        $updated = external_api::clean_returnvalue(core_notes_external::update_notes_returns(), $updated);
        $this->assertSame([], $updated);
        $stored = $DB->get_record('post', ['id' => $note->id], '*', MUST_EXIST);
        $this->assertSame('second teacher revised course note', $stored->content);

        [$output, $exception] = $this->execute_notes_script('delete.php', ['id' => $note->id], []);
        $this->assertStringContainsString('second teacher revised course note', $output);
        if ($exception !== null) {
            $this->assertNotSame('nopermissiontodelete', $exception->errorcode);
        }

        $_POST = [
            'id' => $note->id,
            'sesskey' => sesskey(),
        ];
        $this->execute_notes_script('delete.php', [], $_POST);
        $this->assertFalse($DB->get_record('post', ['id' => $note->id]));
    }

    /**
     * A student in the course, who lacks the notes capabilities, cannot read the note.
     */
    public function test_student_cannot_read_personal_note(): void {
        $this->resetAfterTest();
        $fx = $this->create_course_with_teachers();
        $note = $this->create_personal_note($fx);

        $this->setUser($fx->student);
        $this->assert_external_refused(function () use ($note) {
            return core_notes_external::get_notes([$note->id]);
        });
        $this->assert_note_unchanged($note);
    }

    /**
     * Create a course, a student, an editing teacher (the author) and a non-editing teacher.
     *
     * @return \stdClass
     */
    private function create_course_with_teachers(): \stdClass {
        global $DB, $CFG;

        $CFG->enablenotes = 1;
        set_config('enablenotes', 1);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $author = $this->getDataGenerator()->create_user();
        $attacker = $this->getDataGenerator()->create_user();

        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $authorrole = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $attackerrole = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);

        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole);
        $this->getDataGenerator()->enrol_user($author->id, $course->id, $authorrole);
        $this->getDataGenerator()->enrol_user($attacker->id, $course->id, $attackerrole);

        return (object) [
            'course' => $course,
            'student' => $student,
            'author' => $author,
            'attacker' => $attacker,
        ];
    }

    /**
     * Store a personal note as the author.
     *
     * @param \stdClass $fx
     * @param int|null $authorid
     * @param string|null $content
     * @return \stdClass
     */
    private function create_personal_note(\stdClass $fx, ?int $authorid = null, ?string $content = null): \stdClass {
        $authorid = $authorid ?? $fx->author->id;
        $this->setUser($authorid);
        return $this->create_state_note($fx, NOTES_STATE_DRAFT, $content ?? $this->secret, $authorid);
    }

    /**
     * Store a note in the given publish state as the author.
     *
     * @param \stdClass $fx
     * @param string $state
     * @param string $content
     * @param int|null $authorid
     * @return \stdClass
     */
    private function create_state_note(
        \stdClass $fx,
        string $state,
        string $content,
        ?int $authorid = null,
    ): \stdClass {
        global $DB;

        $authorid = $authorid ?? $fx->author->id;
        $this->setUser($authorid);
        $note = (object) [
            'courseid' => $fx->course->id,
            'userid' => $fx->student->id,
            'content' => $content,
            'format' => FORMAT_PLAIN,
            'publishstate' => $state,
        ];
        note_save($note);
        $stored = $DB->get_record('post', ['id' => $note->id], '*', MUST_EXIST);
        $this->assertSame($state, $stored->publishstate);
        $this->assertEquals($authorid, $stored->usermodified);
        return $stored;
    }

    /**
     * Attach a file to the note so a delete that removes files before refusing is visible.
     *
     * @param \stdClass $note
     */
    private function attach_note_file(\stdClass $note): void {
        $context = \context_course::instance($note->courseid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'notes',
            'filearea' => 'content',
            'itemid' => $note->id,
            'filepath' => '/',
            'filename' => 'remark.txt',
        ], $this->secret);
    }

    /**
     * The note row and its file, when present, are still the author's.
     *
     * @param \stdClass $before
     */
    private function assert_note_unchanged(\stdClass $before): void {
        global $DB;

        $after = $DB->get_record('post', ['id' => $before->id]);
        $this->assertNotFalse($after, 'The note was deleted');
        $this->assertSame($before->content, $after->content);
        $this->assertSame($before->publishstate, $after->publishstate);
        $this->assertEquals($before->courseid, $after->courseid);
        $this->assertEquals($before->usermodified, $after->usermodified);

        $context = \context_course::instance($before->courseid);
        $file = get_file_storage()->get_file($context->id, 'notes', 'content', $before->id, '/', 'remark.txt');
        if ($file) {
            $this->assertSame($this->secret, $file->get_content());
        }
    }

    /**
     * Run a notes page as the current user and capture its output and exception.
     *
     * Redirects throw under PHPUnit because CLI_SCRIPT is set, which keeps the
     * database change that happened before the redirect observable.
     *
     * @param string $script edit.php, delete.php, or index.php
     * @param array $get
     * @param array $post
     * @return array{0: string, 1: \Throwable|null}
     */
    private function execute_notes_script(string $script, array $get, array $post): array {
        global $CFG, $DB, $PAGE, $OUTPUT, $USER, $COURSE, $SITE, $SESSION;

        // Each page run needs a page that has not already started output.
        $PAGE = new \moodle_page();
        $OUTPUT = new \bootstrap_renderer();

        $_GET = $get;
        $_POST = $post;
        $_REQUEST = $post + $get;
        $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
        $_SERVER['REQUEST_URI'] = '/notes/' . $script;
        $_SERVER['SCRIPT_NAME'] = '/notes/' . $script;

        $previous = getcwd();
        chdir($CFG->dirroot . '/notes');
        $exception = null;
        ob_start();
        try {
            require($CFG->dirroot . '/notes/' . $script);
        } catch (\Throwable $caught) {
            $exception = $caught;
        }
        $output = ob_get_clean();
        chdir($previous);

        // The included page reads these globals from this function scope.
        $pagescope = [$CFG, $DB, $PAGE, $OUTPUT, $USER, $COURSE, $SITE, $SESSION];
        unset($pagescope);

        return [$output, $exception];
    }

    /**
     * The page must refuse before it reveals the note or redirects after changing it.
     *
     * @param string $output
     * @param \Throwable|null $exception
     */
    private function assert_script_refused(string $output, ?\Throwable $exception): void {
        $this->assertStringNotContainsString($this->secret, $output);
        $this->assertInstanceOf(\moodle_exception::class, $exception);
        $this->assertNotSame('redirecterrordetected', $exception->errorcode);
        $this->assertStringNotContainsString($this->secret, $exception->getMessage());
        $this->assertStringNotContainsString($this->secret, (string) ($exception->debuginfo ?? ''));
    }

    /**
     * Call a web service and keep any output or exception, without deciding the result yet.
     *
     * Database checks can then run before the assertion that access was refused, so a write
     * that lands and is then reported as a failure is still visible.
     *
     * @param callable $call
     * @return array{output: string, returned: string, exception: \Throwable|null}
     */
    private function call_external(callable $call): array {
        $returned = '';
        $exception = null;
        ob_start();
        try {
            $returned = (string) json_encode($call());
        } catch (\Throwable $caught) {
            $exception = $caught;
        }
        $output = ob_get_clean();
        return [
            'output' => $output,
            'returned' => $returned,
            'exception' => $exception,
        ];
    }

    /**
     * The web service must throw before it returns the forbidden note.
     *
     * @param callable $call
     */
    private function assert_external_refused(callable $call): void {
        $this->assert_external_result_refused($this->call_external($call));
    }

    /**
     * @param array{output: string, returned: string, exception: \Throwable|null} $result
     */
    private function assert_external_result_refused(array $result): void {
        $exception = $result['exception'];
        $this->assertStringNotContainsString($this->secret, $result['output'] . $result['returned']);
        $this->assertInstanceOf(\moodle_exception::class, $exception);
        $this->assertStringNotContainsString($this->secret, $exception->getMessage());
        $this->assertStringNotContainsString($this->secret, (string) ($exception->debuginfo ?? ''));
    }
}
