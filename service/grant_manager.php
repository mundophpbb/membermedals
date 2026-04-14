<?php
/**
 *
 * Manual and automatic award management service.
 *
 */

namespace mundophpbb\membermedals\service;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\event\dispatcher_interface;
use phpbb\notification\manager;

class grant_manager
{
    /** @var driver_interface */
    protected $db;

    /** @var config */
    protected $config;

    /** @var manager */
    protected $notification_manager;

    protected dispatcher_interface $dispatcher;

    /** @var string */
    protected $medals_table;

    /** @var string */
    protected $awards_table;

    /** @var string */
    protected $featured_table;

    /** @var bool|null */
    protected $supports_award_family = null;

    public function __construct(
        driver_interface $db,
        config $config,
        manager $notification_manager,
        dispatcher_interface $dispatcher,
        string $medals_table,
        string $awards_table,
        string $featured_table
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->notification_manager = $notification_manager;
        $this->dispatcher = $dispatcher;
        $this->medals_table = $medals_table;
        $this->awards_table = $awards_table;
        $this->featured_table = $featured_table;
    }

    public function grant_medal_by_username(string $username, int $medal_id, string $reason = '', int $actor_id = 0, array $context = []): array
    {
        $user_row = $this->get_user_by_username($username);
        if (!$user_row) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_USER_NOT_FOUND',
            ];
        }

        return $this->grant_medal_to_user((int) $user_row['user_id'], $medal_id, 0, 'manual', $reason, $actor_id, true, '', $context);
    }

    public function grant_medal_to_user(
        int $user_id,
        int $medal_id,
        int $rule_id = 0,
        string $source = 'manual',
        string $reason = '',
        int $actor_id = 0,
        bool $notify = true,
        string $award_family = '',
        array $context = []
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

        $cancel = false;
        $cancel_message = '';
        extract($this->dispatcher->trigger_event('mundophpbb.membermedals.before_award', compact(
            'user_id',
            'medal_id',
            'rule_id',
            'source',
            'reason',
            'actor_id',
            'notify',
            'award_family',
            'context',
            'user_row',
            'medal_row',
            'cancel',
            'cancel_message'
        )));

        if (!empty($cancel)) {
            return [
                'success' => false,
                'message' => $cancel_message !== '' ? (string) $cancel_message : 'ACP_MEMBERMEDALS_AWARD_NOT_FOUND',
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

        if ($this->has_award_family_column()) {
            $sql_ary['award_family'] = $award_family;
        }

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

        $result = [
            'success'    => true,
            'created'    => true,
            'message'    => $source === 'auto' ? 'ACP_MEMBERMEDALS_AUTO_AWARD_GRANTED' : 'ACP_MEMBERMEDALS_AWARD_GRANTED',
            'medal_name' => (string) ($medal_row['medal_name'] ?? ''),
            'award_id'   => $award_id,
        ];

        $this->dispatcher->trigger_event('mundophpbb.membermedals.after_award', compact(
            'award_id',
            'user_id',
            'medal_id',
            'rule_id',
            'source',
            'reason',
            'actor_id',
            'notify',
            'award_family',
            'context',
            'user_row',
            'medal_row',
            'result'
        ));

        return $result;
    }

    public function remove_medal_by_username(string $username, int $medal_id, array $context = []): array
    {
        $user_row = $this->get_user_by_username($username);
        if (!$user_row) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_USER_NOT_FOUND',
            ];
        }

        return $this->revoke_medal_from_user((int) $user_row['user_id'], $medal_id, $context);
    }

    public function revoke_medal_from_user(int $user_id, int $medal_id, array $context = []): array
    {
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

        if (!$this->user_has_medal($user_id, $medal_id)) {
            return [
                'success' => false,
                'message' => 'ACP_MEMBERMEDALS_AWARD_NOT_FOUND',
                'medal_name' => (string) ($medal_row['medal_name'] ?? ''),
            ];
        }

        $cancel = false;
        $cancel_message = '';
        extract($this->dispatcher->trigger_event('mundophpbb.membermedals.before_revoke', compact(
            'user_id',
            'medal_id',
            'context',
            'user_row',
            'medal_row',
            'cancel',
            'cancel_message'
        )));

        if (!empty($cancel)) {
            return [
                'success' => false,
                'message' => $cancel_message !== '' ? (string) $cancel_message : 'ACP_MEMBERMEDALS_AWARD_NOT_FOUND',
                'medal_name' => (string) ($medal_row['medal_name'] ?? ''),
            ];
        }

        $this->remove_medal_from_user($user_id, $medal_id, $context);

        $result = [
            'success'    => true,
            'message'    => 'ACP_MEMBERMEDALS_AWARD_REMOVED',
            'medal_name' => (string) ($medal_row['medal_name'] ?? ''),
        ];

        $this->dispatcher->trigger_event('mundophpbb.membermedals.after_revoke', compact(
            'user_id',
            'medal_id',
            'context',
            'user_row',
            'medal_row',
            'result'
        ));

        return $result;
    }

    public function remove_medal_from_user(int $user_id, int $medal_id, array $context = []): void
    {
        if ($user_id <= ANONYMOUS || $medal_id <= 0) {
            return;
        }

        $sql = 'DELETE FROM ' . $this->awards_table . '
            WHERE user_id = ' . (int) $user_id . '
                AND medal_id = ' . (int) $medal_id;
        $this->db->sql_query($sql);

        $sql = 'DELETE FROM ' . $this->featured_table . '
            WHERE user_id = ' . (int) $user_id . '
                AND medal_id = ' . (int) $medal_id;
        $this->db->sql_query($sql);
    }

    public function has_medal(int $user_id, int $medal_id): bool
    {
        return $this->user_has_medal($user_id, $medal_id);
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
        $result = $this->db->sql_query($sql);
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

    protected function user_has_medal(int $user_id, int $medal_id): bool
    {
        $sql = 'SELECT award_id
            FROM ' . $this->awards_table . '
            WHERE user_id = ' . (int) $user_id . '
                AND medal_id = ' . (int) $medal_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return !empty($row);
    }
}
