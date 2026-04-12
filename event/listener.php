<?php
namespace mundophpbb\membermedals\event;

use phpbb\config\config;
use phpbb\controller\helper;
use phpbb\template\template;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use mundophpbb\membermedals\service\medals_manager;
use mundophpbb\membermedals\service\rules_manager;

class listener implements EventSubscriberInterface
{
    protected user $user;
    protected config $config;
    protected template $template;
    protected helper $helper;
    protected medals_manager $medals_manager;
    protected rules_manager $rules_manager;

    public function __construct(user $user, config $config, template $template, helper $helper, medals_manager $medals_manager, rules_manager $rules_manager)
    {
        $this->user = $user;
        $this->config = $config;
        $this->template = $template;
        $this->helper = $helper;
        $this->medals_manager = $medals_manager;
        $this->rules_manager = $rules_manager;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'core.user_setup'                 => 'load_language_on_setup',
            'core.page_header'                => 'page_header',
            'core.viewtopic_modify_post_row'  => 'viewtopic_modify_post_row',
            'core.memberlist_view_profile'    => 'memberlist_view_profile',
            'core.submit_post_end'            => 'submit_post_end',
        ];
    }

    public function load_language_on_setup($event): void
    {
        $lang_set_ext = $event['lang_set_ext'];
        $lang_set_ext[] = ['ext_name' => 'mundophpbb/membermedals', 'lang_set' => 'common'];
        $event['lang_set_ext'] = $lang_set_ext;

        if (!empty($this->config['membermedals_enabled']) && !empty($this->user->data['user_id']) && (int) $this->user->data['user_id'] > ANONYMOUS) {
            $this->rules_manager->evaluate_all_rules_for_user((int) $this->user->data['user_id']);
        }
    }

    public function page_header($event): void
    {
        if (empty($this->config['membermedals_enabled']) || empty($this->config['membermedals_public_page'])) {
            $this->template->assign_vars([
                'S_MEMBERMEDALS_NAV' => false,
            ]);
            return;
        }

        $vars = [
            'S_MEMBERMEDALS_NAV' => true,
            'U_MEMBERMEDALS_PAGE' => $this->medals_manager->get_showcase_url(),
        ];

        if (!empty($this->user->data['user_id']) && (int) $this->user->data['user_id'] > ANONYMOUS && !$this->medals_manager->is_registered_bot((int) $this->user->data['user_id'])) {
            $vars['U_MEMBERMEDALS_UCP'] = $this->medals_manager->get_ucp_url();
        }

        $this->template->assign_vars($vars);
    }

    public function viewtopic_modify_post_row($event): void
    {
        if (empty($this->config['membermedals_enabled'])) {
            return;
        }
        $post_row = $event['post_row'];
        $poster_id = (int) ($post_row['POSTER_ID'] ?? 0);
        if ($poster_id <= ANONYMOUS || $this->medals_manager->is_registered_bot($poster_id)) {
            return;
        }
        $post_row['MEMBERMEDALS_HTML'] = $this->medals_manager->render_viewtopic_html($poster_id, (int) ($this->config['membermedals_viewtopic_limit'] ?? 3), false);
        $event['post_row'] = $post_row;
    }

    public function memberlist_view_profile($event): void
    {
        if (empty($this->config['membermedals_enabled'])) {
            return;
        }

        $member = $event['member'];
        $user_id = (int) ($member['user_id'] ?? 0);
        if ($user_id <= ANONYMOUS || $this->medals_manager->is_registered_bot($user_id)) {
            return;
        }

        $this->template->assign_var('MEMBERMEDALS_PROFILE_HTML', $this->medals_manager->render_profile_html($user_id, 12, true));
    }

    public function submit_post_end($event): void
    {
        if (empty($this->config['membermedals_enabled'])) {
            return;
        }

        $poster_id = (int) ($event['data']['poster_id'] ?? 0);
        if ($poster_id <= ANONYMOUS || $this->medals_manager->is_registered_bot($poster_id)) {
            return;
        }

        $this->rules_manager->evaluate_all_rules_for_user($poster_id);
    }
}
