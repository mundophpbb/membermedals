<?php
namespace mundophpbb\membermedals\migrations;

class v1005_files_image_dir_and_grant_guard extends \phpbb\db\migration\migration
{
    public function effectively_installed(): bool
    {
        return isset($this->config['membermedals_version']) && version_compare((string) $this->config['membermedals_version'], '0.4.1', '>=');
    }

    static public function depends_on(): array
    {
        return ['\mundophpbb\membermedals\migrations\v1004_profile_and_membership_rules'];
    }

    public function update_data(): array
    {
        return [
            ['config.update', ['membermedals_image_dir', 'files/membermedals/']],
            ['config.update', ['membermedals_version', '0.4.1']],
        ];
    }
}
