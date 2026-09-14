<?php
namespace Stanford\GiftcardReward;

use Project;
use REDCap;
use Exception;

/**
 * Finding and repairing Gift Card Library rows that were issued before the reservation-time write
 * existed -- rows with a `status` of Reserved/Claimed but no `reward_email_addr`, and no `url`.
 *
 * Both the Control Center page (pages/BackfillEmailAddr.php) and the CLI script
 * (scripts/backfill-reward-email-addr.php) run through here, so there is one definition of what
 * "affected" means and one definition of what gets written.
 *
 * The repair is possible at all because the library row still records which project record it was
 * reserved for (`reward_record`), and the module configuration still records which field on that
 * record holds the address (`reward-email`) -- the same two things reserveReward() uses.
 *
 * Rules, deliberately conservative and enforced here rather than at the call sites:
 *   - only ever fill a BLANK field, never overwrite one. A claimed reward may carry the address the
 *     participant typed on the claim page, which is more specific than the one on the record.
 *   - only touch library rows whose `reward_pid` is the project being repaired. A gift card library
 *     is commonly shared between studies.
 *   - only fill `url` where `egift_number` is itself a link, because that is the one case where
 *     what the participant received is known with certainty. For the claim-link flow the URL
 *     carried a one-time hash that was never stored, and inventing one would be a lie.
 */
class BackfillEmailAddr
{
    /** The library field this class exists to repair. */
    const EMAIL_FIELD = 'reward_email_addr';

    /** Library `status` values meaning a reward actually went out. 1 = Ready, 2 = Reserved, 3 = Claimed. */
    const ISSUED_STATUSES = ['2', '3'];

    /** @var GiftcardReward */
    private $module;

    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * Every project with this module enabled, paired with the library it points at.
     *
     * @return array<int,int> project pid => library pid (libraries of 0 mean "not configured")
     */
    public function projectsAndLibraries(): array
    {
        $out = [];
        foreach ($this->module->getProjectsWithModuleEnabled() as $pid) {
            $out[(int) $pid] = (int) $this->module->getProjectSetting('gcr-pid', $pid);
        }

        return $out;
    }

    /**
     * A server-wide picture, cheaply.
     *
     * One query per DISTINCT LIBRARY, grouped by `reward_pid` -- not one query per project. Several
     * studies commonly share a single library, so a per-project loop would scan the same table
     * repeatedly and still have to filter by reward_pid inside it. Grouping by reward_pid gets
     * every study's counts out of one pass and makes the shared-library case ordinary rather than
     * special.
     *
     * @return array{rows: array<int,array>, libraries: array<int,array>}
     *         rows: project pid => [library, issued, missing_email, missing_url, has_field]
     *         libraries: library pid => reward_pid => issued count (including reward_pids that
     *                    belong to no project on this server, which is the tell for a copied project)
     */
    public function overview(): array
    {
        $projects  = $this->projectsAndLibraries();
        $byLibrary = [];
        foreach ($projects as $pid => $libPid) {
            if ($libPid > 0) {
                $byLibrary[$libPid][] = $pid;
            }
        }

        $rows      = [];
        $libraries = [];

        foreach ($byLibrary as $libPid => $pids) {
            if (!$this->libraryExists((int) $libPid)) {
                foreach ($pids as $pid) {
                    $rows[$pid] = ['library' => $libPid, 'error' => "Library project $libPid does not exist on this server"];
                }
                continue;
            }
            try {
                $counts  = $this->libraryCountsByRewardPid($libPid, (int) reset($pids));
                $hasFld  = $this->libraryHasEmailField($libPid);
            } catch (Exception $ex) {
                $this->module->emError("Backfill overview: cannot read library $libPid: " . $ex->getMessage());
                foreach ($pids as $pid) {
                    $rows[$pid] = ['library' => $libPid, 'error' => $ex->getMessage()];
                }
                continue;
            }

            $libraries[$libPid] = $counts;

            foreach ($pids as $pid) {
                $c = $counts[(string) $pid] ?? ['issued' => 0, 'missing_email' => 0, 'missing_url' => 0];
                $rows[$pid] = [
                    'library'       => $libPid,
                    'issued'        => (int) $c['issued'],
                    'missing_email' => (int) $c['missing_email'],
                    'missing_url'   => (int) $c['missing_url'],
                    'has_field'     => $hasFld,
                ];
            }
        }

        // Projects with the module on but no library configured.
        foreach ($projects as $pid => $libPid) {
            if ($libPid <= 0) {
                $rows[$pid] = ['library' => 0, 'error' => 'No Gift Card Library configured'];
            }
        }

        ksort($rows);

        return ['rows' => $rows, 'libraries' => $libraries];
    }

