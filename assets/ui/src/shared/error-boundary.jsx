/**
 * Catches render errors in one subtree so a bad card cannot blank the page.
 *
 * React unmounts the whole tree above a throwing component; without a boundary
 * one malformed payload section turns the admin screen white. The fallback
 * keeps the rest of the page usable and offers a retry.
 */
import { Component } from 'react';

export default class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { failed: false };
		this.retry = this.retry.bind( this );
	}

	static getDerivedStateFromError() {
		return { failed: true };
	}

	componentDidCatch( error, info ) {
		window.console.error( 'sakura-tools: a card failed to render', error, info );
	}

	componentDidUpdate( previous ) {
		if ( previous.resetKey !== this.props.resetKey && this.state.failed ) {
			this.setState( { failed: false } );
		}
	}

	retry() {
		this.setState( { failed: false } );
	}

	render() {
		if ( this.state.failed ) {
			return (
				<div className="card__desc" role="alert">
					<p>这块内容加载失败，可以重试；仍不行就刷新整页。</p>
					<button type="button" className="btn btn--primary" onClick={ this.retry }>重试</button>
				</div>
			);
		}

		return this.props.children;
	}
}