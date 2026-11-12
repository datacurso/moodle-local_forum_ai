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

namespace local_forum_ai;

use aiprovider_datacurso\httpclient\ai_services_api;
use local_forum_ai\local;
use local_forum_ai\local\outbound_payload;

/**
 * Class for AI service communication.
 *
 * @package    local_forum_ai
 * @category   event
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_service {
    /** @var object|null Test-only replacement for the AI HTTP client. */
    private static ?object $testclient = null;

    /** @var \Closure|null Test-only factory receiving the provider client constructor arguments. */
    private static ?\Closure $testclientfactory = null;

    /**
     * Injects a stub HTTP client for PHPUnit runs.
     *
     * The stub only needs to expose request(string $method, string $path,
     * array $body = []): ?array, matching ai_services_api. Pass null to
     * restore the real client (do this in tearDown).
     *
     * @param object|null $client Stub client or null to reset.
     * @return void
     * @throws \coding_exception When called outside a PHPUnit run.
     */
    public static function set_client_for_testing(?object $client): void {
        if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            throw new \coding_exception('set_client_for_testing() is only available during PHPUnit runs.');
        }
        self::$testclient = $client;
    }

    /**
     * Injects a factory that builds the HTTP client for PHPUnit runs.
     *
     * The factory receives the constructor arguments the real ai_services_api would get (see
     * get_provider_client_arguments()), so tests can assert which tenant the request is charged to.
     * Pass null to restore the real client (do this in tearDown).
     *
     * @param \Closure|null $factory function(array $arguments): object, or null to reset.
     * @return void
     * @throws \coding_exception When called outside a PHPUnit run.
     */
    public static function set_client_factory_for_testing(?\Closure $factory): void {
        if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            throw new \coding_exception('set_client_factory_for_testing() is only available during PHPUnit runs.');
        }
        self::$testclientfactory = $factory;
    }

    /**
     * Returns the HTTP client used to reach the AI service.
     *
     * The client is bound to the tenant of the user the request is for, the same tenant as the provider switch,
     * so the licence, region and credits of that tenant are used; never the tenant of the course.
     *
     * @param int|null $usertenantid Tenant of the user the request is for, null for the current user.
     * @return object The injected test client, or a real ai_services_api.
     */
    private static function get_client(?int $usertenantid = null): object {
        if (self::$testclient !== null) {
            return self::$testclient;
        }

        $arguments = self::get_provider_client_arguments($usertenantid);
        if (self::$testclientfactory !== null) {
            return (self::$testclientfactory)($arguments);
        }

        // Without a tenant the provider resolves the licence of the tenant of the current user; tasks pass the
        // tenant of the author explicitly because cron does not run as that user.
        return new ai_services_api(...$arguments);
    }

    /**
     * Whether an exception means that the AI licence of the tenant is missing, invalid or not allowed.
     *
     * @param \Throwable $e The exception thrown by the AI call.
     * @return bool
     */
    public static function is_licence_failure(\Throwable $e): bool {
        // Only Moodle Workplace sites record the failure; a plain LMS keeps the failure behaviour it always had.
        if (!local\tenant_context::is_tenancy_available()) {
            return false;
        }
        if (!($e instanceof \moodle_exception) || $e->module !== 'aiprovider_datacurso') {
            return false;
        }

        return in_array($e->errorcode, ['invalidlicensekey', 'license_not_allowed'], true);
    }

    /**
     * Returns the constructor arguments of the provider client for the tenant of a user.
     *
     * @param int|null $usertenantid Tenant of the user the request is for; null for the current user, 0 when
     *                               there is no tenant.
     * @return array Empty without an explicit tenant, so the provider resolves the tenant of the current user.
     */
    public static function get_provider_client_arguments(?int $usertenantid): array {
        return $usertenantid !== null && $usertenantid > 0 ? [null, $usertenantid] : [];
    }

    /**
     * Send the payload to the external AI service and return its response for post rating individually.
     *
     * @param array $payload Data to send to the AI service.
     * @param int $tenantid Tenant of the forum, for the plugin settings only ("Enable AI by default"), 0 when
     *                      there is no tenant.
     * @param int|null $usertenantid Tenant of the user the request is for, null for the current user. It decides
     *                               both the Datacurso AI provider switch and the tenant the call is charged to
     *                               (licence, region, credits).
     * @return array The AI-generated reply.
     * @throws \moodle_exception If an AI switch is off or the request fails.
     */
    public static function call_ai_service(array $payload, int $tenantid = 0, ?int $usertenantid = null): array {
        // Every transfer to the AI provider goes through here, so this is
        // the choke point that honours the site AI switches.
        utils::require_ai_available($tenantid, $usertenantid);

        // FAI-PRIV-001-R1: only allowlisted fields leave the site, without Moodle ids and with
        // the shared user pseudonym. Texts keep their accents: the client sends UTF-8 JSON.
        $client = self::get_client($usertenantid);
        $response = $client->request('POST', '/forum/chat/v2', outbound_payload::for_chat($payload));

        return self::format_chat_response($response);
    }

    /**
     * Map the raw chat service response to the plugin result shape.
     *
     * A missing grade stays null — it must never default to zero, because
     * the tasks apply any non-null grade as a real rating and a service
     * failure would otherwise land as an unfair zero in the student record.
     * An explicit zero returned by the service is a legitimate grade.
     *
     * @param array|null $response Decoded service response.
     * @return array Keys: reply (?string), grade (?int).
     */
    public static function format_chat_response(?array $response): array {
        return [
            'reply' => $response['reply'] ?? null,
            'grade' => $response['grade'] ?? null,
        ];
    }

    /**
     * Send the payload to the external AI service and return its response for rating all of the user's posts.
     *
     * @param array $payload Data to send to the AI service.
     * @param int $tenantid Tenant of the forum, for the plugin settings only ("Enable AI by default"), 0 when
     *                      there is no tenant.
     * @param int|null $usertenantid Tenant of the user the request is for, null for the current user. It decides
     *                               both the Datacurso AI provider switch and the tenant the call is charged to
     *                               (licence, region, credits).
     * @return array The AI-generated reply.
     * @throws \moodle_exception If an AI switch is off or the request fails.
     */
    public static function call_ai_service_global(array $payload, int $tenantid = 0, ?int $usertenantid = null): array {
        utils::require_ai_available($tenantid, $usertenantid);

        // FAI-PRIV-001-R1: only allowlisted fields leave the site, without Moodle ids and with
        // the shared user pseudonym. Rubric and guide criteria travel verbatim, so the AI
        // echoes them exactly as the form shows them.
        $client = self::get_client($usertenantid);
        $response = $client->request('POST', '/forum/grade', outbound_payload::for_grade($payload));

        if (is_array($response)) {
            return $response;
        }

        return [];
    }
}
