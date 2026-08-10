<?php

defined( 'ABSPATH' ) || exit;

final class AMA_DM_Event_Queue {
	const SESSION_KEY = 'ama_dm_pending_events';
	const USER_META   = '_ama_dm_pending_events';
	const LOG_OPTION  = 'ama_dm_event_log';

	public static function make( $command, $payload = array(), $source = '' ) {
		return array(
			'id'      => wp_generate_uuid4(),
			'command' => (string) $command,
			'payload' => is_array( $payload ) ? $payload : array(),
			'source'  => sanitize_key( $source ),
			'time'    => time(),
		);
	}

	public static function push( $command, $payload = array(), $source = '' ) {
		$event = self::make( $command, $payload, $source );

		if ( self::wc_session_available() ) {
			$events   = WC()->session->get( self::SESSION_KEY, array() );
			$events   = is_array( $events ) ? $events : array();
			$events[] = $event;
			WC()->session->set( self::SESSION_KEY, array_slice( $events, -100 ) );
		} elseif ( is_user_logged_in() ) {
			self::push_for_user( get_current_user_id(), $event );
		}

		self::log( $event );
		return $event;
	}

	public static function push_for_user( $user_id, $event_or_command, $payload = array(), $source = '' ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$event = is_array( $event_or_command ) && isset( $event_or_command['command'] )
			? $event_or_command
			: self::make( $event_or_command, $payload, $source );

		$events   = get_user_meta( $user_id, self::USER_META, true );
		$events   = is_array( $events ) ? $events : array();
		$events[] = $event;
		update_user_meta( $user_id, self::USER_META, array_slice( $events, -100 ) );
		self::log( $event );
		return $event;
	}

	public static function pull_all() {
		$events = array();

		if ( self::wc_session_available() ) {
			$session_events = WC()->session->get( self::SESSION_KEY, array() );
			if ( is_array( $session_events ) ) {
				$events = array_merge( $events, $session_events );
			}
			WC()->session->set( self::SESSION_KEY, array() );
		}

		if ( is_user_logged_in() ) {
			$user_id     = get_current_user_id();
			$user_events = get_user_meta( $user_id, self::USER_META, true );
			if ( is_array( $user_events ) ) {
				$events = array_merge( $events, $user_events );
			}
			delete_user_meta( $user_id, self::USER_META );
		}

		return array_values( array_filter( $events, array( __CLASS__, 'is_valid_event' ) ) );
	}

	private static function is_valid_event( $event ) {
		return is_array( $event ) && ! empty( $event['id'] ) && ! empty( $event['command'] );
	}

	private static function wc_session_available() {
		return function_exists( 'WC' ) && WC() && isset( WC()->session ) && WC()->session;
	}

	public static function log( $event ) {
		if ( ! AMA_DM_Settings::yes( 'debug_log' ) ) {
			return;
		}
		$log   = get_option( self::LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = $event;
		update_option( self::LOG_OPTION, array_slice( $log, -100 ), false );
	}

	public static function get_log() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	public static function clear_log() {
		delete_option( self::LOG_OPTION );
	}
}
