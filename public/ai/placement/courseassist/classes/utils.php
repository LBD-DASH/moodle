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

namespace aiplacement_courseassist;

use core_ai\aiactions\explain_text;
use core_ai\aiactions\summarise_text;
use core_ai\manager;

/**
 * AI Placement course assist utils.
 *
 * @package    aiplacement_courseassist
 * @copyright  2024 Huong Nguyen <huongnv13@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils {
    /**
     * Check if AI Placement course assist is available.
     *
     * @return bool True if AI Placement course assist is available, false otherwise.
     */
    public static function is_course_assist_available(): bool {
        [$plugintype, $pluginname] = explode('_', \core_component::normalize_componentname('aiplacement_courseassist'), 2);
        $pluginmanager = \core_plugin_manager::resolve_plugininfo_class($plugintype);
        if (!$pluginmanager::is_plugin_enabled($pluginname)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a course assist action is available to the current user in the context.
     *
     * @param \context $context The context.
     * @param string $actionclass The fully qualified action class name.
     * @param bool $checkcontext If true, check the action is enabled in the context.
     * @return bool True if the user may run the action in this context.
     */
    public static function is_course_assist_action_available(
        \context $context,
        string $actionclass,
        bool $checkcontext = true,
    ): bool {
        if (!in_array($actionclass, [summarise_text::class, explain_text::class], true)) {
            return false;
        }

        $manager = \core\di::get(manager::class);
        return has_capability('aiplacement/courseassist:' . $actionclass::get_basename(), $context)
            && $manager->is_action_available($actionclass)
            && $manager->is_action_enabled('aiplacement_courseassist', $actionclass)
            && (!$checkcontext || $manager->is_action_enabled_in_context($context, $actionclass));
    }

    /**
     * Get all the actions available and return action data for template.
     *
     * @param \context $context The context.
     * @param bool $checkcontext If true, check the action is available in context.
     * @return array Return the actions available with data.
     */
    public static function get_actions_available(\context $context, bool $checkcontext = true): array {
        $actions = [];

        // Summarise text.
        if (self::is_course_assist_action_available($context, summarise_text::class, $checkcontext)) {
            $actions[] = [
                'action' => 'summarise_text',
                'buttontext' => get_string('summarise', 'aiplacement_courseassist'),
                'title' => get_string('summarise_tooltips', 'aiplacement_courseassist'),
            ];
        }

        // Explain text.
        if (self::is_course_assist_action_available($context, explain_text::class, $checkcontext)) {
            $actions[] = [
                'action' => 'explain_text',
                'buttontext' => get_string('explain', 'aiplacement_courseassist'),
                'title' => get_string('explain_tooltips', 'aiplacement_courseassist'),
            ];
        }

        return $actions;
    }
}
