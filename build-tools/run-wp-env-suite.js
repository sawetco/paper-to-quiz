const { spawnSync } = require( 'node:child_process' );
const path = require( 'node:path' );

const root = path.resolve( __dirname, '..' );
const target = process.argv[ 2 ];
const suite = process.argv[ 3 ];
const targets = {
	wp71: {
		config: path.join( root, '.wp-env.json' ),
		version: '7.1',
		url: 'http://localhost:8888',
	},
	wp68: {
		config: path.join( root, '.wp-env.wp68.json' ),
		version: '6.8.8',
		url: 'http://localhost:8890',
	},
};

if ( ! targets[ target ] || ! [ 'integration', 'e2e' ].includes( suite ) ) {
	console.error(
		'Usage: node build-tools/run-wp-env-suite.js <wp71|wp68> <integration|e2e>'
	);
	process.exit( 2 );
}

const selected = targets[ target ];
const wpEnv =
	process.platform === 'win32'
		? path.join( root, 'node_modules', '.bin', 'wp-env.cmd' )
		: path.join( root, 'node_modules', '.bin', 'wp-env' );
const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
const commandOptions = {
	cwd: root,
	env: {
		...process.env,
		WP_ENV_HOME: path.join( root, '.wp-env-home', target ),
	},
	shell: process.platform === 'win32',
};

function execute( command, args, options = {} ) {
	const result = spawnSync( command, args, {
		...commandOptions,
		encoding: options.capture ? 'utf8' : undefined,
		stdio: options.capture ? 'pipe' : 'inherit',
		env: { ...commandOptions.env, ...( options.env || {} ) },
	} );
	if ( options.capture && result.stdout ) {
		process.stdout.write( result.stdout );
	}
	if ( options.capture && result.stderr ) {
		process.stderr.write( result.stderr );
	}
	if ( result.status !== 0 && ! options.allowFailure ) {
		throw new Error(
			`${ command } ${ args.join( ' ' ) } failed with ${ result.status }`
		);
	}
	return result;
}

function envCommand( args, options = {} ) {
	return execute( wpEnv, [ '--config', selected.config, ...args ], options );
}

function installWordPress() {
	const installed = envCommand(
		[ 'run', 'cli', 'wp', 'core', 'is-installed' ],
		{
			allowFailure: true,
			capture: true,
		}
	);
	if ( installed.status !== 0 ) {
		envCommand( [
			'run',
			'cli',
			'wp',
			'core',
			'install',
			`--url=${ selected.url }`,
			'--title=Paper to Quiz Compatibility',
			'--admin_user=admin',
			'--admin_password=paper-to-quiz-integration',
			'--admin_email=admin@example.com',
			'--skip-email',
		] );
	}
	const version = envCommand( [ 'run', 'cli', 'wp', 'core', 'version' ], {
		capture: true,
	} ).stdout.trim();
	if ( version !== selected.version ) {
		throw new Error(
			`Expected WordPress ${ selected.version }, received ${ version }`
		);
	}
	envCommand( [
		'run',
		'cli',
		'wp',
		'user',
		'update',
		'admin',
		'--user_pass=paper-to-quiz-integration',
	] );
	envCommand( [ 'run', 'cli', 'wp', 'plugin', 'activate', 'paper-to-quiz' ] );
}

function runRegression() {
	for ( const file of [ 'data-regression.php', 'rest-regression.php' ] ) {
		envCommand( [
			'run',
			'cli',
			'env',
			'WP_ENVIRONMENT_TYPE=local',
			'PAPER_TO_QUIZ_ALLOW_REGRESSION=1',
			'wp',
			'eval-file',
			`wp-content/plugins/paper-to-quiz/tests/${ file }`,
		] );
	}
}

let started = false;
let fixtureCreated = false;
try {
	if ( suite === 'e2e' ) {
		execute( npm, [ 'run', 'build' ] );
	}
	envCommand( [ 'start' ] );
	started = true;
	envCommand( [ 'reset', 'all' ] );
	installWordPress();

	if ( suite === 'integration' ) {
		runRegression();
	} else {
		const fixture = envCommand(
			[
				'run',
				'cli',
				'env',
				'WP_ENVIRONMENT_TYPE=local',
				'PAPER_TO_QUIZ_ALLOW_E2E=1',
				'PTQ_E2E_ACTION=create',
				'wp',
				'eval-file',
				'wp-content/plugins/paper-to-quiz/tests/e2e-fixture.php',
			],
			{ capture: true }
		);
		const match = fixture.stdout.match( /PTQ_E2E_ASSESSMENT_ID=(\d+)/ );
		if ( ! match ) {
			throw new Error(
				'The E2E fixture did not return an assessment ID.'
			);
		}
		fixtureCreated = true;
		execute( npm, [ 'exec', '--', 'playwright', 'test' ], {
			env: {
				PTQ_BASE_URL: selected.url,
				PTQ_E2E_ASSESSMENT_ID: match[ 1 ],
			},
		} );
	}
} finally {
	if ( fixtureCreated ) {
		envCommand(
			[
				'run',
				'cli',
				'env',
				'WP_ENVIRONMENT_TYPE=local',
				'PAPER_TO_QUIZ_ALLOW_E2E=1',
				'PTQ_E2E_ACTION=cleanup',
				'wp',
				'eval-file',
				'wp-content/plugins/paper-to-quiz/tests/e2e-fixture.php',
			],
			{ allowFailure: true }
		);
	}
	if ( started ) {
		envCommand( [ 'stop' ], { allowFailure: true } );
	}
}
