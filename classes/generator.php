<?php
// Talks to the external generation service and imports the validated XML into the question bank.
namespace local_stackforge;

defined('MOODLE_INTERNAL') || die();

class generator {

    /**
     * Ask the generation service for validated STACK questions.
     *
     * @return array list of ['name','type','difficulty','source','xml'] (already oracle-validated)
     * @throws \moodle_exception
     */
    public static function generate(string $type, string $difficulty, int $count): array {
        $base = rtrim((string)get_config('local_stackforge', 'serviceurl'), '/');
        $token = (string)get_config('local_stackforge', 'apitoken');
        if ($base === '') {
            throw new \moodle_exception('notconfigured', 'local_stackforge');
        }
        $payload = json_encode(['type' => $type, 'difficulty' => $difficulty, 'count' => $count]);

        // ignoresecurity: the service URL is an admin-configured, trusted endpoint (often an
        // internal address like http://generate:8092), so bypass Moodle's block on private hosts
        // for this one call. It is never user-controlled.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        $resp = $curl->post($base . '/generate', $payload, [
            'CURLOPT_TIMEOUT' => 180,
            'CURLOPT_IPRESOLVE' => CURL_IPRESOLVE_V4,   // Moodle container has no IPv6 egress
        ]);
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        if ($code !== 200) {
            throw new \moodle_exception('servicefail', 'local_stackforge', '',
                $code . ' ' . substr((string)$resp, 0, 200));
        }
        $data = json_decode((string)$resp, true);
        return (is_array($data) && !empty($data['questions'])) ? $data['questions'] : [];
    }

    /**
     * Import one <quiz>…</quiz> STACK XML into a question category. Returns true on success.
     *
     * Uses Moodle's standard XML question import, so the questions land in the bank exactly as a
     * manual "Import" would — fully usable in quizzes, including the STACK question type.
     */
    public static function import_one(string $xml, \stdClass $category, \context $context, \stdClass $course): bool {
        global $CFG;
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $tmp = make_request_directory() . '/stackforge.xml';
        file_put_contents($tmp, $xml);

        $qformat = new \qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);
        $qformat->setCourse($course);
        $qformat->setFilename($tmp);
        $qformat->setRealfilename('stackforge.xml');
        $qformat->setMatchgrades('error');
        $qformat->setCatfromfile(false);       // import into the chosen category, not one named in the file
        $qformat->setContextfromfile(false);
        $qformat->setStoponerror(true);

        ob_start();   // importprocess() echoes progress HTML; swallow it
        try {
            $ok = $qformat->importpreprocess()
                && $qformat->importprocess()
                && $qformat->importpostprocess();
        } catch (\Throwable $e) {
            $ok = false;
        }
        ob_end_clean();
        return (bool)$ok;
    }
}
