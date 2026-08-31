import { useEffect, useRef, useState } from '@wordpress/element';
import { Notice, Spinner, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api } from './api';
import { BusyLabel } from './BusyLabel';

type Settings = {
	max_pdf_mb: number;
	retention_days: number;
	crop_dpi: number;
	max_image_edge: number;
	page_warning: number;
	network_grace: number;
	storage_writable?: boolean;
	openssl?: boolean;
	max_upload_bytes?: number;
	purge_on_uninstall: boolean;
	encryption_migration?: {
		status: 'pending' | 'running' | 'complete';
		failures: number;
	};
};

const fields: Array< {
	key: keyof Settings;
	label: string;
	description: string;
	min: number;
	max: number;
} > = [
	{
		key: 'max_pdf_mb',
		label: __( 'Maximum PDF size', 'paper-to-quiz' ),
		description: __(
			'Limits the size of each PDF you can upload while preparing an exam or test.',
			'paper-to-quiz'
		),
		min: 1,
		max: 500,
	},
	{
		key: 'crop_dpi',
		label: __( 'Question image quality', 'paper-to-quiz' ),
		description: __(
			'Higher values make small text and formulas clearer, but use more storage. 240 DPI is recommended.',
			'paper-to-quiz'
		),
		min: 120,
		max: 360,
	},
	{
		key: 'max_image_edge',
		label: __( 'Question image size limit', 'paper-to-quiz' ),
		description: __(
			'Limits the width or height of generated question images. A higher limit can improve readability but increases file size.',
			'paper-to-quiz'
		),
		min: 1200,
		max: 6000,
	},
	{
		key: 'page_warning',
		label: __( 'Large PDF warning threshold', 'paper-to-quiz' ),
		description: __(
			'Shows a performance reminder when an uploaded PDF has more pages than this value.',
			'paper-to-quiz'
		),
		min: 20,
		max: 1000,
	},
	{
		key: 'retention_days',
		label: __( 'Participant data retention', 'paper-to-quiz' ),
		description: __(
			'Participant names and contact details are anonymized after this many days. Scores and aggregate reports remain available.',
			'paper-to-quiz'
		),
		min: 1,
		max: 3650,
	},
	{
		key: 'network_grace',
		label: __( 'Submission connection allowance', 'paper-to-quiz' ),
		description: __(
			'Allows this many extra seconds only when a timed exam is being submitted during a brief connection problem. It does not extend the exam duration.',
			'paper-to-quiz'
		),
		min: 0,
		max: 120,
	},
];