    /**
     * Issued-row counts in one library, broken down by the `reward_pid` stored on each row.
     *
     * The breakdown matters as much as the totals. If a library holds issued rows whose reward_pid
     * is not any project on this server, that library almost certainly came from a restored copy --
     * copying renumbers the project, but reward_pid is ordinary field data and keeps pointing at
     * the original. Without this the repair silently finds nothing and looks like it worked.
     *
     * @return array<string,array{issued:int,missing_email:int,missing_url:int}> keyed by reward_pid
     */
    public function libraryCountsByRewardPid(int $libPid, int $forPid): array
    {
        $eventId = $this->libraryEventId($libPid, $forPid);
        $tbl     = REDCap::getDataTable($libPid);

        // $tbl comes from REDCap's own sharding map, never from user input.
        $sql = "SELECT p.value AS reward_pid,
                       COUNT(*) AS issued,
                       SUM(CASE WHEN e.value IS NULL OR e.value = '' THEN 1 ELSE 0 END) AS missing_email,
                       SUM(CASE WHEN (u.value IS NULL OR u.value = '')
                                 AND g.value LIKE 'http%' THEN 1 ELSE 0 END) AS missing_url
                  FROM $tbl p
                  JOIN $tbl s ON s.project_id = p.project_id AND s.record = p.record
                             AND s.event_id = p.event_id AND s.field_name = 'status'
             LEFT JOIN $tbl e ON e.project_id = p.project_id AND e.record = p.record
                             AND e.event_id = p.event_id AND e.field_name = ?
             LEFT JOIN $tbl u ON u.project_id = p.project_id AND u.record = p.record
                             AND u.event_id = p.event_id AND u.field_name = 'url'
             LEFT JOIN $tbl g ON g.project_id = p.project_id AND g.record = p.record
                             AND g.event_id = p.event_id AND g.field_name = 'egift_number'
                 WHERE p.project_id = ? AND p.event_id = ? AND p.field_name = 'reward_pid'
                   AND s.value IN ('" . implode("','", self::ISSUED_STATUSES) . "')
              GROUP BY p.value";

        $q   = $this->module->query($sql, [self::EMAIL_FIELD, $libPid, $eventId]);
        $out = [];
        while ($r = $q->fetch_assoc()) {
            $out[(string) $r['reward_pid']] = [
                'issued'        => (int) $r['issued'],
                'missing_email' => (int) $r['missing_email'],
                'missing_url'   => (int) $r['missing_url'],
            ];
        }

        return $out;
    }

    public function libraryHasEmailField(int $libPid): bool
    {
        $proj = new Project($libPid);

        return !empty($proj->metadata[self::EMAIL_FIELD]);
    }

    /**
     * Does the configured library project actually exist?
     *
     * Worth distinguishing from "exists but has no reward_email_addr field": a `gcr-pid` pointing
     * at a project id that is not on this server is the signature of a restored or partially
     * copied project, and reporting it as a missing field sends the admin looking in the wrong
     * place entirely.
     */
    public function libraryExists(int $libPid): bool
    {
        $q = $this->module->query('SELECT project_id FROM redcap_projects WHERE project_id = ?', [$libPid]);

        return $q->fetch_assoc() !== null;
    }

