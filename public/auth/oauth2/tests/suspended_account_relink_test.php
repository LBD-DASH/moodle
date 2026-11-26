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

namespace auth_oauth2;

use core\oauth2\client;
use core\oauth2\endpoint;
use core\oauth2\issuer;
use core\oauth2\user_field_mapping;

/**
 * Suspended OAuth2 accounts must not be relinked to another user without the
 * issuer's email confirmation step.
 *
 * @package    auth_oauth2
 * @category   test
 * @copyright  2026 Moodle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \auth_oauth2\auth::complete_login
 */
final class suspended_account_relink_test extends \advanced_testcase {

    /**
     * A suspended linked account must not take a site admin session when the
     * issuer requires email confirmation, which is the default.
     */
    public function test_suspended_link_does_not_take_over_site_admin(): void {
        $this->resetAfterTest();
        $issuer = $this->create_custom_issuer(true);
        $attacker = $this->create_suspended_linked_user($issuer, 'stable-subject', 'attacker@idp.example.com');
        $victim = $this->create_victim('victim-admin', 'victim@example.com', 'manual', true);

        $raw = $this->identity_payload('stable-subject', 'victim@example.com', 'Attacker', 'Overwrite');
        $this->run_login_twice($issuer, $raw);

        $this->assert_victim_not_taken($issuer, 'stable-subject', $attacker, $victim);
    }

    /**
     * Same takeover when the issuer is the built-in Nextcloud service, whose
     * default field map keeps username (ocs-data-id) stable while email changes.
     */
    public function test_suspended_link_does_not_take_over_with_nextcloud_mappings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        \core\plugininfo\auth::enable_plugin('oauth2', 1);

        $issuer = \core\oauth2\api::create_standard_issuer('nextcloud', 'https://cloud.example.com');
        $issuer->set('clientid', 'phpunit-client-id');
        $issuer->set('clientsecret', 'phpunit-client-secret');
        $issuer->set('showonloginpage', issuer::EVERYWHERE);
        $issuer->set('requireconfirmation', 1);
        $issuer->set('enabled', 1);
        $issuer->update();
        $this->assertTrue($issuer->is_available_for_login());
        $this->assertTrue(\auth_oauth2\api::is_enabled());
        $this->assertNotEmpty($issuer->get('requireconfirmation'));

        $attacker = $this->create_suspended_linked_user($issuer, 'stable-subject', 'attacker@idp.example.com');
        $victim = $this->create_victim('victim-nextcloud', 'victim@example.com', 'manual', true);

        $raw = json_decode('{"ocs":{"data":{"id":"stable-subject","email":"victim@example.com"}}}');
        $this->assertInstanceOf(\stdClass::class, $raw);
        $this->run_login_twice($issuer, $raw);

