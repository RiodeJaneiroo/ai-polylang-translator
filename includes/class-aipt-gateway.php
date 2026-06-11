<?php
// Vercel AI Gateway client (OpenAI-compatible chat/completions).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Gateway {

	const ENDPOINT = 'https://ai-gateway.vercel.sh/v1/chat/completions';

	private static $usage = array('tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0);

	private static function reset_usage(): void {
		self::$usage = array('tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0);
	}

	public static function get_usage(): array {
		return self::$usage;
	}

	/**
	 * Translate a {id: text} map in one request; returns the same keys translated.
	 *
	 * @return array|WP_Error
	 */
	public static function translate_map(array $map, string $source_name, string $target_name, string $model = '') {
		// Owns its usage window: get_usage() after the call returns the tokens
		// and cost of exactly this map (including the missing-keys retry).
		self::reset_usage();

		$settings = AIPT_Settings::get();
		$context  = trim($settings['site_context']);

		$system = 'You are a professional web-content translator.';
		if ($context !== '') {
			$system .= ' Site context: ' . $context . '.';
		}
		$system .= sprintf(
			' Translate the values of the JSON object from %s into %s.'
			. ' Return ONLY a valid JSON object with the very same keys and the translated values, with no explanations and no markdown.'
			. ' Keep unchanged: HTML tags and their attributes, shortcodes in square brackets, placeholders of the form %%%%placeholder%%%%, URLs, email addresses and numbers.'
			. ' Translate only the visible text.',
			$source_name,
			$target_name
		);

		$messages = array(
			array('role' => 'system', 'content' => $system),
			array('role' => 'user', 'content' => wp_json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
		);

		$result = self::attempt($messages, $map, $model);
		if (is_wp_error($result)) {
			return $result;
		}

		// Keep the valid translations; retry only the missing keys in a fresh short conversation.
		$missing = array_diff_key($map, $result);
		if ($missing) {
			$retry_messages = array(
				array('role' => 'system', 'content' => $system),
				array('role' => 'user', 'content' => wp_json_encode($missing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
			);
			$retry = self::attempt($retry_messages, $missing, $model);
			if (is_wp_error($retry)) {
				return $retry;
			}
			$result += $retry;
		}

		$missing = array_diff_key($map, $result);
		if ($missing) {
			return new WP_Error(
				'aipt_bad_json',
				sprintf(
					/* translators: %d: number of keys the model failed to translate */
					__('Failed to translate %d items in this block.', 'ai-polylang-translator'),
					count($missing)
				)
			);
		}

		return $result;
	}

	/**
	 * Collect the valid translations present in the reply; missing/empty keys are simply skipped.
	 *
	 * @return array|WP_Error
	 */
	private static function attempt(array $messages, array $map, string $model = '') {
		$content = self::request($messages, $model !== '' ? array('model' => $model) : array());
		if (is_wp_error($content)) {
			return $content;
		}

		$decoded = self::decode_json($content);
		if (!is_array($decoded)) {
			return new WP_Error('aipt_bad_json', __('The model returned invalid JSON.', 'ai-polylang-translator'), array('raw' => $content));
		}

		$out = array();
		foreach ($map as $key => $source_text) {
			$translated = $decoded[$key] ?? null;
			if (is_string($translated) && trim($translated) !== '') {
				$out[$key] = $translated;
			}
		}
		return $out;
	}

	/**
	 * @param array $overrides ['api_key' => ..., 'model' => ..., 'max_tokens' => ..., 'json_mode' => bool]
	 * @return string|WP_Error First choice content.
	 */
	public static function request(array $messages, array $overrides = array()) {
		$settings = AIPT_Settings::get();
		$api_key  = (string) ($overrides['api_key'] ?? AIPT_Settings::api_key());
		$model    = (string) ($overrides['model'] ?? $settings['model']);

		if ($api_key === '') {
			return new WP_Error('aipt_no_key', __('API key is not set. Enter it on the settings page.', 'ai-polylang-translator'));
		}

		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => 0.2,
			'max_tokens'  => (int) ($overrides['max_tokens'] ?? 16000),
			'stream'      => false,
		);
		if (str_starts_with($model, 'google/gemini-2.5-')) {
			$body['reasoning'] = array(
				'effort'  => 'none',
				'exclude' => true,
			);
		}
		if ($overrides['json_mode'] ?? true) {
			$body['response_format'] = array('type' => 'json_object');
		}

		$response = self::post($body, $api_key, (int) $settings['timeout']);

		// Some models reject response_format — retry once without it on any HTTP 400.
		if (is_wp_error($response) && $response->get_error_code() === 'aipt_http_400'
			&& isset($body['response_format'])) {
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
			return new WP_Error('aipt_http', sprintf(__('Connection error with AI Gateway: %s', 'ai-polylang-translator'), $response->get_error_message()));
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw  = (string) wp_remote_retrieve_body($response);

		if ($code !== 200) {
			$excerpt = mb_substr(wp_strip_all_tags($raw), 0, 300);
			return new WP_Error('aipt_http_' . $code, sprintf(__('AI Gateway returned error %1$d: %2$s', 'ai-polylang-translator'), $code, $excerpt));
		}

		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return new WP_Error(
				'aipt_bad_response',
				__('AI Gateway returned a malformed response.', 'ai-polylang-translator')
			);
		}

		// Tokens are billed even when the answer is later rejected, so accumulate now.
		self::$usage['tokens_in']  += (int) ($data['usage']['prompt_tokens'] ?? 0);
		self::$usage['tokens_out'] += (int) ($data['usage']['completion_tokens'] ?? 0);
		// Vercel AI Gateway reports the exact request cost in USD.
		self::$usage['cost']       += (float) ($data['usage']['cost'] ?? 0);

		$choice        = $data['choices'][0] ?? array();
		$finish_reason = (string) ($choice['finish_reason'] ?? '');
		if ($finish_reason === 'length') {
			return new WP_Error('aipt_truncated', __('The model response was cut off by the token limit — try another model or reduce the amount of text.', 'ai-polylang-translator'));
		}
		if ($finish_reason === 'content_filter') {
			return new WP_Error('aipt_content_filter', __('The model rejected the response because of a safety filter.', 'ai-polylang-translator'));
		}

		$content = $choice['message']['content'] ?? null;
		if (!is_string($content) || trim($content) === '') {
			$generation_id = sanitize_text_field((string) ($data['id'] ?? ''));
			$message = __('The model finished the request without a text response.', 'ai-polylang-translator');
			if ($generation_id !== '') {
				$message .= ' ' . sprintf(
					/* translators: %s: Vercel AI Gateway generation ID */
					__('Request ID: %s.', 'ai-polylang-translator'),
					$generation_id
				);
			}
			return new WP_Error('aipt_empty', $message);
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
			array('api_key' => $key, 'max_tokens' => 128, 'json_mode' => false)
		);
		if (is_wp_error($result)) {
			return $result;
		}
		return true;
	}
}