export function SettingsPage() {
	const [ settings, setSettings ] = useState< Settings | null >( null );
	const [ saved, setSaved ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const saveLock = useRef( false );

	useEffect( () => {
		api< Settings >( '/admin/settings' )
			.then( setSettings )
			.catch( ( caught ) => setError( caught.message ) );
	}, [] );

	if ( ! settings ) {
		return (
			<div className="ptq-page">
				{ error ? (
					<Notice status="error">{ error }</Notice>
				) : (
					<Spinner />
				) }
			</div>
		);
	}

	let migrationMessage = '';
	let migrationNoticeStatus: 'warning' | 'error' = 'warning';
	if (
		settings.encryption_migration &&
		( settings.encryption_migration.status === 'pending' ||
			settings.encryption_migration.status === 'running' )
	) {
		if ( settings.encryption_migration.failures > 0 ) {
			migrationNoticeStatus = 'error';
			migrationMessage = __(
				'Some encrypted records need attention. Paper to Quiz will keep retrying the secure upgrade automatically. Do not rotate WordPress salts until the upgrade is complete.',
				'paper-to-quiz'
			);
		} else if ( settings.encryption_migration.status === 'running' ) {
			migrationMessage = __(
				'Encryption upgrade is running in the background. Your existing data remains available. Do not rotate WordPress salts until the upgrade is complete.',
				'paper-to-quiz'
			);
		} else {
			migrationMessage = __(
				'Encryption upgrade is queued and will continue automatically in the background. Do not rotate WordPress salts until the upgrade is complete.',
				'paper-to-quiz'
			);
		}
	}

	return (
		<div className="ptq-page paper-to-quiz-settings-page">
			<h1>{ __( 'Settings', 'paper-to-quiz' ) }</h1>
			{ saved && (
				<Notice status="success" onRemove={ () => setSaved( false ) }>
					{ __( 'Settings saved.', 'paper-to-quiz' ) }
				</Notice>
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ ! settings.storage_writable && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'File storage is unavailable. Ask your server administrator to check the upload directory write permissions.',
						'paper-to-quiz'
					) }
				</Notice>
			) }
			{ ! settings.openssl && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'File security is unavailable. Do not upload PDFs until the server configuration is fixed.',
						'paper-to-quiz'
					) }
				</Notice>
			) }
			{ migrationMessage && (
				<Notice
					status={ migrationNoticeStatus }
					isDismissible={ false }
				>
					{ migrationMessage }
				</Notice>
			) }
			<form
				onSubmit={ async ( event ) => {
					event.preventDefault();
					if ( saveLock.current ) {
						return;
					}
					saveLock.current = true;
					setSaving( true );
					setError( '' );
					try {
						const payload = Object.fromEntries(
							fields.map( ( field ) => [
								field.key,
								settings[ field.key ],
							] )
						);
						payload.purge_on_uninstall = Boolean(
							settings.purge_on_uninstall
						);
						const updated = await api< Settings >(
							'/admin/settings',
							{
								method: 'PUT',
								json: payload,
							}
						);
						setSettings( { ...settings, ...updated } );
						setSaved( true );
					} catch ( caught ) {
						setError(
							caught instanceof Error
								? caught.message
								: __(
										'Settings could not be saved.',
										'paper-to-quiz'
								  )
						);
					} finally {
						saveLock.current = false;
						setSaving( false );
					}
				} }
			>
				<table className="form-table" role="presentation">
					<tbody>
						{ fields.map( ( field ) => (
							<tr key={ field.key }>
								<th scope="row">
									<label htmlFor={ `ptq-${ field.key }` }>
										{ field.label }
									</label>
								</th>
								<td>
									<input
										id={ `ptq-${ field.key }` }
										type="number"
										className="small-text"
										min={ field.min }
										max={ field.max }
										value={ String(
											settings[ field.key ] ?? ''
										) }
										disabled={ saving }
										onChange={ ( event ) =>
											setSettings( {
												...settings,
												[ field.key ]: Number(
													event.target.value
												),
											} )
										}
									/>
									{ field.key === 'max_pdf_mb' && (
										<span className="ptq-field-unit">
											{ ' ' }
											MB
										</span>
									) }
									{ field.key === 'crop_dpi' && (
										<span className="ptq-field-unit">
											{ ' ' }
											DPI
										</span>
									) }
									{ field.key === 'max_image_edge' && (
										<span className="ptq-field-unit">
											{ ' ' }
											{ __( 'pixels', 'paper-to-quiz' ) }
										</span>
									) }
									{ [
										'retention_days',
										'page_warning',
									].includes( field.key ) && (
										<span className="ptq-field-unit">
											{ ' ' }
											{ field.key === 'retention_days'
												? __( 'days', 'paper-to-quiz' )
												: __(
														'pages',
														'paper-to-quiz'
												  ) }
										</span>
									) }
									{ field.key === 'network_grace' && (
										<span className="ptq-field-unit">
											{ ' ' }
											{ __( 'seconds', 'paper-to-quiz' ) }
										</span>
									) }
									<p className="description">
										{ field.description }
										{ field.key === 'max_pdf_mb' &&
											settings.max_upload_bytes && (
												<>
													{ ' ' }
													{ sprintf(
														/* translators: %d: Maximum upload size in megabytes. */
														__(
															'Server maximum: %d MB.',
															'paper-to-quiz'
														),
														Math.round(
															settings.max_upload_bytes /
																1048576
														)
													) }
												</>
											) }
									</p>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
				<section
					className="ptq-danger-zone"
					aria-labelledby="ptq-danger-zone-title"
				>
					<h2 id="ptq-danger-zone-title">
						{ __( 'Danger Zone', 'paper-to-quiz' ) }
					</h2>
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Your exams, tests, participant results, and uploaded files are preserved when you deactivate Paper to Quiz.',
							'paper-to-quiz'
						) }
					</Notice>
					<ToggleControl
						label={ __(
							'Delete all Paper to Quiz data when the plugin is deleted',
							'paper-to-quiz'
						) }
						help={ __(
							'Use this only when you are finished with Paper to Quiz. Deleting the plugin will also permanently delete every exam, test, participant result, setting, and private file created by it.',
							'paper-to-quiz'
						) }
						checked={ Boolean( settings.purge_on_uninstall ) }
						disabled={ saving }
						onChange={ ( value ) =>
							setSettings( {
								...settings,
								purge_on_uninstall: value,
							} )
						}
					/>
				</section>
				<p className="submit">
					<button
						type="submit"
						className="button button-primary"
						disabled={ saving }
					>
						{ saving ? (
							<BusyLabel>
								{ __( 'Saving…', 'paper-to-quiz' ) }
							</BusyLabel>
						) : (
							__( 'Save changes', 'paper-to-quiz' )
						) }
					</button>
				</p>
			</form>
		</div>
	);
}
