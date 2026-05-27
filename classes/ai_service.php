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
use local_forum_ai\utils;

/**
 * Class for AI service communication.
 *
 * @package    local_forum_ai
 * @category   event
 * @copyright  2025 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_service {
    /**
     * Send the payload to the external AI service and return its response for post rating individually.
     *
     * @param array $payload Data to send to the AI service.
     * @return array The AI-generated reply.
     * @throws \moodle_exception If the request fails.
     */
    public static function call_ai_service(array $payload): array {
        $payload = utils::normalize_payload($payload);

        $client = new ai_services_api();
        $response = $client->request('POST', '/forum/chat/v2', $payload);

        return [
            'reply' => $response['reply'] ?? null,
            'grade' => $response['grade'] ?? 0,
        ];
    }

    /**
     * Send the payload to the external AI service and return its response for rating all of the user's posts.
     *
     * @param array $payload Data to send to the AI service.
     * @return array The AI-generated reply.
     * @throws \moodle_exception If the request fails.
     */
    public static function call_ai_service_global(array $payload): array {
        $payload = utils::normalize_payload($payload);

        $client = new ai_services_api();
        $response = $client->request('POST', '/forum/grade', $payload);

        if (is_array($response)) {
            return $response;
        }

        return [];
    }
}
