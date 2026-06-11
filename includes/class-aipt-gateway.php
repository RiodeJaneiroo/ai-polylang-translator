<?php
// Vercel AI Gateway client (OpenAI-compatible chat/completions).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Gateway {

	const ENDPOINT = 'https://ai-gateway.vercel.sh/v1/chat/completions';

	/**
	 * Translate a {id: text} map in one request; returns the same keys translated.
	 *
	 * @return array|WP_Error
	 */
	public static function translate_map(array $map, string $source_name, string $target_name) {
		$settings = AIPT_Settings::get();
		$context  = trim($settings['site_context']);

		$system = 'Ты профессиональный переводчик веб-контента.';
		if ($context !== '') {
			$system .= ' Контекст сайта: ' . $context . '.';
		}
		$system .= sprintf(
			' Переведи значения JSON-объекта с языка «%s» на язык «%s».'
			. ' Верни ТОЛЬКО валидный JSON-объект с теми же самыми ключами и переведёнными значениями, без пояснений и без markdown.'
			. ' Сохраняй без изменений: HTML-теги и их атрибуты, шорткоды в квадратных скобках, плейсхолдеры вида %%%%placeholder%%%%, URL, email-адреса и числа.'
			. ' Переводи только видимый текст.',
			$source_name,
			$target_name
		);

		$messages = array(
			array('role' => 'system', 'content' => $system),
			array('role' => 'user', 'content' => wp_json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
		);

		$result = self::attempt($messages, $map);

		// One automatic retry on an invalid JSON reply.
		if (is_wp_error($result) && $result->get_error_code() === 'aipt_bad_json') {
			$raw        = (string) ($result->get_error_data()['raw'] ?? '');
			$messages[] = array('role' => 'assistant', 'content' => $raw !== '' ? $raw : '{}');
			$messages[] = array(
				'role'    => 'user',
				'content' => 'Предыдущий ответ был невалидным. Верни строго валидный JSON-объект со ВСЕМИ исходными ключами и непустыми строковыми значениями, без пояснений и без markdown.',
			);
			$result = self::attempt($messages, $map);
		}

		return $result;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function attempt(array $messages, array $map) {
		$content = self::request($messages);
		if (is_wp_error($content)) {
			return $content;
		}

		$decoded = self::decode_json($content);
		if (!is_array($decoded)) {
			return new WP_Error('aipt_bad_json', __('Модель вернула невалидный JSON.', 'ai-polylang-translator'), array('raw' => $content));
		}

		$out = array();
		foreach ($map as $key => $source_text) {
			$translated = $decoded[$key] ?? null;
			if (!is_string($translated) || trim($translated) === '') {
				return new WP_Error(
					'aipt_bad_json',
					sprintf(__('В ответе модели нет перевода для ключа «%s».', 'ai-polylang-translator'), $key),
					array('raw' => $content)
				);
			}
			$out[$key] = $translated;
		}
		return $out;
	}

	/**
	 * @param array $overrides ['api_key' => ..., 'max_tokens' => ..., 'json_mode' => bool]
	 * @return string|WP_Error First choice content.
	 */
	public static function request(array $messages, array $overrides = array()) {
		$settings = AIPT_Settings::get();
		$api_key  = (string) ($overrides['api_key'] ?? AIPT_Settings::api_key());

		if ($api_key === '') {
			return new WP_Error('aipt_no_key', __('API-ключ не задан. Укажите его в настройках.', 'ai-polylang-translator'));
		}

		$body = array(
			'model'       => $settings['model'],
			'messages'    => $messages,
			'temperature' => 0.2,
			'max_tokens'  => (int) ($overrides['max_tokens'] ?? 16000),
			'stream'      => false,
		);
		if ($overrides['json_mode'] ?? true) {
			$body['response_format'] = array('type' => 'json_object');
		}

		$response = self::post($body, $api_key, (int) $settings['timeout']);

		// Some models reject response_format — retry without it.
		if (is_wp_error($response) && $response->get_error_code() === 'aipt_http_400'
			&& isset($body['response_format'])
			&& str_contains((string) $response->get_error_message(), 'response_format')) {
			unset($body['response_format']);
			$response = self::post($body, $api_key, (int) $settings['timeout']);
		}

		return $response;
	}

	/**
	 * @return string|WP_Error
	 */
	private static function post(array $body, string $api_key, int $timeout) {
		$response = wp_remote_post(self::ENDPOINT, array(
			'timeout' => max(30, $timeout),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
		));

		if (is_wp_error($response)) {
			return new WP_Error('aipt_http', sprintf(__('Ошибка соединения с AI Gateway: %s', 'ai-polylang-translator'), $response->get_error_message()));
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw  = (string) wp_remote_retrieve_body($response);

		if ($code !== 200) {
			$excerpt = mb_substr(wp_strip_all_tags($raw), 0, 300);
			return new WP_Error('aipt_http_' . $code, sprintf(__('AI Gateway вернул ошибку %1$d: %2$s', 'ai-polylang-translator'), $code, $excerpt));
		}

		$data    = json_decode($raw, true);
		$content = $data['choices'][0]['message']['content'] ?? null;
		if (!is_string($content) || $content === '') {
			return new WP_Error('aipt_empty', __('Пустой ответ от модели.', 'ai-polylang-translator'));
		}

		if (($data['choices'][0]['finish_reason'] ?? '') === 'length') {
			return new WP_Error('aipt_truncated', __('Ответ модели обрезан по лимиту токенов — попробуйте другую модель или уменьшите объём текста.', 'ai-polylang-translator'));
		}

		return $content;
	}

	private static function decode_json(string $content): ?array {
		$content = trim($content);
		$content = preg_replace('~^```(?:json)?\s*~i', '', $content);
		$content = preg_replace('~\s*```$~', '', $content);

		$decoded = json_decode($content, true);
		if (is_array($decoded)) {
			return $decoded;
		}

		$start = strpos($content, '{');
		$end   = strrpos($content, '}');
		if ($start !== false && $end !== false && $end > $start) {
			$decoded = json_decode(substr($content, $start, $end - $start + 1), true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}
		return null;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function test_key(string $key) {
		$result = self::request(
			array(array('role' => 'user', 'content' => 'Reply with the single word: ok')),
			array('api_key' => $key, 'max_tokens' => 16, 'json_mode' => false)
		);
		if (is_wp_error($result)) {
			return $result;
		}
		return true;
	}
}
