<?php

namespace App\Migration\Core;

/** Disk-backed preflight: project groups can cross any source page boundary. */
final class TimesheetsFactStage
{
    private \PDO $db;
    private string $path;
    private \PDOStatement $insert;

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'timesheets-stage-');
        $this->db = new \PDO('sqlite:'.$this->path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE facts (source_id TEXT PRIMARY KEY, member_id INTEGER, work_date TEXT, employee_id INTEGER, partner_id INTEGER, term_id INTEGER, client_id INTEGER, cents INTEGER, source_json TEXT)');
        $this->db->exec('CREATE INDEX fact_group ON facts(member_id,work_date,source_id)');
        $this->insert = $this->db->prepare('INSERT INTO facts VALUES (?,?,?,?,?,?,?,?,?)');
        $this->db->beginTransaction();
    }

    public function add(string $id, array $row, array $rate, string $date, int $term, int $cents): void
    {
        $this->insert->execute([$id, $rate['client_member_id'], $date, $rate['employee_id'],
            $rate['partner_specialist_id'], $term, $rate['client_id'], $cents,
            json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }

    public function dailyTotals(): iterable
    {
        $q = $this->db->query("SELECT CASE WHEN employee_id IS NOT NULL THEN 'employee:' || employee_id ELSE 'partner:' || partner_id END || ':' || work_date AS day_key, SUM(cents) AS cents FROM facts GROUP BY day_key");
        while ($row = $q->fetch(\PDO::FETCH_ASSOC)) yield $row['day_key'] => (int) $row['cents'];
    }

    public function groups(): iterable
    {
        $q = $this->db->query('SELECT member_id AS id, work_date AS date, employee_id, partner_id AS partner_specialist_id, term_id, client_id, COUNT(DISTINCT term_id) AS term_variants, SUM(cents) AS cents FROM facts GROUP BY member_id,work_date ORDER BY member_id,work_date');
        $sources = $this->db->prepare('SELECT source_id,source_json FROM facts WHERE member_id=? AND work_date=? ORDER BY source_id');
        while ($group = $q->fetch(\PDO::FETCH_ASSOC)) {
            if ((int) $group['term_variants'] !== 1) {
                throw new SourceRowConflict('FACT_GROUP_TERM_CONFLICT',
                    'Одна дневная запись ClientMember содержит часы из разных версий коммерческих условий; требуется ручная сверка.',
                    ['client_member_id' => (int) $group['id'], 'date' => $group['date']]);
            }
            unset($group['term_variants']);
            $sources->execute([$group['id'], $group['date']]);
            $group['source'] = [];
            while ($source = $sources->fetch(\PDO::FETCH_ASSOC)) $group['source'][] = [
                'id' => $source['source_id'], 'row' => json_decode($source['source_json'], true, 512, JSON_THROW_ON_ERROR),
            ];
            yield $group;
        }
    }

    public function __destruct()
    {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollBack();
        if (isset($this->path)) @unlink($this->path);
    }
}
