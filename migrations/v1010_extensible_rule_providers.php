<?php

namespace mundophpbb\membermedals\migrations;

class v1010_extensible_rule_providers extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['membermedals_version'])
            && version_compare((string) $this->config['membermedals_version'], '0.7.0', '>=');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\membermedals\migrations\v1009_public_page_limits'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'membermedals_awards' => [
                    'award_family' => ['VCHAR:100', ''],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'membermedals_awards' => [
                    'award_family',
                ],
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['config.update', ['membermedals_version', '0.7.0']],
        ];
    }
}
