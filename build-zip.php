<?php
/**
 * Build the distribution zip from .distignore.
 *
 * WordPress's installer activates the file returned by Plugin_Upgrader::plugin_info(),
 * which is the first PHP file with a plugin header in the unpacked folder. A second
 * header (a stale copy, another plugin, or a manually zipped working directory) makes
 * that link point at the wrong file and fails with "The plugin does not have a valid
 * header." This script refuses to build unless the package contains exactly one
 * plugin header in sticky-order.php and one top-level sticky-order/ folder.
 *
 * PharData is used instead of ZipArchive because the zip extension is not installed
 * on every host this plugin is developed on — PharData writes a real .zip without it.
 *
 * Usage:
 *   php build-zip.php [--output=<path>] [--list]
 *
 *   --list  Print what would be included and exit without writing anything.
 *
 * @package StickyOrder
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script: writes plain text to STDOUT, never to HTML.

$root    = __DIR__;
$output  = '';
$dry_run = false;

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--output=' ) ) {
		$output = substr( $arg, 9 );
	} elseif ( '--list' === $arg ) {
		$dry_run = true;
	} else {
		fwrite( STDERR, "Unknown argument: {$arg}\n" );
		exit( 2 );
	}
}

// --- Main file and version ------------------------------------------------

$main_file = $root . '/sticky-order.php';
if ( ! is_file( $main_file ) ) {
	fwrite( STDERR, "Main plugin file not found.\n" );
	exit( 2 );
}

$head = (string) file_get_contents( $main_file, false, null, 0, 8192 );

if ( "\xEF\xBB\xBF" === substr( (string) file_get_contents( $main_file, false, null, 0, 3 ), 0, 3 ) ) {
	fwrite( STDERR, "Refusing to build: {$main_file} starts with a UTF-8 BOM.\n" );
	exit( 1 );
}

if ( ! preg_match( '/^\s*\*?\s*Plugin Name\s*:/mi', $head ) ) {
	fwrite( STDERR, "Refusing to build: no Plugin Name header in sticky-order.php.\n" );
	exit( 1 );
}

$version = '';
if ( preg_match( '/^\s*\*?\s*Version:\s*(\S+)/mi', $head, $m ) ) {
	$version = trim( $m[1] );
}
if ( '' === $version ) {
	fwrite( STDERR, "Cannot read the plugin version from the header.\n" );
	exit( 2 );
}

$readme_file = $root . '/readme.txt';
if ( is_file( $readme_file ) ) {
	$readme = (string) file_get_contents( $readme_file );
	if ( preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $m ) && trim( $m[1] ) !== $version ) {
		fwrite( STDERR, "Refusing to build: readme Stable tag ({$m[1]}) does not match Version ({$version}).\n" );
		exit( 1 );
	}
}

$slug = basename( $root );

// --- .distignore ----------------------------------------------------------

$patterns = array();
if ( is_file( $root . '/.distignore' ) ) {
	foreach ( preg_split( '/\r\n|\r|\n/', (string) file_get_contents( $root . '/.distignore' ) ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		$patterns[] = rtrim( $line, '/' );
	}
}

/**
 * Whether a root-relative path matches one of the ignore patterns.
 *
 * A pattern without a slash matches any path segment, so `scripts` excludes
 * `scripts/` at the root; a pattern with a slash is anchored at the root.
 *
 * @param string   $relative Root-relative path, forward slashes.
 * @param string[] $patterns Patterns from .distignore.
 * @return bool
 */
function sticky_order_dist_ignored( string $relative, array $patterns ): bool {
	foreach ( $patterns as $pattern ) {
		if ( false !== strpos( $pattern, '/' ) ) {
			if ( 0 === strpos( $relative, $pattern . '/' ) || $relative === $pattern ) {
				return true;
			}
			continue;
		}

		$segments = explode( '/', $relative );
		foreach ( $segments as $segment ) {
			if ( fnmatch( $pattern, $segment ) ) {
				return true;
			}
		}
	}

	return false;
}

// --- Collect files --------------------------------------------------------

$files    = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $iterator as $item ) {
	$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root ) + 1 ) );

	if ( sticky_order_dist_ignored( $relative, $patterns ) ) {
		continue;
	}

	if ( $item->isFile() ) {
		$files[] = $relative;
	}
}

sort( $files );

// --- Structure guard ------------------------------------------------------

$expected_main = basename( $main_file );
$header_files  = array();

foreach ( $files as $relative ) {
	if ( '.php' !== substr( $relative, -4 ) ) {
		continue;
	}

	$file_head = (string) file_get_contents( $root . '/' . $relative, false, null, 0, 8192 );
	if ( preg_match( '/^\s*\*?\s*Plugin Name\s*:/mi', $file_head ) ) {
		$header_files[] = $relative;
	}
}

if ( ! in_array( $expected_main, $files, true ) ) {
	fwrite( STDERR, "Refusing to build: {$expected_main} is missing.\n" );
	exit( 1 );
}

if ( array( $expected_main ) !== $header_files ) {
	fwrite(
		STDERR,
		"Refusing to build: expected exactly one plugin header in {$expected_main}, found:\n  "
			. implode( "\n  ", $header_files ) . "\n"
	);
	exit( 1 );
}

$forbidden = array( '.git', 'AUDIT.md', 'scripts', 'build-zip.php', 'dist' );
foreach ( $files as $relative ) {
	foreach ( $forbidden as $needle ) {
		if ( $relative === $needle || 0 === strpos( $relative, $needle . '/' ) ) {
			fwrite( STDERR, "Refusing to build: {$relative} must not ship.\n" );
			exit( 1 );
		}
	}
}

// --- Report ---------------------------------------------------------------

$total_bytes = 0;
foreach ( $files as $relative ) {
	$total_bytes += (int) filesize( $root . '/' . $relative );
}

if ( $dry_run ) {
	echo "Distribution contents for {$slug} {$version}\n";
	foreach ( $files as $relative ) {
		printf( "  %8d  %s\n", filesize( $root . '/' . $relative ), $relative );
	}
	printf( "\n%d files, %s\n", count( $files ), sticky_order_bytes( $total_bytes ) );
	exit( 0 );
}

/**
 * Human-readable byte count.
 *
 * @param int $bytes Byte count.
 * @return string
 */
function sticky_order_bytes( int $bytes ): string {
	if ( $bytes >= 1048576 ) {
		return round( $bytes / 1048576, 1 ) . ' MB';
	}
	if ( $bytes >= 1024 ) {
		return round( $bytes / 1024, 1 ) . ' KB';
	}

	return $bytes . ' B';
}

// --- Write ----------------------------------------------------------------

if ( '' === $output ) {
	$output = $root . '/dist/' . $slug . '-' . $version . '.zip';
}

$output_dir = dirname( $output );
if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) ) {
	fwrite( STDERR, "Cannot create {$output_dir}\n" );
	exit( 1 );
}

if ( is_file( $output ) ) {
	unlink( $output );
}

try {
	$zip = new PharData( $output, 0, null, Phar::ZIP );
	$zip->startBuffering();
	foreach ( $files as $relative ) {
		$zip->addFile( $root . '/' . $relative, $slug . '/' . $relative );
	}
	$zip->stopBuffering();
} catch ( Exception $e ) {
	fwrite( STDERR, 'Build failed: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

clearstatcache();
printf(
	"Built %s\n  %d files, %s\n",
	$output,
	count( $files ),
	sticky_order_bytes( (int) filesize( $output ) )
);
