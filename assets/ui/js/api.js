/**
 * Sakura Admin API: one fetch wrapper for admin-ajax and REST, with a typed
 * error and an abort deadline. It never shows a toast on its own; callers
 * decide how a failure is surfaced.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window ) {
	'use strict';

	var admin = window.SakuraAdmin;
	var page = admin.page;

	function SakuraApiError( message, options ) {
		var settings = options || {};
		this.name = 'SakuraApiError';
		this.message = message || '请求失败';
		this.status = settings.status || 0;
		this.code = settings.code || '';
		this.payload = settings.payload === undefined ? null : settings.payload;
		if ( Error.captureStackTrace ) {
			Error.captureStackTrace( this, SakuraApiError );
		}
	}

	SakuraApiError.prototype = Object.create( Error.prototype );
	SakuraApiError.prototype.constructor = SakuraApiError;

	function isPlainObject( value ) {
		return Object.prototype.toString.call( value ) === '[object Object]';
	}

	function encode( data ) {
		if ( ! data ) {
			return null;
		}
		if ( data instanceof window.FormData ) {
			return data;
		}
		if ( data instanceof window.URLSearchParams ) {
			return data;
		}
		var params = new window.URLSearchParams();
		Object.keys( data ).forEach( function ( key ) {
			var value = data[ key ];
			if ( value === undefined || value === null ) {
				return;
			}
			if ( Array.isArray( value ) ) {
				var arrayKey = /\[\]$/.test( key ) ? key : key + '[]';
				value.forEach( function ( item ) {
					params.append( arrayKey, item );
				} );
				return;
			}
			if ( isPlainObject( value ) ) {
				params.append( key, JSON.stringify( value ) );
				return;
			}
			params.append( key, value );
		} );
		return params;
	}

	function makeError( response, payload ) {
		var message = payload && ( payload.message || ( payload.data && payload.data.message ) );
		return new SakuraApiError( message || '服务器返回 ' + response.status, {
			status: response.status,
			payload: payload
		} );
	}

	/**
	 * @param {string} url
	 * @param {Object} options fetch options plus { timeout, raw, type }.
	 *   type "text" answers with { text, url, status, redirected } instead of JSON:
	 *   a whole-page response (options.php) is HTML, and JSON.parse would throw.
	 * @return {Promise<any>} Parsed JSON, a text result, or the Response when raw is true.
	 */
	function request( url, options ) {
		var settings = Object.assign( {}, options || {} );
		var timeout = Number( settings.timeout ) || 20000;
		var wantText = 'text' === settings.type;
		var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
		var timer = 0;

		delete settings.timeout;
		delete settings.type;
		var raw = !! settings.raw;
		delete settings.raw;

		settings.credentials = settings.credentials || 'same-origin';
		settings.headers = Object.assign( { Accept: 'application/json' }, settings.headers || {} );

		if ( settings.body && ! ( settings.body instanceof window.FormData ) && ! ( settings.body instanceof window.URLSearchParams ) ) {
			if ( isPlainObject( settings.body ) ) {
				settings.headers[ 'Content-Type' ] = 'application/json';
				settings.body = JSON.stringify( settings.body );
			}
		}

		if ( controller ) {
			settings.signal = controller.signal;
			timer = window.setTimeout( function () {
				controller.abort();
			}, timeout );
		}

		return window.fetch( url, settings ).then( function ( response ) {
			return response.text().then( function ( text ) {
				if ( wantText ) {
					if ( ! response.ok ) {
						throw makeError( response, null );
					}
					return {
						text: text,
						url: response.url,
						status: response.status,
						redirected: response.redirected
					};
				}
				var payload = text ? admin.parseJSON( text, null ) : null;
				if ( ! response.ok ) {
					throw makeError( response, payload );
				}
				if ( raw ) {
					return response;
				}
				return payload;
			} );
		} ).catch( function ( error ) {
			if ( error instanceof SakuraApiError ) {
				throw error;
			}
			if ( error && error.name === 'AbortError' ) {
				throw new SakuraApiError( '请求超时，请重试', { code: 'timeout' } );
			}
			throw new SakuraApiError( error && error.message ? error.message : '网络请求失败', { code: 'network' } );
		} ).finally( function () {
			window.clearTimeout( timer );
		} );
	}

	function post( url, data, options ) {
		var settings = Object.assign( {}, options || {} );
		var body = encode( data );
		if ( body ) {
			settings.body = body;
			if ( body instanceof window.URLSearchParams ) {
				settings.headers = Object.assign( { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, settings.headers || {} );
			}
		}
		settings.method = 'POST';
		return request( url, settings );
	}

	function get( url, data, options ) {
		var target = new window.URL( url, window.location.href );
		var params = encode( data );
		if ( params ) {
			target.search = params.toString();
		}
		return request( target.toString(), options );
	}

	/**
	 * admin-ajax.php POST that unwraps WordPress's {success, data} envelope.
	 */
	function ajax( action, data, options ) {
		var payload = Object.assign( { action: action }, data || {} );
		return post( page.ajaxUrl, payload, options ).then( function ( response ) {
			if ( response && typeof response === 'object' && 'success' in response ) {
				if ( ! response.success ) {
					throw new SakuraApiError( '操作未完成', { payload: response } );
				}
				return response.data;
			}
			return response;
		} );
	}

	function upload( url, file, fields, options ) {
		var body = new window.FormData();
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );
		body.append( 'file', file );
		var settings = Object.assign( { method: 'POST' }, options || {} );
		settings.body = body;
		return request( url, settings );
	}

	admin.api = {
		SakuraApiError: SakuraApiError,
		request: request,
		get: get,
		post: post,
		ajax: ajax,
		upload: upload
	};
}( window ) );
