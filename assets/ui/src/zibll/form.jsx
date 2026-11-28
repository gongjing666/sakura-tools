import { BusyLabel } from '../shared/ui.jsx';

/**
 * One card's controlled form. Cards submit JSON to Space_Settings_Controller;
 * there are no option names, hidden markers or options.php nonces left here.
 */
export default function Form( { children, label, saving, pending, failure, onSubmit } ) {
	return (
		<form className="card__form" onSubmit={ onSubmit } noValidate>
			{ children }
			{ failure ? (
				<p className="zibll-error" role="alert">{ failure }</p>
			) : null }
			<div className="card__savebar">
				<button type="submit" className="btn btn--primary" aria-busy={ pending ? 'true' : 'false' }>
					<BusyLabel idle={ label } active={ saving } busy={ pending } />
				</button>
			</div>
		</form>
	);
}
