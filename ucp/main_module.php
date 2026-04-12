<?php
namespace mundophpbb\membermedals\ucp;

class main_module
{
    public $u_action = '';
    public $tpl_name = '';
    public $page_title = '';

    public function main($id, $mode)
    {
        global $phpbb_container, $request, $template, $user;

        $user->add_lang_ext('mundophpbb/membermedals', 'common');
        $user->add_lang_ext('mundophpbb/membermedals', 'info_ucp_membermedals');

        $config = $phpbb_container->get('config');
        /** @var \mundophpbb\membermedals\service\medals_manager $medals_manager */
        $medals_manager = $phpbb_container->get('mundophpbb.membermedals.medals_manager');

        if (empty($config['membermedals_enabled'])) {
            trigger_error('NOT_AUTHORISED');
        }

        $user_id = (int) ($user->data['user_id'] ?? 0);
        if ($user_id <= ANONYMOUS || $medals_manager->is_registered_bot($user_id)) {
            trigger_error('NOT_AUTHORISED');
        }

        add_form_key('ucp_membermedals');

        switch ($mode) {
            case 'overview':
            default:
                $this->tpl_name = '@mundophpbb_membermedals/ucp_membermedals_body';
                $this->page_title = $user->lang('UCP_MEMBERMEDALS_TITLE');

                $message = '';
                $featured_limit = $medals_manager->get_featured_limit();

                if ($request->is_set_post('submit_featured')) {
                    if (!check_form_key('ucp_membermedals')) {
                        trigger_error('FORM_INVALID');
                    }

                    $selected = $request->variable('featured_medals', [0]);
                    $selected_orders = $request->variable('featured_order', [0 => 0]);
                    $saved_count = $medals_manager->save_user_featured_medals($user_id, $selected, $selected_orders);
                    $message = $user->lang('UCP_MEMBERMEDALS_FEATURED_SAVED', (int) $saved_count, (int) $featured_limit);
                }
                $award_total = 0;
                $featured_orders = $medals_manager->get_user_featured_order_map($user_id, true);
                $awards = $medals_manager->get_user_medals($user_id, true);
                foreach ($awards as $award) {
                    $award_total++;
                    $medal_id = (int) $award['medal_id'];
                    $featured_order = (int) ($featured_orders[$medal_id] ?? 0);
                    $is_user_featured = $featured_order > 0;
                    $template->assign_block_vars('awards', [
                        'MEDAL_ID'          => $medal_id,
                        'MEDAL_NAME'        => $award['medal_name'],
                        'MEDAL_DESCRIPTION' => $award['medal_description'],
                        'MEDAL_IMAGE'       => $award['medal_image_url'],
                        'AWARDED_AT'        => $user->format_date((int) $award['awarded_at']),
                        'AWARD_SOURCE'      => $user->lang('MEMBERMEDALS_SOURCE_' . strtoupper((string) $award['award_source'])),
                        'S_FEATURED'        => !empty($award['medal_featured']),
                        'S_USER_FEATURED'   => $is_user_featured,
                        'FEATURED_ORDER'    => $featured_order > 0 ? $featured_order : min($award_total, $featured_limit),
                        'U_VIEW_MEDAL'      => $award['U_VIEW_MEDAL'],
                    ]);

                    for ($i = 1; $i <= $featured_limit; $i++) {
                        $template->assign_block_vars('awards.featured_order_options', [
                            'VALUE' => $i,
                        ]);
                    }
                }

                $template->assign_vars([
                    'S_MODE_OVERVIEW'               => true,
                    'S_MEMBERMEDALS_MESSAGE'        => $message !== '',
                    'MEMBERMEDALS_MESSAGE'          => $message,
                    'UCP_MEMBERMEDALS_TOTAL'        => $award_total,
                    'UCP_MEMBERMEDALS_FEATURED_TOTAL'=> count($featured_orders),
                    'UCP_MEMBERMEDALS_FEATURED_LIMIT'=> $featured_limit,
                    'UCP_MEMBERMEDALS_FEATURED_EXPLAIN' => $user->lang('UCP_MEMBERMEDALS_FEATURED_EXPLAIN', (int) $featured_limit, (int) $featured_limit),
                    'U_MEMBERMEDALS_SHOWCASE'       => $medals_manager->get_showcase_url(),
                    'U_MEMBERMEDALS_PUBLIC_PROFILE' => $medals_manager->get_public_profile_url($user_id),
                    'U_ACTION'                      => $this->u_action,
                ]);
            break;
        }
    }
}
