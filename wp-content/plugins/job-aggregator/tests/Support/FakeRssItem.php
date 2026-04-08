<?php

namespace JobAggregator\Tests\Support;

class FakeRssItem {
	private $fields;
	private $namespaced_fields;

	public function __construct( array $fields = array(), array $namespaced_fields = array() ) {
		$this->fields            = $fields;
		$this->namespaced_fields = $namespaced_fields;
	}

	public function get_item_tags( $namespace, $tag ) {
		$namespace = (string) $namespace;
		$tag       = (string) $tag;

		if ( '' === $namespace ) {
			if ( ! array_key_exists( $tag, $this->fields ) ) {
				return array();
			}

			return $this->to_nodes( $this->fields[ $tag ] );
		}

		$key = $namespace . '|' . $tag;
		if ( ! array_key_exists( $key, $this->namespaced_fields ) ) {
			return array();
		}

		return $this->to_nodes( $this->namespaced_fields[ $key ] );
	}

	public function get_title() {
		return isset( $this->fields['title'] ) ? (string) $this->fields['title'] : '';
	}

	public function get_content() {
		return isset( $this->fields['description'] ) ? (string) $this->fields['description'] : '';
	}

	public function get_description() {
		return isset( $this->fields['description'] ) ? (string) $this->fields['description'] : '';
	}

	public function get_id() {
		return isset( $this->fields['guid'] ) ? (string) $this->fields['guid'] : '';
	}

	public function get_link() {
		return isset( $this->fields['link'] ) ? (string) $this->fields['link'] : '';
	}

	private function to_nodes( $value ) {
		if ( null === $value ) {
			return array();
		}

		if ( is_array( $value ) && isset( $value['__nodes'] ) && is_array( $value['__nodes'] ) ) {
			return $value['__nodes'];
		}

		if ( is_array( $value ) ) {
			return array( $value );
		}

		return array(
			array(
				'data' => (string) $value,
			),
		);
	}
}
