<?php

namespace JobAggregator\Sources\RSS;

use JobAggregator\Jobs\JobData;

/**
 * Provides the generic RSS-to-JobData mapping used for non-source-specific RSS feeds.
 */
class RssFeedSource extends AbstractRssSource {
	protected function map_item_to_job( $item ) {
		$defaults = $this->defaults();
		$author   = method_exists( $item, 'get_author' ) ? $item->get_author() : null;

		return JobData::from_array(
			array(
				'source_key'       => $this->get_key(),
				'external_id'      => (string) ( $item->get_id() ?: $item->get_link() ),
				'title'            => (string) $item->get_title(),
				'description'      => (string) ( $item->get_content() ?: $item->get_description() ),
				'source_url'       => (string) $item->get_link(),
				'application_url'  => (string) $item->get_link(),
				'company_name'     => ! empty( $defaults['company_name'] ) ? $defaults['company_name'] : ( $author ? (string) $author->get_name() : '' ),
				'company_website'  => isset( $defaults['company_website'] ) ? (string) $defaults['company_website'] : '',
				'company_tagline'  => isset( $defaults['company_tagline'] ) ? (string) $defaults['company_tagline'] : '',
				'company_logo_url' => isset( $defaults['company_logo_url'] ) ? (string) $defaults['company_logo_url'] : null,
				'company_logo_id'  => isset( $defaults['company_logo_id'] ) ? (int) $defaults['company_logo_id'] : null,
				'location'         => isset( $defaults['location'] ) ? (string) $defaults['location'] : '',
				'employment_types' => isset( $defaults['employment_types'] ) ? (array) $defaults['employment_types'] : array(),
				'remote_position'  => ! empty( $defaults['remote_position'] ),
				'salary'           => isset( $defaults['salary'] ) ? (string) $defaults['salary'] : '',
				'salary_currency'  => isset( $defaults['salary_currency'] ) ? (string) $defaults['salary_currency'] : '',
				'salary_unit'      => isset( $defaults['salary_unit'] ) ? (string) $defaults['salary_unit'] : '',
				'published_at'     => (string) $item->get_date( 'c' ),
				'expires_at'       => isset( $defaults['expires_at'] ) ? (string) $defaults['expires_at'] : '',
			)
		);
	}
}
