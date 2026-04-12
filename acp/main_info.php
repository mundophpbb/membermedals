<?php
/**
 *
 * Member Medals ACP module info.
 *
 */

namespace mundophpbb\membermedals\acp;

class main_info
{
    public function module()
    {
        return [
            'filename'  => '\mundophpbb\membermedals\acp\main_module',
            'title'     => 'ACP_MEMBERMEDALS_TITLE',
            'modes'     => [
                'settings'  => [
                    'title' => 'ACP_MEMBERMEDALS_SETTINGS',
                    'auth'  => 'ext_mundophpbb/membermedals && acl_a_board',
                    'cat'   => ['ACP_MEMBERMEDALS_TITLE'],
                ],
                'medals'    => [
                    'title' => 'ACP_MEMBERMEDALS_MEDALS',
                    'auth'  => 'ext_mundophpbb/membermedals && acl_a_board',
                    'cat'   => ['ACP_MEMBERMEDALS_TITLE'],
                ],
                'rules'     => [
                    'title' => 'ACP_MEMBERMEDALS_RULES',
                    'auth'  => 'ext_mundophpbb/membermedals && acl_a_board',
                    'cat'   => ['ACP_MEMBERMEDALS_TITLE'],
                ],
                'awards'    => [
                    'title' => 'ACP_MEMBERMEDALS_AWARDS',
                    'auth'  => 'ext_mundophpbb/membermedals && acl_a_board',
                    'cat'   => ['ACP_MEMBERMEDALS_TITLE'],
                ],
            ],
        ];
    }
}