    /**
     * The library event the gift card form lives in, resolved exactly as RewardInstance does --
     * the configured 'gcr-event-id', or the only event when the library is classic.
     */
    public function libraryEventId(int $libPid, int $forPid): int
    {
        $proj = new Project($libPid);

        return (int) $this->module->checkGiftCardLibEventId($proj, $this->module->getProjectSetting('gcr-event-id', $forPid));
    }

    /**
     * Work out exactly what would be written for one project, without writing anything.
     *
     * @param int      $pid       the gift card PROJECT
     * @param int|null $rewardPid which `reward_pid` in the library identifies this project's rows;
     *                            defaults to $pid, which is right on the server that issued them
     * @return array{library:int,event:int,reward_pid:int,emails:array<string,string>,
     *               urls:array<string,string>,skipped:array<string,string>,error:?string}
     */
    public function scanProject(int $pid, ?int $rewardPid = null): array
    {
        $rewardPid = $rewardPid ?? $pid;
        $plan = [
            'pid'        => $pid,
            'library'    => 0,
            'event'      => 0,
            'reward_pid' => $rewardPid,
            'emails'     => [],
            'urls'       => [],
            'skipped'    => [],
            'error'      => null,
        ];

        $libPid = (int) $this->module->getProjectSetting('gcr-pid', $pid);
        if ($libPid <= 0) {
            $plan['error'] = "Project $pid has no Gift Card Library configured.";
            return $plan;
        }
        $plan['library'] = $libPid;

        if (!$this->libraryExists($libPid)) {
            $plan['error'] = "Library project $libPid does not exist on this server. Check the "
                . "\"GCR Project ID\" setting in this project's module configuration.";
            return $plan;
        }

        try {
            $libProj = new Project($libPid);
            if (empty($libProj->metadata[self::EMAIL_FIELD])) {
                $plan['error'] = "Library project $libPid has no '" . self::EMAIL_FIELD . "' field, so there is nothing to backfill. "
                    . "Add the field to the library's gift card form first.";
                return $plan;
            }
            $eventId = $this->libraryEventId($libPid, $pid);
        } catch (Exception $ex) {
            $plan['error'] = "Cannot read library project $libPid: " . $ex->getMessage();
            return $plan;
        }
        $plan['event'] = $eventId;

        // Which field holds the email address, per reward configuration title.
        //
        // Deliberately NOT verifyConfig(): that evaluates the reward logic against a record and
        // logs an error for every misconfigured project, which on a server-wide scan from a
        // read-only page would be a log flood. The title -> field map plus a "no configuration
        // titled X" skip reason covers what this needs.
        $emailFieldByTitle = [];
        foreach ($this->module->getSubSettings('rewards', $pid) as $cfg) {
            if (!empty($cfg['reward-title']) && !empty($cfg['reward-email'])) {
                $emailFieldByTitle[$cfg['reward-title']] = $cfg['reward-email'];
            }
        }

        $lib = REDCap::getData(
            $libPid,
            'array',
            null,
            ['reward_pid', 'reward_name', 'reward_record', self::EMAIL_FIELD, 'url', 'egift_number', 'status'],
            $eventId
        );

        // Pass one: decide what each row needs, and collect the project records to look up.
        $wanted  = [];                      // email field => [project record, ...]
        $pending = [];                      // library record => [projRecord, field]
        foreach ($lib as $libRecord => $events) {
            $row = $events[$eventId] ?? [];
            if ((string) ($row['reward_pid'] ?? '') !== (string) $rewardPid) {
                continue;
            }
            if (!in_array((string) ($row['status'] ?? ''), self::ISSUED_STATUSES, true)) {
                continue;
            }

            $egift = (string) ($row['egift_number'] ?? '');
            if ((string) ($row['url'] ?? '') === '' && strpos($egift, 'http') === 0) {
                $plan['urls'][(string) $libRecord] = $egift;
            }

            if ((string) ($row[self::EMAIL_FIELD] ?? '') !== '') {
                continue;                                     // already recorded; never overwrite
            }

            $projRecord = (string) ($row['reward_record'] ?? '');
            $title      = (string) ($row['reward_name'] ?? '');
            if ($projRecord === '') {
                $plan['skipped'][(string) $libRecord] = 'no reward_record to look the address up on';
                continue;
            }
            if (!isset($emailFieldByTitle[$title])) {
                $plan['skipped'][(string) $libRecord] = "no current configuration titled \"$title\"";
                continue;
            }

            $field = $emailFieldByTitle[$title];
            $wanted[$field][$projRecord] = $projRecord;
            $pending[(string) $libRecord] = [$projRecord, $field];
        }

        // Pass two: ONE getData per email field, not one per library row.
        $values = [];
        foreach ($wanted as $field => $records) {
            $eventForField = $this->emailEventId($pid, $field);
            if ($eventForField === null) {
                $values[$field] = null;                       // unresolvable; reported per row below
                continue;
            }
            $d = REDCap::getData($pid, 'array', array_values($records), [$field], [$eventForField]);
            foreach ($records as $rec) {
                $values[$field][(string) $rec] = (string) ($d[$rec][$eventForField][$field] ?? '');
            }
        }

        // Pass three: turn the lookups into a plan.
        foreach ($pending as $libRecord => [$projRecord, $field]) {
            if ($values[$field] === null) {
                $plan['skipped'][$libRecord] = "cannot locate the event holding $field";
                continue;
            }
            $email = $values[$field][(string) $projRecord] ?? '';
            if ($email === '') {
                $plan['skipped'][$libRecord] = "record $projRecord has no value in $field";
                continue;
            }
            $plan['emails'][$libRecord] = $email;
        }

        return $plan;
    }

