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

namespace core_question\route\api;

use core\context\module;
use core\router\route_loader_interface;
use core\tests\router\route_testcase;
use Psr\Http\Message\ResponseInterface;

/**
 * Authorization tests for GET /api/rest/v2/question/categories.
 *
 * The route must refuse category names, descriptions, idnumbers, question counts
 * and the module name unless the caller may see that same module's question bank.
 * These tests fail while that check is missing.
 *
 * @package   core_question
 * @copyright 2026 onwards Moodle
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \core_question\route\api\bank
 */
final class bank_categories_authorization_test extends route_testcase {
    /**
     * Question-bank capabilities named by the categories route remediation.
     * Assigned together so a fix that requires any one of them still allows the holder.
     */
    private const QUESTION_BANK_CAPS = [
        'moodle/question:managecategory',
        'moodle/question:add',
        'moodle/question:editmine',
        'moodle/question:editall',
        'moodle/question:viewmine',
        'moodle/question:viewall',
        'moodle/question:usemine',
        'moodle/question:useall',
        'moodle/question:movemine',
        'moodle/question:moveall',
    ];

    /**
     * Register the question bank API routes.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->add_class_routes_to_route_loader(
            bank::class,
            route_loader_interface::ROUTE_GROUP_API,
        );
    }

    /**
     * An authenticated user with no enrolment cannot read a quiz bank by cmid.
     */
    public function test_outsider_cannot_read_quiz_categories_by_cmid(): void {
        $bank = $this->create_secret_bank('quiz');
        $this->setUser(self::getDataGenerator()->create_user());

        $response = $this->request_categories((string) $bank->cmid);
        $this->assert_bank_withheld($response, $bank);
    }

    /**
     * An authenticated user with no enrolment cannot read a question bank activity by cmid.
     */
    public function test_outsider_cannot_read_qbank_categories_by_cmid(): void {
        $bank = $this->create_secret_bank('qbank');
        $this->setUser(self::getDataGenerator()->create_user());

        $response = $this->request_categories((string) $bank->cmid);
        $this->assert_bank_withheld($response, $bank);
    }

    /**
     * The idnumber form of the course-module parameter is the same unprotected lookup.
     */
    public function test_outsider_cannot_read_categories_by_idnumber(): void {
        $bank = $this->create_secret_bank('quiz');
        $this->setUser(self::getDataGenerator()->create_user());

        $response = $this->request_categories('idnumber:' . $bank->cmidnumber);
        $this->assert_bank_withheld($response, $bank);
    }

    /**
     * A student enrolled in the course still has no question capability on the module.
     */
    public function test_enrolled_student_cannot_read_visible_quiz_categories(): void {
        $bank = $this->create_secret_bank('quiz');
        $student = self::getDataGenerator()->create_user();
        self::getDataGenerator()->enrol_user($student->id, $bank->courseid, 'student');
        $this->setUser($student);

        $response = $this->request_categories((string) $bank->cmid);
        $this->assert_bank_withheld($response, $bank);
    }

    /**
     * A hidden course is not a barrier when the route never loads that course for login.
     */
    public function test_enrolled_student_cannot_read_hidden_course_categories(): void {
        $bank = $this->create_secret_bank('quiz', ['visible' => 0]);
        $student = self::getDataGenerator()->create_user();
        self::getDataGenerator()->enrol_user($student->id, $bank->courseid, 'student');
        $this->setUser($student);

        $response = $this->request_categories((string) $bank->cmid);
        $this->assert_bank_withheld($response, $bank);
    }

    /**
     * A hidden activity stays hidden from a caller who may use its question bank but cannot see hidden activities.
     */
    public function test_hidden_module_withheld_without_visibility(): void {
        $bank = $this->create_secret_bank('quiz', [], ['visible' => 0]);
        $student = self::getDataGenerator()->create_user();
        self::getDataGenerator()->enrol_user($student->id, $bank->courseid, 'student');
        $this->grant_question_caps($student->id, $bank->contextid);
        $this->setUser($student);

        $response = $this->request_categories((string) $bank->cmid);
        $this->assert_bank_withheld($response, $bank);
    }

    /**
     * An existing guest session is logged in at site level and must not receive category records.
     */
    public function test_guest_cannot_read_categories(): void {
        $bank = $this->create_secret_bank('qbank');
        $this->setGuestUser();

        $response = $this->request_categories((string) $bank->cmid);
        $this->assert_bank_withheld($response, $bank);
    }

    /**
     * Capability on one module must not authorise categories for another module.
     */
    public function test_capability_is_limited_to_the_authorized_module(): void {
        $allowed = $this->create_secret_bank('quiz', [], [], 'allowed');
        $other = $this->create_secret_bank('qbank', [], [], 'other');
        $user = self::getDataGenerator()->create_user();
        self::getDataGenerator()->enrol_user($user->id, $allowed->courseid, 'student');
        self::getDataGenerator()->enrol_user($user->id, $other->courseid, 'student');
        $this->grant_question_caps($user->id, $allowed->contextid);
        $this->setUser($user);

        $allowedresponse = $this->request_categories((string) $allowed->cmid);
        $this->assert_bank_returned($allowedresponse, $allowed);
        $this->assert_secrets_absent($allowedresponse, $other);

        $otherresponse = $this->request_categories((string) $other->cmid);
        $this->assert_bank_withheld($otherresponse, $other);

        $otheridnumber = $this->request_categories('idnumber:' . $other->cmidnumber);
        $this->assert_bank_withheld($otheridnumber, $other);

        $allowedidnumber = $this->request_categories('idnumber:' . $allowed->cmidnumber);
        $this->assert_bank_returned($allowedidnumber, $allowed);
        $this->assert_secrets_absent($allowedidnumber, $other);
    }

