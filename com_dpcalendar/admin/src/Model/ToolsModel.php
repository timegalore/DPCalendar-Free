<?php
/**
 * @package   DPCalendar
 * @copyright Copyright (C) 2014 Digital Peak GmbH. <https://www.digital-peak.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 */

namespace DigitalPeak\Component\DPCalendar\Administrator\Model;

\defined('_JEXEC') or die();

use DigitalPeak\Component\DPCalendar\Administrator\Helper\DPCalendarHelper;
use DigitalPeak\ThinHTTP\CurlClient;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

class ToolsModel extends BaseDatabaseModel
{
	public function getResourcesFromTranslation(): array
	{
		$token = $this->getTranslationKey();
		if ($token === '') {
			return [];
		}

		$resources = (new CurlClient())->get(
			'https://translate.digital-peak.com/api/projects/dpcalendar/components/',
			$token
		)->results;
		if ($resources === []) {
			return [];
		}

		foreach ($resources as $key => $component) {
			if ($component->slug === 'glossary') {
				unset($resources[$key]);
				continue;
			}
		}

		return $resources;
	}

	public function getResourceStats(string $resourceId): array
	{
		$token = $this->getTranslationKey();
		if ($token === '') {
			return [];
		}

		return (new CurlClient())->get(
			'https://translate.digital-peak.com/api/components/dpcalendar/' . $resourceId . '/statistics/',
			$token
		)->results;
	}

	public function getResourceStrings(string $resourceId, string $language): string
	{
		$token = $this->getTranslationKey();
		if ($token === '') {
			return '';
		}

		$file = (new CurlClient())->get(
			'https://translate.digital-peak.com/api/translations/dpcalendar/' . $resourceId . '/' . $language . '/file/',
			$token
		);

		return $file->dp->body;
	}

	/**
	 * Weblate credentials must not ship in the install zip. Prefer the server
	 * environment, then an admin-only component setting.
	 */
	private function getTranslationKey(): string
	{
		$env = getenv('DPCALENDAR_WEBLATE_TOKEN');
		if (\is_string($env) && $env !== '') {
			return $env;
		}

		return trim((string) DPCalendarHelper::getComponentParameter('weblate_token', ''));
	}

	public function getPluginForName(string $name): ?\stdClass
	{
		$db = $this->getDatabase();
		$db->setQuery("SELECT *  FROM `#__extensions` WHERE  `name` LIKE  '" . str_replace('-sys', '', $name) . "'");

		return $db->loadObject();
	}
}
