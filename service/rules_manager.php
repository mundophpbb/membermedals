<?php

namespace mundophpbb\membermedals\service;

use mundophpbb\membermedals\contract\rule_provider_interface;
use phpbb\db\driver\driver_interface;

class rules_manager
{
    protected driver_interface $db;
    protected string $medals_table;
    protected string $rules_table;
    protected string $awards_table;
    protected grant_manager $grant_manager;
    protected rule_provider_registry $provider_registry;
    protected ?bool $supports_award_family = null;

    public function __construct(
        driver_interface $db,
        string $medals_table,
        string $rules_table,
        string $awards_table,
        grant_manager $grant_manager,
        rule_provider_registry $provider_registry
    ) {
        $this->db = $db;
        $this->medals_table = $medals_table;
        $this->rules_table = $rules_table;
        $this->awards_table = $awards_table;
        $this->grant_manager = $grant_manager;
        $this->provider_registry = $provider_registry;
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
        $result = $this->db->sql_query($sql);
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
        $result = $this->db->sql_query($sql);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row;
    }

    public function save_rule(array $data): int
    {
        $rule_type = $this->resolve_valid_rule_type((string) ($data['rule_type'] ?? ''));
        $provider = $this->provider_registry->get($rule_type);
        $normalized = $provider ? $provider->normalize_rule_data($data) : $data;

        $rule_operator = (string) ($normalized['rule_operator'] ?? '>=');
        if ($provider && !in_array($rule_operator, $provider->get_supported_operators(), true)) {
            $rule_operator = (string) ($provider->get_supported_operators()[0] ?? '>=');
        }

        $rule_options = $normalized['rule_options'] ?? [];
        if (!is_string($rule_options)) {
            $encoded = json_encode($rule_options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $rule_options = $encoded !== false ? $encoded : '';
        }

        $sql_ary = [
            'medal_id'      => (int) ($normalized['medal_id'] ?? 0),
            'rule_type'     => $rule_type,
            'rule_operator' => $rule_operator,
            'rule_value'    => (string) ($normalized['rule_value'] ?? 0),
            'rule_enabled'  => (int) ($normalized['rule_enabled'] ?? 1),
            'rule_notify'   => (int) ($normalized['rule_notify'] ?? 1),
            'rule_options'  => (string) $rule_options,
        ];

        if (!empty($normalized['rule_id'])) {
            $sql = 'UPDATE ' . $this->rules_table . '
                SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                WHERE rule_id = ' . (int) $normalized['rule_id'];
            $this->db->sql_query($sql);

            return (int) $normalized['rule_id'];
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
        $progressive_families = [];

        foreach ($this->get_enabled_rules() as $rule) {
            $provider = $this->get_provider_for_rule($rule);
            if (!$provider) {
                continue;
            }

            if ($provider->is_progressive()) {
                $progressive_families[$provider->get_family()] = true;
                continue;
            }

            if (!$this->rule_matches_user($rule, $user_row, $provider)) {
                continue;
            }

            $result = $this->grant_manager->grant_medal_to_user(
                $user_id,
                (int) $rule['medal_id'],
                (int) $rule['rule_id'],
                'auto',
                '',
                0,
                !empty($rule['rule_notify']),
                $provider->get_family(),
                [
                    'rule' => $rule,
                    'provider_key' => $provider->get_key(),
                    'provider_family' => $provider->get_family(),
                    'mode' => 'evaluate_user',
                ]
            );

            if (!empty($result['success']) && !empty($result['created'])) {
                $awarded++;
            }
        }

        foreach (array_keys($progressive_families) as $family) {
            $awarded += $this->sync_progressive_family_for_user($user_id, $family, $user_row);
        }

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

        $provider = $this->get_provider_for_rule($rule);
        if (!$provider) {
            return 0;
        }

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
            if ($provider->is_progressive()) {
                $awarded += $this->sync_progressive_family_for_user((int) $row['user_id'], $provider->get_family(), $row);
                continue;
            }

            if (!$this->rule_matches_user($rule, $row, $provider)) {
                continue;
            }

            $grant = $this->grant_manager->grant_medal_to_user(
                (int) $row['user_id'],
                (int) $rule['medal_id'],
                (int) $rule['rule_id'],
                'auto',
                '',
                0,
                !empty($rule['rule_notify']),
                $provider->get_family(),
                [
                    'rule' => $rule,
                    'provider_key' => $provider->get_key(),
                    'provider_family' => $provider->get_family(),
                    'mode' => 'sync_rule',
                ]
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

    public function get_rule_type_options(): array
    {
        $types = [];
        foreach ($this->provider_registry->all() as $provider) {
            $attributes = $provider->get_value_input_attributes();
            $types[] = [
                'key' => $provider->get_key(),
                'label_lang_key' => $provider->get_label_lang_key(),
                'description_lang_key' => $provider->get_description_lang_key(),
                'operators' => $provider->get_supported_operators(),
                'family' => $provider->get_family(),
                'is_progressive' => $provider->is_progressive(),
                'value_min' => (int) ($attributes['min'] ?? 0),
                'value_max' => (int) ($attributes['max'] ?? 999999999),
                'value_step' => (int) ($attributes['step'] ?? 1),
            ];
        }

        return $types;
    }

    public function get_default_rule_type(): string
    {
        return $this->provider_registry->get_default_key();
    }

    public function get_supported_operators(string $rule_type): array
    {
        $provider = $this->provider_registry->get($this->resolve_valid_rule_type($rule_type));

        return $provider ? $provider->get_supported_operators() : ['>=', '>', '=', '<=', '<'];
    }

    public function get_rule_type_label_lang_key(string $rule_type): string
    {
        $provider = $this->provider_registry->get($rule_type);

        return $provider ? $provider->get_label_lang_key() : 'ACP_MEMBERMEDALS_RULE_TYPE';
    }

    public function get_rule_type_input_attributes(string $rule_type): array
    {
        $provider = $this->provider_registry->get($this->resolve_valid_rule_type($rule_type));

        return $provider ? $provider->get_value_input_attributes() : [
            'min' => 0,
            'max' => 999999999,
            'step' => 1,
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
            FROM ' . $this->rules_table;

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
        $result = $this->db->sql_query($sql);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row;
    }

    protected function rule_matches_user(array $rule, array $user_row, ?rule_provider_interface $provider = null): bool
    {
        $provider = $provider ?: $this->get_provider_for_rule($rule);
        if (!$provider) {
            return false;
        }

        $rule_operator = (string) ($rule['rule_operator'] ?? '>=');
        if (!in_array($rule_operator, $provider->get_supported_operators(), true)) {
            $rule_operator = (string) ($provider->get_supported_operators()[0] ?? '>=');
        }

        $rule_value = (int) ($rule['rule_value'] ?? 0);
        $value = $provider->get_user_value((int) ($user_row['user_id'] ?? 0), $rule, $user_row);
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

    protected function sync_progressive_family_for_user(int $user_id, string $family, ?array $user_row = null): int
    {
        if ($user_id <= ANONYMOUS) {
            return 0;
        }

        $user_row = $user_row ?: $this->get_user_rule_context($user_id);
        if (!$user_row) {
            return 0;
        }

        $rules = $this->get_enabled_progressive_rules_by_family($family);
        if (empty($rules)) {
            return 0;
        }

        $matched_rules = [];
        foreach ($rules as $rule) {
            $provider = $this->get_provider_for_rule($rule);
            if ($provider && $this->rule_matches_user($rule, $user_row, $provider)) {
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
            $winner_provider = $this->get_provider_for_rule($winner);
            $grant = $this->grant_manager->grant_medal_to_user(
                $user_id,
                (int) $winner['medal_id'],
                (int) $winner['rule_id'],
                'auto',
                '',
                0,
                !empty($winner['rule_notify']),
                $winner_provider ? $winner_provider->get_family() : $family,
                [
                    'rule' => $winner,
                    'provider_key' => $winner_provider ? $winner_provider->get_key() : '',
                    'provider_family' => $winner_provider ? $winner_provider->get_family() : $family,
                    'mode' => 'sync_progressive_family',
                ]
            );

            if (!empty($grant['success']) && !empty($grant['created'])) {
                $created++;
            }
        }

        $keep_rule_id = $winner ? (int) $winner['rule_id'] : 0;
        foreach ($this->get_auto_awards_for_family($user_id, $family) as $award) {
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

    protected function get_enabled_progressive_rules_by_family(string $family): array
    {
        $rules = [];
        foreach ($this->get_enabled_rules() as $rule) {
            $provider = $this->get_provider_for_rule($rule);
            if (!$provider || !$provider->is_progressive() || $provider->get_family() !== $family) {
                continue;
            }

            $rules[] = $rule;
        }

        return $rules;
    }

    protected function get_auto_awards_for_family(int $user_id, string $family): array
    {
        $family_rule_types = $this->get_rule_types_for_family($family);
        if (empty($family_rule_types) && !$this->has_award_family_column()) {
            return [];
        }

        if ($this->has_award_family_column()) {
            $conditions = [
                "a.award_family = '" . $this->db->sql_escape($family) . "'",
            ];

            if (!empty($family_rule_types)) {
                $conditions[] = "(a.award_family = '' AND " . $this->db->sql_in_set('r.rule_type', $family_rule_types) . ')';
            }

            $sql = 'SELECT a.award_id, a.medal_id, a.rule_id, a.award_family
                FROM ' . $this->awards_table . ' a
                LEFT JOIN ' . $this->rules_table . ' r ON r.rule_id = a.rule_id
                WHERE a.user_id = ' . (int) $user_id . "
                    AND a.award_source = 'auto'
                    AND (" . implode(' OR ', $conditions) . ')';
        } else {
            $sql = 'SELECT a.award_id, a.medal_id, a.rule_id
                FROM ' . $this->awards_table . ' a
                LEFT JOIN ' . $this->rules_table . ' r ON r.rule_id = a.rule_id
                WHERE a.user_id = ' . (int) $user_id . "
                    AND a.award_source = 'auto'
                    AND " . $this->db->sql_in_set('r.rule_type', $family_rule_types);
        }

        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            if (!isset($row['award_family'])) {
                $row['award_family'] = '';
            }

            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }


    protected function has_award_family_column(): bool
    {
        if ($this->supports_award_family !== null) {
            return $this->supports_award_family;
        }

        $sql_layer = method_exists($this->db, 'get_sql_layer') ? (string) $this->db->get_sql_layer() : 'mysqli';
        $table_name = $this->awards_table;
        $exists = false;

        switch ($sql_layer) {
            case 'mysqli':
            case 'mysql4':
            case 'mysql':
                $sql = "SHOW COLUMNS FROM " . $table_name . " LIKE 'award_family'";
                $result = $this->db->sql_query($sql);
                $exists = (bool) $this->db->sql_fetchrow($result);
                $this->db->sql_freeresult($result);
            break;

            case 'postgres':
            case 'postgresql':
                $sql = "SELECT column_name
                    FROM information_schema.columns
                    WHERE table_name = '" . $this->db->sql_escape($table_name) . "'
                        AND column_name = 'award_family'";
                $result = $this->db->sql_query($sql);
                $exists = (bool) $this->db->sql_fetchrow($result);
                $this->db->sql_freeresult($result);
            break;

            case 'sqlite':
            case 'sqlite3':
                $sql = 'PRAGMA table_info(' . $table_name . ')';
                $result = $this->db->sql_query($sql);
                while ($row = $this->db->sql_fetchrow($result)) {
                    if ((string) ($row['name'] ?? '') === 'award_family') {
                        $exists = true;
                        break;
                    }
                }
                $this->db->sql_freeresult($result);
            break;

            case 'oracle':
                $sql = "SELECT column_name
                    FROM user_tab_cols
                    WHERE table_name = '" . strtoupper($this->db->sql_escape($table_name)) . "'
                        AND column_name = 'AWARD_FAMILY'";
                $result = $this->db->sql_query($sql);
                $exists = (bool) $this->db->sql_fetchrow($result);
                $this->db->sql_freeresult($result);
            break;

            default:
                $sql = "SELECT column_name
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_NAME = '" . $this->db->sql_escape($table_name) . "'
                        AND COLUMN_NAME = 'award_family'";
                $result = $this->db->sql_query($sql);
                $exists = (bool) $this->db->sql_fetchrow($result);
                $this->db->sql_freeresult($result);
            break;
        }

        $this->supports_award_family = $exists;

        return $this->supports_award_family;
    }

    protected function get_rule_types_for_family(string $family): array
    {
        $types = [];
        foreach ($this->provider_registry->all() as $provider) {
            if ($provider->get_family() === $family) {
                $types[] = $provider->get_key();
            }
        }

        return $types;
    }

    protected function get_provider_for_rule(array $rule): ?rule_provider_interface
    {
        return $this->provider_registry->get((string) ($rule['rule_type'] ?? ''));
    }

    protected function resolve_valid_rule_type(string $rule_type): string
    {
        if ($this->provider_registry->exists($rule_type)) {
            return $rule_type;
        }

        return $this->provider_registry->get_default_key();
    }
}
