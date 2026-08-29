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

		$calendarModel = $app->bootComponent('dpcalendar')->getMVCFactory()->createModel('Calendar', 'Administrator');

		// Get the calendar
		$calendar = $calendarModel->getCalendar($this->input->getCmd('id'));

		// Download the external url
		if ($calendar instanceof CalendarInterface && $calendar->getIcalUrl() !== '' && $calendar->getIcalUrl() === 'plugin') {
			header('Content-Type: text/calendar; charset=utf-8');
			header('Content-disposition: ' . $this->icsContentDisposition($calendar->getTitle()));

			echo implode('', $app->triggerEvent('onDPCalendarGetIcal', ['id' => $calendar->getId()]));
			$app->close();
		}

		if ($calendar instanceof CalendarInterface && $calendar->getIcalUrl() !== '') {
			$url = str_replace('webcal://', 'https://', (string)$calendar->getIcalUrl());
			if (!SafeFetchHelper::isPublicHttpUrl($url)) {
				throw new \Exception('Invalid calendar URL', 400);
			}

			header('Content-Type: text/calendar; charset=utf-8');
			header('Content-disposition: ' . $this->icsContentDisposition($calendar->getTitle()));

			$headers = [
				'Accept-Language: ' . $this->getCurrentUser()->getParam('language', $app->getLanguage()->getTag()),
				'Accept: */*'
			];
			echo (new CurlClient())->get($url, null, null, $headers, SafeFetchHelper::safeCurlOptions())->dp->body;
			$app->close();
		}

		$calendars = [];
		$ids       = [];
		if ($id = $this->input->getCmd('id')) {
			$ids[] = $id;
		}
		if ($id = $this->input->getString('ids')) {
			$ids = array_merge($ids, explode(',', $id));
		}

		foreach ($ids as $id) {
			$calendar = $calendarModel->getCalendar(trim($id));
			if (!$calendar instanceof CalendarInterface) {
				throw new \Exception('Calendar not found with id: ' . $id . '!', 404);
			}

			if (!is_numeric($calendar->getId())) {
				continue;
			}

			// Also include children when available
			$calendars[] = $calendar->getId();
			foreach ($calendar->getChildren() as $c) {
				$calendars[] = $c->getId();
			}
		}

		if (!$calendar instanceof CalendarInterface) {
			throw new \Exception('Calendar not found!', 404);
		}

		$fileName = \count($calendars) > 1 ? 'calendars' : $calendar->getTitle();

		// Download the ical content
		$app->setHeader('Content-Type', 'text/calendar; charset=utf-8', true);
		$app->setHeader('Content-disposition', $this->icsContentDisposition($fileName), true);

		$buffer = $this->app->bootComponent('dpcalendar')->getMVCFactory()->createModel('Ical', 'Administrator')->createIcalFromCalendar($calendars, false);
		$buffer = MailHelper::convertRelativeToAbsoluteUrls($buffer);
		echo $buffer;

		// @deprecated no format is not supported
		if ($app->getInput()->get('format') !== 'raw') {
			header('Content-Type: text/calendar; charset=utf-8');
			header('Content-disposition: ' . $this->icsContentDisposition($fileName));
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

	private function icsContentDisposition(string $title): string
	{
		$name = preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]/', '', $title) ?? '';
		$name = trim($name, " .");
		if ($name === '') {
			$name = 'calendar';
		}

		if (\strlen($name) > 150) {
			$name = substr($name, 0, 150);
		}

		return 'attachment; filename="' . $name . '.ics"';
	}
}
