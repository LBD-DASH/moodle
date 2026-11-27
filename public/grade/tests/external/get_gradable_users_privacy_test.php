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
use core_external\external_settings;

/**
 * Privacy expectations for core_grades_get_gradable_users.
 *
 * A student enrolled in a course can call this AJAX/mobile service today because the only
 * capability check is moodle/course:viewparticipants. The service then returns the raw user
 * rows selected by graded_users_iterator. These tests require that call to withhold identity
 * data the caller is not allowed to see, and to withhold users outside the caller's groups
 * when the course uses separate groups and the caller lacks moodle/site:accessallgroups.
 *
 * An access error with no user payload is an acceptable way to withhold the data. A caller
 * who is allowed to list gradable users must still receive those users, limited to name,
 * picture and identity fields.
 *
 * @package    core_grades
 * @category   test
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_gradable_users::class)]
final class get_gradable_users_privacy_test extends \core_external\tests\externallib_testcase {

    /**
     * Fields that are not name, picture or configured identity fields.
     *
     * Email is handled separately: it is a default identity field, so a caller with
     * moodle/site:viewuseridentity may receive it. Everyone else must not, including
     * when the target has hidden their address and email is listed in hiddenuserfields.
     */
    private const PRIVATE_FIELDS = [
        'username',
        'phone1',
        'phone2',
        'address',
        'idnumber',
        'institution',
        'department',
        'city',
        'country',
        'description',
        'auth',
        'lastaccess',
        'firstaccess',
    ];

    /**
     * Configure identity display the way a site administrator would, then build a course.
     *
     * @param int $groupmode Course group mode.
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass}
     */
    private function create_course_with_hidden_classmate(int $groupmode = NOGROUPS): array {
        global $DB;

        // Identity fields are visible only with moodle/site:viewuseridentity, and hiddenuserfields
        // still apply to callers who lack moodle/course:viewhiddenuserfields.
        set_config('showuseridentity', 'email');
        set_config('hiddenuserfields', 'description,city,country,email,lastaccess,firstaccess');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course([
            'groupmode' => $groupmode,
            'groupmodeforce' => 1,
        ]);
        // A course module makes the grade item usable by graded_users_iterator::init().
        $generator->create_module('assign', ['course' => $course->id]);

        $secrets = [
            'username' => 'hiddenclassmate',
            'email' => 'hidden.classmate@example.com',
            'phone1' => '5550101',
            'phone2' => '5550102',
            'address' => '10 Hidden Street',
            'idnumber' => 'ID-HIDDEN-100',
            'institution' => 'Hidden Institute',
            'department' => 'Hidden Department',
            'city' => 'Hidden City',
            'country' => 'AU',
            'description' => 'Hidden profile description text',
            'auth' => 'manual',
            'maildisplay' => \core_user::MAILDISPLAY_HIDE,
            'lastaccess' => 1600000001,
            'firstaccess' => 1600000002,
        ];
        $victim = $generator->create_user($secrets);
        $attacker = $generator->create_user([
            'username' => 'ordinarystudent',
            'email' => 'ordinary.student@example.com',
        ]);
        $samegroup = $generator->create_user([
            'username' => 'samegroupstudent',
            'email' => 'same.group@example.com',
        ]);

        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $generator->enrol_user($victim->id, $course->id, $studentrole->id);
        $generator->enrol_user($attacker->id, $course->id, $studentrole->id);
        $generator->enrol_user($samegroup->id, $course->id, $studentrole->id);

        return [$course, $attacker, $victim, $samegroup];
    }

    /**
     * Call the service the same way lib/ajax/service.php does.
     *
     * @param int $courseid Course id.
     * @param int $groupid Group id supplied by the caller.
     * @return array
     */
    private function call_as_current_user(int $courseid, int $groupid): array {
        $_POST['sesskey'] = sesskey();
        $settings = external_settings::get_instance();
        $settings->set_file('pluginfile.php');
        $settings->set_fileurl(true);
        $settings->set_filter(true);
        $settings->set_raw(false);

        return external_api::call_external_function('core_grades_get_gradable_users', [
            'courseid' => $courseid,
            'groupid' => $groupid,
        ], true);
    }

    /**
     * Find one returned user by id.
     *
     * @param array $users Cleaned user list.
     * @param int $userid User id.
     * @return array|null
     */
    private function find_user(array $users, int $userid): ?array {
        foreach ($users as $user) {
            if ((int) $user['id'] === $userid) {
                return $user;
            }
        }
        return null;
    }

    /**
     * Assert that a successful payload does not carry the victim's private values.
     *
     * @param array $user Cleaned user record.
     * @param \stdClass $victim User as stored, including the planted private values.
     * @param bool $allowemail Whether email may be returned as an identity field.
     */
    private function assert_private_values_absent(array $user, \stdClass $victim, bool $allowemail): void {
        $encoded = json_encode($user);
        $fields = self::PRIVATE_FIELDS;
        if (!$allowemail) {
            $fields[] = 'email';
        }
        foreach ($fields as $field) {
            $secret = (string) $victim->{$field};
            $this->assertNotSame('', $secret);
            $this->assertStringNotContainsString($secret, $encoded, "Response exposed {$field}");
        }
    }

    /**
     * A student must not receive a classmate's hidden contact and identity data.
     */
    public function test_student_cannot_harvest_classmate_private_fields(): void {
        $this->resetAfterTest();
        [$course, $attacker, $victim] = $this->create_course_with_hidden_classmate();

        $this->setUser($attacker);
        $result = $this->call_as_current_user($course->id, 0);

        if (!empty($result['error'])) {
            $this->assertArrayNotHasKey('data', $result);
            return;
        }

        $this->assertFalse($result['error']);
        $returned = $this->find_user($result['data']['users'], (int) $victim->id);
        if ($returned === null) {
            return;
        }
        $this->assert_private_values_absent($returned, $victim, false);
    }

    /**
     * A grader who may list users still must not receive raw user columns.
     *
     * Email is the only configured identity field. The non-editing teacher has
     * moodle/site:viewuseridentity and moodle/grade:viewall, and does not have
     * moodle/user:viewalldetails, so username and the other planted columns stay hidden.
     */
    public function test_grader_receives_names_without_raw_user_columns(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, , $victim] = $this->create_course_with_hidden_classmate();

        $teacher = $this->getDataGenerator()->create_user([
            'username' => 'nongradingidentity',
            'email' => 'teacher.identity@example.com',
        ]);
        $teacherrole = $DB->get_record('role', ['shortname' => 'teacher'], '*', MUST_EXIST);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $teacherrole->id);

        $context = \context_course::instance($course->id);
        $this->assertTrue(has_capability('moodle/grade:viewall', $context, $teacher->id));
        $this->assertTrue(has_capability('moodle/site:viewuseridentity', $context, $teacher->id));
        $this->assertFalse(has_capability('moodle/user:viewalldetails', $context, $teacher->id));
        $this->assertFalse(has_capability('moodle/course:useremail', $context, $teacher->id));

        $this->setUser($teacher);
        $result = $this->call_as_current_user($course->id, 0);

        $this->assertFalse($result['error']);
        $returned = $this->find_user($result['data']['users'], (int) $victim->id);
        $this->assertNotNull($returned);
        $this->assertEquals($victim->id, $returned['id']);
        $this->assertArrayHasKey('fullname', $returned);
        // Email may be present because it is the configured identity field and this caller
        // holds moodle/site:viewuseridentity. Every other planted column must be absent.
        $this->assert_private_values_absent($returned, $victim, true);
    }

    /**
     * Separate groups: a student must not list classmates outside their own groups.
     */
    public function test_student_separate_groups_does_not_return_other_groups(): void {
        $this->resetAfterTest();
        [$course, $attacker, $victim, $samegroup] = $this->create_course_with_hidden_classmate(SEPARATEGROUPS);

        $generator = $this->getDataGenerator();
        $groupa = $generator->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $generator->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        $generator->create_group_member(['userid' => $attacker->id, 'groupid' => $groupa->id]);
        $generator->create_group_member(['userid' => $samegroup->id, 'groupid' => $groupa->id]);
        $generator->create_group_member(['userid' => $victim->id, 'groupid' => $groupb->id]);

        $context = \context_course::instance($course->id);
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $context, $attacker->id));
        $this->assertTrue(has_capability('moodle/course:viewparticipants', $context, $attacker->id));

        $this->setUser($attacker);
        foreach ([0, (int) $groupb->id] as $groupid) {
            $result = $this->call_as_current_user($course->id, $groupid);
            if (!empty($result['error'])) {
                $this->assertArrayNotHasKey('data', $result);
                continue;
            }
            $returned = $this->find_user($result['data']['users'], (int) $victim->id);
            $this->assertNull($returned, "groupid {$groupid} returned a user from another group");
        }
    }

    /**
     * A non-editing teacher without access-all-groups is limited to their own groups,
     * and an editing teacher who has that capability still does not receive raw columns.
     */
    public function test_grader_separate_groups_follows_accessallgroups(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $attacker, $victim, $samegroup] = $this->create_course_with_hidden_classmate(SEPARATEGROUPS);

        $generator = $this->getDataGenerator();
        $groupa = $generator->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $generator->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        $generator->create_group_member(['userid' => $attacker->id, 'groupid' => $groupa->id]);
        $generator->create_group_member(['userid' => $samegroup->id, 'groupid' => $groupa->id]);
        $generator->create_group_member(['userid' => $victim->id, 'groupid' => $groupb->id]);

        $teacher = $generator->create_user([
            'username' => 'limitedteacher',
            'email' => 'limited.teacher@example.com',
        ]);
        $teacherrole = $DB->get_record('role', ['shortname' => 'teacher'], '*', MUST_EXIST);
        $generator->enrol_user($teacher->id, $course->id, $teacherrole->id);
        $generator->create_group_member(['userid' => $teacher->id, 'groupid' => $groupa->id]);

        $context = \context_course::instance($course->id);
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $context, $teacher->id));
        $this->assertTrue(has_capability('moodle/grade:viewall', $context, $teacher->id));

        $this->setUser($teacher);
        foreach ([0, (int) $groupb->id] as $groupid) {
            $result = $this->call_as_current_user($course->id, $groupid);
            $this->assertFalse($result['error']);
            $returned = $this->find_user($result['data']['users'], (int) $victim->id);
            $this->assertNull($returned, "groupid {$groupid} returned a user from another group");
        }

        // Same-group classmate is gradable and visible, without raw columns.
        $result = $this->call_as_current_user($course->id, 0);
        $this->assertFalse($result['error']);
        $visible = $this->find_user($result['data']['users'], (int) $samegroup->id);
        $this->assertNotNull($visible);
        $this->assertArrayHasKey('fullname', $visible);
        $this->assertArrayNotHasKey('username', $visible);

        $editor = $generator->create_user([
            'username' => 'editingteacher1',
            'email' => 'editing.teacher@example.com',
        ]);
        $editorrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $generator->enrol_user($editor->id, $course->id, $editorrole->id);
        $this->assertTrue(has_capability('moodle/site:accessallgroups', $context, $editor->id));

        $this->setUser($editor);
        $result = $this->call_as_current_user($course->id, 0);
        $this->assertFalse($result['error']);
        $returned = $this->find_user($result['data']['users'], (int) $victim->id);
        $this->assertNotNull($returned);
        $this->assert_private_values_absent($returned, $victim, true);
    }
}
