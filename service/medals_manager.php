<?php
namespace mundophpbb\membermedals\service;

use phpbb\config\config;
use phpbb\controller\helper;
use phpbb\db\driver\driver_interface;
use phpbb\user;

class medals_manager
{
    protected driver_interface $db;
    protected config $config;
    protected user $user;
    protected helper $helper;
    protected string $medals_table;
    protected string $rules_table;
    protected string $awards_table;
    protected string $featured_table;

    public function __construct(driver_interface $db, config $config, user $user, helper $helper, string $medals_table, string $rules_table, string $awards_table, string $featured_table)
    {
        $this->db = $db;
        $this->config = $config;
        $this->user = $user;
        $this->helper = $helper;
        $this->medals_table = $medals_table;
        $this->rules_table = $rules_table;
        $this->awards_table = $awards_table;
        $this->featured_table = $featured_table;
    }

    public function get_all_medals(bool $only_active = false): array
    {
        $sql = 'SELECT * FROM ' . $this->medals_table . ($only_active ? ' WHERE medal_active = 1' : '') . ' ORDER BY medal_featured DESC, medal_display_order ASC, medal_name ASC';
        $result = $this->db->sql_query($sql);
        $medals = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $medals[] = $this->normalize_medal_row($row);
        }
        $this->db->sql_freeresult($result);
        return $medals;
    }

    public function get_medals_for_acp(array $filters = []): array
    {
        $keywords = trim((string) ($filters['keywords'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');

        $where = [];
        if ($keywords !== '') {
            $like = $this->db->sql_like_expression($this->db->any_char . $this->db->sql_escape($keywords) . $this->db->any_char);
            $where[] = '(m.medal_name ' . $like . ' OR m.medal_description ' . $like . ')';
        }
        if ($status === 'active') {
            $where[] = 'm.medal_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'm.medal_active = 0';
        }

        $sql = 'SELECT m.*, COUNT(DISTINCT r.rule_id) AS rule_count, COUNT(DISTINCT CASE WHEN b.user_id IS NULL THEN a.award_id ELSE NULL END) AS award_count
            FROM ' . $this->medals_table . ' m
            LEFT JOIN ' . $this->rules_table . ' r ON r.medal_id = m.medal_id
            LEFT JOIN ' . $this->awards_table . ' a ON a.medal_id = m.medal_id
            LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id';

        if ($where) {
            $sql .= '
            WHERE ' . implode(' AND ', $where);
        }

        $sql .= '
            GROUP BY m.medal_id
            ORDER BY m.medal_featured DESC, m.medal_display_order ASC, m.medal_name ASC';

        $result = $this->db->sql_query($sql);
        $medals = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $row = $this->normalize_medal_row($row);
            $row['rule_count'] = (int) ($row['rule_count'] ?? 0);
            $row['award_count'] = (int) ($row['award_count'] ?? 0);
            $medals[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $medals;
    }

    public function get_medal_stats(): array
    {
        $sql = 'SELECT COUNT(*) AS total, SUM(CASE WHEN medal_active = 1 THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN medal_active = 0 THEN 1 ELSE 0 END) AS inactive, SUM(CASE WHEN medal_featured = 1 THEN 1 ELSE 0 END) AS featured
            FROM ' . $this->medals_table;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
            'featured' => (int) ($row['featured'] ?? 0),
        ];
    }

    public function get_medal(int $medal_id): array
    {
        $sql = 'SELECT * FROM ' . $this->medals_table . ' WHERE medal_id = ' . (int) $medal_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? $this->normalize_medal_row($row) : [];
    }

    public function get_public_medal(int $medal_id): array
    {
        $sql = 'SELECT *
            FROM ' . $this->medals_table . '
            WHERE medal_id = ' . (int) $medal_id . '
                AND medal_active = 1
                AND medal_hidden = 0';
        $result = $this->db->sql_query_limit($sql, 1);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? $this->normalize_medal_row($row) : [];
    }

    public function save_medal(array $data): int
    {
        $sql_ary = [
            'medal_name'            => $data['medal_name'],
            'medal_description'     => $data['medal_description'],
            'medal_image'           => $data['medal_image'],
            'medal_active'          => (int) $data['medal_active'],
            'medal_hidden'          => (int) $data['medal_hidden'],
            'medal_featured'        => (int) $data['medal_featured'],
            'medal_display_order'   => (int) $data['medal_display_order'],
        ];
        if (!empty($data['medal_id'])) {
            $sql = 'UPDATE ' . $this->medals_table . ' SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . ' WHERE medal_id = ' . (int) $data['medal_id'];
            $this->db->sql_query($sql);
            return (int) $data['medal_id'];
        }
        $sql_ary['medal_created_at'] = time();
        $sql = 'INSERT INTO ' . $this->medals_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
        $this->db->sql_query($sql);
        return (int) $this->db->sql_nextid();
    }

    public function delete_medal(int $medal_id): void
    {
        $sql = 'DELETE FROM ' . $this->medals_table . ' WHERE medal_id = ' . (int) $medal_id;
        $this->db->sql_query($sql);
        $sql = 'DELETE FROM ' . $this->awards_table . ' WHERE medal_id = ' . (int) $medal_id;
        $this->db->sql_query($sql);
        $sql = 'DELETE FROM ' . $this->rules_table . ' WHERE medal_id = ' . (int) $medal_id;
        $this->db->sql_query($sql);
        $sql = 'DELETE FROM ' . $this->featured_table . ' WHERE medal_id = ' . (int) $medal_id;
        $this->db->sql_query($sql);
    }

    public function toggle_medal_active(int $medal_id): void
    {
        $medal = $this->get_medal($medal_id);
        if (!$medal) {
            return;
        }

        $sql = 'UPDATE ' . $this->medals_table . '
            SET medal_active = ' . ((int) empty($medal['medal_active']) ? 1 : 0) . '
            WHERE medal_id = ' . (int) $medal_id;
        $this->db->sql_query($sql);
    }

    public function get_public_medals_with_counts(): array
    {
        $sql = 'SELECT m.*, COUNT(a.award_id) AS award_count
            FROM ' . $this->medals_table . ' m
            LEFT JOIN ' . $this->awards_table . ' a ON a.medal_id = m.medal_id
            LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            WHERE m.medal_active = 1
                AND m.medal_hidden = 0
                AND (a.award_id IS NULL OR b.user_id IS NULL)
            GROUP BY m.medal_id
            ORDER BY m.medal_featured DESC, m.medal_display_order ASC, m.medal_name ASC';
        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $row = $this->normalize_medal_row($row);
            $row['award_count'] = (int) ($row['award_count'] ?? 0);
            $row['rarity_label'] = $this->get_rarity_label((int) $row['award_count']);
            $row['U_VIEW_MEDAL'] = $this->helper->route('mundophpbb_membermedals_medal', ['medal_id' => (int) $row['medal_id']]);
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);
        return $rows;
    }

    public function get_public_medals_summary(): array
    {
        $sql = 'SELECT COUNT(*) AS total_medals
            FROM ' . $this->medals_table . '
            WHERE medal_active = 1
                AND medal_hidden = 0';
        $result = $this->db->sql_query_limit($sql, 1);
        $medals_row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        $sql = 'SELECT COUNT(*) AS total_awards
            FROM ' . $this->awards_table . ' a
            INNER JOIN ' . $this->medals_table . ' m ON m.medal_id = a.medal_id
            INNER JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            WHERE m.medal_active = 1
                AND m.medal_hidden = 0
                AND b.user_id IS NULL';
        $result = $this->db->sql_query_limit($sql, 1);
        $awards_row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        $sql = 'SELECT COUNT(DISTINCT a.user_id) AS total_members
            FROM ' . $this->awards_table . ' a
            INNER JOIN ' . $this->medals_table . ' m ON m.medal_id = a.medal_id
            INNER JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            WHERE m.medal_active = 1
                AND m.medal_hidden = 0
                AND b.user_id IS NULL';
        $result = $this->db->sql_query_limit($sql, 1);
        $members_row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return [
            'total_medals' => (int) ($medals_row['total_medals'] ?? 0),
            'total_awards' => (int) ($awards_row['total_awards'] ?? 0),
            'total_members' => (int) ($members_row['total_members'] ?? 0),
        ];
    }

    public function get_recent_awards(int $limit = 20): array
    {
        $sql = 'SELECT a.*, u.username, u.user_colour, m.medal_name
            FROM ' . $this->awards_table . ' a
            INNER JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id
            INNER JOIN ' . $this->medals_table . ' m ON m.medal_id = a.medal_id
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            WHERE m.medal_active = 1
                AND m.medal_hidden = 0
                AND b.user_id IS NULL
            ORDER BY a.awarded_at DESC';
        $result = $this->db->sql_query_limit($sql, $limit);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $row['U_USER_MEDALS'] = $this->helper->route('mundophpbb_membermedals_user', ['user_id' => (int) $row['user_id']]);
            $row['U_VIEW_MEDAL'] = $this->helper->route('mundophpbb_membermedals_medal', ['medal_id' => (int) $row['medal_id']]);
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);
        return $rows;
    }


    public function get_public_member_ranking(int $limit = 10): array
    {
        $sql = 'SELECT u.user_id, u.username, u.user_colour, u.username_clean, COUNT(a.award_id) AS award_total, MAX(a.awarded_at) AS latest_award
            FROM ' . $this->awards_table . ' a
            INNER JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id
            INNER JOIN ' . $this->medals_table . ' m ON m.medal_id = a.medal_id
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            WHERE m.medal_active = 1
                AND m.medal_hidden = 0
                AND b.user_id IS NULL
            GROUP BY u.user_id, u.username, u.user_colour, u.username_clean
            ORDER BY award_total DESC, latest_award DESC, u.username_clean ASC';
        $result = $limit > 0 ? $this->db->sql_query_limit($sql, $limit) : $this->db->sql_query($sql);
        $rows = [];
        $rank = 1;
        while ($row = $this->db->sql_fetchrow($result)) {
            $row['award_total'] = (int) ($row['award_total'] ?? 0);
            $row['latest_award'] = (int) ($row['latest_award'] ?? 0);
            $row['rank_pos'] = $rank;
            $row['U_USER_MEDALS'] = $this->get_public_profile_url((int) $row['user_id']);
            $rows[] = $row;
            $rank++;
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    public function get_public_member_rank_position(int $user_id): int
    {
        if ($user_id <= ANONYMOUS || $this->is_registered_bot($user_id)) {
            return 0;
        }

        foreach ($this->get_public_member_ranking(0) as $member) {
            if ((int) ($member['user_id'] ?? 0) === $user_id) {
                return (int) ($member['rank_pos'] ?? 0);
            }
        }

        return 0;
    }

    public function get_public_medals_extremes(int $limit = 6, string $direction = 'ASC'): array
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $secondaryDirection = $direction === 'ASC' ? 'ASC' : 'DESC';
        $sql = 'SELECT m.*, COUNT(a.award_id) AS award_count
            FROM ' . $this->medals_table . ' m
            LEFT JOIN ' . $this->awards_table . ' a ON a.medal_id = m.medal_id
            LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            WHERE m.medal_active = 1
                AND m.medal_hidden = 0
                AND (a.award_id IS NULL OR b.user_id IS NULL)
            GROUP BY m.medal_id
            ORDER BY award_count ' . $direction . ', m.medal_featured DESC, m.medal_display_order ' . $secondaryDirection . ', m.medal_name ASC';
        $result = $this->db->sql_query_limit($sql, $limit);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $row = $this->normalize_medal_row($row);
            $row['award_count'] = (int) ($row['award_count'] ?? 0);
            $row['rarity_label'] = $this->get_rarity_label((int) $row['award_count']);
            $row['U_VIEW_MEDAL'] = $this->helper->route('mundophpbb_membermedals_medal', ['medal_id' => (int) $row['medal_id']]);
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    public function get_user_medals(int $user_id, bool $public_only = true): array
    {
        if ($this->is_registered_bot($user_id)) {
            return [];
        }

        $where = $public_only ? ' AND m.medal_hidden = 0 AND m.medal_active = 1' : '';
        $sql = 'SELECT a.*, m.medal_name, m.medal_description, m.medal_image, m.medal_display_order, m.medal_featured
            FROM ' . $this->awards_table . ' a
            INNER JOIN ' . $this->medals_table . ' m ON m.medal_id = a.medal_id
            WHERE a.user_id = ' . (int) $user_id . $where . '
            ORDER BY m.medal_featured DESC, m.medal_display_order ASC, a.awarded_at DESC';
        $result = $this->db->sql_query($sql);
        $rows = [];
        $selected_map = $this->get_user_featured_order_map($user_id, $public_only);
        while ($row = $this->db->sql_fetchrow($result)) {
            $row = $this->normalize_medal_row($row);
            $featured_order = (int) ($selected_map[(int) $row['medal_id']] ?? 0);
            $row['user_featured'] = $featured_order > 0 ? 1 : 0;
            $row['user_featured_order'] = $featured_order;
            $row['U_VIEW_MEDAL'] = $this->helper->route('mundophpbb_membermedals_medal', ['medal_id' => (int) $row['medal_id']]);
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $this->sort_user_awards($rows);
    }

    public function get_user_medals_limited(int $user_id, int $limit): array
    {
        if ($limit <= 0 || $this->is_registered_bot($user_id)) {
            return [];
        }

        return array_slice($this->get_user_medals($user_id, true), 0, $limit);
    }

    public function count_user_medals(int $user_id): int
    {
        if ($this->is_registered_bot($user_id)) {
            return 0;
        }
        $sql = 'SELECT COUNT(a.award_id) AS total
            FROM ' . $this->awards_table . ' a
            INNER JOIN ' . $this->medals_table . ' m ON m.medal_id = a.medal_id
            WHERE a.user_id = ' . (int) $user_id . '
                AND m.medal_hidden = 0
                AND m.medal_active = 1';
        $result = $this->db->sql_query_limit($sql, 1);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return (int) ($row['total'] ?? 0);
    }

    public function get_showcase_url(): string
    {
        return $this->helper->route('mundophpbb_membermedals_index');
    }

    public function get_public_profile_url(int $user_id): string
    {
        return $this->helper->route('mundophpbb_membermedals_user', ['user_id' => $user_id]);
    }

    public function get_ucp_url(): string
    {
        return $this->get_board_relative_path('ucp.php') . '?i=-mundophpbb-membermedals-ucp-main_module&mode=overview';
    }

    public function get_user_row(int $user_id): array
    {
        $sql = 'SELECT u.user_id, u.username, u.user_colour
            FROM ' . USERS_TABLE . ' u
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            WHERE u.user_id = ' . (int) $user_id . '
                AND b.user_id IS NULL';
        $result = $this->db->sql_query_limit($sql, 1);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return $row;
    }

    public function get_medal_award_count(int $medal_id): int
    {
        $sql = 'SELECT COUNT(a.award_id) AS total
            FROM ' . $this->awards_table . ' a
            INNER JOIN ' . $this->medals_table . ' m ON m.medal_id = a.medal_id
            INNER JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            WHERE a.medal_id = ' . (int) $medal_id . '
                AND m.medal_active = 1
                AND m.medal_hidden = 0
                AND b.user_id IS NULL';
        $result = $this->db->sql_query_limit($sql, 1);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return (int) ($row['total'] ?? 0);
    }

    public function get_medal_winners(int $medal_id, int $limit = 50): array
    {
        $sql = 'SELECT a.awarded_at, a.award_source, a.award_reason, u.user_id, u.username, u.user_colour
            FROM ' . $this->awards_table . ' a
            INNER JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id
            INNER JOIN ' . $this->medals_table . ' m ON m.medal_id = a.medal_id
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            WHERE a.medal_id = ' . (int) $medal_id . '
                AND m.medal_active = 1
                AND m.medal_hidden = 0
                AND b.user_id IS NULL
            ORDER BY a.awarded_at DESC';
        $result = $this->db->sql_query_limit($sql, $limit);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $row['U_USER_MEDALS'] = $this->helper->route('mundophpbb_membermedals_user', ['user_id' => (int) $row['user_id']]);
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);
        return $rows;
    }

    public function get_medal_rule_summaries(int $medal_id): array
    {
        $sql = 'SELECT rule_type, rule_operator, rule_value, rule_enabled
            FROM ' . $this->rules_table . '
            WHERE medal_id = ' . (int) $medal_id . '
            ORDER BY rule_enabled DESC, rule_type ASC, rule_value + 0 ASC, rule_id ASC';
        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $rows[] = [
                'rule_type' => (string) ($row['rule_type'] ?? ''),
                'rule_operator' => (string) ($row['rule_operator'] ?? '>='),
                'rule_value' => (string) ($row['rule_value'] ?? ''),
                'rule_enabled' => (int) ($row['rule_enabled'] ?? 0),
            ];
        }
        $this->db->sql_freeresult($result);
        return $rows;
    }

    protected function is_featured_storage_ready(): bool
    {
        return isset($this->config['membermedals_featured_limit']) && version_compare((string) ($this->config['membermedals_version'] ?? '0.0.0'), '0.6.2', '>=');
    }

    public function get_featured_limit(): int
    {
        $limit = (int) ($this->config['membermedals_featured_limit'] ?? 3);
        return max(1, min(8, $limit));
    }

    public function get_user_featured_medal_ids(int $user_id, bool $public_only = true): array
    {
        if (!$this->is_featured_storage_ready() || $user_id <= ANONYMOUS || $this->is_registered_bot($user_id)) {
            return [];
        }

        $where = $public_only ? ' AND m.medal_hidden = 0 AND m.medal_active = 1' : '';
        $sql = 'SELECT f.medal_id
            FROM ' . $this->featured_table . ' f
            INNER JOIN ' . $this->awards_table . ' a
                ON a.user_id = f.user_id
                AND a.medal_id = f.medal_id
            INNER JOIN ' . $this->medals_table . ' m
                ON m.medal_id = f.medal_id
            WHERE f.user_id = ' . (int) $user_id . $where . '
            ORDER BY f.featured_order ASC, f.featured_at ASC';
        $result = $this->db->sql_query($sql);
        $ids = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $ids[] = (int) $row['medal_id'];
        }
        $this->db->sql_freeresult($result);

        return array_values(array_unique($ids));
    }

    public function get_user_featured_order_map(int $user_id, bool $public_only = true): array
    {
        if (!$this->is_featured_storage_ready() || $user_id <= ANONYMOUS || $this->is_registered_bot($user_id)) {
            return [];
        }

        $where = $public_only ? ' AND m.medal_hidden = 0 AND m.medal_active = 1' : '';
        $sql = 'SELECT f.medal_id, f.featured_order
            FROM ' . $this->featured_table . ' f
            INNER JOIN ' . $this->awards_table . ' a
                ON a.user_id = f.user_id
                AND a.medal_id = f.medal_id
            INNER JOIN ' . $this->medals_table . ' m
                ON m.medal_id = f.medal_id
            WHERE f.user_id = ' . (int) $user_id . $where . '
            ORDER BY f.featured_order ASC, f.featured_at ASC';
        $result = $this->db->sql_query($sql);
        $map = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $medal_id = (int) ($row['medal_id'] ?? 0);
            $order = (int) ($row['featured_order'] ?? 0);
            if ($medal_id > 0 && !isset($map[$medal_id])) {
                $map[$medal_id] = max(1, $order);
            }
        }
        $this->db->sql_freeresult($result);

        return $map;
    }

    public function save_user_featured_medals(int $user_id, array $medal_ids, array $medal_orders = []): int
    {
        if (!$this->is_featured_storage_ready() || $user_id <= ANONYMOUS || $this->is_registered_bot($user_id)) {
            return 0;
        }

        $allowed = [];
        foreach ($this->get_user_medals($user_id, true) as $award) {
            $allowed[(int) $award['medal_id']] = true;
        }

        $position = 0;
        $clean_rows = [];
        foreach ($medal_ids as $medal_id) {
            $medal_id = (int) $medal_id;
            if ($medal_id <= 0 || !isset($allowed[$medal_id]) || isset($clean_rows[$medal_id])) {
                continue;
            }

            $requested_order = (int) ($medal_orders[$medal_id] ?? 0);
            if ($requested_order <= 0) {
                $requested_order = (int) ($medal_orders[(string) $medal_id] ?? 0);
            }

            $clean_rows[$medal_id] = [
                'medal_id' => $medal_id,
                'requested_order' => $requested_order > 0 ? $requested_order : 999,
                'position' => $position,
            ];
            $position++;
        }

        $clean_rows = array_values($clean_rows);
        if ($clean_rows) {
            usort($clean_rows, static function (array $a, array $b): int {
                $orderCompare = ((int) $a['requested_order']) <=> ((int) $b['requested_order']);
                if ($orderCompare !== 0) {
                    return $orderCompare;
                }
                return ((int) $a['position']) <=> ((int) $b['position']);
            });
            $clean_rows = array_slice($clean_rows, 0, $this->get_featured_limit());
        }

        $sql = 'DELETE FROM ' . $this->featured_table . ' WHERE user_id = ' . (int) $user_id;
        $this->db->sql_query($sql);

        $order = 1;
        foreach ($clean_rows as $row) {
            $sql_ary = [
                'user_id' => $user_id,
                'medal_id' => (int) $row['medal_id'],
                'featured_order' => $order,
                'featured_at' => time(),
            ];
            $sql = 'INSERT INTO ' . $this->featured_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
            $this->db->sql_query($sql);
            $order++;
        }

        return count($clean_rows);
    }

    public function render_viewtopic_html(int $user_id, int $limit, bool $show_showcase_link = true): string
    {
        return $this->render_compact_html($user_id, $limit, 'viewtopic', $show_showcase_link);
    }

    public function render_profile_html(int $user_id, int $limit = 12, bool $show_showcase_link = true): string
    {
        return $this->render_compact_html($user_id, $limit, 'profile', $show_showcase_link);
    }

    public function render_compact_html(int $user_id, int $limit, string $context = 'viewtopic', bool $show_showcase_link = true): string
    {
        $total = $this->count_user_medals($user_id);
        if (!$total) {
            return '';
        }

        $awards = $this->get_user_medals_limited($user_id, $limit);
        if (!$awards) {
            return '';
        }

        $is_current_user = !empty($this->user->data['user_id']) && (int) $this->user->data['user_id'] === $user_id;
        $profile_target_url = $is_current_user ? $this->get_ucp_url() : $this->get_public_profile_url($user_id);
        $profile_url = htmlspecialchars($profile_target_url, ENT_COMPAT, 'UTF-8');
        $showcase_url = htmlspecialchars($this->get_showcase_url(), ENT_COMPAT, 'UTF-8');
        $more_count = max(0, $total - count($awards));
        $count_label = htmlspecialchars($this->user->lang('MEMBERMEDALS_COMPACT_COUNT', (int) $total), ENT_COMPAT, 'UTF-8');
        $profile_label_key = $is_current_user ? 'MEMBERMEDALS_OPEN_UCP' : 'MEMBERMEDALS_COMPACT_PROFILE';
        $profile_label = htmlspecialchars($this->user->lang($profile_label_key), ENT_COMPAT, 'UTF-8');
        $showcase_label = htmlspecialchars($this->user->lang('MEMBERMEDALS_COMPACT_SHOWCASE'), ENT_COMPAT, 'UTF-8');
        $title_label = htmlspecialchars($this->user->lang('MEMBERMEDALS_LABEL'), ENT_COMPAT, 'UTF-8');

        $primary_html = '';
        $secondary_html = '';

        if ($context === 'profile') {
            $primary_awards = array_slice($awards, 0, min(4, count($awards)));
            $secondary_awards = array_slice($awards, count($primary_awards));

            foreach ($primary_awards as $award) {
                $primary_html .= $this->build_compact_card($award);
            }

            foreach ($secondary_awards as $award) {
                $secondary_html .= $this->build_compact_icon($award);
            }
        } elseif ($context === 'viewtopic') {
            foreach ($awards as $award) {
                $secondary_html .= $this->build_compact_icon($award, false);
            }
        } else {
            foreach ($awards as $award) {
                $secondary_html .= $this->build_compact_icon($award);
            }
        }

        if ($context === 'viewtopic') {
            if ($more_count > 0) {
                $secondary_html .= '<span class="membermedals-more" title="' . $count_label . '">+' . (int) $more_count . '</span>';
            }

            return '<div class="membermedals-miniprofile" title="' . $count_label . '">' . $secondary_html . '</div>';
        }

        if ($more_count > 0) {
            $secondary_html .= '<a class="membermedals-more" href="' . $profile_url . '">+' . (int) $more_count . '</a>';
        }

        $html = '<div class="membermedals-compact membermedals-compact-' . htmlspecialchars($context, ENT_COMPAT, 'UTF-8') . '">';
        $html .= '<div class="membermedals-compact-header">';
        $html .= '<span class="membermedals-compact-title">' . $title_label . '</span>';
        $html .= '<span class="membermedals-compact-count">' . $count_label . '</span>';
        $html .= '</div>';

        if ($primary_html !== '') {
            $html .= '<div class="membermedals-compact-primary">' . $primary_html . '</div>';
        }

        if ($secondary_html !== '') {
            $html .= '<div class="membermedals-list-inline membermedals-compact-secondary">' . $secondary_html . '</div>';
        }

        $html .= '<div class="membermedals-compact-actions">';
        $html .= '<a class="membermedals-compact-link" href="' . $profile_url . '">' . $profile_label . '</a>';
        if ($show_showcase_link) {
            $html .= '<a class="membermedals-compact-link" href="' . $showcase_url . '">' . $showcase_label . '</a>';
        }
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    protected function build_compact_card(array $award): string
    {
        $title = htmlspecialchars((string) ($award['medal_name'] ?? ''), ENT_COMPAT, 'UTF-8');
        $desc = trim((string) ($award['medal_description'] ?? ''));
        $tooltip = $title;
        if ($desc !== '') {
            $tooltip .= ' — ' . htmlspecialchars($desc, ENT_COMPAT, 'UTF-8');
        }
        $src = htmlspecialchars((string) ($award['medal_image_url'] ?? ''), ENT_COMPAT, 'UTF-8');
        $url = htmlspecialchars((string) ($award['U_VIEW_MEDAL'] ?? '#'), ENT_COMPAT, 'UTF-8');
        $featured_class = !empty($award['user_featured']) ? ' membermedals-badge-card-selected' : '';

        $html = '<a class="membermedals-badge-card' . $featured_class . '" href="' . $url . '" title="' . $tooltip . '">';
        $html .= '<span class="membermedals-badge-card-image">';
        $html .= $src !== '' ? '<img src="' . $src . '" alt="' . $title . '" />' : '<span class="membermedals-fallback">🏅</span>';
        $html .= '</span>';
        $html .= '<span class="membermedals-badge-card-label">' . $title . '</span>';
        $html .= '</a>';

        return $html;
    }

    protected function build_compact_icon(array $award, bool $clickable = true): string
    {
        $title = htmlspecialchars((string) ($award['medal_name'] ?? ''), ENT_COMPAT, 'UTF-8');
        $desc = trim((string) ($award['medal_description'] ?? ''));
        $tooltip = $title;
        if ($desc !== '') {
            $tooltip .= ' — ' . htmlspecialchars($desc, ENT_COMPAT, 'UTF-8');
        }
        $src = htmlspecialchars((string) ($award['medal_image_url'] ?? ''), ENT_COMPAT, 'UTF-8');
        $url = htmlspecialchars((string) ($award['U_VIEW_MEDAL'] ?? '#'), ENT_COMPAT, 'UTF-8');
        $featured_class = !empty($award['user_featured']) ? ' membermedals-item-selected' : '';

        if ($clickable) {
            $html = '<a class="membermedals-item' . $featured_class . '" href="' . $url . '" title="' . $tooltip . '">';
            $html .= $src !== '' ? '<img src="' . $src . '" alt="' . $title . '" />' : '<span class="membermedals-fallback">🏅</span>';
            $html .= '</a>';
        } else {
            $html = '<span class="membermedals-item membermedals-item-static' . $featured_class . '" title="' . $tooltip . '">';
            $html .= $src !== '' ? '<img src="' . $src . '" alt="' . $title . '" />' : '<span class="membermedals-fallback">🏅</span>';
            $html .= '</span>';
        }

        return $html;
    }

    public function resolve_image_url(string $image_path): string
    {
        $image_path = trim(str_replace('\\', '/', $image_path));
        if ($image_path === '') {
            return '';
        }

        if (preg_match('#^(https?:)?//#i', $image_path)) {
            return $image_path;
        }

        if (str_starts_with($image_path, './')) {
            $image_path = substr($image_path, 2);
        }

        if ($image_path !== '' && $image_path[0] === '/') {
            return $image_path;
        }

        if (preg_match('#(?:^|/)(files/membermedals/[^?\s]+)$#i', $image_path, $matches)) {
            return $this->get_board_relative_path($matches[1]);
        }

        if (preg_match('#(?:^|/)(ext/[^?\s]+)$#i', $image_path, $matches)) {
            return $this->get_board_relative_path($matches[1]);
        }

        if (str_starts_with($image_path, 'membermedals/')) {
            return $this->get_board_relative_path('files/' . ltrim($image_path, '/'));
        }

        if (str_starts_with($image_path, 'files/') || str_starts_with($image_path, 'ext/')) {
            return $this->get_board_relative_path($image_path);
        }

        if (strpos($image_path, '/') === false) {
            return $this->get_board_relative_path('files/membermedals/' . $image_path);
        }

        return $this->get_board_relative_path(ltrim($image_path, '/'));
    }

    public function is_registered_bot(int $user_id): bool
    {
        if ($user_id <= ANONYMOUS) {
            return false;
        }

        $sql = 'SELECT bot_id
            FROM ' . BOTS_TABLE . '
            WHERE user_id = ' . (int) $user_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return !empty($row);
    }

    protected function normalize_medal_row(array $row): array
    {
        $row['medal_name'] = trim((string) ($row['medal_name'] ?? ''));
        if ($row['medal_name'] === '') {
            $row['medal_name'] = '[Medal #' . (int) ($row['medal_id'] ?? 0) . ']';
        }
        $row['medal_description'] = (string) ($row['medal_description'] ?? '');
        $row['medal_image'] = trim(str_replace('\\', '/', (string) ($row['medal_image'] ?? '')));

        $medal_id = (int) ($row['medal_id'] ?? 0);
        if ($row['medal_image'] !== '' && !preg_match('#^(https?:)?//#i', $row['medal_image']) && $medal_id > 0) {
            $url = $this->helper->route('mundophpbb_membermedals_image', ['medal_id' => $medal_id]);
            $version = substr(md5($row['medal_image']), 0, 12);
            $row['medal_image_url'] = $url . '?v=' . $version;
        } else {
            $row['medal_image_url'] = $this->resolve_image_url((string) ($row['medal_image'] ?? ''));
        }

        return $row;
    }

    protected function get_rarity_label(int $award_count): string
    {
        if ($award_count <= 0) {
            return 'unclaimed';
        }
        if ($award_count <= 2) {
            return 'ultra_rare';
        }
        if ($award_count <= 5) {
            return 'rare';
        }
        if ($award_count <= 15) {
            return 'uncommon';
        }
        return 'common';
    }

    protected function get_board_relative_path(string $path): string
    {
        $script_path = trim((string) ($this->config['script_path'] ?? ''));
        $script_path = trim($script_path, '/');
        $prefix = $script_path === '' ? '' : '/' . $script_path;

        return $prefix . '/' . ltrim($path, '/');
    }

    protected function sort_user_awards(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            $aSelected = (int) ($a['user_featured'] ?? 0);
            $bSelected = (int) ($b['user_featured'] ?? 0);
            if ($aSelected !== $bSelected) {
                return $bSelected <=> $aSelected;
            }

            $aSelectedOrder = (int) ($a['user_featured_order'] ?? 0);
            $bSelectedOrder = (int) ($b['user_featured_order'] ?? 0);
            if ($aSelected && $bSelected && $aSelectedOrder !== $bSelectedOrder) {
                return $aSelectedOrder <=> $bSelectedOrder;
            }

            $aFeatured = (int) ($a['medal_featured'] ?? 0);
            $bFeatured = (int) ($b['medal_featured'] ?? 0);
            if ($aFeatured !== $bFeatured) {
                return $bFeatured <=> $aFeatured;
            }

            $aOrder = (int) ($a['medal_display_order'] ?? 0);
            $bOrder = (int) ($b['medal_display_order'] ?? 0);
            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }

            return (int) ($b['awarded_at'] ?? 0) <=> (int) ($a['awarded_at'] ?? 0);
        });

        return $rows;
    }
}
