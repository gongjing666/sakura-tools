<?php
/**
 * Collect named add_action()/add_filter() registrations from production PHP.
 *
 * The external codegraph scanner records only a literal first argument, so it
 * drops hooks built from class constants or concatenation. This collector
 * resolves string literals, self/static constants, imported class constants,
 * and "." concatenation, then emits JSON for build-code-structure.mjs.
 *
 * Usage: php collect-hooks.php <plugin-root> > hooks.json
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}

$root = rtrim( $argv[1] ?? '.', '/' );
if ( '' === $root || ! is_dir( $root ) ) {
	fwrite( STDERR, "usage: collect-hooks.php <plugin-root>\n" );
	exit( 2 );
}

$exclude = array( '.agents', 'vendor', 'node_modules', 'tests', 'stubs', 'docs', '.git' );
$files   = array();
$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$rel  = ltrim( substr( $file->getPathname(), strlen( $root ) ), '/' );
	$skip = false;
	foreach ( $exclude as $dir ) {
		if ( $rel === $dir || str_starts_with( $rel, $dir . '/' ) ) {
			$skip = true;
			break;
		}
	}
	if ( ! $skip ) {
		$files[] = array( $rel, $file->getPathname() );
	}
}
usort( $files, static fn( array $a, array $b ): int => strcmp( $a[0], $b[0] ) );

/**
 * Return the source text of a token range.
 *
 * @param array<int,array|string> $tokens Tokens.
 * @param int                     $from   Start index.
 * @param int                     $to     End index, exclusive.
 * @return string
 */
function token_text( array $tokens, int $from, int $to ): string {
	$out = '';
	for ( $i = $from; $i < $to; $i++ ) {
		$out .= is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
	}
	return $out;
}

/**
 * Decode a PHP string literal, or return null when the text is not a literal.
 *
 * @param string $text Expression text.
 * @return string|null
 */
function unquote_php( string $text ): ?string {
	$text = trim( $text );
	if ( strlen( $text ) < 2 ) {
		return null;
	}
	$quote = $text[0];
	if ( ( "'" !== $quote && '"' !== $quote ) || substr( $text, -1 ) !== $quote ) {
		return null;
	}
	$body = substr( $text, 1, -1 );
	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}
	return stripcslashes( $body );
}

/**
 * Split a "." concatenation without cutting inside strings or parentheses.
 *
 * @param string $expr Expression text.
 * @return string[]
 */
function split_concat( string $expr ): array {
	$parts = array();
	$cur   = '';
	$quote = null;
	$depth = 0;
	$len   = strlen( $expr );
	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $expr[ $i ];
		if ( null !== $quote ) {
			$cur .= $ch;
			if ( '\\' === $ch ) {
				$i++;
				if ( $i < $len ) {
					$cur .= $expr[ $i ];
				}
				continue;
			}
			if ( $ch === $quote ) {
				$quote = null;
			}
			continue;
		}
		if ( "'" === $ch || '"' === $ch ) {
			$quote = $ch;
			$cur  .= $ch;
			continue;
		}
		if ( '(' === $ch || '[' === $ch || '{' === $ch ) {
			$depth++;
			$cur .= $ch;
			continue;
		}
		if ( ')' === $ch || ']' === $ch || '}' === $ch ) {
			$depth--;
			$cur .= $ch;
			continue;
		}
		if ( '.' === $ch && 0 === $depth ) {
			$parts[] = trim( $cur );
			$cur     = '';
			continue;
		}
		$cur .= $ch;
	}
	if ( '' !== trim( $cur ) ) {
		$parts[] = trim( $cur );
	}
	return $parts;
}

/**
 * Resolve a class-constant expression against a declaring class.
 *
 * @param string $expr    Constant value expression.
 * @param string $class   Declaring class FQCN.
 * @param array  $state   Collector state.
 * @param array  $seen    Recursion guard.
 * @return string|null
 */
function resolve_expr( string $expr, string $class, array &$state, array $seen = array() ): ?string {
	$expr = trim( $expr );
	if ( '' === $expr ) {
		return null;
	}
	$literal = unquote_php( $expr );
	if ( null !== $literal ) {
		return $literal;
	}
	$parts = split_concat( $expr );
	if ( count( $parts ) > 1 ) {
		$out = '';
		foreach ( $parts as $part ) {
			$value = resolve_term( $part, $class, $state, $seen );
			if ( null === $value ) {
				return null;
			}
			$out .= $value;
		}
		return $out;
	}
	return resolve_term( $expr, $class, $state, $seen );
}

/**
 * Resolve one term (literal or class constant).
 *
 * @param string $term  Term text.
 * @param string $class Current class FQCN.
 * @param array  $state Collector state.
 * @param array  $seen  Recursion guard.
 * @return string|null
 */
