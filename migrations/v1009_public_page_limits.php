<?php
namespace mundophpbb\membermedals\migrations;

class v1009_public_page_limits extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['membermedals_version']) && version_compare($this->config['membermedals_version'], '0.6.18', '>=');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\membermedals\migrations\v1008_ucp_featured_medals'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['membermedals_public_recent_limit', 5]],
            ['config.add', ['membermedals_public_top_members_limit', 5]],
            ['config.add', ['membermedals_public_extremes_limit', 6]],
            ['config.add', ['membermedals_public_winners_limit', 50]],
            ['config.update', ['membermedals_version', '0.6.18']],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['membermedals_public_recent_limit']],
            ['config.remove', ['membermedals_public_top_members_limit']],
            ['config.remove', ['membermedals_public_extremes_limit']],
            ['config.remove', ['membermedals_public_winners_limit']],
            ['config.update', ['membermedals_version', '0.6.17.1']],
        ];
    }
}
