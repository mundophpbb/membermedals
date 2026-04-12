<?php
namespace mundophpbb\membermedals\migrations;

class v1004_profile_and_membership_rules extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['membermedals_version']) && version_compare($this->config['membermedals_version'], '0.4.0', '>=');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\membermedals\migrations\v1003_upload_and_posts_rules'];
    }

    public function update_data()
    {
        return [
            ['config.update', ['membermedals_version', '0.4.0']],
        ];
    }
}
