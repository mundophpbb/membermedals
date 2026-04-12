<?php
/**
 *
 * Medal awarded notification type.
 *
 */

namespace mundophpbb\membermedals\notification\type;

class medal_awarded extends \phpbb\notification\type\base
{
    /**
     * {@inheritdoc}
     */
    public function get_type()
    {
        return 'mundophpbb.membermedals.notification.type.medal_awarded';
    }

    /**
     * {@inheritdoc}
     */
    public static $notification_option = [
        'id'    => 'mundophpbb.membermedals.notification.type.medal_awarded',
        'lang'  => 'NOTIFICATION_TYPE_MEMBERMEDALS_AWARDED',
        'group' => 'NOTIFICATION_GROUP_MISCELLANEOUS',
    ];

    /**
     * {@inheritdoc}
     */
    public static function get_item_id($type_data)
    {
        return (int) ($type_data['award_id'] ?? 0);
    }

    /**
     * {@inheritdoc}
     */
    public static function get_item_parent_id($type_data)
    {
        return (int) ($type_data['user_id'] ?? 0);
    }

    /**
     * {@inheritdoc}
     */
    public function is_available()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function users_to_query()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function get_style_class()
    {
        return 'notification-membermedals';
    }

    /**
     * {@inheritdoc}
     */
    public function get_title()
    {
        return $this->language->lang(
            'MEMBERMEDALS_NOTIFICATION_AWARDED',
            (string) $this->get_data('medal_name')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function get_reference()
    {
        return (string) $this->get_data('medal_name');
    }

    /**
     * {@inheritdoc}
     */
    public function get_url()
    {
        return append_sid(
            $this->phpbb_root_path . 'ucp.' . $this->php_ext,
            'i=-mundophpbb-membermedals-ucp-main_module&mode=overview'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function get_email_template()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function get_email_template_variables()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function find_users_for_notification($type_data, $options = [])
    {
        $user_id = (int) ($type_data['user_id'] ?? 0);
        if ($user_id <= ANONYMOUS)
        {
            return [];
        }

        return [$user_id => $this->notification_manager->get_default_methods()];
    }

    /**
     * {@inheritdoc}
     */
    public function create_insert_array($type_data, $pre_create_data = [])
    {
        $this->set_data('medal_id', (int) ($type_data['medal_id'] ?? 0));
        $this->set_data('medal_name', (string) ($type_data['medal_name'] ?? ''));
        $this->set_data('award_source', (string) ($type_data['award_source'] ?? 'manual'));

        parent::create_insert_array($type_data, $pre_create_data);
    }
}
