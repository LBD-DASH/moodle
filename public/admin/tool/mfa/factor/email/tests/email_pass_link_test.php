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

namespace factor_email;

use tool_mfa\plugininfo\factor;

/**
 * The email factor pass link must authenticate only the user who owns the code.
 *
 * These tests drive public/admin/tool/mfa/factor/email/email.php. The pass branch
 * loads a tool_mfa row by the instance id from the query string and, when the
 * secret matches, marks the email factor passed in the current session. MFA
 * completion is then decided by tool_mfa\manager::resolve_mfa_status(), the same
 * call require_login makes on the next page.
 *
 * @package     factor_email
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \factor_email\factor
 */
final class email_pass_link_test extends \advanced_testcase {

    use \tool_mfa\tests\mfa_settings_trait;

    /**
     * Enable MFA with only the email factor at its default weight.
     */
    private function enable_email_factor_only(): void {
        $this->resetAfterTest(true);
        set_config('enabled', 1, 'tool_mfa');
        foreach (factor::get_factors() as $factor) {
            if ($factor->name === 'email') {
                $this->set_factor_state('email', 1, 100);
            } else {
                $this->set_factor_state($factor->name, 0, 0);
            }
        }
        set_config('duration', 30 * MINSECS, 'factor_email');
        set_config('suspend', 0, 'factor_email');
    }

    /**
     * Create the code record the email factor creates when its login form is built.
     *
     * @param \stdClass $user
     * @return \stdClass tool_mfa row that holds the emailed code
     */
    private function issue_email_code(\stdClass $user): \stdClass {
        global $DB;

        $this->redirectEmails();
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit-email-factor';
        $this->setUser($user);

        $factor = new \factor_email\factor('email');
        $method = new \ReflectionMethod($factor, 'generate_and_email_code');
        $method->invoke($factor);

        return $DB->get_record_select(
            'tool_mfa',
            'userid = :userid AND factor = :factor AND label <> :label',
            [
                'userid' => $user->id,
                'factor' => 'email',
                'label' => $user->email,
            ],
            '*',
            MUST_EXIST,
        );
    }