function resolve_term( string $term, string $class, array &$state, array $seen = array() ): ?string {
	$term = trim( $term );
	if ( '' === $term ) {
		return null;
	}
	$literal = unquote_php( $term );
	if ( null !== $literal ) {
		return $literal;
	}

	if ( preg_match( '/^(self|static)::([A-Za-z_][A-Za-z0-9_]*)$/', $term, $m ) ) {
		return resolve_const( $class, $m[2], $state, $seen );
	}
	if ( preg_match( '/^\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)$/', $term, $m ) ) {
		$target = resolve_class_name( $m[1], $class, $state );
		if ( null === $target ) {
			return null;
		}
		return resolve_const( $target, $m[2], $state, $seen );
	}
	return null;
}

/**
 * Resolve a class short name to an FQCN using namespace and imports.
 *
 * @param string $name  Class name as written.
 * @param string $class Current class FQCN.
 * @param array  $state Collector state.
 * @return string|null
 */
function resolve_class_name( string $name, string $class, array &$state ): ?string {
	$name   = ltrim( $name, '\\' );
	$ctx    = $state['classes'][ $class ] ?? null;
	$ns     = $ctx['namespace'] ?? '';
	$import = $ctx['imports'] ?? array();

	if ( str_contains( $name, '\\' ) ) {
		$head = strtolower( strtok( $name, '\\' ) );
		if ( isset( $import[ $head ] ) ) {
			$tail = substr( $name, strlen( $head ) );
			return $import[ $head ] . $tail;
		}
		return ( '' !== $ns ? $ns . '\\' : '' ) . $name;
	}
	$key = strtolower( $name );
	if ( isset( $import[ $key ] ) ) {
		return $import[ $key ];
	}
	return ( '' !== $ns ? $ns . '\\' : '' ) . $name;
}

/**
 * Resolve one class constant, following inherited self references.
 *
 * @param string $class Class FQCN.
 * @param string $name  Constant name.
 * @param array  $state Collector state.
 * @param array  $seen  Recursion guard.
 * @return string|null
 */
function resolve_const( string $class, string $name, array &$state, array $seen = array() ): ?string {
	$key = $class . '::' . $name;
	if ( isset( $seen[ $key ] ) ) {
		return null;
	}
	if ( ! isset( $state['consts'][ $class ][ $name ] ) ) {
		return null;
	}
	$seen[ $key ] = true;
	return resolve_expr( $state['consts'][ $class ][ $name ], $class, $state, $seen );
}

$state = array(
	'classes' => array(),
	'consts'  => array(),
);

