<?php
namespace mundophpbb\membermedals\service;

use phpbb\db\driver\driver_interface;

class rules_manager
{
    protected driver_interface $db;
    protected string $medals_table;
    protected string $rules_table;
    protected string $awards_table;
    protected grant_manager $grant_manager;

    public function __construct(
        driver_interface $db,
        string $medals_table,
        string $rules_table,
        string $awards_table,
        grant_manager $grant_manager
    ) {
        $this->db = $db;
        $this->medals_table = $medals_table;
        $this->rules_table = $rules_table;
        $this->awards_table = $awards_table;
        $this->grant_manager = $grant_manager;
    }

    public function get_all_rules(): array
    {
        return $this->get_rules_for_acp();
    }

    public function get_rules_for_acp(array $filters = []): array
    {
        $where = $this->build_rule_filter_where($filters);

        $sql = 'SELECT r.*, m.medal_name, m.medal_image, COUNT(DISTINCT a.award_id) AS award_count
            FROM ' . $this->rules_table . ' r
            LEFT JOIN ' . $this->medals_table . ' m ON m.medal_id = r.medal_id
            LEFT JOIN ' . $this->awards_table . ' a ON a.rule_id = r.rule_id';

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' GROUP BY r.rule_id, m.medal_name, m.medal_image
            ORDER BY r.rule_enabled DESC, r.rule_type ASC, r.rule_value + 0 ASC, r.rule_id ASC';

        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $row['award_count'] = (int) ($row['award_count'] ?? 0);
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    public function get_rule_stats(): array
    {
        $stats = [
            'total' => 0,
            'enabled' => 0,
            'disabled' => 0,
            'topics' => 0,
        ];

        $sql = "SELECT COUNT(*) AS total,
                SUM(CASE WHEN rule_enabled = 1 THEN 1 ELSE 0 END) AS enabled,
                SUM(CASE WHEN rule_enabled = 0 THEN 1 ELSE 0 END) AS disabled,
                SUM(CASE WHEN rule_type = 'topics' THEN 1 ELSE 0 END) AS topics
            FROM " . $this->rules_table;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        $stats['total'] = (int) ($row['total'] ?? 0);
        $stats['enabled'] = (int) ($row['enabled'] ?? 0);
        $stats['disabled'] = (int) ($row['disabled'] ?? 0);
        $stats['topics'] = (int) ($row['topics'] ?? 0);

        return $stats;
    }

    public function get_rule(int $rule_id): array
    {
        $sql = 'SELECT *
            FROM ' . $this->rules_table . '
            WHERE rule_id = ' . (int) $rule_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row;
    }

    public function save_rule(array $data): int
    {
        $normalized_value = $this->normalize_rule_value((string) ($data['rule_type'] ?? ''), $data['rule_value'] ?? 0);

        $sql_ary = [
            'medal_id'      => (int) $data['medal_id'],
            'rule_type'     => (string) $data['rule_type'],
            'rule_operator' => (string) $data['rule_operator'],
            'rule_value'    => (string) $normalized_value,
            'rule_enabled'  => (int) $data['rule_enabled'],
            'rule_notify'   => (int) $data['rule_notify'],
            'rule_options'  => '',
        ];

        if (!empty($data['rule_id'])) {
            $sql = 'UPDATE ' . $this->rules_table . '
                SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                WHERE rule_id = ' . (int) $data['rule_id'];
            $this->db->sql_query($sql);
            return (int) $data['rule_id'];
        }

        $sql_ary['rule_created_at'] = time();
        $sql = 'INSERT INTO ' . $this->rules_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
        $this->db->sql_query($sql);

        return (int) $this->db->sql_nextid();
    }

    public function delete_rule(int $rule_id): void
    {
        $sql = 'DELETE FROM ' . $this->rules_table . '
            WHERE rule_id = ' . (int) $rule_id;
        $this->db->sql_query($sql);
    }

    public function toggle_rule_enabled(int $rule_id): void
    {
        $rule = $this->get_rule($rule_id);
        if (!$rule) {
            return;
        }

        $sql = 'UPDATE ' . $this->rules_table . '
            SET rule_enabled = ' . ((int) empty($rule['rule_enabled']) ? 1 : 0) . '
            WHERE rule_id = ' . (int) $rule_id;
        $this->db->sql_query($sql);
    }

    public function evaluate_all_rules_for_user(int $user_id): int
    {
        if ($user_id <= ANONYMOUS) {
            return 0;
        }

        $user_row = $this->get_user_rule_context($user_id);
        if (!$user_row) {
            return 0;
        }

        $awarded = 0;
        foreach ($this->get_enabled_rules() as $rule) {
            if ((string) ($rule['rule_type'] ?? '') === 'posts') {
                continue;
            }

            if (!$this->rule_matches_user($rule, $user_row)) {
                continue;
            }

            $result = $this->grant_manager->grant_medal_to_user(
                $user_id,
                (int) $rule['medal_id'],
                (int) $rule['rule_id'],
                'auto',
                '',
                0,
                !empty($rule['rule_notify'])
            );

            if (!empty($result['success']) && !empty($result['created'])) {
                $awarded++;
            }
        }

        $awarded += $this->sync_progressive_rule_type_for_user($user_id, 'posts', $user_row);

        return $awarded;
    }

    public function evaluate_posts_rules_for_user(int $user_id): int
    {
        return $this->evaluate_all_rules_for_user($user_id);
    }

    public function sync_rule(int $rule_id): int
    {
        $rule = $this->get_rule($rule_id);
        if (!$rule || empty($rule['rule_enabled'])) {
            return 0;
        }

        $is_progressive_posts_rule = ((string) ($rule['rule_type'] ?? '') === 'posts');

        $sql = 'SELECT u.user_id, u.user_posts, u.user_avatar, u.user_avatar_type, u.user_sig, u.user_regdate,
                g.group_avatar AS default_group_avatar, g.group_avatar_type AS default_group_avatar_type,
                COALESCE(t.topic_count, 0) AS user_topics
            FROM ' . USERS_TABLE . ' u
            LEFT JOIN ' . GROUPS_TABLE . ' g ON g.group_id = u.group_id
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            LEFT JOIN (
                SELECT topic_poster, COUNT(topic_id) AS topic_count
                FROM ' . TOPICS_TABLE . '
                WHERE topic_poster <> ' . (int) ANONYMOUS . '
                    AND topic_visibility = ' . ITEM_APPROVED . '
                GROUP BY topic_poster
            ) t ON t.topic_poster = u.user_id
            WHERE u.user_id <> ' . (int) ANONYMOUS . '
                AND b.user_id IS NULL';
        $result = $this->db->sql_query($sql);
        $awarded = 0;
        while ($row = $this->db->sql_fetchrow($result)) {
            if ($is_progressive_posts_rule) {
                $awarded += $this->sync_progressive_rule_type_for_user((int) $row['user_id'], 'posts', $row);
                continue;
            }

            if (!$this->rule_matches_user($rule, $row)) {
                continue;
            }

            $grant = $this->grant_manager->grant_medal_to_user(
                (int) $row['user_id'],
                (int) $rule['medal_id'],
                (int) $rule['rule_id'],
                'auto',
                '',
                0,
                !empty($rule['rule_notify'])
            );
            if (!empty($grant['success']) && !empty($grant['created'])) {
                $awarded++;
            }
        }
        $this->db->sql_freeresult($result);

        return $awarded;
    }

    public function sync_posts_rule(int $rule_id): int
    {
        return $this->sync_rule($rule_id);
    }


    public function sync_all_rules(): array
    {
        return $this->sync_rules_by_filters([]);
    }

    public function sync_rules_by_filters(array $filters = []): array
    {
        $rule_ids = $this->get_filtered_rule_ids($filters, true);
        $awards = 0;

        foreach ($rule_ids as $rule_id) {
            $awards += $this->sync_rule((int) $rule_id);
        }

        return [
            'rules' => count($rule_ids),
            'awards' => $awards,
        ];
    }

    protected function get_filtered_rule_ids(array $filters = [], bool $enabled_only = false): array
    {
        if ($enabled_only && (($filters['enabled'] ?? 'all') === 'disabled')) {
            return [];
        }

        $where = $this->build_rule_filter_where($filters);
        if ($enabled_only) {
            $where[] = 'rule_enabled = 1';
        }

        $sql = 'SELECT rule_id
'
            . ' FROM ' . $this->rules_table;

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY rule_id ASC';

        $result = $this->db->sql_query($sql);
        $rule_ids = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $rule_ids[] = (int) $row['rule_id'];
        }
        $this->db->sql_freeresult($result);

        return $rule_ids;
    }

