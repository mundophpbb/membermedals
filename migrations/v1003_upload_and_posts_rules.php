<?php
namespace mundophpbb\membermedals\migrations;

class v1003_upload_and_posts_rules extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['membermedals_version']) && version_compare($this->config['membermedals_version'], '0.3.0', '>=');
    }

    static public function depends_on()
    {
        return [
            '\\mundophpbb\\membermedals\\migrations\\v1002_acp_module_repair_hard',
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['membermedals_image_dir', 'ext/mundophpbb/membermedals/styles/all/theme/images/medals/']],
            ['config.update', ['membermedals_version', '0.3.0']],
        ];
    }
}
