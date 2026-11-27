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

namespace core_external;

use context_system;

/**
 * Browser AJAX writes must be rejected when the request has no sesskey.
 *
 * These tests follow the same steps as public/lib/ajax/service.php: decode a
 * JSON request body (including one a text/plain HTML form can produce) and call
 * external_api::call_external_function() with ajaxonly set. They fail while a
 * write service sets loginrequired to false, because that flag skips the
 * logged-in and sesskey checks. They pass once those writes require a sesskey
 * again and leave the database unchanged when the key is missing or wrong.
 *
 * @package     core_external
 * @category    test
 * @copyright   2026 onwards Moodle
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(external_api::class)]
final class ajax_write_without_sesskey_test extends \advanced_testcase {

    /**
     * Build the POST body a text/plain form would send for one AJAX request.
     *
     * The form field name is the JSON up to an open string, and the field value
     * closes that string, so the "=" PHP inserts between them stays inside JSON.
     *
     * @param string $methodname External function name.
     * @param array $args Named arguments for that function.
     * @return string Raw request body.
     */
    private function textplain_ajax_body(string $methodname, array $args): string {
        $prefix = '[{"index":0,"methodname":' . json_encode($methodname) .
            ',"args":' . json_encode($args) . ',"ignore":"';
        return $prefix . '=' . '"}]';
    }

    /**
     * Run the request the way public/lib/ajax/service.php does after it reads the body.
     *
     * @param string $body Raw POST body.
     * @return array Responses keyed by request index.
     */
    private function dispatch_ajax_body(string $body): array {
        $requests = json_decode($body, true);
        $this->assertIsArray($requests, 'The text/plain form body must be JSON the AJAX script can decode');

        $responses = [];
        foreach ($requests as $request) {
            $methodname = clean_param($request['methodname'], PARAM_ALPHANUMEXT);
            $index = clean_param($request['index'], PARAM_INT);
            $args = $request['args'];
            $responses[$index] = external_api::call_external_function($methodname, $args, true);
        }
        return $responses;
    }

    /**
     * Drop any sesskey a previous test left in the request.
     */
    private function clear_request_sesskey(): void {
        unset($_GET['sesskey'], $_POST['sesskey'], $_REQUEST['sesskey']);
    }

    /**
     * The AJAX call must stop before the external function writes anything.
     *
     * With no sesskey parameter, require_sesskey() reaches required_param() and
     * the dispatcher reports missingparam. A present but wrong value is invalidsesskey.
     *
     * @param array $response One entry from call_external_function().
     * @param string $errorcode Expected exception error code.
     */
    private function assert_rejected_before_write(array $response, string $errorcode): void {
        $this->assertTrue($response['error'], 'The AJAX write must be rejected without a valid sesskey');
        $this->assertIsObject($response['exception']);
        $this->assertSame($errorcode, $response['exception']->errorcode);
    }

    /**
     * A logged-in user can change these preferences for the named account.
     *
     * @param int $userid Preference owner. 0 means the current user.
     * @return array[]
     */
    private function attacker_preferences(int $userid): array {
        global $CFG;

        $CFG->defaulthomepage = HOMEPAGE_USER;
        $CFG->messaging = 1;
        $CFG->messagingallusers = 1;

        return [
            [
                'name' => 'htmleditor',
                'value' => 'textarea',
                'userid' => $userid,
            ],
            [
                'name' => 'message_blocknoncontacts',
                'value' => (string) \core_message\api::MESSAGE_PRIVACY_SITE,
                'userid' => $userid,
            ],
            [
                'name' => 'message_provider_moodle_newlogin_enabled',
                'value' => 'none',
                'userid' => $userid,
            ],
            [
                'name' => 'user_home_page_preference',
                'value' => (string) HOMEPAGE_SITE,
                'userid' => $userid,
            ],
        ];
    }

    /**
     * @param int $userid
     * @param string $name
     * @return string|false
     */
    private function stored_preference(int $userid, string $name) {
        global $DB;
        return $DB->get_field('user_preferences', 'value', ['userid' => $userid, 'name' => $name]);
    }

    /**
     * An ordinary user's own preferences must not change when the browser posts
     * core_user_set_user_preferences without a sesskey.
     */
    public function test_own_preferences_rejected_without_sesskey(): void {
        $this->resetAfterTest();
        $victim = $this->getDataGenerator()->create_user();
        $this->setUser($victim);
        $this->clear_request_sesskey();

        $preferences = $this->attacker_preferences(0);
        $body = $this->textplain_ajax_body('core_user_set_user_preferences', ['preferences' => $preferences]);
        $responses = $this->dispatch_ajax_body($body);

        $this->assert_rejected_before_write($responses[0], 'missingparam');
        foreach ($preferences as $preference) {
            $this->assertFalse(
                $this->stored_preference($victim->id, $preference['name']),
                $preference['name'] . ' was stored for the victim'
            );
        }
    }

    /**
     * A wrong sesskey is not a substitute for the session key.
     */
    public function test_own_preferences_rejected_with_wrong_sesskey(): void {
        $this->resetAfterTest();
        $victim = $this->getDataGenerator()->create_user();
        $this->setUser($victim);
        $this->clear_request_sesskey();
        $_POST['sesskey'] = 'not-the-session-key';
        $_REQUEST['sesskey'] = 'not-the-session-key';

        $preferences = $this->attacker_preferences($victim->id);
        $body = $this->textplain_ajax_body('core_user_set_user_preferences', ['preferences' => $preferences]);
        $responses = $this->dispatch_ajax_body($body);

        $this->assert_rejected_before_write($responses[0], 'invalidsesskey');
        $this->assertFalse($this->stored_preference($victim->id, 'htmleditor'));
        $this->assertFalse($this->stored_preference($victim->id, 'message_provider_moodle_newlogin_enabled'));
        $this->assertFalse($this->stored_preference($victim->id, 'message_blocknoncontacts'));
    }

    /**
     * A site administrator's session must not set auth_forcepasswordchange, or
     * another user's notification preferences, unless the request has that
     * administrator's sesskey. The rows checked are the ones named in the request.
     */
    public function test_admin_cannot_edit_other_user_without_sesskey(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        $target = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        $otheradmin = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        global $USER;
        // Site admins hold moodle/site:config, so the notices provider is editable for them.
        $CFG->siteadmins = $CFG->siteadmins . ',' . $otheradmin->id;
        $CFG->messaging = 1;
        $this->clear_request_sesskey();

        $preferences = [
            [
                'name' => 'auth_forcepasswordchange',
                'value' => '1',
                'userid' => $target->id,
            ],
            [
                'name' => 'htmleditor',
                'value' => 'textarea',
                'userid' => $target->id,
            ],
            [
                'name' => 'message_provider_moodle_newlogin_enabled',
                'value' => 'none',
                'userid' => $target->id,
            ],
            [
                'name' => 'message_provider_moodle_notices_enabled',
                'value' => 'none',
                'userid' => $USER->id,
            ],
            [
                'name' => 'message_provider_moodle_notices_enabled',
                'value' => 'none',
                'userid' => $otheradmin->id,
            ],
        ];
        $body = $this->textplain_ajax_body('core_user_set_user_preferences', ['preferences' => $preferences]);
        $responses = $this->dispatch_ajax_body($body);

        $this->assert_rejected_before_write($responses[0], 'missingparam');
        foreach ([$target, $bystander, $otheradmin, $USER] as $user) {
            $this->assertFalse($this->stored_preference($user->id, 'auth_forcepasswordchange'));
            $this->assertFalse($this->stored_preference($user->id, 'message_provider_moodle_notices_enabled'));
            $this->assertFalse($this->stored_preference($user->id, 'message_provider_moodle_newlogin_enabled'));
            $this->assertFalse($this->stored_preference($user->id, 'htmleditor'));
        }
        $this->assertSame(0, $DB->count_records('user_preferences', ['userid' => $target->id]));
        $this->assertSame(0, $DB->count_records('user_preferences', ['userid' => $otheradmin->id]));
    }

    /**
     * moodle/user:update on a non-admin session is enough to force a password
     * change, so that session also needs a sesskey.
     */
    public function test_user_update_capability_cannot_force_password_change_without_sesskey(): void {
        $this->resetAfterTest();

        $editor = $this->getDataGenerator()->create_user();
        $target = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $systemcontext = context_system::instance();
        assign_capability('moodle/user:update', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $editor->id, $systemcontext->id);

        $this->setUser($editor);
        $this->clear_request_sesskey();

        $body = $this->textplain_ajax_body('core_user_set_user_preferences', [
            'preferences' => [
                [
                    'name' => 'auth_forcepasswordchange',
                    'value' => '1',
                    'userid' => $target->id,
                ],
            ],
        ]);
        $responses = $this->dispatch_ajax_body($body);

        $this->assert_rejected_before_write($responses[0], 'missingparam');
        $this->assertFalse($this->stored_preference($target->id, 'auth_forcepasswordchange'));
        $this->assertFalse($this->stored_preference($editor->id, 'auth_forcepasswordchange'));
    }

    /**
     * The same AJAX call with the current session's sesskey still saves only the
     * requested user's preference.
     */
    public function test_preferences_saved_only_for_requested_user_with_sesskey(): void {
        $this->resetAfterTest();

        $target = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $this->clear_request_sesskey();
        $_POST['sesskey'] = sesskey();

        $body = $this->textplain_ajax_body('core_user_set_user_preferences', [
            'preferences' => [
                [
                    'name' => 'htmleditor',
                    'value' => 'textarea',
                    'userid' => $target->id,
                ],
            ],
        ]);
        $responses = $this->dispatch_ajax_body($body);

        $this->assertFalse($responses[0]['error']);
        $this->assertSame('textarea', $this->stored_preference($target->id, 'htmleditor'));
        $this->assertFalse($this->stored_preference($bystander->id, 'htmleditor'));
    }

    /**
     * Logged-in Tiny autosave writes share the loginrequired flag and must not
     * create, replace, or delete a draft without a sesskey.
     */
    public function test_tiny_autosave_writes_rejected_without_sesskey(): void {
        global $DB;
        $this->resetAfterTest();

        $victim = $this->getDataGenerator()->create_user();
        $this->setUser($victim);
        $this->clear_request_sesskey();

        $contextid = context_system::instance()->id;
        $pagehash = 'pagehashcsrf';
        $pageinstance = 'pageinstancecsrf';
        $elementid = 'elementcsrf';
        $original = 'victim draft that must stay';

        $manager = new \tiny_autosave\autosave_manager($contextid, $pagehash, $pageinstance, $elementid);
        $created = $manager->create_autosave_record($original);
        $this->assertSame($original, $DB->get_field('tiny_autosave', 'drafttext', ['id' => $created->id]));

        $update = $this->dispatch_ajax_body($this->textplain_ajax_body(
            'tiny_autosave_update_session',
            [
                'contextid' => $contextid,
                'pagehash' => $pagehash,
                'pageinstance' => $pageinstance,
                'elementid' => $elementid,
                'drafttext' => 'replaced by the other page',
            ]
        ));
        $this->assert_rejected_before_write($update[0], 'missingparam');
        $this->assertSame($original, $DB->get_field('tiny_autosave', 'drafttext', ['id' => $created->id]));

        $reset = $this->dispatch_ajax_body($this->textplain_ajax_body(
            'tiny_autosave_reset_session',
            [
                'contextid' => $contextid,
                'pagehash' => $pagehash,
                'pageinstance' => $pageinstance,
                'elementid' => $elementid,
            ]
        ));
        $this->assert_rejected_before_write($reset[0], 'missingparam');
        $this->assertTrue($DB->record_exists('tiny_autosave', ['id' => $created->id]));

        $resume = $this->dispatch_ajax_body($this->textplain_ajax_body(
            'tiny_autosave_resume_session',
            [
                'contextid' => $contextid,
                'pagehash' => 'otherhashcsrf',
                'pageinstance' => 'otherinstancecsrf',
                'elementid' => 'otherelementcsrf',
                'draftid' => null,
            ]
        ));
        $this->assert_rejected_before_write($resume[0], 'missingparam');
        $this->assertFalse($DB->record_exists('tiny_autosave', [
            'userid' => $victim->id,
            'pagehash' => 'otherhashcsrf',
            'elementid' => 'otherelementcsrf',
        ]));
    }

    /**
     * PayPal completion attributes the payment to the logged-in user and is
     * exposed to AJAX with loginrequired false. It must be rejected before any
     * local payment row is written when the sesskey is absent.
     */
    public function test_paypal_transaction_complete_rejected_without_sesskey(): void {
        global $DB;
        $this->resetAfterTest();

        $victim = $this->getDataGenerator()->create_user();
        $this->setUser($victim);
        $this->clear_request_sesskey();

        $beforepayments = $DB->count_records('payments');
        $beforepaypal = $DB->count_records('paygw_paypal');

        $responses = $this->dispatch_ajax_body($this->textplain_ajax_body(
            'paygw_paypal_create_transaction_complete',
            [
                'component' => 'notacomponent',
                'paymentarea' => 'area',
                'itemid' => 1,
                'orderid' => 'order-placeholder',
            ]
        ));

        $this->assert_rejected_before_write($responses[0], 'missingparam');
        $this->assertSame($beforepayments, $DB->count_records('payments'));
        $this->assertSame($beforepaypal, $DB->count_records('paygw_paypal'));
    }
}