    /**
     * Write a plan produced by scanProject().
     *
     * @return array{written:int,errors:array}
     */
    public function apply(array $plan): array
    {
        if (!empty($plan['error']) || ($plan['emails'] === [] && $plan['urls'] === [])) {
            return ['written' => 0, 'errors' => []];
        }

        $data = [];
        foreach ($plan['emails'] as $libRecord => $email) {
            $data[$libRecord][$plan['event']][self::EMAIL_FIELD] = $email;
        }
        foreach ($plan['urls'] as $libRecord => $url) {
            $data[$libRecord][$plan['event']]['url'] = $url;
        }

        // 'normal', not 'overwrite': this must never blank a field it did not plan to set.
        $res = REDCap::saveData($plan['library'], 'array', $data, 'normal');
        if (!empty($res['errors'])) {
            $this->module->emError("Backfill of library " . $plan['library'] . " reported errors", json_encode($res['errors']));
            return ['written' => 0, 'errors' => (array) $res['errors']];
        }

        // Counts only -- the values are participant email addresses and do not belong in a log.
        REDCap::logEvent(
            'Gift Card Reward: backfilled ' . self::EMAIL_FIELD . '/url',
            count($plan['emails']) . ' email address(es) and ' . count($plan['urls'])
                . ' url(s) filled for project ' . $plan['pid'],
            '',
            null,
            null,
            $plan['library']
        );

        return ['written' => count($res['ids'] ?? []), 'errors' => []];
    }

    /**
     * The event holding a field on the gift card project, resolved as RewardInstance does: the
     * first event carrying the form the field is on.
     */
    private function emailEventId(int $pid, string $field): ?int
    {
        $proj = new Project($pid);
        $form = $proj->metadata[$field]['form_name'] ?? null;
        if ($form === null) {
            return null;
        }
        foreach ($proj->eventsForms as $eventId => $forms) {
            if (in_array($form, $forms, true)) {
                return (int) $eventId;
            }
        }

        return null;
    }
}
