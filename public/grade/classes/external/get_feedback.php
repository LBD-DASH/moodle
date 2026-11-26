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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot.'/grade/lib.php');

/**
 * Web service to fetch students feedback for a grade item.
 *
 * @package    core_grades
 * @copyright  2023 Kevin Percy <kevin.percy@moodle.com>
 * @category   external
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_feedback extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters (
            [
                'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_REQUIRED),
                'userid' => new external_value(PARAM_INT, 'User ID', VALUE_REQUIRED),
                'itemid' => new external_value(PARAM_INT, 'Grade Item ID', VALUE_REQUIRED)
            ]
        );
    }

    /**
     * Given a user ID and grade item ID, return feedback and user details.
     *
     * @param int $courseid The course ID.
     * @param int $userid
     * @param int $itemid
     * @return array Feedback and user details
     */
    public static function execute(int $courseid, int $userid, int $itemid): array {
        global $OUTPUT, $CFG;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'courseid' => $courseid,
                'userid' => $userid,
                'itemid' => $itemid
            ]
        );

        $context = \context_course::instance($courseid);
        parent::validate_context($context);

        require_capability('gradereport/grader:view', $context);

        $gtree = new \grade_tree($params['courseid'], false, false, null, !$CFG->enableoutcomes);
        $gradeitem = $gtree->get_item($params['itemid']);

        // If Item ID is not part of Course ID, $gradeitem will be set to false.
        if ($gradeitem === false) {
            throw new invalid_parameter_exception('Course ID and item ID mismatch');
        }

        $user = self::get_visible_gradable_user($context, $gradeitem, $params['userid']);

        $grade = $gradeitem->get_grade($user->id, false);
        $extrafields = \core_user\fields::get_identity_fields($context);

        return [
            'feedbacktext' => $grade->feedback,
            'title' => $gradeitem->get_name(true),
            'fullname' => fullname($user),
            'picture' => $OUTPUT->user_picture($user, ['size' => 50, 'link' => false]),
            'additionalfield' => empty($extrafields) ? '' : $user->{$extrafields[0]},
        ];
    }

    /**
     * Load the requested user only if they are shown to the current user in the grader report.
     *
     * The user must be enrolled in the course with a gradebook role and, when separate groups apply to the
     * course or to the activity behind the grade item, share a group with the current user unless the current
     * user can access all groups.
     *
     * @param \context_course $context The course context.
     * @param \grade_item $gradeitem The grade item the feedback belongs to.
     * @param int $userid The requested user ID.
     * @return \stdClass The user record.
     * @throws \moodle_exception If the user is not available to the current user.
     */
    protected static function get_visible_gradable_user(
        \context_course $context,
        \grade_item $gradeitem,
        int $userid
    ): \stdClass {
        global $CFG, $DB;

        $gradebookroles = array_filter(explode(',', $CFG->gradebookroles ?? ''));
        if (empty($gradebookroles)) {
            throw new \moodle_exception('usernotavailable', 'error');
        }

        $showonlyactiveenrol = get_user_preferences('grade_report_showonlyactiveenrol',
            !empty($CFG->grade_report_showonlyactiveenrol));
        $onlyactive = $showonlyactiveenrol || !has_capability('moodle/course:viewsuspendedusers', $context);

        [$enrolledsql, $enrolledparams] = get_enrolled_sql($context, '', 0, $onlyactive);
        [$rolesql, $roleparams] = $DB->get_in_or_equal($gradebookroles, SQL_PARAMS_NAMED, 'grbr');
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'relatedctx');

        $sql = "SELECT u.*
                  FROM {user} u
                  JOIN ($enrolledsql) je ON je.id = u.id
                 WHERE u.id = :targetuserid
                   AND u.deleted = 0
                   AND EXISTS (
                           SELECT 1
                             FROM {role_assignments} ra
                            WHERE ra.userid = u.id
                              AND ra.roleid $rolesql
                              AND ra.contextid $ctxsql
                       )";
        $params = array_merge($enrolledparams, $roleparams, $ctxparams, ['targetuserid' => $userid]);
        $user = $DB->get_record_sql($sql, $params);
        if (!$user) {
            throw new \moodle_exception('usernotavailable', 'error');
        }

        $course = get_course($context->instanceid);
        if (!groups_user_groups_visible($course, $user->id)) {
            throw new \moodle_exception('usernotavailable', 'error');
        }

        if ($gradeitem->itemtype === 'mod') {
            $instances = get_fast_modinfo($course)->get_instances_of($gradeitem->itemmodule);
            $cm = $instances[$gradeitem->iteminstance] ?? null;
            if ($cm && !groups_user_groups_visible($course, $user->id, $cm)) {
                throw new \moodle_exception('usernotavailable', 'error');
            }
        }

        return $user;
    }

    /**
     * Describes the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'feedbacktext' => new external_value(PARAM_RAW, 'The full feedback text'),
            'title' => new external_value(PARAM_TEXT, 'Title of the grade item that the feedback is for'),
            'fullname' => new external_value(PARAM_TEXT, 'Students name'),
            'picture' => new external_value(PARAM_RAW, 'Students picture'),
            'additionalfield' => new external_value(PARAM_RAW, 'Additional field for the user (email or ID number, for example)'),
        ]);
    }
}
