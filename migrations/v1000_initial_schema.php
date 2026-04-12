<?php
/**
 *
 * Initial schema for Member Medals.
 *
 */

namespace mundophpbb\membermedals\migrations;

class v1000_initial_schema extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['membermedals_version']) && version_compare($this->config['membermedals_version'], '0.1.0', '>=');
    }

    static public function depends_on()
    {
        return ['\phpbb\db\migration\data\v33x\v331'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'membermedals' => [
                    'COLUMNS' => [
                        'medal_id'              => ['UINT', null, 'auto_increment'],
                        'medal_name'            => ['VCHAR_UNI:255', ''],
                        'medal_description'     => ['TEXT_UNI', ''],
                        'medal_image'           => ['VCHAR:255', ''],
                        'medal_active'          => ['BOOL', 1],
                        'medal_hidden'          => ['BOOL', 0],
                        'medal_featured'        => ['BOOL', 0],
                        'medal_display_order'   => ['UINT', 0],
                        'medal_created_at'      => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'medal_id',
                    'KEYS' => [
                        'medal_active'          => ['INDEX', 'medal_active'],
                        'medal_hidden'          => ['INDEX', 'medal_hidden'],
                        'medal_display_order'   => ['INDEX', 'medal_display_order'],
                    ],
                ],
                $this->table_prefix . 'membermedals_rules' => [
                    'COLUMNS' => [
                        'rule_id'               => ['UINT', null, 'auto_increment'],
                        'medal_id'              => ['UINT', 0],
                        'rule_type'             => ['VCHAR:100', ''],
                        'rule_operator'         => ['VCHAR:20', '>='],
                        'rule_value'            => ['VCHAR:255', ''],
                        'rule_enabled'          => ['BOOL', 1],
                        'rule_notify'           => ['BOOL', 1],
                        'rule_options'          => ['TEXT_UNI', ''],
                        'rule_created_at'       => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'rule_id',
                    'KEYS' => [
                        'medal_id'              => ['INDEX', 'medal_id'],
                        'rule_type'             => ['INDEX', 'rule_type'],
                        'rule_enabled'          => ['INDEX', 'rule_enabled'],
                    ],
                ],
                $this->table_prefix . 'membermedals_awards' => [
                    'COLUMNS' => [
                        'award_id'              => ['UINT', null, 'auto_increment'],
                        'user_id'               => ['UINT', 0],
                        'medal_id'              => ['UINT', 0],
                        'rule_id'               => ['UINT', 0],
                        'awarded_by_user_id'    => ['UINT', 0],
                        'award_source'          => ['VCHAR:20', 'manual'],
                        'award_reason'          => ['TEXT_UNI', ''],
                        'awarded_at'            => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'award_id',
                    'KEYS' => [
                        'user_medal_unique'     => ['UNIQUE', ['user_id', 'medal_id']],
                        'user_id'               => ['INDEX', 'user_id'],
                        'medal_id'              => ['INDEX', 'medal_id'],
                        'rule_id'               => ['INDEX', 'rule_id'],
                        'awarded_at'            => ['INDEX', 'awarded_at'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'membermedals',
                $this->table_prefix . 'membermedals_rules',
                $this->table_prefix . 'membermedals_awards',
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['membermedals_version', '0.1.0']],
            ['config.add', ['membermedals_enabled', 1]],
            ['config.add', ['membermedals_public_page', 1]],
            ['config.add', ['membermedals_notify', 1]],
            ['config.add', ['membermedals_viewtopic_limit', 3]],

            ['module.add', [
                'acp',
                'ACP_CAT_DOT_MODS',
                'ACP_MEMBERMEDALS_TITLE',
            ]],
            ['module.add', [
                'acp',
                'ACP_MEMBERMEDALS_TITLE',
                [
                    'module_basename' => '\mundophpbb\membermedals\acp\main_module',
                    'modes'           => ['settings', 'medals', 'rules', 'awards'],
                ],
            ]],
        ];
    }
}