    /**
     * Run email.php's pass link as the current user, then the post-login MFA decision.
     *
     * The script is included so require_login(), the no-redirect list, the record
     * lookup, and set_state() run in that order. Globals are imported first because
     * an include inside a method does not otherwise see the page-scope variables
     * the script uses.
     *
     * @param int $instanceid
     * @param int $secret
     */
    private function follow_pass_link(int $instanceid, int $secret): void {
        global $CFG, $DB, $PAGE, $OUTPUT, $USER, $SESSION, $SITE, $COURSE, $FULLME;

        $_GET = [
            'instance' => $instanceid,
            'pass' => 1,
            'secret' => $secret,
        ];
        $_POST = [];
        $_REQUEST = $_GET;

        $level = ob_get_level();
        ob_start();
        try {
            include($CFG->dirroot . '/admin/tool/mfa/factor/email/email.php');
        } catch (\moodle_exception $e) {
            // redirect() throws under PHPUnit. A rejected link may also throw.
            $expected = ['redirecterrordetected', 'error:parameters', 'error:badcode'];
            if (!in_array($e->errorcode, $expected, true)) {
                throw $e;
            }
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $status = \tool_mfa\manager::get_status();
        if ($status !== factor::STATE_FAIL) {
            // The next authenticated page calls this with a redirect when MFA is still open.
            // shouldreload is false here so a still-open challenge does not leave the test.
            \tool_mfa\manager::resolve_mfa_status(false);
        }
    }

    /**
     * Whether this session has been let through MFA.
     *
     * @return bool
     */
    private function session_passed_mfa(): bool {
        global $SESSION;

        if (!empty($SESSION->tool_mfa_authenticated)) {
            return true;
        }
        $emailfactor = factor::get_factor('email');
        return $emailfactor->get_state() === factor::STATE_PASS;
    }

    /**
     * A current code completes MFA for the user it was issued to.
     */
    public function test_owner_pass_link_completes_mfa(): void {
        $this->enable_email_factor_only();
        $owner = $this->getDataGenerator()->create_user();
        $record = $this->issue_email_code($owner);

        // setUser() starts a new session, which is the state after a password login.
        $this->setUser($owner);
        $this->assertFalse($this->session_passed_mfa());

        $this->follow_pass_link((int) $record->id, (int) $record->secret);

        $this->assertTrue($this->session_passed_mfa());
    }

    /**
     * A code issued to one user must not complete MFA in another user's session.
     */
    public function test_other_users_pass_link_does_not_complete_mfa(): void {
        global $DB;

        $this->enable_email_factor_only();
        $attacker = $this->getDataGenerator()->create_user();
        $victim = $this->getDataGenerator()->create_user();
        $record = $this->issue_email_code($attacker);
        $secret = (int) $record->secret;
        $instanceid = (int) $record->id;

        $this->setUser($victim);
        $this->assertFalse($this->session_passed_mfa());
        $this->follow_pass_link($instanceid, $secret);

        $this->assertFalse($this->session_passed_mfa());
        $this->assertFalse(\tool_mfa\manager::passed_enough_factors());

        // The code still belongs to the user it was issued to, and that user can use it.
        $stillthere = $DB->get_record('tool_mfa', ['id' => $instanceid], '*', MUST_EXIST);
        $this->assertEquals($attacker->id, $stillthere->userid);
        $this->assertTrue((int) $stillthere->secret === $secret);

        $this->setUser($attacker);
        $this->follow_pass_link($instanceid, $secret);
        $this->assertTrue($this->session_passed_mfa());
    }

    /**
     * The same unbound code must not complete MFA for a site administrator.
     */
    public function test_other_users_pass_link_does_not_complete_admin_mfa(): void {
        global $CFG;

        $this->enable_email_factor_only();
        $attacker = $this->getDataGenerator()->create_user();
        $admin = $this->getDataGenerator()->create_user();
        $admins = array_filter(explode(',', (string) $CFG->siteadmins));
        $admins[] = $admin->id;
        set_config('siteadmins', implode(',', $admins));

        $record = $this->issue_email_code($attacker);

        $this->setUser($admin);
        $this->assertTrue(is_siteadmin($admin));
        $this->follow_pass_link((int) $record->id, (int) $record->secret);

        $this->assertFalse($this->session_passed_mfa());
        $this->assertFalse(\tool_mfa\manager::passed_enough_factors());
    }

    /**
     * One code must not complete MFA for a second user after it was presented for the first.
     */
    public function test_same_code_does_not_complete_mfa_for_a_second_user(): void {
        $this->enable_email_factor_only();
        $attacker = $this->getDataGenerator()->create_user();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $record = $this->issue_email_code($attacker);
        $secret = (int) $record->secret;
        $instanceid = (int) $record->id;

        $this->setUser($first);
        $this->follow_pass_link($instanceid, $secret);
        $this->assertFalse($this->session_passed_mfa());

        $this->setUser($second);
        $this->follow_pass_link($instanceid, $secret);
        $this->assertFalse($this->session_passed_mfa());
        $this->assertFalse(\tool_mfa\manager::passed_enough_factors());
    }

    /**
     * An expired code must not complete MFA, including for the user it was issued to.
     */
    public function test_expired_pass_link_does_not_complete_mfa(): void {
        global $DB;

        $this->enable_email_factor_only();
        $owner = $this->getDataGenerator()->create_user();
        $record = $this->issue_email_code($owner);
        $duration = (int) get_config('factor_email', 'duration');
        $DB->update_record('tool_mfa', (object) [
            'id' => $record->id,
            'timecreated' => time() - $duration - 5,
        ]);

        $this->setUser($owner);
        $this->follow_pass_link((int) $record->id, (int) $record->secret);

        $this->assertFalse($this->session_passed_mfa());
        $this->assertFalse(\tool_mfa\manager::passed_enough_factors());
    }

    /**
     * A tool_mfa row for a different factor must not pass the email factor.
     */
    public function test_other_factor_record_does_not_complete_email_mfa(): void {
        global $DB;

        $this->enable_email_factor_only();
        $attacker = $this->getDataGenerator()->create_user();
        $victim = $this->getDataGenerator()->create_user();
        $secret = random_int(100000, 999999);
        $instanceid = $DB->insert_record('tool_mfa', [
            'userid' => $attacker->id,
            'factor' => 'totp',
            'secret' => $secret,
            'label' => 'phpunit-other-factor',
            'timecreated' => time(),
            'timemodified' => time(),
            'lastverified' => time(),
            'revoked' => 0,
        ]);

        $this->setUser($victim);
        $this->follow_pass_link((int) $instanceid, $secret);

        $this->assertFalse($this->session_passed_mfa());
        $this->assertFalse(\tool_mfa\manager::passed_enough_factors());
    }

    /**
     * After a successful pass, the same code must not complete a new session.
     */
    public function test_owner_code_cannot_be_replayed_after_success(): void {
        $this->enable_email_factor_only();
        $owner = $this->getDataGenerator()->create_user();
        $record = $this->issue_email_code($owner);
        $secret = (int) $record->secret;
        $instanceid = (int) $record->id;

        $this->setUser($owner);
        $this->follow_pass_link($instanceid, $secret);
        $this->assertTrue($this->session_passed_mfa());

        $this->setUser($owner);
        $this->assertFalse($this->session_passed_mfa());
        $this->follow_pass_link($instanceid, $secret);
        $this->assertFalse($this->session_passed_mfa());
    }
}
