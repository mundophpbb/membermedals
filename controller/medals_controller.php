<?php
namespace mundophpbb\membermedals\controller;

use phpbb\config\config;
use phpbb\controller\helper;
use phpbb\template\template;
use phpbb\user;
use Symfony\Component\HttpFoundation\Response;
use mundophpbb\membermedals\service\medals_manager;
use mundophpbb\membermedals\service\image_manager;

class medals_controller
{
    protected config $config;
    protected template $template;
    protected helper $helper;
    protected user $user;
    protected medals_manager $medals_manager;
    protected image_manager $image_manager;

    public function __construct(config $config, template $template, helper $helper, user $user, medals_manager $medals_manager, image_manager $image_manager)
    {
        $this->config = $config;
        $this->template = $template;
        $this->helper = $helper;
        $this->user = $user;
        $this->medals_manager = $medals_manager;
        $this->image_manager = $image_manager;
    }

    public function image(int $medal_id): Response
    {
        $medal = $this->medals_manager->get_medal($medal_id);
        $image_path = (string) ($medal['medal_image'] ?? '');
        if ($image_path === '' || $this->image_manager->is_external_path($image_path)) {
            return new Response('', 404);
        }

        $full_path = $this->image_manager->get_internal_full_path($image_path);
        if ($full_path === '' || !is_file($full_path) || !is_readable($full_path)) {
            return new Response('', 404);
        }

        $content = @file_get_contents($full_path);
        if ($content === false) {
            return new Response('', 404);
        }

        $mime = $this->image_manager->guess_mime_type($full_path);
        $etag = '"' . md5((string) @filemtime($full_path) . '|' . (string) @filesize($full_path) . '|' . $image_path) . '"';

        return new Response($content, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $etag,
        ]);
    }

    public function index(): Response
    {
        $this->guard_public_page();
        $summary = $this->medals_manager->get_public_medals_summary();
        $this->assign_common_links();

        foreach ($this->medals_manager->get_public_medals_with_counts() as $medal) {
            $this->template->assign_block_vars('medals', [
                'MEDAL_NAME'        => $medal['medal_name'],
                'MEDAL_DESCRIPTION' => $medal['medal_description'],
                'MEDAL_IMAGE'       => $medal['medal_image_url'],
                'AWARD_COUNT'       => $medal['award_count'],
                'RARITY_LABEL'      => $this->user->lang('MEMBERMEDALS_RARITY_' . strtoupper($medal['rarity_label'])),
                'S_FEATURED'        => !empty($medal['medal_featured']),
                'U_VIEW_MEDAL'      => $medal['U_VIEW_MEDAL'],
            ]);
        }

        $recentLimit = max(0, min(20, (int) ($this->config['membermedals_public_recent_limit'] ?? 5)));
        $topMembersLimit = max(0, min(20, (int) ($this->config['membermedals_public_top_members_limit'] ?? 5)));
        $extremesLimit = max(0, min(20, (int) ($this->config['membermedals_public_extremes_limit'] ?? 6)));

        if ($recentLimit > 0) {
            foreach ($this->medals_manager->get_recent_awards($recentLimit) as $award) {
                $this->template->assign_block_vars('recent_awards', [
                    'USERNAME'      => get_username_string('full', (int) $award['user_id'], $award['username'], $award['user_colour']),
                    'MEDAL_NAME'    => $award['medal_name'],
                    'AWARDED_AT'    => $this->user->format_date((int) $award['awarded_at']),
                    'U_USER_MEDALS' => $award['U_USER_MEDALS'],
                    'U_VIEW_MEDAL'  => $award['U_VIEW_MEDAL'],
                ]);
            }
        }

        if ($topMembersLimit > 0) {
            foreach ($this->medals_manager->get_public_member_ranking($topMembersLimit) as $member) {
                $this->template->assign_block_vars('top_members', [
                    'RANK_POS'      => $member['rank_pos'],
                    'USERNAME_FULL' => get_username_string('full', (int) $member['user_id'], $member['username'], $member['user_colour']),
                    'AWARD_TOTAL'   => $member['award_total'],
                    'LATEST_AWARD'  => !empty($member['latest_award']) ? $this->user->format_date((int) $member['latest_award']) : '',
                    'U_USER_MEDALS' => $member['U_USER_MEDALS'],
                ]);
            }
        }

        if ($extremesLimit > 0) {
            foreach ($this->medals_manager->get_public_medals_extremes($extremesLimit, 'ASC') as $medal) {
                $this->template->assign_block_vars('rare_medals', [
                    'MEDAL_NAME'   => $medal['medal_name'],
                    'MEDAL_IMAGE'  => $medal['medal_image_url'],
                    'AWARD_COUNT'  => $medal['award_count'],
                    'RARITY_LABEL' => $this->user->lang('MEMBERMEDALS_RARITY_' . strtoupper($medal['rarity_label'])),
                    'U_VIEW_MEDAL' => $medal['U_VIEW_MEDAL'],
                ]);
            }

            foreach ($this->medals_manager->get_public_medals_extremes($extremesLimit, 'DESC') as $medal) {
                $this->template->assign_block_vars('popular_medals', [
                    'MEDAL_NAME'   => $medal['medal_name'],
                    'MEDAL_IMAGE'  => $medal['medal_image_url'],
                    'AWARD_COUNT'  => $medal['award_count'],
                    'RARITY_LABEL' => $this->user->lang('MEMBERMEDALS_RARITY_' . strtoupper($medal['rarity_label'])),
                    'U_VIEW_MEDAL' => $medal['U_VIEW_MEDAL'],
                ]);
            }
        }

        $this->template->assign_vars([
            'TOTAL_PUBLIC_MEDALS' => $summary['total_medals'],
            'TOTAL_PUBLIC_AWARDS' => $summary['total_awards'],
            'TOTAL_PUBLIC_MEMBERS' => $summary['total_members'],
            'S_HAS_RECENT_AWARDS' => $recentLimit > 0,
            'S_HAS_TOP_MEMBERS' => $topMembersLimit > 0,
            'S_HAS_EXTREMES' => $extremesLimit > 0,
        ]);

        return $this->helper->render('@mundophpbb_membermedals/medals_page.html', $this->user->lang('MEMBERMEDALS_PAGE_TITLE'));
    }

    public function user(int $user_id): Response
    {
        $this->guard_public_page();
        $member = $this->medals_manager->get_user_row($user_id);
        $this->assign_common_links();
        if (!$member) {
            trigger_error('NO_USER');
        }

        $award_total = 0;
        foreach ($this->medals_manager->get_user_medals($user_id, true) as $award) {
            $award_total++;
            $this->template->assign_block_vars('awards', [
                'MEDAL_NAME'        => $award['medal_name'],
                'MEDAL_DESCRIPTION' => $award['medal_description'],
                'MEDAL_IMAGE'       => $award['medal_image_url'],
                'AWARDED_AT'        => $this->user->format_date((int) $award['awarded_at']),
                'AWARD_SOURCE'      => $this->user->lang('MEMBERMEDALS_SOURCE_' . strtoupper((string) $award['award_source'])),
                'S_FEATURED'        => !empty($award['medal_featured']),
                'S_USER_FEATURED'   => !empty($award['user_featured']),
                'U_VIEW_MEDAL'      => $award['U_VIEW_MEDAL'],
            ]);
        }

        $member_rank = $this->medals_manager->get_public_member_rank_position($user_id);

        $this->template->assign_vars([
            'MEMBER_USERNAME'       => get_username_string('full', (int) $member['user_id'], $member['username'], $member['user_colour']),
            'AWARD_TOTAL'           => $award_total,
            'MEMBER_RANK'           => $member_rank,
            'S_HAS_MEMBER_RANK'     => $member_rank > 0,
            'U_ALL_MEDALS'          => $this->medals_manager->get_showcase_url(),
            'S_OWN_MEDALS_PAGE'     => !empty($this->user->data['user_id']) && (int) $this->user->data['user_id'] === (int) $member['user_id'],
        ]);

        return $this->helper->render('@mundophpbb_membermedals/medals_user_page.html', $this->user->lang('MEMBERMEDALS_USER_PAGE_TITLE'));
    }

    public function medal(int $medal_id): Response
    {
        $this->guard_public_page();
        $medal = $this->medals_manager->get_public_medal($medal_id);
        $this->assign_common_links();
        if (!$medal) {
            trigger_error('NO_DATA');
        }

        $award_count = $this->medals_manager->get_medal_award_count($medal_id);
        $rules = $this->medals_manager->get_medal_rule_summaries($medal_id);
        $winnersLimit = max(0, min(100, (int) ($this->config['membermedals_public_winners_limit'] ?? 50)));
        $winners = $this->medals_manager->get_medal_winners($medal_id, $winnersLimit > 0 ? $winnersLimit : 1);

        foreach ($rules as $rule) {
            $this->template->assign_block_vars('rules', [
                'RULE_LABEL'   => $this->format_rule_label($rule),
                'RULE_STATUS'  => !empty($rule['rule_enabled']) ? $this->user->lang('MEMBERMEDALS_ENABLED') : $this->user->lang('MEMBERMEDALS_DISABLED'),
                'S_RULE_ON'    => !empty($rule['rule_enabled']),
            ]);
        }

        if ($winnersLimit > 0) {
            foreach ($winners as $winner) {
                $this->template->assign_block_vars('winners', [
                    'USERNAME_FULL' => get_username_string('full', (int) $winner['user_id'], $winner['username'], $winner['user_colour']),
                    'AWARDED_AT'    => $this->user->format_date((int) $winner['awarded_at']),
                    'AWARD_SOURCE'  => $this->user->lang('MEMBERMEDALS_SOURCE_' . strtoupper((string) $winner['award_source'])),
                    'U_USER_MEDALS' => $winner['U_USER_MEDALS'],
                ]);
            }
        }

        $this->template->assign_vars([
            'MEDAL_NAME'          => $medal['medal_name'],
            'MEDAL_DESCRIPTION'   => $medal['medal_description'],
            'MEDAL_IMAGE'         => $medal['medal_image_url'],
            'MEDAL_WINNER_COUNT'  => $award_count,
            'MEDAL_RARITY'        => $this->user->lang('MEMBERMEDALS_RARITY_' . strtoupper($this->get_rarity_key($award_count))),
            'U_ALL_MEDALS'        => $this->medals_manager->get_showcase_url(),
            'S_HAS_WINNERS'       => $winnersLimit > 0,
        ]);

        return $this->helper->render('@mundophpbb_membermedals/medal_detail_page.html', $medal['medal_name']);
    }

    protected function assign_common_links(): void
    {
        if (!empty($this->user->data['user_id']) && (int) $this->user->data['user_id'] > ANONYMOUS && !$this->medals_manager->is_registered_bot((int) $this->user->data['user_id'])) {
            $current_user_id = (int) $this->user->data['user_id'];
            $this->template->assign_vars([
                'U_MY_MEMBERMEDALS' => $this->medals_manager->get_public_profile_url($current_user_id),
                'U_MEMBERMEDALS_UCP' => $this->medals_manager->get_ucp_url(),
            ]);
        }

        $this->template->assign_var('U_MEMBERMEDALS_HOME', $this->medals_manager->get_showcase_url());
    }

    protected function guard_public_page(): void
    {
        $this->user->add_lang_ext('mundophpbb/membermedals', 'common');
        if (empty($this->config['membermedals_public_page'])) {
            trigger_error('NOT_AUTHORISED');
        }
    }

    protected function format_rule_label(array $rule): string
    {
        $type_map = [
            'posts' => 'MEMBERMEDALS_RULE_TYPE_POSTS',
            'topics' => 'MEMBERMEDALS_RULE_TYPE_TOPICS',
            'avatar' => 'MEMBERMEDALS_RULE_TYPE_AVATAR',
            'signature' => 'MEMBERMEDALS_RULE_TYPE_SIGNATURE',
            'membership_days' => 'MEMBERMEDALS_RULE_TYPE_MEMBERSHIP_DAYS',
        ];
        $type_key = $type_map[(string) ($rule['rule_type'] ?? '')] ?? 'MEMBERMEDALS_RULE_TYPE_UNKNOWN';
        return $this->user->lang($type_key) . ' ' . (string) ($rule['rule_operator'] ?? '>=') . ' ' . (string) ($rule['rule_value'] ?? '');
    }

    protected function get_rarity_key(int $award_count): string
    {
        if ($award_count <= 0) {
            return 'UNCLAIMED';
        }
        if ($award_count <= 2) {
            return 'ULTRA_RARE';
        }
        if ($award_count <= 5) {
            return 'RARE';
        }
        if ($award_count <= 15) {
            return 'UNCOMMON';
        }
        return 'COMMON';
    }
}
