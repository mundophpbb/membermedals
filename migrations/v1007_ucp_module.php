<?php
namespace mundophpbb\membermedals\migrations;

class v1007_ucp_module extends \phpbb\db\migration\container_aware_migration
{
    public function effectively_installed()
    {
        return isset($this->config['membermedals_version']) && version_compare($this->config['membermedals_version'], '0.6.0', '>=');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\membermedals\migrations\v1006_request_file_and_upload_dir_fix'];
    }

    public function update_data()
    {
        return [
            ['custom', [[$this, 'install_ucp_module']]],
            ['config.update', ['membermedals_version', '0.6.0']],
        ];
    }

    public function revert_data()
    {
        return [
            ['custom', [[$this, 'remove_ucp_module']]],
            ['config.update', ['membermedals_version', '0.5.5']],
        ];
    }

    public function install_ucp_module()
    {
        $module_tool = $this->container->get('migrator.tool.module');

        if (!$module_tool->exists('ucp', 'UCP_PROFILE', 'UCP_MEMBERMEDALS_TITLE')) {
            $module_tool->add('ucp', 'UCP_PROFILE', [
                'module_basename' => '\\mundophpbb\\membermedals\\ucp\\main_module',
                'module_langname' => 'UCP_MEMBERMEDALS_TITLE',
                'module_mode'     => 'overview',
                'module_auth'     => 'acl_u_',
            ]);
        }
    }

    public function remove_ucp_module()
    {
        $module_tool = $this->container->get('migrator.tool.module');

        if ($module_tool->exists('ucp', 'UCP_PROFILE', 'UCP_MEMBERMEDALS_TITLE', true)) {
            $module_tool->remove('ucp', 'UCP_PROFILE', 'UCP_MEMBERMEDALS_TITLE');
        }
    }
}
