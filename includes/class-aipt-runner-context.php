<?php
// What an estimate (AIPT_Runner::estimate) plans to create, so later records of the same
// run count it as translated: posts a real run would create (their children are not
// reported as skipped_parent_missing) and terms (posts using them are not reported as
// skipped_missing_terms). Serializable, for an estimate split across requests.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Runner_Context {

	// target language => [source post ID => true]
	private $posts = array();
	// target language => [source term ID => true]
	private $terms = array();

	public function plan_post(int $post_id, string $target): void {
		$this->posts[$target][$post_id] = true;
	}

	public function has_post(int $post_id, string $target): bool {
		return isset($this->posts[$target][$post_id]);
	}

	public function plan_term(int $term_id, string $target): void {
		$this->terms[$target][$term_id] = true;
	}

	public function has_term(int $term_id, string $target): bool {
		return isset($this->terms[$target][$term_id]);
	}

	public function to_array(): array {
		return array(
			'posts' => array_map('array_keys', $this->posts),
			'terms' => array_map('array_keys', $this->terms),
		);
	}

	public static function from_array(array $data): self {
		$context = new self();
		foreach (array('posts' => 'plan_post', 'terms' => 'plan_term') as $key => $plan) {
			foreach ((array) ($data[$key] ?? array()) as $target => $ids) {
				foreach ((array) $ids as $id) {
					$context->$plan((int) $id, (string) $target);
				}
			}
		}
		return $context;
	}
}
