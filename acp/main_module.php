<?php
/**
 *
 * Member Medals ACP module.
 *
 */

namespace mundophpbb\membermedals\acp;

class main_module
{
    public $u_action;
    public $tpl_name;
    public $page_title;

    public function main($id, $mode)
    {
        global $config, $phpbb_container, $request, $template, $user, $phpbb_log;

        $user->add_lang_ext('mundophpbb/membermedals', 'common');
        $user->add_lang_ext('mundophpbb/membermedals', 'acp_membermedals');

        /** @var \mundophpbb\membermedals\service\medals_manager $medals_manager */
        $medals_manager = $phpbb_container->get('mundophpbb.membermedals.medals_manager');

        /** @var \mundophpbb\membermedals\service\grant_manager $grant_manager */
        $grant_manager = $phpbb_container->get('mundophpbb.membermedals.grant_manager');

        /** @var \mundophpbb\membermedals\service\rules_manager $rules_manager */
        $rules_manager = $phpbb_container->get('mundophpbb.membermedals.rules_manager');

        /** @var \mundophpbb\membermedals\service\image_manager $image_manager */
        $image_manager = $phpbb_container->get('mundophpbb.membermedals.image_manager');

        $this->tpl_name = 'acp_membermedals_body';
        $this->page_title = $user->lang('ACP_MEMBERMEDALS_TITLE');

        add_form_key('acp_membermedals');

        $rule_type_options = $rules_manager->get_rule_type_options();
        $default_rule_type = $rules_manager->get_default_rule_type();

        switch ($mode) {
            case 'settings':
                $this->page_title = $user->lang('ACP_MEMBERMEDALS_SETTINGS');

                if ($request->is_set_post('submit')) {
                    if (!check_form_key('acp_membermedals')) {
                        trigger_error('FORM_INVALID');
                    }

                    $config->set('membermedals_enabled', $request->variable('membermedals_enabled', 1));
                    $config->set('membermedals_public_page', $request->variable('membermedals_public_page', 1));
                    $config->set('membermedals_notify', $request->variable('membermedals_notify', 1));
                    $config->set('membermedals_viewtopic_limit', $request->variable('membermedals_viewtopic_limit', 3));
                    $config->set('membermedals_featured_limit', max(1, min(8, $request->variable('membermedals_featured_limit', 3))));
                    $config->set('membermedals_public_recent_limit', max(0, min(20, $request->variable('membermedals_public_recent_limit', (int) ($config['membermedals_public_recent_limit'] ?? 5)))));
                    $config->set('membermedals_public_top_members_limit', max(0, min(20, $request->variable('membermedals_public_top_members_limit', (int) ($config['membermedals_public_top_members_limit'] ?? 5)))));
                    $config->set('membermedals_public_extremes_limit', max(0, min(20, $request->variable('membermedals_public_extremes_limit', (int) ($config['membermedals_public_extremes_limit'] ?? 6)))));
                    $config->set('membermedals_public_winners_limit', max(0, min(100, $request->variable('membermedals_public_winners_limit', (int) ($config['membermedals_public_winners_limit'] ?? 50)))));

                    $phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_MEMBERMEDALS_SETTINGS_UPDATED');

                    trigger_error($user->lang('ACP_MEMBERMEDALS_SETTINGS_SAVED') . adm_back_link($this->u_action));
                }

                $template->assign_vars([
                    'S_MODE_SETTINGS'               => true,
                    'MEMBERMEDALS_ENABLED'         => (int) $config['membermedals_enabled'],
                    'MEMBERMEDALS_PUBLIC_PAGE'     => (int) $config['membermedals_public_page'],
                    'MEMBERMEDALS_NOTIFY'          => (int) $config['membermedals_notify'],
                    'MEMBERMEDALS_VIEWTOPIC_LIMIT'     => (int) $config['membermedals_viewtopic_limit'],
                    'MEMBERMEDALS_FEATURED_LIMIT'      => (int) ($config['membermedals_featured_limit'] ?? 3),
                    'MEMBERMEDALS_PUBLIC_RECENT_LIMIT' => (int) ($config['membermedals_public_recent_limit'] ?? 5),
                    'MEMBERMEDALS_PUBLIC_TOP_MEMBERS_LIMIT' => (int) ($config['membermedals_public_top_members_limit'] ?? 5),
                    'MEMBERMEDALS_PUBLIC_EXTREMES_LIMIT' => (int) ($config['membermedals_public_extremes_limit'] ?? 6),
                    'MEMBERMEDALS_PUBLIC_WINNERS_LIMIT' => (int) ($config['membermedals_public_winners_limit'] ?? 50),
                ]);
            break;

            case 'medals':
                $this->page_title = $user->lang('ACP_MEMBERMEDALS_MEDALS');
                $image_manager->ensure_upload_dir();
                $action = $request->variable('action', '');
                $medal_id = $request->variable('medal_id', 0);

                if ($action === 'toggle' && $medal_id) {
                    $medals_manager->toggle_medal_active($medal_id);
                    redirect($this->u_action);
                }

                if ($action === 'delete' && $medal_id) {
                    if (confirm_box(true)) {
                        $existing_medal = $medals_manager->get_medal($medal_id);
                        if (!empty($existing_medal['medal_image'])) {
                            $image_manager->delete_internal_image((string) $existing_medal['medal_image']);
                        }
                        $medals_manager->delete_medal($medal_id);
                        $phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_MEMBERMEDALS_MEDAL_DELETED', false, [$medal_id]);
                        trigger_error($user->lang('ACP_MEMBERMEDALS_MEDAL_DELETED') . adm_back_link($this->u_action));
                    } else {
                        confirm_box(false, $user->lang('ACP_MEMBERMEDALS_CONFIRM_DELETE'), build_hidden_fields([
                            'action'   => 'delete',
                            'medal_id' => $medal_id,
                            'i'        => $id,
                            'mode'     => $mode,
                        ]));
                    }
                }

                if ($request->is_set_post('submit_medal')) {
                    if (!check_form_key('acp_membermedals')) {
                        trigger_error('FORM_INVALID');
                    }

                    $existing_medal = [];
                    $editing_medal_id = $request->variable('medal_id', 0);
                    if ($editing_medal_id) {
                        $existing_medal = $medals_manager->get_medal($editing_medal_id);
                    }

                    $medal_image = $image_manager->normalize_storage_path($request->variable('medal_image', '', true));
                    $remove_image = (bool) $request->variable('remove_medal_image', 0);

                    if ($remove_image && !empty($existing_medal['medal_image'])) {
                        $image_manager->delete_internal_image((string) $existing_medal['medal_image']);
                        $medal_image = '';
                    }

                    $uploaded_file = $request->file('medal_image_upload');
                    if (!empty($uploaded_file) && !empty($uploaded_file['name']) && $uploaded_file['name'] !== 'none') {
                        $upload = $image_manager->upload_medal_image($uploaded_file, (string) ($existing_medal['medal_image'] ?? ''));
                        if (empty($upload['success'])) {
                            trigger_error($user->lang($upload['message']) . adm_back_link($this->u_action));
                        }
                        if (!empty($upload['uploaded'])) {
                            $medal_image = $image_manager->normalize_storage_path((string) $upload['path']);
                        }
                    }

                    $medal_name = trim($request->variable('medal_name', '', true));
                    if ($medal_name === '') {
                        trigger_error($user->lang('ACP_MEMBERMEDALS_MEDAL_NAME_REQUIRED') . adm_back_link($this->u_action . ($editing_medal_id ? '&action=edit&medal_id=' . $editing_medal_id : '')));
                    }

                    $data = [
                        'medal_id'            => $editing_medal_id,
                        'medal_name'          => $medal_name,
                        'medal_description'   => $request->variable('medal_description', '', true),
                        'medal_image'         => $medal_image,
                        'medal_active'        => $request->variable('medal_active', 1),
                        'medal_hidden'        => $request->variable('medal_hidden', 0),
                        'medal_featured'      => $request->variable('medal_featured', 0),
                        'medal_display_order' => $request->variable('medal_display_order', 0),
                    ];

                    $saved_id = $medals_manager->save_medal($data);

                    $phpbb_log->add(
                        'admin',
                        $user->data['user_id'],
                        $user->ip,
                        $data['medal_id'] ? 'LOG_MEMBERMEDALS_MEDAL_UPDATED' : 'LOG_MEMBERMEDALS_MEDAL_ADDED',
                        false,
                        [$data['medal_name']]
                    );

                    $continue_editing = (bool) $request->variable('submit_medal_continue', 0);
                    $back_link = $continue_editing
                        ? $this->u_action . '&action=edit&medal_id=' . $saved_id
                        : $this->u_action;

                    trigger_error($user->lang('ACP_MEMBERMEDALS_MEDAL_SAVED') . adm_back_link($back_link));
                }

                $edit_medal = [
                    'medal_id'            => 0,
                    'medal_name'          => '',
                    'medal_description'   => '',
                    'medal_image'         => '',
                    'medal_image_url'     => '',
                    'medal_active'        => 1,
                    'medal_hidden'        => 0,
                    'medal_featured'      => 0,
                    'medal_display_order' => 0,
                ];

                if ($action === 'edit' && $medal_id) {
                    $existing = $medals_manager->get_medal($medal_id);
                    if ($existing) {
                        $edit_medal = $existing;
                    }
                    $edit_medal['medal_image'] = $image_manager->normalize_storage_path((string) ($edit_medal['medal_image'] ?? ''));
                }

                $medal_keywords = trim($request->variable('keywords', '', true));
                $medal_status = $request->variable('status_filter', 'all');
                $medals = $medals_manager->get_medals_for_acp([
                    'keywords' => $medal_keywords,
                    'status' => $medal_status,
                ]);
                $medal_stats = $medals_manager->get_medal_stats();

                foreach ($medals as $medal) {
                    $template->assign_block_vars('medals', [
                        'MEDAL_ID'          => (int) $medal['medal_id'],
                        'MEDAL_NAME'        => $medal['medal_name'],
                        'MEDAL_DESCRIPTION' => $medal['medal_description'],
                        'MEDAL_IMAGE'       => $medal['medal_image'],
                        'MEDAL_IMAGE_URL'   => $medal['medal_image_url'],
                        'MEDAL_IMAGE_CACHE' => md5((string) ($medal['medal_image'] ?? '') . '|' . (int) $medal['medal_id']),
                        'MEDAL_ACTIVE'      => (int) $medal['medal_active'],
                        'MEDAL_HIDDEN'      => (int) $medal['medal_hidden'],
                        'MEDAL_FEATURED'    => (int) $medal['medal_featured'],
                        'MEDAL_ORDER'       => (int) $medal['medal_display_order'],
                        'RULE_COUNT'        => (int) ($medal['rule_count'] ?? 0),
                        'AWARD_COUNT'       => (int) ($medal['award_count'] ?? 0),
                        'U_EDIT'            => $this->u_action . '&action=edit&medal_id=' . (int) $medal['medal_id'],
                        'U_DELETE'          => $this->u_action . '&action=delete&medal_id=' . (int) $medal['medal_id'],
                        'U_TOGGLE'          => $this->u_action . '&action=toggle&medal_id=' . (int) $medal['medal_id'],
                    ]);
                }

                $template->assign_vars([
                    'S_MODE_MEDALS'            => true,
                    'S_EDIT_MEDAL'             => $action === 'edit',
                    'EDIT_MEDAL_ID'            => (int) $edit_medal['medal_id'],
                    'EDIT_MEDAL_NAME'          => $edit_medal['medal_name'],
                    'EDIT_MEDAL_DESCRIPTION'   => $edit_medal['medal_description'],
                    'EDIT_MEDAL_IMAGE'         => $edit_medal['medal_image'],
                    'EDIT_MEDAL_IMAGE_URL'     => $edit_medal['medal_image_url'],
                    'EDIT_MEDAL_IMAGE_CACHE'   => md5((string) ($edit_medal['medal_image'] ?? '') . '|' . (int) ($edit_medal['medal_id'] ?? 0)),
                    'EDIT_MEDAL_ACTIVE'        => (int) $edit_medal['medal_active'],
                    'EDIT_MEDAL_HIDDEN'        => (int) $edit_medal['medal_hidden'],
                    'EDIT_MEDAL_FEATURED'      => (int) $edit_medal['medal_featured'],
                    'EDIT_MEDAL_DISPLAY_ORDER' => (int) $edit_medal['medal_display_order'],
                    'MEDAL_FILTER_KEYWORDS'    => $medal_keywords,
                    'MEDAL_FILTER_STATUS'      => $medal_status,
                    'MEDAL_STATS_TOTAL'        => (int) $medal_stats['total'],
                    'MEDAL_STATS_ACTIVE'       => (int) $medal_stats['active'],
                    'MEDAL_STATS_INACTIVE'     => (int) $medal_stats['inactive'],
                    'MEDAL_STATS_FEATURED'     => (int) $medal_stats['featured'],
                    'U_MEDALS_RESET_FILTERS'   => $this->u_action,
                ]);
            break;

            case 'rules':
                $this->page_title = $user->lang('ACP_MEMBERMEDALS_RULES');
                $action = $request->variable('action', '');
                $rule_id = $request->variable('rule_id', 0);

                if ($action === 'toggle' && $rule_id) {
                    $rules_manager->toggle_rule_enabled($rule_id);
                    redirect($this->u_action);
                }

                if ($action === 'sync' && $rule_id) {
                    if (confirm_box(true)) {
                        $awarded = $rules_manager->sync_rule($rule_id);
                        $phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_MEMBERMEDALS_RULE_SYNCED', false, [$rule_id, $awarded]);
                        trigger_error($user->lang('ACP_MEMBERMEDALS_RULE_SYNCED', $awarded) . adm_back_link($this->u_action));
                    } else {
                        confirm_box(false, $user->lang('ACP_MEMBERMEDALS_CONFIRM_SYNC_RULE'), build_hidden_fields([
                            'action'  => 'sync',
                            'rule_id' => $rule_id,
                            'i'       => $id,
                            'mode'    => $mode,
                        ]));
                    }
                }

                $rule_keywords = trim($request->variable('keywords', '', true));
                $rule_filter_type = $request->variable('rule_type_filter', 'all');
                $rule_filter_enabled = $request->variable('enabled_filter', 'all');
                $rule_sync_filters = [
                    'keywords' => $rule_keywords,
                    'type' => $rule_filter_type,
                    'enabled' => $rule_filter_enabled,
                ];

                if ($action === 'sync_all') {
                    if (confirm_box(true)) {
                        $sync_stats = $rules_manager->sync_all_rules();
                        $phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_MEMBERMEDALS_RULES_SYNCED', false, [$sync_stats['rules'], $sync_stats['awards']]);
                        trigger_error($user->lang('ACP_MEMBERMEDALS_RULES_SYNCED', $sync_stats['rules'], $sync_stats['awards']) . adm_back_link($this->u_action));
                    } else {
                        confirm_box(false, $user->lang('ACP_MEMBERMEDALS_CONFIRM_SYNC_ALL_RULES'), build_hidden_fields([
                            'action'  => 'sync_all',
                            'i'       => $id,
                            'mode'    => $mode,
                        ]));
                    }
                }

                if ($action === 'sync_filtered') {
                    if (confirm_box(true)) {
                        $sync_stats = $rules_manager->sync_rules_by_filters($rule_sync_filters);
                        $phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_MEMBERMEDALS_RULES_FILTERED_SYNCED', false, [$sync_stats['rules'], $sync_stats['awards']]);
                        trigger_error($user->lang('ACP_MEMBERMEDALS_RULES_FILTERED_SYNCED', $sync_stats['rules'], $sync_stats['awards']) . adm_back_link($this->u_action));
                    } else {
                        confirm_box(false, $user->lang('ACP_MEMBERMEDALS_CONFIRM_SYNC_FILTERED_RULES'), build_hidden_fields([
                            'action'         => 'sync_filtered',
                            'keywords'       => $rule_keywords,
                            'rule_type_filter' => $rule_filter_type,
                            'enabled_filter' => $rule_filter_enabled,
                            'i'              => $id,
                            'mode'           => $mode,
                        ]));
                    }
                }

                if ($action === 'delete' && $rule_id) {
                    if (confirm_box(true)) {
                        $rules_manager->delete_rule($rule_id);
                        $phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_MEMBERMEDALS_RULE_DELETED', false, [$rule_id]);
                        trigger_error($user->lang('ACP_MEMBERMEDALS_RULE_DELETED') . adm_back_link($this->u_action));
                    } else {
                        confirm_box(false, $user->lang('ACP_MEMBERMEDALS_CONFIRM_DELETE_RULE'), build_hidden_fields([
                            'action'  => 'delete',
                            'rule_id' => $rule_id,
                            'i'       => $id,
                            'mode'    => $mode,
                        ]));
                    }
                }

                if ($request->is_set_post('submit_rule')) {
                    if (!check_form_key('acp_membermedals')) {
                        trigger_error('FORM_INVALID');
                    }

                    $data = [
                        'rule_id'       => $request->variable('rule_id', 0),
                        'medal_id'      => $request->variable('medal_id', 0),
                        'rule_type'     => $request->variable('rule_type', $default_rule_type),
                        'rule_operator' => $request->variable('rule_operator', '>='),
                        'rule_value'    => $request->variable('rule_value', ''),
                        'rule_enabled'  => $request->variable('rule_enabled', 1),
                        'rule_notify'   => $request->variable('rule_notify', 1),
                    ];

                    $saved_rule_id = $rules_manager->save_rule($data);
                    $backfill_count = $rules_manager->sync_rule($saved_rule_id);

                    $phpbb_log->add(
                        'admin',
                        $user->data['user_id'],
                        $user->ip,
                        $data['rule_id'] ? 'LOG_MEMBERMEDALS_RULE_UPDATED' : 'LOG_MEMBERMEDALS_RULE_ADDED',
                        false,
                        [$saved_rule_id]
                    );

                    trigger_error($user->lang('ACP_MEMBERMEDALS_RULE_SAVED', $backfill_count) . adm_back_link($this->u_action . '&action=edit&rule_id=' . $saved_rule_id));
                }

                $rule_defaults = [
                    'rule_id'       => 0,
                    'medal_id'      => 0,
                    'rule_type'     => $default_rule_type,
                    'rule_operator' => '>=',
                    'rule_value'    => '',
                    'rule_enabled'  => 1,
                    'rule_notify'   => 1,
                ];

                $edit_rule = $rule_defaults;

                if ($action === 'edit' && $rule_id) {
                    $existing_rule = $rules_manager->get_rule($rule_id);
                    if ($existing_rule) {
                        $edit_rule = array_merge($rule_defaults, $existing_rule);
                    }
                }

                $rule_medals = $medals_manager->get_all_medals(false);
                if (!(int) $edit_rule['medal_id'] && !empty($rule_medals)) {
                    $edit_rule['medal_id'] = (int) $rule_medals[0]['medal_id'];
                }

                foreach ($rule_medals as $medal) {
                    $template->assign_block_vars('rule_medals', [
                        'MEDAL_ID'            => (int) $medal['medal_id'],
                        'MEDAL_NAME'          => $medal['medal_name'],
                        'MEDAL_DESCRIPTION'   => (string) ($medal['medal_description'] ?? ''),
                        'MEDAL_IMAGE_URL'     => (string) ($medal['medal_image_url'] ?? ''),
                        'MEDAL_IMAGE_CACHE'   => md5((string) ($medal['medal_image'] ?? '') . '|' . (int) ($medal['medal_id'] ?? 0)),
                        'S_SELECTED'          => (int) $medal['medal_id'] === (int) $edit_rule['medal_id'],
                    ]);
                }

                $selected_rule_medal = [];
                foreach ($rule_medals as $medal) {
                    if ((int) $medal['medal_id'] === (int) $edit_rule['medal_id']) {
                        $selected_rule_medal = $medal;
                        break;
                    }
                }

                foreach ($rule_type_options as $type_option) {
                    $template->assign_block_vars('rule_types', [
                        'RULE_TYPE_KEY'   => (string) $type_option['key'],
                        'RULE_TYPE_LANG'  => $user->lang((string) $type_option['label_lang_key']),
                        'RULE_OPERATORS'  => implode('|', (array) ($type_option['operators'] ?? [])),
                        'RULE_VALUE_MIN'  => (int) ($type_option['value_min'] ?? 0),
                        'RULE_VALUE_MAX'  => (int) ($type_option['value_max'] ?? 999999999),
                        'RULE_VALUE_STEP' => (int) ($type_option['value_step'] ?? 1),
                        'RULE_PROGRESSIVE' => !empty($type_option['is_progressive']) ? 1 : 0,
                        'S_SELECTED'      => (string) $type_option['key'] === (string) $edit_rule['rule_type'],
                    ]);
                }

                foreach ($rules_manager->get_supported_operators((string) ($edit_rule['rule_type'] ?? $default_rule_type)) as $operator) {
                    $template->assign_block_vars('rule_operators', [
                        'OPERATOR_VALUE' => $operator,
                        'OPERATOR_LABEL' => $operator,
                        'S_SELECTED'     => $operator === (string) ($edit_rule['rule_operator'] ?? '>='),
                    ]);
                }

                $rules = $rules_manager->get_rules_for_acp([
                    'keywords' => $rule_keywords,
                    'type' => $rule_filter_type,
                    'enabled' => $rule_filter_enabled,
                ]);
                $rule_stats = $rules_manager->get_rule_stats();

                foreach ($rules as $rule) {
                    $type_key = (string) ($rule['rule_type'] ?? $default_rule_type);
                    $template->assign_block_vars('rules', [
                        'RULE_ID'        => (int) ($rule['rule_id'] ?? 0),
                        'MEDAL_NAME'     => $rule['medal_name'] ?? '',
                        'RULE_TYPE'      => $type_key,
                        'RULE_TYPE_LANG' => $user->lang($rules_manager->get_rule_type_label_lang_key($type_key)),
                        'RULE_OPERATOR'  => (string) ($rule['rule_operator'] ?? '>='),
                        'RULE_VALUE'     => (string) ($rule['rule_value'] ?? ''),
                        'RULE_ENABLED'   => (int) ($rule['rule_enabled'] ?? 1),
                        'RULE_NOTIFY'    => (int) ($rule['rule_notify'] ?? 1),
                        'AWARD_COUNT'    => (int) ($rule['award_count'] ?? 0),
                        'U_EDIT'         => $this->u_action . '&action=edit&rule_id=' . (int) ($rule['rule_id'] ?? 0),
                        'U_DELETE'       => $this->u_action . '&action=delete&rule_id=' . (int) ($rule['rule_id'] ?? 0),
                        'U_TOGGLE'       => $this->u_action . '&action=toggle&rule_id=' . (int) ($rule['rule_id'] ?? 0),
                        'U_SYNC'         => $this->u_action . '&action=sync&rule_id=' . (int) ($rule['rule_id'] ?? 0),
                    ]);
                }

                $template->assign_vars([
                    'S_MODE_RULES'               => true,
                    'S_EDIT_RULE'                => $action === 'edit',
                    'S_HAS_RULE_MEDALS'          => !empty($rule_medals),
                    'EDIT_RULE_ID'               => (int) ($edit_rule['rule_id'] ?? 0),
                    'EDIT_RULE_TYPE'             => (string) ($edit_rule['rule_type'] ?? $default_rule_type),
                    'EDIT_RULE_OPERATOR'         => (string) ($edit_rule['rule_operator'] ?? '>='),
                    'EDIT_RULE_VALUE'            => (string) ($edit_rule['rule_value'] ?? ''),
                    'EDIT_RULE_ENABLED'          => (int) ($edit_rule['rule_enabled'] ?? 1),
                    'EDIT_RULE_NOTIFY'           => (int) ($edit_rule['rule_notify'] ?? 1),
                    'EDIT_RULE_VALUE_MIN'        => (int) (($rules_manager->get_rule_type_input_attributes((string) ($edit_rule['rule_type'] ?? $default_rule_type)))['min'] ?? 0),
                    'EDIT_RULE_VALUE_MAX'        => (int) (($rules_manager->get_rule_type_input_attributes((string) ($edit_rule['rule_type'] ?? $default_rule_type)))['max'] ?? 999999999),
                    'EDIT_RULE_VALUE_STEP'       => (int) (($rules_manager->get_rule_type_input_attributes((string) ($edit_rule['rule_type'] ?? $default_rule_type)))['step'] ?? 1),
                    'RULE_PREVIEW_MEDAL_NAME'    => (string) ($selected_rule_medal['medal_name'] ?? ''),
                    'RULE_PREVIEW_MEDAL_DESC'    => (string) ($selected_rule_medal['medal_description'] ?? ''),
                    'RULE_PREVIEW_MEDAL_IMAGE'   => (string) ($selected_rule_medal['medal_image_url'] ?? ''),
                    'RULE_PREVIEW_MEDAL_IMAGE_CACHE' => md5((string) ($selected_rule_medal['medal_image'] ?? '') . '|' . (int) ($selected_rule_medal['medal_id'] ?? 0)),
                    'RULE_FILTER_KEYWORDS'       => $rule_keywords,
                    'RULE_FILTER_TYPE'           => $rule_filter_type,
                    'RULE_FILTER_ENABLED'        => $rule_filter_enabled,
                    'RULE_STATS_TOTAL'           => (int) $rule_stats['total'],
                    'RULE_STATS_ENABLED'         => (int) $rule_stats['enabled'],
                    'RULE_STATS_DISABLED'        => (int) $rule_stats['disabled'],
                    'RULE_STATS_TOPICS'          => (int) $rule_stats['topics'],
                    'U_RULES_RESET_FILTERS'      => $this->u_action,
                    'U_RULES_SYNC_ALL'           => $this->u_action . '&action=sync_all',
                    'U_RULES_SYNC_FILTERED'      => $this->u_action . '&action=sync_filtered&keywords=' . rawurlencode($rule_keywords) . '&rule_type_filter=' . rawurlencode($rule_filter_type) . '&enabled_filter=' . rawurlencode($rule_filter_enabled),
                ]);
            break;

            case 'awards':
                $this->page_title = $user->lang('ACP_MEMBERMEDALS_AWARDS');

                if ($request->is_set_post('submit_award')) {
                    if (!check_form_key('acp_membermedals')) {
                        trigger_error('FORM_INVALID');
                    }

                    $award_action = $request->variable('award_action', 'grant');
                    $username = $request->variable('username', '', true);
                    $medal_id = $request->variable('award_medal_id', 0);
                    $reason = $request->variable('award_reason', '', true);

                    if ($award_action === 'remove') {
                        $result = $grant_manager->remove_medal_by_username($username, $medal_id);
                        $log_action = 'LOG_MEMBERMEDALS_AWARD_REMOVED';
                        $success_message = 'ACP_MEMBERMEDALS_AWARD_REMOVED';
                    } else {
                        $result = $grant_manager->grant_medal_by_username($username, $medal_id, $reason, (int) $user->data['user_id']);
                        $log_action = 'LOG_MEMBERMEDALS_AWARD_GRANTED';
                        $success_message = 'ACP_MEMBERMEDALS_AWARD_GRANTED';
                    }

                    if (!$result['success']) {
                        trigger_error($user->lang($result['message']) . adm_back_link($this->u_action));
                    }

                    $phpbb_log->add('admin', $user->data['user_id'], $user->ip, $log_action, false, [$username, $result['medal_name']]);
                    trigger_error($user->lang($success_message) . adm_back_link($this->u_action));
                }

                $recent_awards = $medals_manager->get_recent_awards(20);
                foreach ($recent_awards as $award) {
                    $template->assign_block_vars('recent_awards', [
                        'USERNAME'      => $award['username'],
                        'MEDAL_NAME'    => $award['medal_name'],
                        'AWARD_SOURCE'  => $award['award_source'],
                        'AWARD_REASON'  => $award['award_reason'],
                        'AWARDED_AT'    => $user->format_date((int) $award['awarded_at']),
                    ]);
                }

                foreach ($medals_manager->get_all_medals(false) as $medal) {
                    $template->assign_block_vars('award_medals', [
                        'MEDAL_ID'      => (int) $medal['medal_id'],
                        'MEDAL_NAME'    => $medal['medal_name'],
                    ]);
                }

                $template->assign_vars([
                    'S_MODE_AWARDS' => true,
                ]);
            break;
        }

        $script_path = trim((string) ($config['script_path'] ?? ''));
        $script_path = trim($script_path, '/');
        $membermedals_base_path = $script_path === '' ? '/' : '/' . $script_path . '/';

        $template->assign_vars([
            'U_ACTION' => $this->u_action,
            'MEMBERMEDALS_BOARD_URL' => $membermedals_base_path,
        ]);
    }
}
