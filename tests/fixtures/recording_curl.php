<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_forum_ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * cURL double that records the exact bytes the AI provider client would put on the wire.
 *
 * Only the transport is replaced: the provider's real request building (default fields,
 * pseudonymisation, JSON encoding) runs unchanged, so the recorded body is the real one.
 *
 * @package   local_forum_ai
 * @category  test
 * @copyright 2026 Datacurso
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_curl extends \curl {
    /** @var array<int, array{method: string, url: string, body: mixed}> Recorded calls. */
    public array $calls = [];

    /** @var string JSON answered to every call. */
    private string $answer;

    /**
     * Constructor.
     *
     * @param array $answer Decoded answer returned to every call.
     */
    public function __construct(array $answer) {
        parent::__construct();
        $this->answer = json_encode($answer);
    }

    /**
     * Record a call and answer it.
     *
     * @param string $method HTTP method.
     * @param string $url Request URL.
     * @param mixed $body Raw request body.
     * @return string
     */
    private function record(string $method, string $url, $body): string {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
        $this->info = ['http_code' => 200];
        $this->error = '';
        $this->errno = 0;
        return $this->answer;
    }

    #[\Override]
    public function post($url, $params = '', $options = []) {
        return $this->record('POST', (string) $url, $params);
    }

    #[\Override]
    public function get($url, $params = [], $options = []) {
        return $this->record('GET', (string) $url, $params);
    }

    /**
     * The raw JSON body of the last call, exactly as it would be sent.
     *
     * @return string
     */
    public function last_raw_body(): string {
        $call = end($this->calls);
        return $call ? (string) $call['body'] : '';
    }
}
