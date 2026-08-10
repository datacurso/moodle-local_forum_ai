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
 * Stub AI HTTP client shared by the local_forum_ai PHPUnit tests.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_forum_ai;

/**
 * Configurable stand-in for aiprovider_datacurso\httpclient\ai_services_api.
 *
 * Injected through ai_service::set_client_for_testing(). It mirrors the real
 * client's public contract: request(string $method, string $path, array $body
 * = []): ?array. Canned responses can be set per path; every received payload
 * is logged in the public $requests property so tests can assert the exact
 * contract sent to the service. Loaded explicitly with require_once, which
 * works both for a single test file and for the whole component suite.
 */
final class mock_ai_client {
    /** @var array<int, array{method: string, path: string, body: array}> Log of every received request. */
    public array $requests = [];

    /** @var array<string, array|null> Canned responses keyed by request path. */
    private array $responses = [];

    /** @var array|null Response returned when no per-path response is set. */
    private ?array $defaultresponse;

    /** @var \Throwable|null Exception thrown on the next request, simulating a service failure. */
    private ?\Throwable $failure = null;

    /**
     * Constructor.
     *
     * @param array|null $defaultresponse Fallback response for any path.
     */
    public function __construct(?array $defaultresponse = ['reply' => 'Mock AI reply']) {
        $this->defaultresponse = $defaultresponse;
    }

    /**
     * Sets the canned response for a specific service path.
     *
     * @param string $path Request path (e.g. '/forum/chat/v2' or '/forum/grade').
     * @param array|null $response Decoded response the stub will return.
     * @return void
     */
    public function set_response(string $path, ?array $response): void {
        $this->responses[$path] = $response;
    }

    /**
     * Makes every subsequent request throw, simulating a service failure.
     *
     * @param \Throwable $failure Exception to throw.
     * @return void
     */
    public function fail_with(\Throwable $failure): void {
        $this->failure = $failure;
    }

    /**
     * Mirror of ai_services_api::request(): logs the call and returns the canned response.
     *
     * @param string $method HTTP method.
     * @param string $path Service path.
     * @param array $body Request payload.
     * @return array|null Canned response.
     * @throws \Throwable When a failure was armed with fail_with().
     */
    public function request(string $method, string $path, array $body = []): ?array {
        $this->requests[] = [
            'method' => $method,
            'path' => $path,
            'body' => $body,
        ];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if (array_key_exists($path, $this->responses)) {
            return $this->responses[$path];
        }

        return $this->defaultresponse;
    }

    /**
     * Returns the last received request, or null when none was made.
     *
     * @return array|null
     */
    public function last_request(): ?array {
        return $this->requests === [] ? null : end($this->requests);
    }
}