        $this->assert_victim_not_taken($issuer, 'stable-subject', $attacker, $victim);
    }

    /**
     * When the active account uses OAuth2, the suspended branch also copies
     * identity-provider profile fields onto it. That write must not happen
     * while confirmation is required.
     */
    public function test_suspended_link_does_not_overwrite_oauth2_profile(): void {
        $this->resetAfterTest();
        $issuer = $this->create_custom_issuer(true);
        $attacker = $this->create_suspended_linked_user($issuer, 'stable-subject', 'attacker@idp.example.com');
        $victim = $this->create_victim('victim-oauth', 'victim@example.com', 'oauth2', false);

        $raw = $this->identity_payload('stable-subject', 'victim@example.com', 'Attacker', 'Overwrite');
        $this->run_login_twice($issuer, $raw);

        $this->assert_victim_not_taken($issuer, 'stable-subject', $attacker, $victim);
        $fresh = $this->reload_user($victim->id);
        $this->assertSame('Victim', $fresh->firstname);
        $this->assertSame('Account', $fresh->lastname);
        $this->assertSame('victim@example.com', $fresh->email);
    }

    /**
     * The active account is chosen even when the suspended account is newer
     * and both Moodle users share the reported email.
     */
    public function test_suspended_link_prefers_active_account_sharing_email(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('allowaccountssameemail', 1);
        $issuer = $this->create_custom_issuer(true);
        $victim = $this->create_victim('victim-shared', 'shared@example.com', 'oauth2', false);
        $attacker = $this->create_suspended_linked_user($issuer, 'stable-subject', 'shared@example.com');
        // Make the suspended account the newest row so a lookup that does not
        // prefer active users would return the attacker and refuse the login.
        $DB->set_field('user', 'timecreated', $victim->timecreated + 1000, ['id' => $attacker->id]);

        $raw = $this->identity_payload('stable-subject', 'shared@example.com', 'Attacker', 'Overwrite');
        $this->run_login_twice($issuer, $raw);

        $this->assert_victim_not_taken($issuer, 'stable-subject', $attacker, $victim);
        $fresh = $this->reload_user($victim->id);
        $this->assertSame('Victim', $fresh->firstname);
        $this->assertSame('Account', $fresh->lastname);
    }

    /**
     * No matching active account must not crash on a false lookup, and must
     * not create a session.
     */
    public function test_suspended_link_missing_email_does_not_fatal_or_login(): void {
        global $USER, $DB;

        $this->resetAfterTest();
        $issuer = $this->create_custom_issuer(true);
        $attacker = $this->create_suspended_linked_user($issuer, 'stable-subject', 'attacker@idp.example.com');
        $before = $DB->count_records('user');

        $raw = $this->identity_payload('stable-subject', 'nobody@example.com', 'Attacker', 'Overwrite');
        $caught = $this->run_login_once($issuer, $raw, false);

        $detail = $caught->getMessage();
        $last = error_get_last();
        if (!empty($last['message'])) {
            $detail .= ' ' . $last['message'];
        }
        $this->assertStringNotContainsString('Attempt to read property', $detail);
        $this->assertFalse(
            $caught instanceof \Error,
            'A missing email lookup must not read properties of false: ' . $detail
        );
        $this->assertNotEquals($attacker->id, $USER->id);
        $links = $DB->get_records('auth_oauth2_linked_login', [
            'issuerid' => $issuer->get('id'),
            'username' => 'stable-subject',
            'confirmtoken' => '',
        ]);
        $this->assertCount(1, $links);
        $link = reset($links);
        $this->assertEquals($attacker->id, $link->userid);
        $this->assertEquals($before, $DB->count_records('user'));
    }

    /**
     * Without a separate username mapping, the provider username follows the
     * email, so the suspended link does not match and confirmation still applies.
     */
    public function test_email_as_username_still_requires_confirmation(): void {
        global $USER, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        \core\plugininfo\auth::enable_plugin('oauth2', 1);
        $issuer = $this->create_custom_issuer(true, ['email' => 'email', 'given_name' => 'firstname']);
        $attacker = $this->create_suspended_linked_user($issuer, 'attacker@idp.example.com', 'attacker@idp.example.com');
        $victim = $this->create_victim('victim-emailuser', 'victim@example.com', 'manual', true);

        $raw = json_decode('{"email":"victim@example.com","given_name":"Attacker"}');
        $this->run_login_once($issuer, $raw);

        $this->assertNotEquals($victim->id, $USER->id);
        $confirmedtovictim = $DB->get_records('auth_oauth2_linked_login', [
            'issuerid' => $issuer->get('id'),
            'userid' => $victim->id,
            'confirmtoken' => '',
        ]);
        $this->assertCount(0, $confirmedtovictim);
        $fresh = $this->reload_user($victim->id);
        $this->assertSame('Victim', $fresh->firstname);
        $this->assertNotEquals($attacker->id, $USER->id);
    }

    /**
     * Turning confirmation off is the supported way to link on email alone.
     * The suspended branch may relink in that configuration.
     */
    public function test_confirmation_disabled_may_relink_active_account(): void {
        global $DB;

        $this->resetAfterTest();
        $issuer = $this->create_custom_issuer(false);
        $attacker = $this->create_suspended_linked_user($issuer, 'stable-subject', 'attacker@idp.example.com');
        $victim = $this->create_victim('victim-noconfirm', 'victim@example.com', 'manual', false);
        unset_user_preference('auth_forcepasswordchange', $victim);

        $raw = $this->identity_payload('stable-subject', 'victim@example.com', 'Attacker', 'Overwrite');
        $this->run_login_once($issuer, $raw, true);

        $links = $DB->get_records('auth_oauth2_linked_login', [
            'issuerid' => $issuer->get('id'),
            'username' => 'stable-subject',
            'confirmtoken' => '',
        ]);
        $this->assertCount(1, $links);
        $link = reset($links);
        $this->assertEquals($victim->id, $link->userid);
        $this->assertNotEquals($attacker->id, $link->userid);
    }

    /**
     * An email match with no existing link still has to confirm before login.
     */
    public function test_unmapped_email_match_still_requires_confirmation(): void {
        global $USER, $DB;

        $this->resetAfterTest();
        $issuer = $this->create_custom_issuer(true);
        $victim = $this->create_victim('victim-unmapped', 'victim@example.com', 'manual', true);

        $raw = $this->identity_payload('brand-new-subject', 'victim@example.com', 'Attacker', 'Overwrite');
        $this->run_login_once($issuer, $raw);

        $this->assertNotEquals($victim->id, $USER->id);
        $confirmed = $DB->get_records('auth_oauth2_linked_login', [
            'issuerid' => $issuer->get('id'),
            'username' => 'brand-new-subject',
            'confirmtoken' => '',
        ]);
        $this->assertCount(0, $confirmed);
        $pending = $DB->get_records_select(
            'auth_oauth2_linked_login',
            'issuerid = :issuerid AND username = :username AND confirmtoken <> :empty',
            ['issuerid' => $issuer->get('id'), 'username' => 'brand-new-subject', 'empty' => '']
        );
        $this->assertCount(1, $pending);
        $row = reset($pending);
        $this->assertEquals($victim->id, $row->userid);
        $fresh = $this->reload_user($victim->id);
        $this->assertSame('Victim', $fresh->firstname);
    }

    /**
     * @param bool $requireconfirmation
     * @param array|null $mappings
     * @return issuer
     */
    private function create_custom_issuer(bool $requireconfirmation, ?array $mappings = null): issuer {
        $this->setAdminUser();
        \core\plugininfo\auth::enable_plugin('oauth2', 1);

        $issuer = new issuer(0, (object) [
            'name' => 'Custom IdP',
            'image' => 'https://idp.example.com/icon.png',
            'clientid' => 'phpunit-client-id',
            'clientsecret' => 'phpunit-client-secret',
            'baseurl' => 'https://idp.example.com',
            'enabled' => 1,
            'showonloginpage' => issuer::EVERYWHERE,
            'requireconfirmation' => $requireconfirmation ? 1 : 0,
        ]);
        $issuer->create();

        $endpoint = new endpoint(0, (object) [
            'issuerid' => $issuer->get('id'),
            'name' => 'userinfo_endpoint',
            'url' => 'https://idp.example.com/userinfo',
        ]);
        $endpoint->create();

        if ($mappings === null) {
            $mappings = [
                'sub' => 'username',
                'email' => 'email',
                'given_name' => 'firstname',
                'family_name' => 'lastname',
            ];
        }
        foreach ($mappings as $external => $internal) {
            $mapping = new user_field_mapping(0, (object) [
                'issuerid' => $issuer->get('id'),
                'externalfield' => $external,
                'internalfield' => $internal,
            ]);
            $mapping->create();
        }

        $this->assertSame($requireconfirmation, (bool) $issuer->get('requireconfirmation'));
        $this->assertTrue($issuer->is_available_for_login());
        $this->assertTrue(\auth_oauth2\api::is_enabled());
        return $issuer;
    }

    /**
     * @param issuer $issuer
     * @param string $providerusername
     * @param string $email
     * @return \stdClass
     */
    private function create_suspended_linked_user(issuer $issuer, string $providerusername, string $email): \stdClass {
        $attacker = $this->getDataGenerator()->create_user([
            'auth' => 'oauth2',
            'username' => 'attacker' . $issuer->get('id'),
            'email' => $email,
            'firstname' => 'Suspended',
            'lastname' => 'Attacker',
            'confirmed' => 1,
            'suspended' => 0,
        ]);
        \auth_oauth2\api::link_login([
            'username' => $providerusername,
            'email' => $email,
        ], $issuer, $attacker->id, true);

        $attacker->suspended = 1;
        user_update_user($attacker, false, false);
        return $this->reload_user($attacker->id);
    }

    /**
     * @param string $username
     * @param string $email
     * @param string $auth
     * @param bool $siteadmin
     * @return \stdClass
     */
    private function create_victim(string $username, string $email, string $auth, bool $siteadmin): \stdClass {
        global $CFG, $DB;

        $victim = $this->getDataGenerator()->create_user([
            'auth' => $auth,
            'username' => $username,
            'email' => $email,
            'firstname' => 'Victim',
            'lastname' => 'Account',
            'confirmed' => 1,
            'suspended' => 0,
        ]);
        if ($siteadmin) {
            $CFG->siteadmins = trim($CFG->siteadmins . ',' . $victim->id, ',');
            $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager']);
            role_assign($managerrole, $victim->id, \context_system::instance()->id);
            $this->assertTrue(is_siteadmin($victim->id));
        }
        return $this->reload_user($victim->id);
    }

    /**
     * @param string $subject
     * @param string $email
     * @param string $givenname
     * @param string $familyname
     * @return \stdClass
     */
    private function identity_payload(string $subject, string $email, string $givenname, string $familyname): \stdClass {
        $raw = json_decode(json_encode([
            'sub' => $subject,
            'email' => $email,
            'given_name' => $givenname,
            'family_name' => $familyname,
        ]));
        $this->assertInstanceOf(\stdClass::class, $raw);
        return $raw;
    }

    /**
     * @param issuer $issuer
     * @param \stdClass $rawuserinfo
     * @return void
     */
    private function run_login_twice(issuer $issuer, \stdClass $rawuserinfo): void {
        $this->redirectEmails();
        $this->run_login_once($issuer, $rawuserinfo, false);
        $this->run_login_once($issuer, $rawuserinfo, false);
    }

    /**
     * Drive complete_login with the real userinfo mapper.
     *
     * @param issuer $issuer
     * @param \stdClass $rawuserinfo
     * @param bool $suppresswarnings Session regeneration warns when a login succeeds.
     * @return \Throwable
     */
    private function run_login_once(issuer $issuer, \stdClass $rawuserinfo, bool $suppresswarnings = false): \Throwable {
        $this->redirectEmails();
        $client = new oauth2_userinfo_client($issuer, $rawuserinfo);
        $mapped = $client->get_userinfo();
        $this->assertNotEmpty($mapped['email']);
        $this->assertNotEmpty($mapped['username']);

        $auth = new \auth_oauth2\auth();
        ob_start();
        try {
            if ($suppresswarnings) {
                @$auth->complete_login($client, new \moodle_url('/'));
            } else {
                $auth->complete_login($client, new \moodle_url('/'));
            }
            $this->fail('complete_login returned without stopping the request');
        } catch (\Throwable $e) {
            return $e;
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
    }

    /**
     * @param issuer $issuer
     * @param string $providerusername
     * @param \stdClass $attacker
     * @param \stdClass $victim
     * @return void
     */
    private function assert_victim_not_taken(
        issuer $issuer,
        string $providerusername,
        \stdClass $attacker,
        \stdClass $victim
    ): void {
        global $USER, $DB;

        $freshvictim = $this->reload_user($victim->id);
        $this->assertSame('Victim', $freshvictim->firstname);
        $this->assertSame('Account', $freshvictim->lastname);
        $this->assertSame($victim->email, $freshvictim->email);
        $this->assertEquals(0, (int) $freshvictim->suspended);

        $this->assertNotEquals($victim->id, $USER->id);
        $loggedin = $DB->get_records('logstore_standard_log', [
            'eventname' => '\core\event\user_loggedin',
            'userid' => $victim->id,
        ]);
        $this->assertCount(0, $loggedin);

        $confirmed = $DB->get_records('auth_oauth2_linked_login', [
            'issuerid' => $issuer->get('id'),
            'username' => $providerusername,
            'confirmtoken' => '',
        ]);
        foreach ($confirmed as $row) {
            $this->assertNotEquals(
                (int) $victim->id,
                (int) $row->userid,
                'A confirmed linked login was moved to the active account without email confirmation'
            );
        }
        $confirmedtovictim = $DB->get_records('auth_oauth2_linked_login', [
            'issuerid' => $issuer->get('id'),
            'userid' => $victim->id,
            'confirmtoken' => '',
        ]);
        $this->assertCount(0, $confirmedtovictim);

        // The suspended account must still be the only confirmed owner, or the
        // confirmed link must have been removed rather than handed to the victim.
        if ($confirmed) {
            $this->assertCount(1, $confirmed);
            $row = reset($confirmed);
            $this->assertEquals($attacker->id, $row->userid);
        }
    }

    /**
     * @param int $userid
     * @return \stdClass
     */
    private function reload_user(int $userid): \stdClass {
        global $DB;
        return $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    }
}

/**
 * Client that already holds the identity-provider userinfo document.
 *
 * get_userinfo() still runs the product's field mapping.
 *
 * @package    auth_oauth2
 * @copyright  2026 Moodle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oauth2_userinfo_client extends client {

    /**
     * @param issuer $issuer
     * @param \stdClass $rawuserinfo Decoded userinfo document.
     */
    public function __construct(issuer $issuer, \stdClass $rawuserinfo) {
        parent::__construct($issuer, new \moodle_url('/auth/oauth2/login.php'), '', false);
        $this->rawuserinfo = $rawuserinfo;
    }
}

/**
 * @param string $message
 * @param string $link
 * @param mixed $course
 * @return void
 */
function notice($message, $link = '', $course = null): void {
    throw new \moodle_exception('notice', 'moodle', '', $message);
}
