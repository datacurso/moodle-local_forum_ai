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
     * Returns the HTTP client used to reach the AI service.
     *
     * @param int $tenantid Tenant the request belongs to, 0 when there is no tenant.
     * @return object The injected test client, or a real ai_services_api.
     */
    private static function get_client(int $tenantid = 0): object {
        if (self::$testclient !== null) {
            return self::$testclient;
        }

        // The provider resolves the licence of the tenant of the current user by default; the tenant of the
        // forum is passed explicitly because cron and ad hoc tasks do not run as a user of that tenant.
        return new ai_services_api(...self::get_provider_client_arguments($tenantid));
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
     * Returns the constructor arguments of the provider client for a tenant.
     *
     * @param int $tenantid Tenant the request belongs to, 0 when there is no tenant.
     * @return array Empty when there is no tenant, so the provider keeps its default behaviour.
     */
    public static function get_provider_client_arguments(int $tenantid): array {
        return $tenantid > 0 ? [null, $tenantid] : [];
    }

    /**
     * Send the payload to the external AI service and return its response for post rating individually.
     *
     * @param array $payload Data to send to the AI service.
     * @param int $tenantid Tenant the request belongs to, 0 when there is no tenant.
     * @return array The AI-generated reply.
     * @throws \moodle_exception If an AI switch is off or the request fails.
     */
    public static function call_ai_service(array $payload, int $tenantid = 0): array {
        // Every transfer to the AI provider goes through here, so this is
        // the choke point that honours the site AI switches.
        utils::require_ai_available($tenantid);

        // The payload travels verbatim: the HTTP client sends UTF-8 JSON, so
        // accents and special characters must reach the AI service intact.
        $client = self::get_client($tenantid);
        $response = $client->request('POST', '/forum/chat/v2', $payload);

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
     * @param int $tenantid Tenant the request belongs to, 0 when there is no tenant.
     * @return array The AI-generated reply.
     * @throws \moodle_exception If an AI switch is off or the request fails.
     */
    public static function call_ai_service_global(array $payload, int $tenantid = 0): array {
        utils::require_ai_available($tenantid);

        // The payload travels verbatim: rubric and guide criteria must keep
        // their accents so the AI echoes them exactly as the form shows them.
        $client = self::get_client($tenantid);
        $response = $client->request('POST', '/forum/grade', $payload);

        if (is_array($response)) {
            return $response;
        }

        return [];
    }
}
