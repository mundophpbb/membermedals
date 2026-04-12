<?php
/**
 *
 * Manual and automatic award management service.
 *
 */

namespace mundophpbb\membermedals\service;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\notification\manager;

class grant_manager
{
    /** @var driver_interface */
    protected $db;

    /** @var config */
    protected $config;

    /** @var manager */
    protected $notification_manager;

    /** @var string */
    protected $medals_table;

    /** @var string */
    protected $awards_table;

    /** @var string */
    protected $featured_table;

    public function __construct(
        driver_interface $db,
        config $config,
        manager $notification_manager,
        string $medals_table,
        string $awards_table,
        string $featured_table
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->notification_manager = $notification_manager;
        $this->medals_table = $medals_table;
        $this->awards_table = $awards_table;
        $this->featured_table = $featured_table;
    }

    public function grant_medal_by_username(string $username, int $medal_id, string $reason = '', int $actor_id = 0): array
    {
        $user_row = $this->get_user_by_username($username);
        if (!$user_row) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_USER_NOT_FOUND',
            ];
        }

        return $this->grant_medal_to_user((int) $user_row['user_id'], $medal_id, 0, 'manual', $reason, $actor_id, true);
    }

    public function grant_medal_to_user(
        int $user_id,
        int $medal_id,
        int $rule_id = 0,
        string $source = 'manual',
        string $reason = '',
        int $actor_id = 0,
        bool $notify = true
    ): array {
        $user_row = $this->get_user_by_id($user_id);
        if (!$user_row) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_USER_NOT_FOUND',
            ];
        }

        $medal_row = $this->get_medal($medal_id);
        if (!$medal_row) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_MEDAL_NOT_FOUND',
            ];
        }

        if ($this->user_has_medal($user_id, $medal_id)) {
            return [
                'success' => true,
                'created' => false,
                'message' => 'ACP_MEMBERMEDALS_AWARD_ALREADY_EXISTS',
                'medal_name' => (string) ($medal_row['medal_name'] ?? ''),
            ];
        }

        $sql_ary = [
            'user_id'            => $user_id,
            'medal_id'           => $medal_id,
            'rule_id'            => $rule_id,
            'awarded_by_user_id' => $actor_id,
            'award_source'       => $source,
            'award_reason'       => $reason,
            'awarded_at'         => time(),
        ];

        $sql = 'INSERT INTO ' . $this->awards_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
        $this->db->sql_query($sql);

        $award_id = (int) $this->db->sql_nextid();
        if ($award_id > 0 && $notify && !empty($this->config['membermedals_notify'])) {
            $this->notification_manager->add_notifications(
                'mundophpbb.membermedals.notification.type.medal_awarded',
                [
                    'award_id'     => $award_id,
                    'user_id'      => $user_id,
                    'medal_id'     => $medal_id,
                    'medal_name'   => (string) ($medal_row['medal_name'] ?? ''),
                    'award_source' => $source,
                ]
            );
        }

        return [
            'success'    => true,
            'created'    => true,
            'message'    => $source === 'auto' ? 'ACP_MEMBERMEDALS_AUTO_AWARD_GRANTED' : 'ACP_MEMBERMEDALS_AWARD_GRANTED',
            'medal_name' => (string) ($medal_row['medal_name'] ?? ''),
        ];
    }

    public function remove_medal_by_username(string $username, int $medal_id): array
    {
        $user_row = $this->get_user_by_username($username);
        if (!$user_row) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_USER_NOT_FOUND',
            ];
        }

        $medal_row = $this->get_medal($medal_id);
        if (!$medal_row) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_MEDAL_NOT_FOUND',
            ];
        }

        if (!$this->user_has_medal((int) $user_row['user_id'], $medal_id)) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_AWARD_NOT_FOUND',
            ];
        }

        $sql = 'DELETE FROM ' . $this->awards_table . '
            WHERE user_id = ' . (int) $user_row['user_id'] . '
                AND medal_id = ' . (int) $medal_id;
        $this->db->sql_query($sql);

        $sql = 'DELETE FROM ' . $this->featured_table . '
            WHERE user_id = ' . (int) $user_row['user_id'] . '
                AND medal_id = ' . (int) $medal_id;
        $this->db->sql_query($sql);

        return [
            'success'    => true,
            'message'    => 'ACP_MEMBERMEDALS_AWARD_REMOVED',
            'medal_name' => (string) ($medal_row['medal_name'] ?? ''),
        ];
    }

    protected function get_user_by_username(string $username): array
    {
        $username_clean = utf8_clean_string($username);

        $sql = 'SELECT u.user_id, u.username
            FROM ' . USERS_TABLE . ' u
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            WHERE u.username_clean = \'' . $this->db->sql_escape($username_clean) . '\'
                AND b.user_id IS NULL';
        $result = $this->db->sql_query($sql);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row;
    }

    protected function get_user_by_id(int $user_id): array
    {
        $sql = 'SELECT u.user_id, u.username
            FROM ' . USERS_TABLE . ' u
            LEFT JOIN ' . BOTS_TABLE . ' b ON b.user_id = u.user_id
            WHERE u.user_id = ' . (int) $user_id . '
                AND b.user_id IS NULL';
        $result = $this->db->sql_query_limit($sql, 1);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row;
    }

    protected function get_medal(int $medal_id): array
    {
        $sql = 'SELECT medal_id, medal_name
            FROM ' . $this->medals_table . '
            WHERE medal_id = ' . (int) $medal_id;
        $result = $this->db->sql_query($sql);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row;
    }

    protected function user_has_medal(int $user_id, int $medal_id): bool
    {
        $sql = 'SELECT award_id
            FROM ' . $this->awards_table . '
            WHERE user_id = ' . (int) $user_id . '
                AND medal_id = ' . (int) $medal_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return !empty($row);
    }
}
