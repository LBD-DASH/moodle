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

namespace core_backup;

defined('MOODLE_INTERNAL') || die();

/**
 * Backup file manager must not open a file area other than the one the caller is allowed to manage.
 *
 * backupfilesedit.php takes the capability context and the file context from different request
 * parameters. These tests load and save through that page. A request that is authorized in one
 * context must not read or write a different context, component, or file area.
 *
 * @package    core_backup
 * @copyright  2026 onwards Moodle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backupfilesedit_test extends \advanced_testcase {

    /** @var \Throwable|null Exception thrown by the page, including the redirect used after a save. */
    private ?\Throwable $pagethrowable = null;

    /**
     * Cross-boundary areas an editing teacher must not read or write.
     *
     * @return array<string, array{0: string}>
     */
    public static function cross_boundary_area_provider(): array {
        return [
            'other user private files' => ['other-user-private'],
            'administrator private files' => ['admin-private'],
            'other user backup area' => ['other-user-backup'],
            'hidden course resource content' => ['other-resource-content'],
            'hidden course folder content' => ['other-folder-content'],
            'hidden activity intro attachment' => ['other-activity-intro'],
            'other course summary files' => ['other-course-summary'],
            'other course overview files' => ['other-course-overview'],
            'other course backup' => ['other-course-backup'],
            'other activity backup' => ['other-activity-backup'],
            'same course summary files' => ['same-course-summary'],
            'same course resource content' => ['same-course-resource'],
        ];
    }

    /**
     * Opening the page copies the named area into the caller draft area.
     *
     * @dataProvider cross_boundary_area_provider
     * @param string $area Area key from the provider.
     */
    public function test_read_does_not_copy_unauthorized_area_into_draft(string $area): void {
        $fixture = $this->build_fixture();
        $target = $this->target_for($fixture, $area);
        $secret = 'secret-' . $area;
        $this->store_area_file($target, 'kept.txt', $secret);
        $canary = 'canary-course-backup';
        $this->store_area_file($fixture->owncoursebackup, 'canary.mbz', $canary);

        $before = $this->file_sequence();
        $hashcount = $this->count_content($secret);
        $this->setUser($fixture->teacher);
        $this->open_page($fixture->teacher, $fixture->coursecontext->id, $target);

        $this->assertFalse(
            $this->draft_serves_content($fixture->teacher->id, 'kept.txt', $secret),
            'Unauthorized file was copied into the caller draft area, where draftfile.php would serve it. ' .
                $this->page_failure_detail(),
        );
        $this->assertFalse(
            $this->draft_serves_content($fixture->teacher->id, 'canary.mbz', $canary),
            'The page served the authorized course backup instead of the requested area. ' .
                $this->page_failure_detail(),
        );
        $this->assertSame($secret, $this->area_file_content($target, 'kept.txt'));
        $this->assertSame($canary, $this->area_file_content($fixture->owncoursebackup, 'canary.mbz'));
        $this->assertSame($hashcount, $this->count_content($secret), 'The secret was copied and then removed.');
        $this->assertSame($before, $this->file_sequence(), 'A file row was created before the page refused.');
    }

    /**
     * Saving the file manager must not replace, add, or delete files in an unauthorized area.
     *
     * @dataProvider cross_boundary_area_provider
     * @param string $area Area key from the provider.
     */
    public function test_save_does_not_modify_unauthorized_area(string $area): void {
        $fixture = $this->build_fixture();
        $target = $this->target_for($fixture, $area);
        $secret = 'secret-' . $area;
        $this->store_area_file($target, 'kept.txt', $secret);
        $extra = 'second-' . $area;
        $this->store_area_file($target, 'extra.txt', $extra);
        $canary = 'canary-course-backup';
        $this->store_area_file($fixture->owncoursebackup, 'canary.mbz', $canary);

        $this->setUser($fixture->teacher);
        $replacement = 'replaced-' . $area;
        $added = 'added-' . $area;
        $draftid = $this->create_draft($fixture->teacher->id, [
            'kept.txt' => $replacement,
            'added.txt' => $added,
        ]);
        $before = $this->file_sequence();
        $this->save_page($fixture->teacher, $fixture->coursecontext->id, $target, $draftid);

        $this->assertSame($secret, $this->area_file_content($target, 'kept.txt'), $this->page_failure_detail());
        $this->assertSame($extra, $this->area_file_content($target, 'extra.txt'), $this->page_failure_detail());
        $this->assertNull($this->area_file_content($target, 'added.txt'), $this->page_failure_detail());
        $this->assertSame($canary, $this->area_file_content($fixture->owncoursebackup, 'canary.mbz'));
        $this->assertSame(0, $this->count_content_outside_draft($fixture->teacher->id, $replacement));
        $this->assertSame(0, $this->count_content_outside_draft($fixture->teacher->id, $added));
        $this->assertSame($before, $this->file_sequence(), 'A file row was created before the page refused.');
    }

    /**
     * Submitting an empty file manager must not delete an unauthorized area.
     *
     * @dataProvider cross_boundary_area_provider
     * @param string $area Area key from the provider.
     */
    public function test_empty_save_does_not_wipe_unauthorized_area(string $area): void {
        $fixture = $this->build_fixture();
        $target = $this->target_for($fixture, $area);
        $secret = 'secret-' . $area;
        $this->store_area_file($target, 'kept.txt', $secret);
        $canary = 'canary-course-backup';
        $this->store_area_file($fixture->owncoursebackup, 'canary.mbz', $canary);

        $this->setUser($fixture->teacher);
        $draftid = $this->create_draft($fixture->teacher->id, []);
        $before = $this->file_sequence();
        $this->save_page($fixture->teacher, $fixture->coursecontext->id, $target, $draftid);

        $this->assertSame($secret, $this->area_file_content($target, 'kept.txt'), $this->page_failure_detail());
        $this->assertSame($canary, $this->area_file_content($fixture->owncoursebackup, 'canary.mbz'));
        $this->assertSame($before, $this->file_sequence(), 'A file row was created before the page refused.');
    }

    /**
     * A course manager can satisfy the automated-backup check in their own course.
     * That check must not open another course's automated backups.
     */
    public function test_manager_cannot_read_or_write_other_course_automated_backup(): void {
        $fixture = $this->build_fixture();
        $target = $fixture->otherautomated;
        $secret = 'automated-secret';
        $this->store_area_file($target, 'auto.mbz', $secret);
        $own = 'own-automated';
        $this->store_area_file($fixture->ownautomated, 'own-auto.mbz', $own);

        $this->setUser($fixture->manager);
        $before = $this->file_sequence();
        $this->open_page($fixture->manager, $fixture->coursecontext->id, $target);
        $this->assertFalse($this->draft_serves_content($fixture->manager->id, 'auto.mbz', $secret), $this->page_failure_detail());
        $this->assertSame($secret, $this->area_file_content($target, 'auto.mbz'));
        $this->assertSame($before, $this->file_sequence(), 'A file row was created before the page refused.');

        $replacement = 'automated-replaced';
        $draftid = $this->create_draft($fixture->manager->id, ['auto.mbz' => $replacement]);
        $before = $this->file_sequence();
        $this->save_page($fixture->manager, $fixture->coursecontext->id, $target, $draftid);
        $this->assertSame($secret, $this->area_file_content($target, 'auto.mbz'), $this->page_failure_detail());
        $this->assertSame($own, $this->area_file_content($fixture->ownautomated, 'own-auto.mbz'));
        $this->assertSame(0, $this->count_content_outside_draft($fixture->manager->id, $replacement));
        $this->assertSame($before, $this->file_sequence(), 'A file row was created before the page refused.');

        $draftid = $this->create_draft($fixture->manager->id, []);
        $before = $this->file_sequence();
        $this->save_page($fixture->manager, $fixture->coursecontext->id, $target, $draftid);
        $this->assertSame($secret, $this->area_file_content($target, 'auto.mbz'), $this->page_failure_detail());
        $this->assertSame($before, $this->file_sequence(), 'A file row was created before the page refused.');
    }

    /**
     * An editing teacher does not have moodle/restore:userinfo, so automated backups stay closed.
     */
    public function test_editing_teacher_cannot_read_automated_backup_via_own_course(): void {
        $fixture = $this->build_fixture();
        $secret = 'teacher-automated';
        $this->store_area_file($fixture->ownautomated, 'auto.mbz', $secret);
        $other = 'other-automated';
        $this->store_area_file($fixture->otherautomated, 'other-auto.mbz', $other);

        $this->setUser($fixture->teacher);
        foreach ([$fixture->ownautomated, $fixture->otherautomated] as $target) {
            $before = $this->file_sequence();
            $this->open_page($fixture->teacher, $fixture->coursecontext->id, $target);
            $this->assertFalse($this->draft_serves_content($fixture->teacher->id, 'auto.mbz', $secret));
            $this->assertFalse($this->draft_serves_content($fixture->teacher->id, 'other-auto.mbz', $other));
            $this->assertSame($before, $this->file_sequence(), 'A file row was created before the page refused.');
        }
        $this->assertSame($secret, $this->area_file_content($fixture->ownautomated, 'auto.mbz'));
        $this->assertSame($other, $this->area_file_content($fixture->otherautomated, 'other-auto.mbz'));
    }

    /**
     * The allowed course backup area, tied to the course context that was authorized, still works.
     */
    public function test_editing_teacher_can_read_and_save_own_course_backup(): void {
        $fixture = $this->build_fixture();
        $target = $fixture->owncoursebackup;
        $original = 'own-course-backup';
        $this->store_area_file($target, 'course.mbz', $original);

        $this->setUser($fixture->teacher);
        $this->open_page($fixture->teacher, $fixture->coursecontext->id, $target);
        $this->assertTrue(
            $this->draft_serves_content($fixture->teacher->id, 'course.mbz', $original),
            'Own course backup was not copied into the caller draft. ' . $this->page_failure_detail(),
        );

        $replacement = 'own-course-backup-replaced';
        $draftid = $this->create_draft($fixture->teacher->id, [
            'course.mbz' => $replacement,
            'added.mbz' => 'own-course-backup-added',
        ]);
        $this->save_page($fixture->teacher, $fixture->coursecontext->id, $target, $draftid);
        $this->assertSame($replacement, $this->area_file_content($target, 'course.mbz'), $this->page_failure_detail());
        $this->assertSame('own-course-backup-added', $this->area_file_content($target, 'added.mbz'), $this->page_failure_detail());

        $draftid = $this->create_draft($fixture->teacher->id, []);
        $this->save_page($fixture->teacher, $fixture->coursecontext->id, $target, $draftid);
        $this->assertNull($this->area_file_content($target, 'course.mbz'), $this->page_failure_detail());
        $this->assertNull($this->area_file_content($target, 'added.mbz'), $this->page_failure_detail());
    }

    /**
     * The allowed user backup area is the current user's own user context.
     */
    public function test_editing_teacher_can_read_and_save_own_user_backup(): void {
        $fixture = $this->build_fixture();
        $target = $fixture->ownuserbackup;
        $original = 'own-user-backup';
        $this->store_area_file($target, 'user.mbz', $original);

        $this->setUser($fixture->teacher);
        $this->open_page($fixture->teacher, $fixture->coursecontext->id, $target);
        $this->assertTrue(
            $this->draft_serves_content($fixture->teacher->id, 'user.mbz', $original),
            'Own user backup was not copied into the caller draft. ' . $this->page_failure_detail(),
        );

        $replacement = 'own-user-backup-replaced';
        $draftid = $this->create_draft($fixture->teacher->id, ['user.mbz' => $replacement]);
        $this->save_page($fixture->teacher, $fixture->coursecontext->id, $target, $draftid);
        $this->assertSame($replacement, $this->area_file_content($target, 'user.mbz'), $this->page_failure_detail());
    }

    /**
     * The allowed activity backup area is the current module context.
     */
    public function test_editing_teacher_can_read_and_save_own_activity_backup(): void {
        $fixture = $this->build_fixture();
        $target = $fixture->ownactivitybackup;
        $original = 'own-activity-backup';
        $this->store_area_file($target, 'activity.mbz', $original);

        $this->setUser($fixture->teacher);
        $this->open_page($fixture->teacher, $target->contextid, $target);
        $this->assertTrue(
            $this->draft_serves_content($fixture->teacher->id, 'activity.mbz', $original),
            'Own activity backup was not copied into the caller draft. ' . $this->page_failure_detail(),
        );

        $replacement = 'own-activity-backup-replaced';
        $draftid = $this->create_draft($fixture->teacher->id, ['activity.mbz' => $replacement]);
        $this->save_page($fixture->teacher, $target->contextid, $target, $draftid);
        $this->assertSame($replacement, $this->area_file_content($target, 'activity.mbz'), $this->page_failure_detail());
    }

    /**
     * A course manager may manage automated backups of the course context that was authorized.
     */
    public function test_manager_can_read_and_save_own_automated_backup(): void {
        $fixture = $this->build_fixture();
        $target = $fixture->ownautomated;
        $original = 'own-automated-backup';
        $this->store_area_file($target, 'auto.mbz', $original);

        $this->setUser($fixture->manager);
        $this->open_page($fixture->manager, $fixture->coursecontext->id, $target);
        $this->assertTrue(
            $this->draft_serves_content($fixture->manager->id, 'auto.mbz', $original),
            'Own automated backup was not copied into the caller draft. ' . $this->page_failure_detail(),
        );

        $replacement = 'own-automated-replaced';
        $draftid = $this->create_draft($fixture->manager->id, ['auto.mbz' => $replacement]);
        $this->save_page($fixture->manager, $fixture->coursecontext->id, $target, $draftid);
        $this->assertSame($replacement, $this->area_file_content($target, 'auto.mbz'), $this->page_failure_detail());
    }

    /**
     * moodle/restore:uploadfile is required in the context the page is authorized for.
     */
    public function test_student_cannot_read_own_course_backup(): void {
        $fixture = $this->build_fixture();
        $original = 'student-should-not-see';
        $this->store_area_file($fixture->owncoursebackup, 'course.mbz', $original);
        $this->setUser($fixture->student);
        $before = $this->file_sequence();
        $this->open_page($fixture->student, $fixture->coursecontext->id, $fixture->owncoursebackup);
        $this->assertFalse($this->draft_serves_content($fixture->student->id, 'course.mbz', $original));
        $this->assertSame($original, $this->area_file_content($fixture->owncoursebackup, 'course.mbz'));
        $this->assertSame($before, $this->file_sequence(), 'A file row was created before the page refused.');
    }

    /**
     * Build users, courses, and the file areas the page can be pointed at.
     *
     * @return \stdClass
     */
    private function build_fixture(): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $fixture = new \stdClass();
        $fixture->course = $generator->create_course();
        $fixture->coursecontext = \context_course::instance($fixture->course->id);
        $fixture->teacher = $generator->create_user();
        $fixture->manager = $generator->create_user();
        $fixture->student = $generator->create_user();
        $generator->enrol_user($fixture->teacher->id, $fixture->course->id, 'editingteacher');
        $generator->enrol_user($fixture->manager->id, $fixture->course->id, 'manager');
        $generator->enrol_user($fixture->student->id, $fixture->course->id, 'student');

        $fixture->victim = $generator->create_user();
        $fixture->admin = get_admin();

        $fixture->othercourse = $generator->create_course(['visible' => 0]);
        $fixture->othercoursecontext = \context_course::instance($fixture->othercourse->id);
        $otherresource = $generator->create_module('resource', [
            'course' => $fixture->othercourse->id,
            'visible' => 0,
        ]);
        $otherfolder = $generator->create_module('folder', [
            'course' => $fixture->othercourse->id,
            'visible' => 0,
        ]);
        $ownresource = $generator->create_module('resource', ['course' => $fixture->course->id]);

        $fixture->owncoursebackup = $this->area($fixture->coursecontext->id, 'backup', 'course');
        $fixture->ownuserbackup = $this->area(\context_user::instance($fixture->teacher->id)->id, 'user', 'backup');
        $fixture->ownactivitybackup = $this->area(\context_module::instance($ownresource->cmid)->id, 'backup', 'activity');
        $fixture->ownautomated = $this->area($fixture->coursecontext->id, 'backup', 'automated');
        $fixture->areas = [
            'other-user-private' => $this->area(\context_user::instance($fixture->victim->id)->id, 'user', 'private'),
            'admin-private' => $this->area(\context_user::instance($fixture->admin->id)->id, 'user', 'private'),
            'other-user-backup' => $this->area(\context_user::instance($fixture->victim->id)->id, 'user', 'backup'),
            'other-resource-content' => $this->area(\context_module::instance($otherresource->cmid)->id, 'mod_resource', 'content'),
            'other-folder-content' => $this->area(\context_module::instance($otherfolder->cmid)->id, 'mod_folder', 'content'),
            'other-activity-intro' => $this->area(\context_module::instance($otherresource->cmid)->id, 'mod_resource', 'intro'),
            'other-course-summary' => $this->area($fixture->othercoursecontext->id, 'course', 'summary'),
            'other-course-overview' => $this->area($fixture->othercoursecontext->id, 'course', 'overviewfiles'),
            'other-course-backup' => $this->area($fixture->othercoursecontext->id, 'backup', 'course'),
            'other-activity-backup' => $this->area(\context_module::instance($otherresource->cmid)->id, 'backup', 'activity'),
            'same-course-summary' => $this->area($fixture->coursecontext->id, 'course', 'summary'),
            'same-course-resource' => $this->area(\context_module::instance($ownresource->cmid)->id, 'mod_resource', 'content'),
        ];
        $fixture->otherautomated = $this->area($fixture->othercoursecontext->id, 'backup', 'automated');
        return $fixture;
    }

    /**
     * @param \stdClass $fixture
     * @param string $area
     * @return \stdClass
     */
    private function target_for(\stdClass $fixture, string $area): \stdClass {
        return $fixture->areas[$area];
    }

    /**
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @return \stdClass
     */
    private function area(int $contextid, string $component, string $filearea): \stdClass {
        return (object) [
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
        ];
    }

    /**
     * @param \stdClass $area
     * @param string $filename
     * @param string $content
     */
    private function store_area_file(\stdClass $area, string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $area->contextid,
            'component' => $area->component,
            'filearea' => $area->filearea,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * @param \stdClass $area
     * @param string $filename
     * @return string|null
     */
    private function area_file_content(\stdClass $area, string $filename): ?string {
        $file = get_file_storage()->get_file(
            $area->contextid,
            $area->component,
            $area->filearea,
            0,
            '/',
            $filename,
        );
        if (!$file) {
            return null;
        }
        return $file->get_content();
    }

    /**
     * Create a draft the file manager would submit. An empty list is an empty file manager.
     *
     * @param int $userid
     * @param array<string, string> $files
     * @return int
     */
    private function create_draft(int $userid, array $files): int {
        $this->setUser($userid);
        $draftitemid = file_get_unused_draft_itemid();
        $contextid = \context_user::instance($userid)->id;
        $fs = get_file_storage();
        foreach ($files as $filename => $content) {
            $fs->create_file_from_string([
                'contextid' => $contextid,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
            ], $content);
        }
        return $draftitemid;
    }

    /**
     * @param \stdClass $user
     * @param int $currentcontext
     * @param \stdClass $area
     */
    private function open_page(\stdClass $user, int $currentcontext, \stdClass $area): void {
        $this->setUser($user);
        $this->invoke_page([
            'currentcontext' => $currentcontext,
            'contextid' => $area->contextid,
            'component' => $area->component,
            'filearea' => $area->filearea,
            'returnurl' => '/course/view.php',
        ], false, null);
    }

    /**
     * @param \stdClass $user
     * @param int $currentcontext
     * @param \stdClass $area
     * @param int $draftitemid
     */
    private function save_page(\stdClass $user, int $currentcontext, \stdClass $area, int $draftitemid): void {
        $this->setUser($user);
        $this->invoke_page([
            'currentcontext' => $currentcontext,
            'contextid' => $area->contextid,
            'component' => $area->component,
            'filearea' => $area->filearea,
            'returnurl' => '/course/view.php',
        ], true, $draftitemid);
    }

    /**
     * Run public/backup/backupfilesedit.php as the current user.
     *
     * The include stays in this method so the script sees the same globals it has when it is the request entry point.
     *
     * @param array<string, int|string> $params
     * @param bool $post
     * @param int|null $draftitemid
     */
    private function invoke_page(array $params, bool $post, ?int $draftitemid): void {
        global $CFG, $PAGE, $OUTPUT, $SITE, $COURSE, $USER;

        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_REQUEST = [];
        if ($post) {
            $_POST = $params;
            $_POST['sesskey'] = sesskey();
            $_POST['_qf__backup_files_edit_form'] = 1;
            $_POST['submitbutton'] = 'Save changes';
            $_POST['files_filemanager'] = $draftitemid;
            $_REQUEST = $_POST;
            $_SERVER['REQUEST_METHOD'] = 'POST';
        } else {
            $_GET = $params;
            $_REQUEST = $_GET;
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        $PAGE = new \moodle_page();
        $COURSE = clone($SITE);
        $OUTPUT = new \core_renderer($PAGE, null);

        $cwd = getcwd();
        $level = ob_get_level();
        $this->pagethrowable = null;
        // The page prints a full HTML document. Swallow it, including a flush of this buffer.
        ob_start(static function (): string {
            return '';
        });
        chdir($CFG->dirroot . '/backup');
        try {
            include($CFG->dirroot . '/backup/backupfilesedit.php');
        } catch (\Throwable $error) {
            $this->pagethrowable = $error;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            if ($cwd !== false) {
                chdir($cwd);
            }
            $_GET = [];
            $_POST = [];
            $_FILES = [];
            $_REQUEST = [];
        }
    }

    /**
     * True when the bytes are stored in this user's draft at the path draftfile.php serves.
     *
     * @param int $userid
     * @param string $filename
     * @param string $content
     * @return bool
     */
    private function draft_serves_content(int $userid, string $filename, string $content): bool {
        $fs = get_file_storage();
        $context = \context_user::instance($userid);
        $files = $fs->get_area_files($context->id, 'user', 'draft', false, 'id', false);
        foreach ($files as $file) {
            if ($file->get_filename() !== $filename || $file->get_filepath() !== '/') {
                continue;
            }
            // draftfile.php builds this path and loads it by pathname hash. See public/draftfile.php.
            $fullpath = '/' . $context->id . '/user/draft/' . $file->get_itemid() . '/' . $filename;
            $served = $fs->get_file_by_hash(sha1($fullpath));
            if ($served && $served->get_content() === $content) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $content
     * @return int
     */
    private function count_content(string $content): int {
        global $DB;
        return $DB->count_records('files', ['contenthash' => sha1($content)]);
    }

    /**
     * @param int $userid
     * @param string $content
     * @return int
     */
    private function count_content_outside_draft(int $userid, string $content): int {
        global $DB;
        $contextid = \context_user::instance($userid)->id;
        $records = $DB->get_records('files', ['contenthash' => sha1($content)]);
        $count = 0;
        foreach ($records as $record) {
            if ((int) $record->contextid === $contextid && $record->component === 'user' && $record->filearea === 'draft') {
                continue;
            }
            $count++;
        }
        return $count;
    }

    /**
     * Last value of the files id sequence. An insert that is later deleted still advances it.
     *
     * @return int
     */
    private function file_sequence(): int {
        global $DB;
        $sequence = $DB->get_prefix() . 'files_id_seq';
        return (int) $DB->get_field_sql("SELECT last_value FROM {$sequence}");
    }

    /**
     * @return string
     */
    private function page_failure_detail(): string {
        if ($this->pagethrowable === null) {
            return 'The page returned without an exception.';
        }
        $code = $this->pagethrowable instanceof \moodle_exception ? $this->pagethrowable->errorcode : '';
        return get_class($this->pagethrowable) . ' ' . $code . ' ' . $this->pagethrowable->getMessage();
    }
}
