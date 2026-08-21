<?php
/**
 * @package   DPCalendar
 * @copyright Copyright (C) 2020 Digital Peak GmbH. <https://www.digital-peak.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 */

\defined('_JEXEC') or die();

use Joomla\CMS\Language\Text;
?>
<div class="com-dpcalendar-adminlist__footer dp-footer">
	<div><?php echo Text::_('COM_DPCALENDAR_FOOTER_UPGRADE'); ?></div>
	<div><?php echo Text::sprintf('COM_DPCALENDAR_FOOTER', $this->input->getString('DPCALENDAR_VERSION', '')); ?></div>
</div>
