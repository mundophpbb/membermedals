<?php
namespace mundophpbb\membermedals\migrations;

class v1006_request_file_and_upload_dir_fix extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return version_compare((string) $this->config['membermedals_version'], '0.4.3', '>=');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\membermedals\migrations\v1005_files_image_dir_and_grant_guard'];
    }

    public function update_data()
    {
        return [
            ['config.update', ['membermedals_version', '0.4.3']],
            ['config.update', ['membermedals_image_dir', 'files/membermedals/']],
        ];
    }
}
