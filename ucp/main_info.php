<?php
namespace mundophpbb\membermedals\ucp;

class main_info
{
    public function module()
    {
        return [
            'filename'  => '\\mundophpbb\\membermedals\\ucp\\main_module',
            'title'     => 'UCP_MEMBERMEDALS_TITLE',
            'modes'     => [
                'overview' => [
                    'title' => 'UCP_MEMBERMEDALS_TITLE',
                    'auth'  => 'acl_u_',
                    'cat'   => ['UCP_PROFILE'],
                ],
            ],
        ];
    }

    public function install()
    {
    }

    public function uninstall()
    {
    }
}
