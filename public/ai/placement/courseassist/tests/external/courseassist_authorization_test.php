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

namespace aiplacement_courseassist\external;

use GuzzleHttp\Psr7\Response;

/**
 * Server-side authorization for the course assist AI web services.
 *
 * These tests exercise aiplacement_courseassist_summarise_text and
 * aiplacement_courseassist_explain_text through external_api::call_external_function(),
 * the same call public/lib/ajax/service.php makes. The HTTP client stands in only
 * for the outbound provider request. The placement service, action object, manager
 * and OpenAI processor are the real classes.
 *
 * Each case expects the service to refuse, and to leave no provider request and no
 * stored action, while the vulnerability is present. An allowed call in the same
 * test proves the provider is reachable and that the prompt and context that were
 * authorized are the prompt and context that were sent.
 *
 * @package    aiplacement_courseassist
 * @copyright  2026 Moodle Pty Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_courseassist\external\summarise_text
 * @covers     \aiplacement_courseassist\external\explain_text
 */
final class courseassist_authorization_test extends \advanced_testcase {

    /** @var string Text the provider would return if it is contacted. */
    private const GENERATED = 'provider-generated-content';

    /**
     * Web service names that share this placement.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function service_provider(): array {
        return [
            'summarise_text' => ['aiplacement_courseassist_summarise_text', 'summarise_text'],
            'explain_text' => ['aiplacement_courseassist_explain_text', 'explain_text'],
        ];
    }

    /**
     * An authenticated user with no course-assist role can pass the system context
     * and their own user context, but must not reach the provider.
     *
     * @dataProvider service_provider
     * @param string $methodname External function name.
     * @param string $actionname Action basename.
     */
    public function test_unprivileged_user_is_refused_for_system_and_user_context(
        string $methodname,
        string $actionname,
    ): void {
        global $DB;

        $this->resetAfterTest();
        $this->enable_placement_and_provider();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $coursecontext = \context_course::instance($course->id);
        $systemcontext = \context_system::instance();
        $usercontext = \context_user::instance($user->id);

        $this->setUser($user);
        $history = [];
        $this->queue_provider_responses($history, 3);

        $systemprompt = 'system-context-prompt-' . $actionname;
        $registerbefore = $DB->count_records('ai_action_register', ['userid' => $user->id]);
        $historybefore = count($history);
        $systemresult = $this->call_service($methodname, $systemcontext->id, $systemprompt);
        $userprompt = 'user-context-prompt-' . $actionname;
        $userresult = $this->call_service($methodname, $usercontext->id, $userprompt);
        // Both contexts are attempted before the assertion so one failure reports both results.
        $this->assertTrue(
            !empty($systemresult['error']) && !empty($userresult['error']) && count($history) === $historybefore,
            'System result: ' . $this->describe_result($systemresult)
                . ' User result: ' . $this->describe_result($userresult)
                . ' provider_requests=' . count($history),
        );
        $this->assertSame(
            $registerbefore,
            $DB->count_records('ai_action_register', ['userid' => $user->id]),
        );
        $this->assertSame(0, $this->count_stored_prompts('ai_action_summarise_text', $systemprompt));
        $this->assertSame(0, $this->count_stored_prompts('ai_action_explain_text', $systemprompt));
        $this->assertSame(0, $this->count_stored_prompts('ai_action_summarise_text', $userprompt));
        $this->assertSame(0, $this->count_stored_prompts('ai_action_explain_text', $userprompt));

        // The same user is allowed in a course where the student role has the capability.
        $courseprompt = 'course-context-prompt-' . $actionname;
        $courseresult = $this->call_service($methodname, $coursecontext->id, $courseprompt);
        $this->assert_provider_received($courseresult, $history, $courseprompt, $coursecontext->id, $user->id, $actionname);
    }

