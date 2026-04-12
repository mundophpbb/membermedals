<?php
/**
 *
 * Member Medals extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace mundophpbb\membermedals;

class ext extends \phpbb\extension\base
{
    public function enable_step($old_state)
    {
        if ($old_state === false)
        {
            $phpbb_notifications = $this->container->get('notification_manager');
            $phpbb_notifications->enable_notifications('mundophpbb.membermedals.notification.type.medal_awarded');

            return 'notifications';
        }

        return parent::enable_step($old_state);
    }

    public function disable_step($old_state)
    {
        if ($old_state === false)
        {
            $phpbb_notifications = $this->container->get('notification_manager');
            $phpbb_notifications->disable_notifications('mundophpbb.membermedals.notification.type.medal_awarded');

            return 'notifications';
        }

        return parent::disable_step($old_state);
    }

    public function purge_step($old_state)
    {
        if ($old_state === false)
        {
            $phpbb_notifications = $this->container->get('notification_manager');
            $phpbb_notifications->purge_notifications('mundophpbb.membermedals.notification.type.medal_awarded');

            return 'notifications';
        }

        return parent::purge_step($old_state);
    }
}
