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

namespace core_grades\external;

use core_external\external_api;

/**
 * Authorization tests for core_grades_get_feedback.
 *
 * The service must return grade feedback, name, picture and the first identity
 * field only for a user the caller is allowed to see in the gradebook. A caller
 * who holds gradereport/grader:view but not moodle/site:accessallgroups must not
 * receive that data for a user outside their separate groups, or for a user who
 * is not enrolled in the course.
 *
 * @package    core_grades
 * @category   test
 * @copyright  2026 Moodle Pty Ltd (http://moodle.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_feedback_authorization_test extends \core_external\tests\externallib_testcase {

    /**
     * Call core_grades_get_feedback the way lib/ajax/service.php does.
     *
     * @param int $courseid
     * @param int $userid Target user id supplied by the caller.
     * @param int $itemid
     * @return array
     */
    private function call_get_feedback(int $courseid, int $userid, int $itemid): array {
        // A logged-in AJAX request carries the session key. service.php then
        // dispatches through external_api::call_external_function(..., true).
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('core_grades_get_feedback', [
            'courseid' => $courseid,
            'userid' => $userid,
            'itemid' => $itemid,
        ], true);
    }

    /**
     * The response must be a refusal and must not contain the target user's secrets.
     *
     * @param array $response
     * @param string[] $secrets Values that belong only to the unauthorized user.
     */
    private function assert_feedback_refused(array $response, array $secrets): void {
        $this->assertTrue(
            $response['error'],
            'core_grades_get_feedback returned data for a user the caller may not see'
        );
        $encoded = json_encode($response);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    /**
     * The response must be exactly the requested user's feedback and identity.
     *
     * @param array $response
     * @param \stdClass $user
     * @param string $feedback
     * @param string $itemname
     * @param string[] $forbidden Secrets belonging to a different user.
     */
    private function assert_feedback_for_user(
        array $response,
        \stdClass $user,
        string $feedback,
        string $itemname,
        array $forbidden = []
    ): void {
        $this->assertFalse($response['error']);
        $data = $response['data'];
        $this->assertSame($feedback, $data['feedbacktext']);
        $this->assertSame($itemname, $data['title']);
        $this->assertSame(fullname($user), $data['fullname']);
        $this->assertSame($user->email, $data['additionalfield']);
        $this->assertNotSame('', $data['picture']);
        $encoded = json_encode($data);
        foreach ($forbidden as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    /**
     * Store feedback for a user on a grade item without enrolling them.
     *
     * @param int $itemid
     * @param int $userid
     * @param string $feedback
     */
    private function store_feedback(int $itemid, int $userid, string $feedback): void {
        $grade = new \grade_grade();
        $grade->itemid = $itemid;
        $grade->userid = $userid;
        $grade->feedback = $feedback;
        $grade->timemodified = time();
        $grade->insert();
    }

    /**
     * A non-editing teacher limited to one separate group can read that group's
     * feedback and cannot read another group's feedback or an unenrolled user.
     */
    public function test_separate_groups_teacher_cannot_read_hidden_users(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('showuseridentity', 'email');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course([
            'groupmode' => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);
        $context = \context_course::instance($course->id);
        $itemname = 'Manual item separate groups';
        $gradeitem = $generator->create_grade_item([
            'itemname' => $itemname,
            'courseid' => $course->id,
        ]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'teacher');

        $groupa = $generator->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $generator->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        $generator->create_group_member(['userid' => $teacher->id, 'groupid' => $groupa->id]);

        $studenta = $generator->create_user([
            'firstname' => 'Visible',
            'lastname' => 'Groupastudent',
            'email' => 'visible.groupa@example.com',
        ]);
        $studentb = $generator->create_user([
            'firstname' => 'Hidden',
            'lastname' => 'Groupbstudent',
            'email' => 'hidden.groupb@example.com',
        ]);
        $studenta2 = $generator->create_user([
            'firstname' => 'Second',
            'lastname' => 'Groupastudent',
            'email' => 'second.groupa@example.com',
        ]);
        $outsider = $generator->create_user([
            'firstname' => 'Unenrolled',
            'lastname' => 'Siteperson',
            'email' => 'unenrolled.siteperson@example.com',
        ]);
        $outsiderwithoutgrade = $generator->create_user([
            'firstname' => 'Listedonly',
            'lastname' => 'Identityperson',
            'email' => 'listedonly.identity@example.com',
        ]);

        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $generator->enrol_user($studenta->id, $course->id, $studentrole);
        $generator->enrol_user($studentb->id, $course->id, $studentrole);
        $generator->enrol_user($studenta2->id, $course->id, $studentrole);
        $generator->create_group_member(['userid' => $studenta->id, 'groupid' => $groupa->id]);
        $generator->create_group_member(['userid' => $studenta2->id, 'groupid' => $groupa->id]);
        $generator->create_group_member(['userid' => $studentb->id, 'groupid' => $groupb->id]);

        $feedbacka = 'group-a-one-feedback';
        $feedbacka2 = 'group-a-two-feedback';
        $feedbackb = 'group-b-hidden-feedback';
        $feedbackoutsider = 'unenrolled-user-feedback';
        $this->store_feedback($gradeitem->id, $studenta->id, $feedbacka);
        $this->store_feedback($gradeitem->id, $studenta2->id, $feedbacka2);
        $this->store_feedback($gradeitem->id, $studentb->id, $feedbackb);
        $this->store_feedback($gradeitem->id, $outsider->id, $feedbackoutsider);

        $this->setUser($teacher);
        $this->assertTrue(has_capability('gradereport/grader:view', $context));
        $this->assertTrue(has_capability('moodle/grade:viewall', $context));
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $context));
        $this->assertTrue(is_enrolled($context, $studenta->id));
        $this->assertTrue(is_enrolled($context, $studentb->id));
        $this->assertFalse(is_enrolled($context, $outsider->id));
        $this->assertFalse(is_enrolled($context, $outsiderwithoutgrade->id));

        $gradesbefore = $DB->count_records('grade_grades');

        $this->assert_feedback_for_user(
            $this->call_get_feedback($course->id, $studenta->id, $gradeitem->id),
            $studenta,
            $feedbacka,
            $itemname,
            [$feedbackb, $feedbacka2, $feedbackoutsider, $studentb->email, $outsider->email, $outsiderwithoutgrade->email]
        );
        // A second visible user must return that user's row, not the user authorized above.
        $this->assert_feedback_for_user(
            $this->call_get_feedback($course->id, $studenta2->id, $gradeitem->id),
            $studenta2,
            $feedbacka2,
            $itemname,
            [$feedbacka, $feedbackb, $studentb->email]
        );

        $hiddencalls = [
            [$studentb, [$feedbackb, $studentb->email, 'Hidden', 'Groupbstudent']],
            [$outsider, [$feedbackoutsider, $outsider->email, 'Unenrolled', 'Siteperson']],
            [$outsiderwithoutgrade, [$outsiderwithoutgrade->email, 'Listedonly', 'Identityperson']],
        ];
        foreach ($hiddencalls as [$target, $secrets]) {
            $this->assert_feedback_refused(
                $this->call_get_feedback($course->id, $target->id, $gradeitem->id),
                $secrets
            );
        }

        $this->assertSame($gradesbefore, $DB->count_records('grade_grades'));
    }

    /**
     * Separate groups do not hide users from a caller who holds accessallgroups.
     */
    public function test_accessallgroups_can_read_other_group_feedback(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', 'email');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course([
            'groupmode' => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);
        $gradeitem = $generator->create_grade_item([
            'itemname' => 'Item for editing teacher',
            'courseid' => $course->id,
        ]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['userid' => $teacher->id, 'groupid' => $groupa->id]);

        $student = $generator->create_user([
            'firstname' => 'Other',
            'lastname' => 'Groupstudent',
            'email' => 'other.groupstudent@example.com',
        ]);
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->create_group_member(['userid' => $student->id, 'groupid' => $groupb->id]);
        $feedback = 'editing-teacher-may-read-this';
        $this->store_feedback($gradeitem->id, $student->id, $feedback);

        $this->setUser($teacher);
        $context = \context_course::instance($course->id);
        $this->assertTrue(has_capability('gradereport/grader:view', $context));
        $this->assertTrue(has_capability('moodle/site:accessallgroups', $context));

        $this->assert_feedback_for_user(
            $this->call_get_feedback($course->id, $student->id, $gradeitem->id),
            $student,
            $feedback,
            'Item for editing teacher'
        );
    }

    /**
     * Granting accessallgroups to a non-editing teacher reveals other groups.
     * Prohibiting it on an editing teacher hides them again.
     */
    public function test_accessallgroups_capability_not_role_shortname_decides(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('showuseridentity', 'email');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course([
            'groupmode' => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);
        $context = \context_course::instance($course->id);
        $gradeitem = $generator->create_grade_item([
            'itemname' => 'Capability item',
            'courseid' => $course->id,
        ]);
        $student = $generator->create_user([
            'firstname' => 'Capability',
            'lastname' => 'Targetstudent',
            'email' => 'capability.target@example.com',
        ]);
        $generator->enrol_user($student->id, $course->id, 'student');
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['userid' => $student->id, 'groupid' => $groupb->id]);
        $feedback = 'capability-gated-feedback';
        $this->store_feedback($gradeitem->id, $student->id, $feedback);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'teacher');
        $groupa = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['userid' => $teacher->id, 'groupid' => $groupa->id]);
        $teacherrole = $DB->get_record('role', ['shortname' => 'teacher'], '*', MUST_EXIST);
        assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $teacherrole->id, $context);
        $context->mark_dirty();

        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/site:accessallgroups', $context));
        $this->assert_feedback_for_user(
            $this->call_get_feedback($course->id, $student->id, $gradeitem->id),
            $student,
            $feedback,
            'Capability item'
        );

        $editing = $generator->create_user();
        $generator->enrol_user($editing->id, $course->id, 'editingteacher');
        $editingrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $editingrole->id, $context);
        $context->mark_dirty();
        $this->setUser($editing);
        $this->assertTrue(has_capability('gradereport/grader:view', $context));
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $context));
        $this->assert_feedback_refused(
            $this->call_get_feedback($course->id, $student->id, $gradeitem->id),
            [$feedback, $student->email, 'Capability', 'Targetstudent']
        );
    }

    /**
     * Visible groups do not hide another group's feedback from a non-editing teacher.
     */
    public function test_visible_groups_teacher_can_read_other_group(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', 'email');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course([
            'groupmode' => VISIBLEGROUPS,
            'groupmodeforce' => 1,
        ]);
        $gradeitem = $generator->create_grade_item([
            'itemname' => 'Visible groups item',
            'courseid' => $course->id,
        ]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'teacher');
        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['userid' => $teacher->id, 'groupid' => $groupa->id]);
        $student = $generator->create_user([
            'firstname' => 'Visible',
            'lastname' => 'Othergroup',
            'email' => 'visible.othergroup@example.com',
        ]);
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->create_group_member(['userid' => $student->id, 'groupid' => $groupb->id]);
        $feedback = 'visible-groups-feedback';
        $this->store_feedback($gradeitem->id, $student->id, $feedback);

        $this->setUser($teacher);
        $context = \context_course::instance($course->id);
        $this->assertTrue(has_capability('gradereport/grader:view', $context));
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $context));

        $this->assert_feedback_for_user(
            $this->call_get_feedback($course->id, $student->id, $gradeitem->id),
            $student,
            $feedback,
            'Visible groups item'
        );
    }

    /**
     * A grade item tied to an activity in separate groups is hidden across groups
     * even when the course itself does not use groups.
     */
    public function test_activity_separate_groups_hides_other_group(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', 'email');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course([
            'groupmode' => NOGROUPS,
            'groupmodeforce' => 0,
        ]);
        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'groupmode' => SEPARATEGROUPS,
            'name' => 'Separate assignment',
        ]);
        $gradeitem = \grade_item::fetch([
            'iteminstance' => $assign->id,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'courseid' => $course->id,
        ]);
        $this->assertNotFalse($gradeitem);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'teacher');
        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['userid' => $teacher->id, 'groupid' => $groupa->id]);

        $samegroup = $generator->create_user([
            'firstname' => 'Same',
            'lastname' => 'Activitygroup',
            'email' => 'same.activitygroup@example.com',
        ]);
        $othergroup = $generator->create_user([
            'firstname' => 'Other',
            'lastname' => 'Activitygroup',
            'email' => 'other.activitygroup@example.com',
        ]);
        $generator->enrol_user($samegroup->id, $course->id, 'student');
        $generator->enrol_user($othergroup->id, $course->id, 'student');
        $generator->create_group_member(['userid' => $samegroup->id, 'groupid' => $groupa->id]);
        $generator->create_group_member(['userid' => $othergroup->id, 'groupid' => $groupb->id]);

        $ownfeedback = 'same-activity-group-feedback';
        $hiddenfeedback = 'other-activity-group-feedback';
        $this->store_feedback($gradeitem->id, $samegroup->id, $ownfeedback);
        $this->store_feedback($gradeitem->id, $othergroup->id, $hiddenfeedback);

        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, MUST_EXIST);
        $this->assertEquals(SEPARATEGROUPS, groups_get_activity_groupmode($cm, $course));

        $this->setUser($teacher);
        $context = \context_course::instance($course->id);
        $this->assertTrue(has_capability('gradereport/grader:view', $context));
        $this->assertFalse(has_capability('moodle/site:accessallgroups', \context_module::instance($cm->id)));

        $this->assert_feedback_for_user(
            $this->call_get_feedback($course->id, $samegroup->id, $gradeitem->id),
            $samegroup,
            $ownfeedback,
            'Separate assignment',
            [$hiddenfeedback, $othergroup->email]
        );
        $this->assert_feedback_refused(
            $this->call_get_feedback($course->id, $othergroup->id, $gradeitem->id),
            [$hiddenfeedback, $othergroup->email, 'Other', 'Activitygroup']
        );
    }

    /**
     * With no groups, an enrolled student is visible and a user outside the course is not.
     */
    public function test_no_groups_still_hides_unenrolled_user(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('showuseridentity', 'email');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => NOGROUPS]);
        $gradeitem = $generator->create_grade_item([
            'itemname' => 'No groups item',
            'courseid' => $course->id,
        ]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'teacher');
        $student = $generator->create_user([
            'firstname' => 'Enrolled',
            'lastname' => 'Courseperson',
            'email' => 'enrolled.courseperson@example.com',
        ]);
        $outsider = $generator->create_user([
            'firstname' => 'Outside',
            'lastname' => 'Courseperson',
            'email' => 'outside.courseperson@example.com',
        ]);
        $generator->enrol_user($student->id, $course->id, 'student');
        $studentfeedback = 'enrolled-student-feedback';
        $outsiderfeedback = 'outside-course-feedback';
        $this->store_feedback($gradeitem->id, $student->id, $studentfeedback);
        $this->store_feedback($gradeitem->id, $outsider->id, $outsiderfeedback);

        $this->setUser($teacher);
        $context = \context_course::instance($course->id);
        $this->assertTrue(has_capability('gradereport/grader:view', $context));

        $gradesbefore = $DB->count_records('grade_grades');
        $this->assert_feedback_for_user(
            $this->call_get_feedback($course->id, $student->id, $gradeitem->id),
            $student,
            $studentfeedback,
            'No groups item',
            [$outsiderfeedback, $outsider->email]
        );
        $this->assert_feedback_refused(
            $this->call_get_feedback($course->id, $outsider->id, $gradeitem->id),
            [$outsiderfeedback, $outsider->email, 'Outside', 'Courseperson']
        );
        $this->assertSame($gradesbefore, $DB->count_records('grade_grades'));
    }
}
