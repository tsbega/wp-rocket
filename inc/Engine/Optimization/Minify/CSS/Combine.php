<?php
namespace WP_Rocket\Engine\Optimization\Minify\CSS;

use MatthiasMullie\Minify\CSS as MinifyCSS;
use WP_Rocket\Admin\Options_Data;
use WP_Rocket\Engine\Optimization\AssetsLocalCache;
use WP_Rocket\Engine\Optimization\CSSTrait;
use WP_Rocket\Logger\Logger;

/**
 * Minify & Combine CSS files
 *
 * @since 3.1
 */
class Combine extends AbstractCSSOptimization {
	use CSSTrait;

	/**
	 * Assets local cache instance
	 *
	 * @var AssetsLocalCache
	 */
	private $local_cache;

	/**
	 * Array of styles
	 *
	 * @var array
	 */
	private $styles = [];

	/**
	 * Combined CSS filename
	 *
	 * @var string
	 */
	private $filename;

	/**
	 * Constructor
	 *
	 * @param Options_Data     $options     Options instance.
	 * @param AssetsLocalCache $local_cache AssetsLocalCache instance.
	 */
	public function __construct( Options_Data $options, AssetsLocalCache $local_cache ) {
		parent::__construct( $options );

		$this->local_cache = $local_cache;
	}

	/**
	 * Minifies and combines all CSS files into one
	 *
	 * @since 3.1
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	public function optimize( $html ) {
		Logger::info( 'CSS COMBINE PROCESS STARTED.', [ 'css combine process' ] );

		$html_nocomments = $this->hide_comments( $html );
		$styles          = $this->find( '<link\s+([^>]+[\s"\'])?href\s*=\s*[\'"]\s*?(?<url>[^\'"]+\.css(?:\?[^\'"]*)?)\s*?[\'"]([^>]+)?\/?>', $html_nocomments );

		if ( ! $styles ) {
			Logger::debug( 'No `<link>` tags found.', [ 'css combine process' ] );
			return $html;
		}

		Logger::debug(
			'Found ' . count( $styles ) . ' `<link>` tag(s).',
			[
				'css combine process',
				'tags' => $styles,
			]
		);

		$styles = $this->parse( $styles );

		if ( empty( $styles ) ) {
			Logger::debug( 'No `<link>` tags to optimize.', [ 'css combine process' ] );
			return $html;
		}

		Logger::debug(
			count( $styles ) . ' `<link>` tag(s) remaining.',
			[
				'css combine process',
				'tags' => $styles,
			]
		);

		if ( ! $this->combine() ) {
			Logger::error( 'CSS combine process failed.', [ 'css combine process' ] );
			return $html;
		}

		return $this->insert_combined_css( $html );
	}

	/**
	 * Parses all found styles tag to keep only the ones to combine
	 *
	 * @since 3.7
	 *
	 * @param array $styles Array of matched styles.
	 * @return array
	 */
	private function parse( array $styles ) {
		foreach ( $styles as $key => $style ) {
			if ( $this->is_external_file( $style['url'] ) ) {
				if ( $this->is_excluded_external( $style['url'] ) ) {
					unset( $styles[ $key ] );

					continue;
				}

				$this->styles[ $style['url'] ] = [
					'type' => 'external',
					'tag'  => $style[0],
					'url'  => $style['url'],
				];

				continue;
			}

			if ( $this->is_minify_excluded_file( $style ) ) {
				Logger::debug(
					'Style is excluded.',
					[
						'css combine process',
						'tag' => $style[0],
					]
				);

				unset( $styles[ $key ] );

				continue;
			}

			$this->styles[ $style['url'] ] = [
				'type' => 'internal',
				'tag'  => $style[0],
				'url'  => $style['url'],
			];
		}

		return $styles;
	}