// Pass 1: namespaces, imports, classes, and class constants.
foreach ( $files as list( $rel, $path ) ) {
	$tokens = token_get_all( file_get_contents( $path ) );
	$n      = count( $tokens );
	$ns     = '';
	$imports = array();
	$class   = null;
	$depth   = 0;
	$class_depth = -1;
	$func    = null;
	$func_depth = -1;
	$interp  = 0;

	for ( $i = 0; $i < $n; $i++ ) {
		$tok = $tokens[ $i ];
		if ( ! is_array( $tok ) ) {
			if ( '}' === $tok && $interp > 0 ) {
				$interp--;
				continue;
			}
			if ( '{' === $tok ) {
				$depth++;
				if ( null !== $func && $func_depth < 0 ) {
					$func_depth = $depth;
				}
			} elseif ( '}' === $tok ) {
				$depth--;
				if ( null !== $func && $func_depth >= 0 && $depth < $func_depth ) {
					$func        = null;
					$func_depth  = -1;
				}
				if ( null !== $class && $depth < $class_depth ) {
					$class       = null;
					$class_depth = -1;
				}
			}
			continue;
		}

		if ( T_CURLY_OPEN === $tok[0] || T_DOLLAR_OPEN_CURLY_BRACES === $tok[0] ) {
			$interp++;
			continue;
		}

		if ( T_NAMESPACE === $tok[0] ) {
			$j   = $i + 1;
			$buf = '';
			while ( $j < $n ) {
				$x = $tokens[ $j ];
				if ( ';' === $x || '{' === $x ) {
					break;
				}
				$buf .= is_array( $x ) ? $x[1] : $x;
				$j++;
			}
			$ns      = trim( $buf, " \t\n\r\0\x0B\\" );
			$imports = array();
			continue;
		}

		if ( T_USE === $tok[0] && null === $class && null === $func ) {
			$j   = $i + 1;
			$buf = '';
			while ( $j < $n ) {
				$x = $tokens[ $j ];
				if ( ';' === $x ) {
					break;
				}
				$buf .= is_array( $x ) ? $x[1] : $x;
				$j++;
			}
			$buf = trim( $buf );
			if ( ! preg_match( '/^(function|const)\b/i', $buf ) ) {
				foreach ( explode( ',', $buf ) as $item ) {
					$item = trim( $item );
					if ( '' === $item || str_contains( $item, '{' ) ) {
						continue;
					}
					if ( ! preg_match( '/^\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$/i', $item, $m ) ) {
						continue;
					}
					$fqcn = ltrim( $m[1], '\\' );
					$alias = $m[2] ?? '';
					if ( '' === $alias ) {
						$pos   = strrpos( $fqcn, '\\' );
						$alias = false === $pos ? $fqcn : substr( $fqcn, $pos + 1 );
					}
					$imports[ strtolower( $alias ) ] = $fqcn;
				}
			}
			continue;
		}

		if ( in_array( $tok[0], array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
			$j = $i - 1;
			while ( $j >= 0 && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$j--;
			}
			if ( $j >= 0 && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_NEW, T_DOUBLE_COLON ), true ) ) {
				continue;
			}
			$j = $i + 1;
			while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}
			if ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
				$class       = ( '' !== $ns ? $ns . '\\' : '' ) . $tokens[ $j ][1];
				$class_depth = $depth + 1;
				$state['classes'][ $class ] = array(
					'namespace' => $ns,
					'imports'   => $imports,
					'file'      => $rel,
				);
			}
			continue;
		}

		if ( null !== $class && T_CONST === $tok[0] ) {
			$j = $i + 1;
			while ( $j < $n ) {
				$eq = -1;
				$name_tok = null;
				$paren = 0;
				for ( ; $j < $n; $j++ ) {
					$x = $tokens[ $j ];
					if ( is_array( $x ) && in_array( $x[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
						continue;
					}
					if ( '(' === $x || '[' === $x ) {
						$paren++;
						continue;
					}
					if ( ')' === $x || ']' === $x ) {
						$paren--;
						continue;
					}
					if ( 0 === $paren && '=' === $x ) {
						$eq = $j;
						break;
					}
				}
				if ( $eq < 0 ) {
					break;
				}
				$k = $eq - 1;
				while ( $k >= 0 && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					$k--;
				}
				if ( $k >= 0 && is_array( $tokens[ $k ] ) && T_STRING === $tokens[ $k ][0] ) {
					$name_tok = $tokens[ $k ][1];
				}
				if ( null === $name_tok ) {
					break;
				}
				$start = $eq + 1;
				$j     = $start;
				$d2    = 0;
				for ( ; $j < $n; $j++ ) {
					$x = $tokens[ $j ];
					if ( '(' === $x || '[' === $x || '{' === $x ) {
						$d2++;
					} elseif ( ')' === $x || ']' === $x || '}' === $x ) {
						$d2--;
					} elseif ( 0 === $d2 && ( ';' === $x || ',' === $x ) ) {
						break;
					}
				}
				$state['consts'][ $class ][ $name_tok ] = trim( token_text( $tokens, $start, $j ) );
				while ( $j < $n && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					$j++;
				}
				if ( $j < $n && ',' === $tokens[ $j ] ) {
					$j++;
					continue;
				}
				break;
			}
			continue;
		}

		if ( T_FUNCTION === $tok[0] ) {
			$j = $i + 1;
			while ( $j < $n && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
				$j++;
			}
			if ( isset( $tokens[ $j ] ) && '&' === $tokens[ $j ] ) {
				$j++;
				while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					$j++;
				}
			}
			if ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
				$func       = $tokens[ $j ][1];
				$func_depth = -1;
			}
			continue;
		}

		if ( '{' === $tok ) {
			$depth++;
		} elseif ( '}' === $tok ) {
			$depth--;
			if ( null !== $class && $depth < $class_depth ) {
				$class       = null;
				$class_depth = -1;
			}
			if ( null !== $func && $func_depth >= 0 && $depth < $func_depth ) {
				$func       = null;
				$func_depth = -1;
			}
		}
	}
}

