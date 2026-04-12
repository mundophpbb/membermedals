<?php
namespace mundophpbb\membermedals\migrations;

class v1008_ucp_featured_medals extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['membermedals_version']) && version_compare($this->config['membermedals_version'], '0.6.2', '>=');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\membermedals\migrations\v1007_ucp_module'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'membermedals_featured' => [
                    'COLUMNS' => [
                        'user_id'           => ['UINT', 0],
                        'medal_id'          => ['UINT', 0],
                        'featured_order'    => ['UINT', 0],
                        'featured_at'       => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => ['user_id', 'medal_id'],
                    'KEYS' => [
                        'user_featured_order' => ['INDEX', ['user_id', 'featured_order']],
                        'medal_id'            => ['INDEX', 'medal_id'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'membermedals_featured',
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['membermedals_featured_limit', 3]],
            ['config.update', ['membermedals_version', '0.6.2']],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['membermedals_featured_limit']],
            ['config.update', ['membermedals_version', '0.6.1']],
        ];
    }
}
