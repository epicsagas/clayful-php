<?php
namespace Clayful;

use Clayful\Requester;

class Clayful {

	public static $baseUrl = 'https://api.clayful.io';
	public static $defaultHeaders = array(
		'Accept-Encoding' => 'gzip',
		'User-Agent'      => 'clayful-php',
		'Clayful-SDK'     => 'clayful-php',
	);
	public static $plugins = array(
		'request' => array('Clayful\Requester', 'request')
	);
	public static $listeners = array(
		'request'  => array(),
		'response' => array(),
	);

	public static function optionsToHeaders($o = array()) {

		$headers = array();

		if (array_key_exists('language', $o)) {
			$headers['Accept-Language'] = $o['language'];
		}

		if (array_key_exists('currency', $o)) {
			$headers['Accept-Currency'] = $o['currency'];
		}

		if (array_key_exists('timeZone', $o)) {
			$headers['Accept-Time-Zone'] = $o['timeZone'];
		}

		if (array_key_exists('client', $o)) {
			$headers['Authorization'] = 'Bearer ' . $o['client'];
		}

		if (array_key_exists('customer', $o)) {
			$headers['Authorization-Customer'] = $o['customer'];
		}

		if (array_key_exists('reCAPTCHA', $o)) {
			$headers['reCAPTCHA-Response'] = $o['reCAPTCHA'];
		}

		if (array_key_exists('debugLanguage', $o)) {
			$headers['Accept-Debug-Language'] = $o['debugLanguage'];
		}

		if (array_key_exists('headers', $o)) {
			$headers = array_merge($headers, $o['headers']);
		}

		return $headers;

	}

	public static function getEndPoint($path = '') {

		return self::$baseUrl . $path;

	}

	public static function normalizeQueryValues($query = array()) {

		$normalize = function($value) {

			if (is_bool($value)) {
				// Since `http_build_query` changes boolean to 1, 0,
				// manually change boolean to 'true', 'false' string.
				return $value ? 'true' : 'false';
			}

			return $value;

		};

		return array_map($normalize, $query);

	}

	public static function extractRequestArguments($options) {

		$result = array(
			'httpMethod' => $options['httpMethod'],
			'payload'    => null,
			'query'      => array(),
			'headers'    => array(),
			'meta'       => array(),
		);

		$rest = array_slice($options['args'], count($options['params']));

		$result['requestUrl'] = str_replace(array_map(function($param) {

			return '{' . $param . '}';

		}, $options['params']), $options['args'], $options['path']);

		if (($options['httpMethod'] === 'POST' ||
			$options['httpMethod'] === 'PUT') &&
			$options['withoutPayload'] === false) {

			$result['payload'] = array_shift($rest); // shift array to extract payload
		}

		$queryHeaders = array_shift($rest); // extract query & header options

		$queryHeaders = empty($queryHeaders) ? array('query' => array()) : $queryHeaders;

		$result['query'] = empty($queryHeaders['query']) ? array() : $queryHeaders['query'];

		$result['query'] = self::normalizeQueryValues($result['query']);

		$result['headers'] = self::optionsToHeaders($queryHeaders);

		$result['meta'] = array_key_exists('meta', $queryHeaders) ? $queryHeaders['meta'] : array();

		return $result;

	}

	public static function callAPI($options) {

		$extracted = self::extractRequestArguments($options);
		$extracted = array_merge($extracted, array(
			'requestUrl'   => self::getEndPoint($extracted['requestUrl']),
			'modelName'    => $options['modelName'],
			'methodName'   => $options['methodName'],
			'usesFormData' => $options['usesFormData'],
			'error'        => null,
			'response'     => null,
		));
		$defaultHeaders = array_merge(array(), self::$defaultHeaders); // copy default headers
		$extracted['headers'] = array_merge($defaultHeaders, $extracted['headers']);

		self::trigger('request', $extracted);

		try {

			$response = call_user_func(self::$plugins['request'], $extracted);

			$extracted['response'] = $response;

			self::trigger('response', $extracted);

			return $response;

		} catch (ClayfulException $e) {

			// only catches `ClayfulException` to call `response` event listeners
			$extracted['error'] = $e;

			self::trigger('response', $extracted);

			throw $e;
		}

	}

	public static function config($options = array()) {

		self::$defaultHeaders = array_merge(self::$defaultHeaders, self::optionsToHeaders($options));

	}

	public static function install($plugin, $options) {

		self::$plugins[$plugin] = $options;

	}

	public static function on($eventName, $callback) {

		if (!array_key_exists($eventName, self::$listeners)) {
			return;
		}

		array_push(self::$listeners[$eventName], $callback);

	}

	public static function off($eventName, $callback) {

		if (!array_key_exists($eventName, self::$listeners)) {
			return;
		}

		$key = array_search($callback, self::$listeners[$eventName]);

		if (!is_numeric($key)) {
			return;
		}

		array_splice(self::$listeners[$eventName], $key, 1);

	}

	public static function trigger($eventName, &$data) {

		if (!array_key_exists($eventName, self::$listeners)) {
			return;
		}

		foreach (self::$listeners[$eventName] as $listener) {
			$listener($data);
		}

	}

	public static function formatImageUrl($baseUrl, $options = array()) {

		$query = http_build_query(self::normalizeQueryValues($options));

		if ($query) {
			$query = '?' . $query;
		}

		return $baseUrl . $query;

	}

	public static function formatNumber($number, $currency = array()) {

		if (!is_numeric($number)) {

			return '';
		}

		$number = $number + 0;

		$currency = array_merge(array(
			'precision' => null,
			'delimiter' => array()
		), $currency);

		$currency['delimiter'] = array_merge(array(
			'thousands' => '',
			'decimal'   => '.'
		), $currency['delimiter']);

		$delimiter = $currency['delimiter'];

		if (is_numeric($currency['precision'])) {

			$currency['precision'] = $currency['precision'] + 0;

			$n = pow(10, $currency['precision']);

			$number = round($number * $n) / $n;

		}

		$stringified = explode('.', (string) $number);

		$a = $stringified[0];
		$b = array_key_exists(1, $stringified) ? $stringified[1] : '';

		$reversed = strrev($a);

		$segments = array();

		$start = 0;

		while (strlen($segment = substr($reversed, $start, 3))) {

			array_unshift($segments, strrev($segment));

			$start += 3;

		}

		if ($currency['precision']) {

			$diff = $currency['precision'] - strlen($b);
			$diff = $diff < 0 ? 0 : $diff;

			$b .= str_repeat('0', $diff);

		}

		$a = implode($delimiter['thousands'], $segments);
		$decimal = $b ? $delimiter['decimal'] : '';

		return implode($decimal, array($a, $b));

	}

	public static function formatPrice($number, $currency = array()) {

		$formattedNumber = self::formatNumber($number, $currency);

		if (!is_string($formattedNumber)) {

			return '';
		}

		$currency = array_merge(array(
			'symbol' => '',
			'format' => '{price}'
		), $currency);

		return str_replace(
			array('{symbol}', '{price}'),
			array($currency['symbol'], $formattedNumber),
			$currency['format']
		);

	}

}