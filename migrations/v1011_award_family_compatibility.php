<?php

namespace mundophpbb\membermedals\migrations;

class v1011_award_family_compatibility extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_column_exists($this->table_prefix . 'membermedals_awards', 'award_family')
            && isset($this->config['membermedals_version'])
            && version_compare((string) $this->config['membermedals_version'], '0.7.1', '>=');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\membermedals\migrations\v1010_extensible_rule_providers'];
    }

    public function update_schema()
    {
        if ($this->db_tools->sql_column_exists($this->table_prefix . 'membermedals_awards', 'award_family')) {
            return [];
        }

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
        return [];
    }

    public function update_data()
    {
        return [
            ['config.update', ['membermedals_version', '0.7.1']],
        ];
    }
}