// Pass 2: named add_action()/add_filter() calls.
$out        = array();
$total      = 0;
$unresolved = array();
foreach ( $files as list( $rel, $path ) ) {
	$tokens = token_get_all( file_get_contents( $path ) );
	$n      = count( $tokens );
	$ns     = '';
	$class  = null;
	$depth  = 0;
	$class_depth = -1;
	$func   = null;
	$func_depth  = -1;
	$interp = 0;

	for ( $i = 0; $i < $n; $i++ ) {
		$tok = $tokens[ $i ];
		if ( ! is_array( $tok ) ) {
			if ( '}' === $tok && $interp > 0 ) {
				$interp--;
				continue;
			}
			if ( '{' === $tok ) {
				$depth++;
				if ( null !== $func && $func_depth < 0 ) {
					$func_depth = $depth;
				}
			} elseif ( '}' === $tok ) {
				$depth--;
				if ( null !== $func && $func_depth >= 0 && $depth < $func_depth ) {
					$func       = null;
					$func_depth = -1;
				}
				if ( null !== $class && $depth < $class_depth ) {
					$class       = null;
					$class_depth = -1;
				}
			}
			continue;
		}

		if ( T_CURLY_OPEN === $tok[0] || T_DOLLAR_OPEN_CURLY_BRACES === $tok[0] ) {
			$interp++;
			continue;
		}

		if ( T_NAMESPACE === $tok[0] ) {
			$j   = $i + 1;
			$buf = '';
			while ( $j < $n ) {
				$x = $tokens[ $j ];
				if ( ';' === $x || '{' === $x ) {
					break;
				}
				$buf .= is_array( $x ) ? $x[1] : $x;
				$j++;
			}
			$ns = trim( $buf, " \t\n\r\0\x0B\\" );
			continue;
		}

		if ( in_array( $tok[0], array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
			$j = $i - 1;
			while ( $j >= 0 && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$j--;
			}
			if ( $j >= 0 && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_NEW, T_DOUBLE_COLON ), true ) ) {
				continue;
			}
			$j = $i + 1;
			while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}
			if ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
				$class       = ( '' !== $ns ? $ns . '\\' : '' ) . $tokens[ $j ][1];
				$class_depth = $depth + 1;
			}
			continue;
		}

		if ( T_FUNCTION === $tok[0] ) {
			$j = $i + 1;
			while ( $j < $n && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
				$j++;
			}
			if ( isset( $tokens[ $j ] ) && '&' === $tokens[ $j ] ) {
				$j++;
				while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					$j++;
				}
			}
			if ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
				$func       = $tokens[ $j ][1];
				$func_depth = -1;
			}
			continue;
		}

		if ( T_STRING !== $tok[0] || ! in_array( strtolower( $tok[1] ), array( 'add_action', 'add_filter' ), true ) ) {
			continue;
		}

		$j = $i - 1;
		while ( $j >= 0 && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$j--;
		}
		if ( $j >= 0 && is_array( $tokens[ $j ] ) && T_FUNCTION === $tokens[ $j ][0] ) {
			continue;
		}

		$k = $i + 1;
		while ( $k < $n && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
			$k++;
		}
		if ( ! isset( $tokens[ $k ] ) || '(' !== $tokens[ $k ] ) {
			continue;
		}

		$total++;
		$args   = array( array() );
		$arg_i  = 0;
		$paren  = 0;
		for ( ; $k < $n; $k++ ) {
			$x = $tokens[ $k ];
			if ( '(' === $x || '[' === $x || '{' === $x ) {
				$paren++;
				if ( 1 === $paren ) {
					continue;
				}
			} elseif ( ')' === $x || ']' === $x || '}' === $x ) {
				$paren--;
				if ( 0 === $paren ) {
					break;
				}
			} elseif ( ',' === $x && 1 === $paren ) {
				$args[] = array();
				$arg_i++;
				continue;
			}
			$args[ $arg_i ][] = $x;
		}

		$expr = trim( token_text( $args[0], 0, count( $args[0] ) ) );
		$hook = resolve_expr( $expr, $class ?? '', $state );
		if ( null === $hook || '' === $hook ) {
			$unresolved[] = array(
				'file'  => $rel,
				'line'  => $tok[2],
				'expr'  => $expr,
				'class' => $class,
				'method' => $func,
			);
			continue;
		}

		$cb_expr = isset( $args[1] ) ? trim( token_text( $args[1], 0, count( $args[1] ) ) ) : '';
		$callback = $cb_expr;
		if ( preg_match( '/array\s*\(\s*(?:\$this|self::class|[A-Za-z_][A-Za-z0-9_\\\\]*::class)\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $cb_expr, $m ) ) {
			$callback = $m[1];
		} elseif ( preg_match( '/\[\s*(?:\$this|self::class|[A-Za-z_][A-Za-z0-9_\\\\]*::class)\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]/', $cb_expr, $m ) ) {
			$callback = $m[1];
		} elseif ( null !== unquote_php( $cb_expr ) ) {
			$callback = (string) unquote_php( $cb_expr );
		}

		$out[] = array(
			'hook'         => $hook,
			'kind'         => strtolower( $tok[1] ),
			'file'         => $rel,
			'line'         => $tok[2],
			'owner'        => $class,
			'method'       => $func,
			'callback'     => $callback,
			'callbackExpr' => $cb_expr,
			'expr'         => $expr,
		);
	}
}

echo json_encode(
	array(
		'root'        => $root,
		'total_calls' => $total,
		'resolved'    => count( $out ),
		'unresolved'  => $unresolved,
		'hooks'       => $out,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
