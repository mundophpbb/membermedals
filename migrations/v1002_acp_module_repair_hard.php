<?php
/**
 *
 * Hard ACP module repair migration for Member Medals.
 *
 */

namespace mundophpbb\membermedals\migrations;

class v1002_acp_module_repair_hard extends \phpbb\db\migration\container_aware_migration
{
    public function effectively_installed()
    {
        return version_compare((string) ($this->config['membermedals_version'] ?? '0.0.0'), '0.2.3', '>=')
            && $this->module_exists('ACP_CAT_DOT_MODS', 'ACP_MEMBERMEDALS_TITLE')
            && $this->mode_exists('settings')
            && $this->mode_exists('medals')
            && $this->mode_exists('rules')
            && $this->mode_exists('awards');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\membermedals\migrations\v1001_acp_module_repair'];
    }

    public function update_data()
    {
        return [
            ['custom', [[$this, 'install_acp_modules_hard']]],
            ['config.update', ['membermedals_version', '0.2.3']],
        ];
    }

    public function revert_data()
    {
        return [
            ['custom', [[$this, 'remove_acp_modules_hard']]],
            ['config.update', ['membermedals_version', '0.2.2']],
        ];
    }

    protected function module_exists($parent_langname, $langname)
    {
        $sql = 'SELECT module_id
            FROM ' . MODULES_TABLE . "
            WHERE module_class = 'acp'
                AND module_langname = '" . $this->db->sql_escape($langname) . "'
                AND parent_id IN (
                    SELECT module_id
                    FROM " . MODULES_TABLE . "
                    WHERE module_class = 'acp'
                        AND module_langname = '" . $this->db->sql_escape($parent_langname) . "'
                )";
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return (bool) $row;
    }

    protected function mode_exists($mode)
    {
        $sql = 'SELECT module_id
            FROM ' . MODULES_TABLE . "
            WHERE module_class = 'acp'
                AND module_basename = '" . $this->db->sql_escape('\mundophpbb\membermedals\acp\main_module') . "'
                AND module_mode = '" . $this->db->sql_escape($mode) . "'";
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return (bool) $row;
    }

    public function install_acp_modules_hard()
    {
        $module_tool = $this->container->get('migrator.tool.module');

        if (!$module_tool->exists('acp', false, 'ACP_MEMBERMEDALS_TITLE'))
        {
            $module_tool->add('acp', 'ACP_CAT_DOT_MODS', 'ACP_MEMBERMEDALS_TITLE');
        }

        $definitions = [
            'settings' => 'ACP_MEMBERMEDALS_SETTINGS',
            'medals'   => 'ACP_MEMBERMEDALS_MEDALS',
            'rules'    => 'ACP_MEMBERMEDALS_RULES',
            'awards'   => 'ACP_MEMBERMEDALS_AWARDS',
        ];

        foreach ($definitions as $mode => $langname)
        {
            if (!$this->mode_exists($mode))
            {
                $module_tool->add('acp', 'ACP_MEMBERMEDALS_TITLE', [
                    'module_basename' => '\mundophpbb\membermedals\acp\main_module',
                    'module_langname' => $langname,
                    'module_mode'     => $mode,
                    'module_auth'     => 'ext_mundophpbb/membermedals && acl_a_board',
                ]);
            }
        }
    }

    public function remove_acp_modules_hard()
    {
        $module_tool = $this->container->get('migrator.tool.module');

        foreach (['ACP_MEMBERMEDALS_SETTINGS', 'ACP_MEMBERMEDALS_MEDALS', 'ACP_MEMBERMEDALS_RULES', 'ACP_MEMBERMEDALS_AWARDS'] as $langname)
        {
            if ($module_tool->exists('acp', 'ACP_MEMBERMEDALS_TITLE', $langname, true))
            {
                $module_tool->remove('acp', 'ACP_MEMBERMEDALS_TITLE', $langname);
            }
        }

        if ($module_tool->exists('acp', 'ACP_CAT_DOT_MODS', 'ACP_MEMBERMEDALS_TITLE', true))
        {
            $module_tool->remove('acp', 'ACP_CAT_DOT_MODS', 'ACP_MEMBERMEDALS_TITLE');
        }
    }
}