	/**
	 * Checks if the provided external URL is excluded from combine
	 *
	 * @since 3.7
	 *
	 * @param string $url External URL to check.
	 * @return boolean
	 */
	private function is_excluded_external( $url ) {
		foreach ( $this->get_excluded_externals() as $excluded ) {
			if ( false !== strpos( $url, $excluded ) ) {
				Logger::debug(
					'Style is external.',
					[
						'css combine process',
						'url' => $url,
					]
				);
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets external URLs excluded from combine
	 *
	 * @since 3.7
	 *
	 * @return array
	 */
	private function get_excluded_externals() {
		/**
		 * Filters CSS external URLs to exclude from the combine process
		 *
		 * @since 3.7
		 *
		 * @param array $pattern Patterns to match.
		 */
		$excluded_externals = (array) apply_filters( 'rocket_combine_css_excluded_external', [] );

		return array_merge( $excluded_externals, $this->options->get( 'exclude_css', [] ) );
	}

	/**
	 * Combine the CSS content into one file and save it
	 *
	 * @since 3.1
	 *
	 * @return bool True if successful, false otherwise
	 */
	protected function combine() {
		if ( empty( $this->styles ) ) {
			return false;
		}

		$file_hash      = implode( ',', array_column( $this->styles, 'url' ) );
		$this->filename = md5( $file_hash . $this->minify_key ) . '.css';

		$combined_file = $this->minify_base_path . $this->filename;

		if ( rocket_direct_filesystem()->exists( $combined_file ) ) {
			Logger::debug(
				'Combined CSS file already exists.',
				[
					'css combine process',
					'path' => $combined_file,
				]
			);

			return true;
		}

		$combined_content = $this->get_content( $combined_file );
		$combined_content = $this->apply_font_display_swap( $combined_content );

		if ( empty( $combined_content ) ) {
			Logger::error(
				'No combined content.',
				[
					'css combine process',
					'path' => $combined_file,
				]
			);
			return false;
		}

		if ( ! $this->write_file( $combined_content, $combined_file ) ) {
			Logger::error(
				'Combined CSS file could not be created.',
				[
					'css combine process',
					'path' => $combined_file,
				]
			);
			return false;
		}

		Logger::debug(
			'Combined CSS file successfully created.',
			[
				'css combine process',
				'path' => $combined_file,
			]
		);

		return true;
	}

	/**
	 * Insert the combined CSS file and remove the original CSS tags
	 *
	 * The combined CSS file is added after the closing </title> tag, and the replacement occurs only once. The original CSS tags are then removed from the HTML.
	 *
	 * @since 3.3.3
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	protected function insert_combined_css( $html ) {
		foreach ( $this->styles as $style ) {
			$html = str_replace( $style['tag'], '', $html );
		}

		$minify_url = $this->get_minify_url( $this->filename );

		Logger::info(
			'Combined CSS file successfully added.',
			[
				'css combine process',
				'url' => $minify_url,
			]
		);

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		return preg_replace( '/<\/title>/i', '$0<link rel="stylesheet" href="' . esc_url( $minify_url ) . '" media="all" data-minify="1" />', $html, 1 );
	}

	/**
	 * Gathers the content from all styles to combine & minify it if needed
	 *
	 * @since 3.7
	 *
	 * @param string $combined_file Absolute path to the combined file.
	 * @return string
	 */
	private function get_content( $combined_file ) {
		$content = '';

		foreach ( $this->styles as $key => $style ) {
			if ( 'internal' === $style['type'] ) {
				$filepath     = $this->get_file_path( $style['url'] );
				$file_content = $this->get_file_content( $filepath );
				$file_content = $this->rewrite_paths( $filepath, $combined_file, $file_content );
			} elseif ( 'external' === $style['type'] ) {
				$file_content = $this->local_cache->get_content( rocket_add_url_protocol( $style['url'] ) );
			}

			if ( empty( $file_content ) ) {
				unset( $this->styles[ $key ] );

				continue;
			}

			if ( false !== strpos( $style['url'], '.min.css' ) ) {
				$content .= $file_content;

				continue;
			}

			$minified_content = $this->minify( $file_content );

			if ( empty( $minified_content ) ) {
				unset( $this->styles[ $key ] );

				continue;
			}

			$content .= $minified_content;
		}

		if ( empty( $content ) ) {
			Logger::debug( 'No CSS content.', [ 'css combine process' ] );
		}

		return $content;
	}

	/**
	 * Minifies the content
	 *
	 * @since 3.1
	 *
	 * @param string $content Content to minify.
	 * @return string
	 */
	protected function minify( $content ) {
		$minifier = new MinifyCSS( $content );

		return $minifier->minify();
	}
}
