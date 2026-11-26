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
 * Executes public/comment/comment_ajax.php in the current process.
 *
 * The script calls die() on every exit. uopz turns that into a return so the
 * PHPUnit process survives, and the source is eval'd so a test can post more
 * than once (require_once would not run the file again).
 *
 * @package    core_comment
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Post one request to comment_ajax.php and return whatever it wrote.
 *
 * @param array $post Request parameters, including sesskey.
 * @return string Captured output.
 */
function unbound_comment_ajax_run(array $post): string {
    global $CFG;

    $path = $CFG->dirroot . '/comment/comment_ajax.php';
    $source = file_get_contents($path);
    if ($source === false) {
        throw new coding_exception('Could not read comment_ajax.php');
    }
    $source = preg_replace('/^<\?php\s*/', '', $source, 1);

    $hadajax = defined('AJAX_SCRIPT');
    $hadnodebug = defined('NO_DEBUG_DISPLAY');
    if ($hadajax) {
        uopz_undefine('AJAX_SCRIPT');
    }
    if ($hadnodebug) {
        uopz_undefine('NO_DEBUG_DISPLAY');
    }

    $previouspost = $_POST;
    $previousget = $_GET;
    $_POST = $post;
    $_GET = [];

    $cwd = getcwd();
    chdir($CFG->dirroot . '/comment');
    uopz_allow_exit(false);
    ob_start();
    try {
        // The script is top-level Moodle code. Eval runs in this function, so the
        // globals it reads have to be imported or they are undefined locals.
        // A fresh page matches a new HTTP request: earlier format_text() calls in the
        // test have already initialised the theme on the shared $PAGE.
        global $CFG, $DB, $PAGE, $OUTPUT, $USER, $SITE, $COURSE, $SESSION;
        $PAGE = new moodle_page();
        $OUTPUT = new bootstrap_renderer();
        eval($source);
    } finally {
        $output = ob_get_clean();
        chdir($cwd);
        $_POST = $previouspost;
        $_GET = $previousget;
        uopz_allow_exit(true);
        if (!$hadajax && defined('AJAX_SCRIPT')) {
            uopz_undefine('AJAX_SCRIPT');
        }
        if (!$hadnodebug && defined('NO_DEBUG_DISPLAY')) {
            uopz_undefine('NO_DEBUG_DISPLAY');
        } else if ($hadnodebug && !defined('NO_DEBUG_DISPLAY')) {
            define('NO_DEBUG_DISPLAY', true);
        }
    }

    return $output;
}