    /**
     * A student in a course where the action is enabled must reach the provider
     * with the prompt and context from that request.
     *
     * @dataProvider service_provider
     * @param string $methodname External function name.
     * @param string $actionname Action basename.
     */
    public function test_enrolled_student_course_context_reaches_provider(
        string $methodname,
        string $actionname,
    ): void {
        $this->resetAfterTest();
        $this->enable_placement_and_provider();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $context = \context_course::instance($course->id);

        $this->setUser($user);
        $history = [];
        $this->queue_provider_responses($history, 1);

        $prompt = 'enrolled-student-prompt-' . $actionname;
        $result = $this->call_service($methodname, $context->id, $prompt);
        $this->assert_provider_received($result, $history, $prompt, $context->id, $user->id, $actionname);
    }

    /**
     * A capability prohibited in one course must not be satisfied by an allow in another course.
     *
     * @dataProvider service_provider
     * @param string $methodname External function name.
     * @param string $actionname Action basename.
     */
    public function test_prohibited_course_is_not_authorized_by_another_course(
        string $methodname,
        string $actionname,
    ): void {
        global $DB;

        $this->resetAfterTest();
        $this->enable_placement_and_provider();

        $user = $this->getDataGenerator()->create_user();
        $allowedcourse = $this->getDataGenerator()->create_course();
        $prohibitedcourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $allowedcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $prohibitedcourse->id, 'student');

        $allowedcontext = \context_course::instance($allowedcourse->id);
        $prohibitedcontext = \context_course::instance($prohibitedcourse->id);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $capability = "aiplacement/courseassist:{$actionname}";
        assign_capability($capability, CAP_PROHIBIT, $studentroleid, $prohibitedcontext->id, true);

        $this->setUser($user);
        $history = [];
        $this->queue_provider_responses($history, 2);

        $deniedprompt = 'prohibited-course-prompt-' . $actionname;
        $denied = $this->call_service($methodname, $prohibitedcontext->id, $deniedprompt);
        $this->assert_refused_without_provider_side_effect($denied, $history, $user->id, $deniedprompt);