    protected function build_rule_filter_where(array $filters = []): array
    {
        $keywords = trim((string) ($filters['keywords'] ?? ''));
        $type = (string) ($filters['type'] ?? 'all');
        $enabled = (string) ($filters['enabled'] ?? 'all');

        $where = [];
        if ($keywords !== '') {
            $like = $this->db->sql_like_expression($this->db->any_char . $this->db->sql_escape($keywords) . $this->db->any_char);
            $where[] = '(rule_type ' . $like . ' OR medal_id IN (SELECT medal_id FROM ' . $this->medals_table . ' WHERE medal_name ' . $like . '))';
        }
        if ($type !== '' && $type !== 'all') {
            $where[] = "rule_type = '" . $this->db->sql_escape($type) . "'";
        }
        if ($enabled === 'enabled') {
            $where[] = 'rule_enabled = 1';
        } elseif ($enabled === 'disabled') {
            $where[] = 'rule_enabled = 0';
        }

        return $where;
    }

    protected function get_enabled_rules(): array
    {
        $sql = 'SELECT *
            FROM ' . $this->rules_table . '
            WHERE rule_enabled = 1
            ORDER BY rule_type ASC, rule_value + 0 ASC, rule_id ASC';
        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    protected function get_user_rule_context(int $user_id): array
    {
        $sql = 'SELECT u.user_id, u.user_posts, u.user_avatar, u.user_avatar_type, u.user_sig, u.user_regdate,
                g.group_avatar AS default_group_avatar, g.group_avatar_type AS default_group_avatar_type,
                COALESCE(t.topic_count, 0) AS user_topics
            FROM ' . USERS_TABLE . ' u
            LEFT JOIN ' . GROUPS_TABLE . ' g ON g.group_id = u.group_id
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            LEFT JOIN (
                SELECT topic_poster, COUNT(topic_id) AS topic_count
                FROM ' . TOPICS_TABLE . '
                WHERE topic_poster = ' . (int) $user_id . '
                    AND topic_visibility = ' . ITEM_APPROVED . '
                GROUP BY topic_poster
            ) t ON t.topic_poster = u.user_id
            WHERE u.user_id = ' . (int) $user_id . '
                AND b.user_id IS NULL';
        $result = $this->db->sql_query_limit($sql, 1);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row;
    }

    protected function rule_matches_user(array $rule, array $user_row): bool
    {
        $rule_type = (string) ($rule['rule_type'] ?? '');
        $rule_value = (int) ($rule['rule_value'] ?? 0);
        $rule_operator = (string) ($rule['rule_operator'] ?? '>=');

        $value = match ($rule_type) {
            'posts' => (int) ($user_row['user_posts'] ?? 0),
            'topics' => (int) ($user_row['user_topics'] ?? 0),
            'avatar' => $this->user_has_custom_avatar($user_row) ? 1 : 0,
            'signature' => !empty(trim((string) ($user_row['user_sig'] ?? ''))) ? 1 : 0,
            'membership_days' => $this->get_membership_days((int) ($user_row['user_regdate'] ?? 0)),
            default => null,
        };

        if ($value === null) {
            return false;
        }

        return match ($rule_operator) {
            '>'  => $value > $rule_value,
            '='  => $value == $rule_value,
            '<'  => $value < $rule_value,
            '<=' => $value <= $rule_value,
            default => $value >= $rule_value,
        };
    }

    protected function normalize_rule_value(string $rule_type, $rule_value): int
    {
        $value = max(0, (int) $rule_value);

        return match ($rule_type) {
            'avatar', 'signature' => min(1, $value),
            default => min(999999999, $value),
        };
    }

    protected function sync_progressive_rule_type_for_user(int $user_id, string $rule_type, ?array $user_row = null): int
    {
        if ($user_id <= ANONYMOUS || $rule_type !== 'posts') {
            return 0;
        }

        $user_row = $user_row ?: $this->get_user_rule_context($user_id);
        if (!$user_row) {
            return 0;
        }

        $rules = $this->get_enabled_rules_by_type($rule_type);
        $matched_rules = [];
        foreach ($rules as $rule) {
            if ($this->rule_matches_user($rule, $user_row)) {
                $matched_rules[] = $rule;
            }
        }

        $winner = null;
        if (!empty($matched_rules)) {
            usort($matched_rules, static function (array $a, array $b): int {
                $value_cmp = ((int) ($a['rule_value'] ?? 0)) <=> ((int) ($b['rule_value'] ?? 0));
                if ($value_cmp !== 0) {
                    return $value_cmp;
                }

                return ((int) ($a['rule_id'] ?? 0)) <=> ((int) ($b['rule_id'] ?? 0));
            });

            $winner = end($matched_rules) ?: null;
        }

        $created = 0;
        if ($winner) {
            $grant = $this->grant_manager->grant_medal_to_user(
                $user_id,
                (int) $winner['medal_id'],
                (int) $winner['rule_id'],
                'auto',
                '',
                0,
                !empty($winner['rule_notify'])
            );

            if (!empty($grant['success']) && !empty($grant['created'])) {
                $created++;
            }
        }

        $keep_rule_id = $winner ? (int) $winner['rule_id'] : 0;
        foreach ($this->get_auto_awards_for_rule_type($user_id, $rule_type) as $award) {
            $award_rule_id = (int) ($award['rule_id'] ?? 0);
            if ($keep_rule_id > 0 && $award_rule_id === $keep_rule_id) {
                continue;
            }

            $this->grant_manager->remove_medal_from_user(
                $user_id,
                (int) ($award['medal_id'] ?? 0)
            );
        }

        return $created;
    }

    protected function get_enabled_rules_by_type(string $rule_type): array
    {
        $sql = 'SELECT *
            FROM ' . $this->rules_table . "
            WHERE rule_enabled = 1
                AND rule_type = '" . $this->db->sql_escape($rule_type) . "'
            ORDER BY rule_value + 0 ASC, rule_id ASC";
        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    protected function get_auto_awards_for_rule_type(int $user_id, string $rule_type): array
    {
        $sql = 'SELECT a.award_id, a.medal_id, a.rule_id
            FROM ' . $this->awards_table . ' a
            INNER JOIN ' . $this->rules_table . ' r ON r.rule_id = a.rule_id
            WHERE a.user_id = ' . (int) $user_id . "
                AND a.award_source = 'auto'
                AND r.rule_type = '" . $this->db->sql_escape($rule_type) . "'";
        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    protected function user_has_custom_avatar(array $user_row): bool
    {
        $user_avatar = trim((string) ($user_row['user_avatar'] ?? ''));
        $user_avatar_type = trim((string) ($user_row['user_avatar_type'] ?? ''));
        if ($user_avatar === '' || $user_avatar_type === '') {
            return false;
        }

        $group_avatar = trim((string) ($user_row['default_group_avatar'] ?? ''));
        $group_avatar_type = trim((string) ($user_row['default_group_avatar_type'] ?? ''));
        if ($group_avatar !== '' && $group_avatar_type !== ''
            && $user_avatar === $group_avatar
            && $user_avatar_type === $group_avatar_type) {
            return false;
        }

        return true;
    }

    protected function get_membership_days(int $user_regdate): int
    {
        if ($user_regdate <= 0) {
            return 0;
        }

        return (int) floor((time() - $user_regdate) / 86400);
    }
}
