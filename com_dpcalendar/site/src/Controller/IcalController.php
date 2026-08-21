<?php
/**
 * @package   DPCalendar
 * @copyright Copyright (C) 2014 Digital Peak GmbH. <https://www.digital-peak.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 */

namespace DigitalPeak\Component\DPCalendar\Site\Controller;

\defined('_JEXEC') or die();

use DigitalPeak\Component\DPCalendar\Administrator\Calendar\CalendarInterface;
use DigitalPeak\Component\DPCalendar\Administrator\Helper\SafeFetchHelper;
use DigitalPeak\ThinHTTP\CurlClient;
use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\User\CurrentUserInterface;
use Joomla\CMS\User\CurrentUserTrait;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryAwareInterface;
use Joomla\CMS\User\UserFactoryAwareTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Filesystem\Path;
use Joomla\Registry\Registry;

class IcalController extends BaseController implements UserFactoryAwareInterface, CurrentUserInterface
{
	use UserFactoryAwareTrait;
	use CurrentUserTrait;

	public function download(): void
	{
		$app = $this->app;
		if (!$app instanceof CMSWebApplicationInterface) {
			return;
		}

		// Remove the script time limit.
		@set_time_limit(0);

		if ($this->getCurrentUser()->guest && ($token = $this->input->getString('token', '')) !== '') {
			$this->applyIcsToken($token);
		}

		// Get the calendar
		$calendar = Factory::getApplication()->bootComponent('dpcalendar')->getMVCFactory()->createModel('Calendar', 'Administrator')->getCalendar($this->input->getCmd('id'));
		if (!$calendar instanceof CalendarInterface) {
			throw new \Exception('Calendar not found!', 404);
		}

		// Download the external url
		if ($calendar->getIcalUrl() !== '' && $calendar->getIcalUrl() !== '0' && $calendar->getIcalUrl() === 'plugin') {
			header('Content-Type: text/calendar; charset=utf-8');
			header('Content-disposition: attachment; filename="' . $calendar->getTitle() . '.ics"');

			echo implode('', $app->triggerEvent('onDPCalendarGetIcal', ['id' => $calendar->getId()]));
			$app->close();
		}

		if ($calendar->getIcalUrl() !== '' && $calendar->getIcalUrl() !== '0') {
			$url = str_replace('webcal://', 'https://', (string)$calendar->getIcalUrl());
			if (!SafeFetchHelper::isPublicHttpUrl($url)) {
				throw new \Exception('Invalid calendar URL', 400);
			}

			header('Content-Type: text/calendar; charset=utf-8');
			header('Content-disposition: attachment; filename="' . $calendar->getTitle() . '.ics"');

			$headers = [
				'Accept-Language: ' . $this->getCurrentUser()->getParam('language', $app->getLanguage()->getTag()),
				'Accept: */*'
			];
			echo (new CurlClient())->get($url, null, null, $headers, SafeFetchHelper::safeCurlOptions())->dp->body;
			$app->close();
		}

		if (!is_numeric($calendar->getId())) {
			throw new \Exception('Only native calendars are allowed!');
		}

		// Also include children when available
		$calendars = [$this->input->getCmd('id')];
		foreach ($calendar->getChildren() as $c) {
			$calendars[] = $c->getId();
		}

		// Download the ical content
		$app->setHeader('Content-Type', 'text/calendar; charset=utf-8', true);
		$app->setHeader('Content-disposition', 'attachment; filename="' . Path::clean($calendar->getTitle()) . '.ics"', true);

		$buffer = $this->app->bootComponent('dpcalendar')->getMVCFactory()->createModel('Ical', 'Administrator')->createIcalFromCalendar($calendars, false);
		$buffer = MailHelper::convertRelativeToAbsoluteUrls($buffer);
		echo $buffer;

		/* @deprecated no format is not supported */
		if ($app->getInput()->get('format') !== 'raw') {
			header('Content-Type: text/calendar; charset=utf-8');
			header('Content-disposition: attachment; filename="' . Path::clean($calendar->getTitle()) . '.ics"');
			$app->close();
		}
	}

	/**
	 * Authorize this ICS request with a user token without creating a site session.
	 */
	private function applyIcsToken(string $token): void
	{
		$app = $this->app;
		if (!$app instanceof CMSWebApplicationInterface) {
			return;
		}

		$user = $this->getUserForIcsToken($token);
		if ($user === null) {
			return;
		}

		$app->loadIdentity($user);
		$this->setCurrentUser($user);
	}

	private function getUserForIcsToken(string $token): ?User
	{
		if (preg_match('/^[A-Za-z0-9_-]{8,128}$/', $token) !== 1) {
			return null;
		}

		$db    = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__users'))
			->where($db->quoteName('params') . ' LIKE ' . $db->quote('%"token":"' . $token . '"%'));
		$db->setQuery($query);
		$id = (int)$db->loadResult();
		if ($id === 0) {
			return null;
		}

		$user = $this->getUserFactory()->loadUserById($id);
		if ($user->guest || (int)$user->block === 1) {
			return null;
		}

		$stored = (string)(new Registry($user->params))->get('token');
		if ($stored === '' || !hash_equals($stored, $token)) {
			return null;
		}

		return $user;
	}
}
