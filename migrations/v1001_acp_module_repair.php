<?php
/**
 *
 * ACP module repair migration for Member Medals.
 *
 */

namespace mundophpbb\membermedals\migrations;

class v1001_acp_module_repair extends \phpbb\db\migration\container_aware_migration
{
    public function effectively_installed()
    {
        return isset($this->config['membermedals_version']) && version_compare($this->config['membermedals_version'], '0.2.2', '>=');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\membermedals\migrations\v1000_initial_schema'];
    }

    public function update_data()
    {
        return [
            ['custom', [[$this, 'install_acp_modules']]],
            ['config.update', ['membermedals_version', '0.2.2']],
        ];
    }

    public function revert_data()
    {
        return [
            ['custom', [[$this, 'remove_acp_modules']]],
            ['config.update', ['membermedals_version', '0.1.0']],
        ];
    }

    public function install_acp_modules()
    {
        $module_tool = $this->container->get('migrator.tool.module');

        if (!$module_tool->exists('acp', false, 'ACP_MEMBERMEDALS_TITLE'))
        {
            $module_tool->add('acp', 'ACP_CAT_DOT_MODS', 'ACP_MEMBERMEDALS_TITLE');
        }

        $modes = [
            'settings' => 'ACP_MEMBERMEDALS_SETTINGS',
            'medals'   => 'ACP_MEMBERMEDALS_MEDALS',
            'rules'    => 'ACP_MEMBERMEDALS_RULES',
            'awards'   => 'ACP_MEMBERMEDALS_AWARDS',
        ];

        foreach ($modes as $mode => $langname)
        {
            if (!$module_tool->exists('acp', 'ACP_MEMBERMEDALS_TITLE', $langname))
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

    public function remove_acp_modules()
    {
        $module_tool = $this->container->get('migrator.tool.module');

        $modes = [
            'ACP_MEMBERMEDALS_SETTINGS',
            'ACP_MEMBERMEDALS_MEDALS',
            'ACP_MEMBERMEDALS_RULES',
            'ACP_MEMBERMEDALS_AWARDS',
        ];

        foreach ($modes as $langname)
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