    /**
     * Build a module whose question category carries distinctive bank metadata and one question.
     *
     * @param string $modname quiz or qbank
     * @param array $courseoverrides
     * @param array $moduleoverrides
     * @param string $suffix disambiguates two banks in one test
     * @return \stdClass
     */
    private function create_secret_bank(
        string $modname,
        array $courseoverrides = [],
        array $moduleoverrides = [],
        string $suffix = 'bank',
    ): \stdClass {
        $generator = self::getDataGenerator();
        $course = $generator->create_course($courseoverrides);
        $cmidnumber = 'cm-' . $suffix . '-' . $modname;
        $modulename = 'Module ' . $suffix . ' ' . $modname;
        $module = $generator->create_module($modname, [
            'course' => $course->id,
            'name' => $modulename,
            'idnumber' => $cmidnumber,
        ] + $moduleoverrides);

        $context = module::instance($module->cmid);
        $categoryname = 'Category ' . $suffix . ' ' . $modname;
        $categoryinfo = 'Internal description ' . $suffix . ' ' . $modname;
        $categoryidnumber = 'cat-' . $suffix . '-' . $modname;
        $questionstem = 'Question stem ' . $suffix . ' ' . $modname;

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => $context->id,
            'name' => $categoryname,
            'info' => $categoryinfo,
            'infoformat' => FORMAT_HTML,
            'idnumber' => $categoryidnumber,
        ]);
        $questiongenerator->create_question('truefalse', overrides: [
            'category' => $category->id,
            'questiontext' => $questionstem,
        ]);

        return (object) [
            'courseid' => $course->id,
            'cmid' => $module->cmid,
            'cmidnumber' => $cmidnumber,
            'contextid' => $context->id,
            'modulename' => $modulename,
            'categoryname' => $categoryname,
            'categoryinfo' => $categoryinfo,
            'categoryidnumber' => $categoryidnumber,
            'questionstem' => $questionstem,
        ];
    }

    /**
     * Grant question-bank capabilities in one module context and nowhere else.
     *
     * @param int $userid
     * @param int $contextid
     */
    private function grant_question_caps(int $userid, int $contextid): void {
        $generator = self::getDataGenerator();
        $roleid = $generator->create_role();
        $permissions = array_fill_keys(self::QUESTION_BANK_CAPS, 'allow');
        $generator->create_role_capability($roleid, $permissions, \core\context::instance_by_id($contextid));
        $generator->role_assign($roleid, $userid, $contextid);
    }

    /**
     * Call the categories route the way the attack path does.
     *
     * @param string $coursemodule cmid or idnumber:value
     * @return ResponseInterface
     */
    private function request_categories(string $coursemodule): ResponseInterface {
        return $this->process_api_request('GET', '/categories?coursemodule=' . $coursemodule);
    }

    /**
     * The route must fail closed and must not echo this bank's metadata.
     *
     * @param ResponseInterface $response
     * @param \stdClass $bank
     */
    private function assert_bank_withheld(ResponseInterface $response, \stdClass $bank): void {
        $body = (string) $response->getBody();
        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            "Categories route returned question bank data: {$body}",
        );
        foreach ($this->bank_secrets($bank) as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    /**
     * A response for one module must not include another module's bank metadata.
     *
     * @param ResponseInterface $response
     * @param \stdClass $bank
     */
    private function assert_secrets_absent(ResponseInterface $response, \stdClass $bank): void {
        $body = (string) $response->getBody();
        foreach ($this->bank_secrets($bank) as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    /**
     * A caller allowed to use this module's question bank receives its metadata and not the question text.
     *
     * @param ResponseInterface $response
     * @param \stdClass $bank
     */
    private function assert_bank_returned(ResponseInterface $response, \stdClass $bank): void {
        $this->assert_valid_response($response);
        $payload = $this->decode_response($response, true);
        $this->assertSame($bank->contextid, (int) $payload['context']['id']);
        $this->assertStringContainsString($bank->modulename, (string) $payload['context']['name']);

        $matched = null;
        foreach ($payload['categories'] as $category) {
            if (($category['name'] ?? '') === $bank->categoryname) {
                $matched = $category;
                break;
            }
        }
        $this->assertNotNull($matched, 'Authorised response did not include the module category');
        $this->assertStringContainsString($bank->categoryinfo, (string) $matched['info']);
        $this->assertSame($bank->categoryidnumber, $matched['idnumber']);
        $this->assertGreaterThan(0, (int) $matched['questioncount']);
        $this->assertStringNotContainsString($bank->questionstem, (string) $response->getBody());
    }

    /**
     * Strings that identify the bank structure and must not leave the route.
     *
     * @param \stdClass $bank
     * @return string[]
     */
    private function bank_secrets(\stdClass $bank): array {
        return [
            $bank->modulename,
            $bank->categoryname,
            $bank->categoryinfo,
            $bank->categoryidnumber,
        ];
    }
}