        $allowedprompt = 'allowed-course-prompt-' . $actionname;
        $allowed = $this->call_service($methodname, $allowedcontext->id, $allowedprompt);
        $this->assert_provider_received(
            $allowed,
            $history,
            $allowedprompt,
            $allowedcontext->id,
            $user->id,
            $actionname,
        );
        $this->assertStringNotContainsString($deniedprompt, $this->request_bodies($history));
    }

    /**
     * A capability granted at system context allows that context through to the provider.
     *
     * @dataProvider service_provider
     * @param string $methodname External function name.
     * @param string $actionname Action basename.
     */
    public function test_system_capability_reaches_provider_for_that_context(
        string $methodname,
        string $actionname,
    ): void {
        $this->resetAfterTest();
        $this->enable_placement_and_provider();

        $user = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();
        $allowrole = create_role('Course assist system only', 'courseassistsystemonly', '');
        assign_capability("aiplacement/courseassist:{$actionname}", CAP_ALLOW, $allowrole, $systemcontext->id);
        role_assign($allowrole, $user->id, $systemcontext->id);

        $this->setUser($user);
        $history = [];
        $this->queue_provider_responses($history, 1);

        $prompt = 'system-capability-prompt-' . $actionname;
        $result = $this->call_service($methodname, $systemcontext->id, $prompt);
        $this->assert_provider_received($result, $history, $prompt, $systemcontext->id, $user->id, $actionname);
    }

    /**
     * A system-level allow must not authorize a course where the capability is prohibited.
     *
     * @dataProvider service_provider
     * @param string $methodname External function name.
     * @param string $actionname Action basename.
     */
    public function test_system_allow_does_not_authorize_prohibited_course(
        string $methodname,
        string $actionname,
    ): void {
        global $DB;

        $this->resetAfterTest();
        $this->enable_placement_and_provider();

        $user = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();
        $allowrole = create_role('Course assist system allow', 'courseassistsystemallow', '');
        $capability = "aiplacement/courseassist:{$actionname}";
        assign_capability($capability, CAP_ALLOW, $allowrole, $systemcontext->id);
        role_assign($allowrole, $user->id, $systemcontext->id);

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $coursecontext = \context_course::instance($course->id);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability($capability, CAP_PROHIBIT, $studentroleid, $coursecontext->id, true);

        $this->setUser($user);
        $history = [];
        $this->queue_provider_responses($history, 2);

        $courseprompt = 'prohibited-despite-system-allow-' . $actionname;
        $courseresult = $this->call_service($methodname, $coursecontext->id, $courseprompt);
        $this->assert_refused_without_provider_side_effect($courseresult, $history, $user->id, $courseprompt);

        $systemprompt = 'system-allow-prompt-' . $actionname;
        $systemresult = $this->call_service($methodname, $systemcontext->id, $systemprompt);
        $this->assert_provider_received(
            $systemresult,
            $history,
            $systemprompt,
            $systemcontext->id,
            $user->id,
            $actionname,
        );
        $this->assertStringNotContainsString($courseprompt, $this->request_bodies($history));
    }

    /**
     * Disabling one placement action must stop only that action.
     *
     * @dataProvider service_provider
     * @param string $methodname External function name.
     * @param string $actionname Action basename.
     */
    public function test_disabled_placement_action_is_not_sent_to_the_provider(
        string $methodname,
        string $actionname,
    ): void {
        $this->resetAfterTest();
        $manager = $this->enable_placement_and_provider();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $context = \context_course::instance($course->id);

        $manager->set_action_state('aiplacement_courseassist', $actionname, 0);

        $otheraction = $actionname === 'summarise_text' ? 'explain_text' : 'summarise_text';
        $othermethod = $otheraction === 'summarise_text'
            ? 'aiplacement_courseassist_summarise_text'
            : 'aiplacement_courseassist_explain_text';

        $this->setUser($user);
        $history = [];
        $this->queue_provider_responses($history, 2);

        $deniedprompt = 'disabled-action-prompt-' . $actionname;
        $denied = $this->call_service($methodname, $context->id, $deniedprompt);
        $this->assert_refused_without_provider_side_effect($denied, $history, $user->id, $deniedprompt);

        $allowedprompt = 'enabled-sibling-action-prompt-' . $otheraction;
        $allowed = $this->call_service($othermethod, $context->id, $allowedprompt);
        $this->assert_provider_received($allowed, $history, $allowedprompt, $context->id, $user->id, $otheraction);
        $this->assertStringNotContainsString($deniedprompt, $this->request_bodies($history));
    }

    /**
     * Turning AI tools off for a course must stop both actions in that course only.
     *
     * @dataProvider service_provider
     * @param string $methodname External function name.
     * @param string $actionname Action basename.
     */
    public function test_course_ai_opt_out_is_enforced(
        string $methodname,
        string $actionname,
    ): void {
        global $DB;

        $this->resetAfterTest();
        $this->enable_placement_and_provider();

        $user = $this->getDataGenerator()->create_user();
        $disabledcourse = $this->getDataGenerator()->create_course();
        $enabledcourse = $this->getDataGenerator()->create_course();
        $DB->set_field('course', 'enableaitools', 0, ['id' => $disabledcourse->id]);
        $this->getDataGenerator()->enrol_user($user->id, $disabledcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $enabledcourse->id, 'student');

        $disabledcontext = \context_course::instance($disabledcourse->id);
        $enabledcontext = \context_course::instance($enabledcourse->id);

        $this->setUser($user);
        $history = [];
        $this->queue_provider_responses($history, 2);

        $deniedprompt = 'course-ai-disabled-prompt-' . $actionname;
        $denied = $this->call_service($methodname, $disabledcontext->id, $deniedprompt);
        $this->assert_refused_without_provider_side_effect($denied, $history, $user->id, $deniedprompt);

        $allowedprompt = 'course-ai-enabled-prompt-' . $actionname;
        $allowed = $this->call_service($methodname, $enabledcontext->id, $allowedprompt);
        $this->assert_provider_received($allowed, $history, $allowedprompt, $enabledcontext->id, $user->id, $actionname);
        $this->assertStringNotContainsString($deniedprompt, $this->request_bodies($history));
    }

    /**
     * An activity that has not enabled the action must not run it, even when the course has.
     *
     * @dataProvider service_provider
     * @param string $methodname External function name.
     * @param string $actionname Action basename.
     */
    public function test_module_action_opt_out_is_enforced_for_that_action(
        string $methodname,
        string $actionname,
    ): void {
        $this->resetAfterTest();
        $this->enable_placement_and_provider();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $otheraction = $actionname === 'summarise_text' ? 'explain_text' : 'summarise_text';
        $module = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'enabledaiactions' => json_encode([
                $actionname => 0,
                $otheraction => 1,
            ]),
        ]);
        $modulecontext = \context_module::instance($module->cmid);
        $othermethod = $otheraction === 'summarise_text'
            ? 'aiplacement_courseassist_summarise_text'
            : 'aiplacement_courseassist_explain_text';

        $this->setUser($user);
        $history = [];
        $this->queue_provider_responses($history, 2);

        $deniedprompt = 'module-action-disabled-prompt-' . $actionname;
        $denied = $this->call_service($methodname, $modulecontext->id, $deniedprompt);
        $this->assert_refused_without_provider_side_effect($denied, $history, $user->id, $deniedprompt);

        $allowedprompt = 'module-action-enabled-prompt-' . $otheraction;
        $allowed = $this->call_service($othermethod, $modulecontext->id, $allowedprompt);
        $this->assert_provider_received(
            $allowed,
            $history,
            $allowedprompt,
            $modulecontext->id,
            $user->id,
            $otheraction,
        );
        $this->assertStringNotContainsString($deniedprompt, $this->request_bodies($history));
    }

    /**
     * Enable the placement and an OpenAI provider instance that will accept both actions.
     *
     * @return \core_ai\manager
     */
    private function enable_placement_and_provider(): \core_ai\manager {
        set_config('enabled', 1, 'aiplacement_courseassist');

        $manager = \core\di::get(\core_ai\manager::class);
        $settings = [
            'model' => 'gpt-4o',
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
            'systeminstruction' => 'Follow the user prompt.',
        ];
        $manager->create_provider_instance(
            classname: \aiprovider_openai\provider::class,
            name: 'courseassist-authorization-test',
            enabled: true,
            config: [
                'apikey' => 'placeholder',
                'enableuserratelimit' => false,
                'enableglobalratelimit' => false,
            ],
            actionconfig: [
                \core_ai\aiactions\summarise_text::class => [
                    'enabled' => true,
                    'settings' => $settings,
                ],
                \core_ai\aiactions\explain_text::class => [
                    'enabled' => true,
                    'settings' => $settings,
                ],
            ],
        );

        return $manager;
    }

    /**
     * Queue successful provider responses and record every outbound request.
     *
     * The history array must be the caller's variable. Guzzle's history middleware
     * keeps a reference to it, and returning that array by value would detach the reference.
     *
     * @param array $history History filled by the HTTP client.
     * @param int $count Number of responses to queue.
     */
    private function queue_provider_responses(array &$history, int $count): void {
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $body = json_encode([
            'id' => 'chatcmpl-test',
            'system_fingerprint' => 'fp_test',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => self::GENERATED],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 5],
            'model' => 'gpt-4o',
        ]);
        for ($i = 0; $i < $count; $i++) {
            $mock->append(new Response(200, ['Content-Type' => 'application/json'], $body));
        }
    }

    /**
     * Call the service the way public/lib/ajax/service.php does.
     *
     * @param string $methodname External function name.
     * @param int $contextid Context supplied by the caller.
     * @param string $prompttext Prompt supplied by the caller.
     * @return array
     */
    private function call_service(string $methodname, int $contextid, string $prompttext): array {
        $_POST['sesskey'] = sesskey();
        return \core_external\external_api::call_external_function($methodname, [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
        ], true);
    }

    /**
     * The service must throw before the provider is contacted or any action row is stored.
     *
     * @param array $result Return value of call_external_function().
     * @param array $history HTTP history.
     * @param int $userid User who made the call.
     * @param string $prompt Prompt that must not have been stored or sent.
     */
    private function assert_refused_without_provider_side_effect(
        array $result,
        array $history,
        int $userid,
        string $prompt,
    ): void {
        global $DB;

        $this->assertTrue(
            $result['error'],
            'The service must refuse this caller. Got: ' . $this->describe_result($result),
        );
        $this->assertSame([], $history, 'The provider must not be contacted for a refused call.');
        $this->assertSame(0, $this->count_stored_prompts('ai_action_summarise_text', $prompt));
        $this->assertSame(0, $this->count_stored_prompts('ai_action_explain_text', $prompt));
        $this->assertSame(0, $DB->count_records('ai_action_register', ['userid' => $userid]));
    }

    /**
     * Count stored action rows whose prompt text matches.
     *
     * The prompt column is TEXT, which this database refuses to compare with a plain equality condition.
     *
     * @param string $table Action table name.
     * @param string $prompt Prompt text.
     * @return int
     */
    private function count_stored_prompts(string $table, string $prompt): int {
        global $DB;

        $sql = "SELECT COUNT(1)
                  FROM {{$table}}
                 WHERE " . $DB->sql_compare_text('prompt') . " = " . $DB->sql_compare_text(':prompt');
        return $DB->count_records_sql($sql, ['prompt' => $prompt]);
    }

    /**
     * The provider must have been called with this prompt, and the stored action must use this context.
     *
     * @param array $result Return value of call_external_function().
     * @param array $history HTTP history.
     * @param string $prompt Prompt that must appear in the provider request.
     * @param int $contextid Context that must be stored with the action.
     * @param int $userid User who made the call.
     * @param string $actionname Action basename that must be stored.
     */
    private function assert_provider_received(
        array $result,
        array $history,
        string $prompt,
        int $contextid,
        int $userid,
        string $actionname,
    ): void {
        global $DB;

        $this->assertFalse($result['error'], 'An authorized call must succeed. Got: ' . $this->describe_result($result));
        $this->assertTrue($result['data']['success']);
        $this->assertEquals(self::GENERATED, $result['data']['generatedcontent']);
        $this->assertNotEmpty($history, 'The authorized call must reach the provider.');

        $bodies = $this->request_bodies($history);
        $this->assertStringContainsString($prompt, $bodies);
        $sent = $history[array_key_last($history)]['request']->getBody();
        $sent->rewind();
        $decoded = json_decode($sent->getContents());
        $usermessages = array_values(array_filter(
            $decoded->messages,
            static function (\stdClass $message): bool {
                return $message->role === 'user';
            },
        ));
        $this->assertCount(1, $usermessages);
        $this->assertSame($prompt, $usermessages[0]->content);

        $register = $DB->get_record('ai_action_register', [
            'userid' => $userid,
            'contextid' => $contextid,
            'actionname' => $actionname,
        ], '*', MUST_EXIST);
        $this->assertEquals($contextid, $register->contextid);
        $this->assertSame(1, $this->count_stored_prompts('ai_action_' . $actionname, $prompt));
    }

    /**
     * Concatenate every recorded provider request body.
     *
     * @param array $history HTTP history.
     * @return string
     */
    private function request_bodies(array $history): string {
        $bodies = '';
        foreach ($history as $entry) {
            $entry['request']->getBody()->rewind();
            $bodies .= $entry['request']->getBody()->getContents();
        }
        return $bodies;
    }

    /**
     * Describe an external-function result for assertion messages.
     *
     * @param array $result Return value of call_external_function().
     * @return string
     */
    private function describe_result(array $result): string {
        if (!empty($result['exception'])) {
            $exception = $result['exception'];
            return ($exception->errorcode ?? '') . ' ' . ($exception->message ?? '');
        }
        if (isset($result['data'])) {
            return json_encode($result['data']);
        }
        return 'empty result';
    }
}
