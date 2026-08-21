<?php
/**
 * @package   DPCalendar
 * @copyright Copyright (C) 2014 Digital Peak GmbH. <https://www.digital-peak.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 */

namespace DigitalPeak\Component\DPCalendar\Administrator\Helper;

\defined('_JEXEC') or die();

/**
 * Guards calendar URI fetches against SSRF and local file disclosure.
 */
class SafeFetchHelper
{
	public static function isPublicHttpUrl(string $uri): bool
	{
		$uri   = str_replace('webcal://', 'https://', $uri);
		$parts = parse_url($uri);
		if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return false;
		}

		$scheme = strtolower((string)$parts['scheme']);
		if (!\in_array($scheme, ['http', 'https'], true)) {
			return false;
		}

		$host = strtolower(trim((string)$parts['host'], '[]'));
		if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
			|| str_ends_with($host, '.local') || str_ends_with($host, '.internal')
			|| str_ends_with($host, '.lan')) {
			return false;
		}

		if (\in_array($host, ['metadata.google.internal', 'metadata', '169.254.169.254'], true)) {
			return false;
		}

		$ips = self::resolveHostIps($host);
		if ($ips === []) {
			return false;
		}

		foreach ($ips as $ip) {
			if (!self::isPublicIp($ip)) {
				return false;
			}
		}

		return true;
	}

	public static function resolveSafeLocalPath(string $uri): ?string
	{
		$root = realpath(JPATH_ROOT);
		if ($root === false || $uri === '' || str_contains($uri, "\0")) {
			return null;
		}

		$uri = str_replace('\\', '/', $uri);
		if (preg_match('#^(?:[a-z]:)?/#i', $uri) !== 1) {
			$uri = JPATH_ROOT . '/' . $uri;
		}

		$resolved = realpath($uri);
		if ($resolved === false) {
			return null;
		}

		$rootNorm = rtrim(str_replace('\\', '/', $root), '/');
		$pathNorm = str_replace('\\', '/', $resolved);

		if (strncasecmp($pathNorm, $rootNorm . '/', \strlen($rootNorm) + 1) !== 0
			&& strcasecmp($pathNorm, $rootNorm) !== 0) {
			return null;
		}

		return $resolved;
	}

	/**
	 * Restrict curl to HTTP(S) and do not follow redirects to internal hosts.
	 *
	 * @param array<int, mixed> $curlOptions
	 *
	 * @return array<int, mixed>
	 */
	public static function safeCurlOptions(array $curlOptions = []): array
	{
		if (\defined('CURLPROTO_HTTP') && \defined('CURLPROTO_HTTPS')) {
			$curlOptions[\CURLOPT_PROTOCOLS]      = \CURLPROTO_HTTP | \CURLPROTO_HTTPS;
			$curlOptions[\CURLOPT_REDIR_PROTOCOLS] = \CURLPROTO_HTTP | \CURLPROTO_HTTPS;
		}

		$curlOptions[\CURLOPT_FOLLOWLOCATION] = false;

		return $curlOptions;
	}

	/**
	 * @return string[]
	 */
	private static function resolveHostIps(string $host): array
	{
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return [$host];
		}

		$ips     = [];
		$records = @dns_get_record($host, DNS_A + DNS_AAAA);
		if (\is_array($records)) {
			foreach ($records as $record) {
				if (!empty($record['ip'])) {
					$ips[] = $record['ip'];
				}

				if (!empty($record['ipv6'])) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		if ($ips === []) {
			$resolved = gethostbynamel($host);
			if (\is_array($resolved)) {
				$ips = $resolved;
			}
		}

		return array_values(array_unique($ips));
	}

	private static function isPublicIp(string $ip): bool
	{
		$ip = trim($ip, '[]');
		if (str_starts_with(strtolower($ip), '::ffff:')) {
			$mapped = substr($ip, 7);
			if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
				$ip = $mapped;
			}
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return false;
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$long = ip2long($ip);
			if ($long !== false && $long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) {
				return false;
			}
		}

		return true;
	}
}
