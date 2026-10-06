<?php

	check_login();

	/**
	 * TEITOK sub-action via global $act query param: edit | logs | kontext | (empty = backend checks / flexicorp env).
	 * @var string $screenAct
	 */
	$screenAct = isset( $_REQUEST['act'] ) ? trim( (string) $_REQUEST['act'] ) : '';
	/** TEITOK module name for this script (default). Override by defining $fqs_backends_action before include. */
	$fqsbBackendsAction = isset( $fqs_backends_action ) ? trim( (string) $fqs_backends_action ) : 'fqs-backends';
	if ( $fqsbBackendsAction === '' ) {
		$fqsbBackendsAction = 'fqs-backends';
	}

	$maintext .= ( $screenAct === 'kontext' )
		? '<h1>KonText server</h1>'
		: '<h1>Backend environments</h1>';

	if ( !function_exists('tt_fqsb_module_url') ) {
		/**
		 * Build index.php?… query URLs with plain "&" separators; use htmlspecialchars() once when embedding in HTML.
		 */
		function tt_fqsb_module_url( $moduleAction, $query = array() ) {
			$moduleAction = trim( (string) $moduleAction );
			$q = array_merge( array( 'action' => $moduleAction ), is_array( $query ) ? $query : array() );
			$q = array_filter(
				$q,
				function ( $v ) {
					return $v !== null && $v !== '';
				}
			);
			return 'index.php?' . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 );
		}
	}

	if ( !function_exists('tt_fqsb_flexicorp_cmd') ) {
		function tt_fqsb_flexicorp_cmd() {
			$bin = '';
			if ( function_exists('findapp') ) {
				$bin = trim((string) findapp('flexicorp'));
			}
			if ( $bin === '' ) {
				$probe = trim((string) shell_exec('command -v flexicorp 2>/dev/null'));
				if ( $probe !== '' ) {
					$bin = $probe;
				}
			}
			if ( $bin !== '' ) {
				return escapeshellarg($bin);
			}

			$candidates = array(
				'/var/www/html/teitok/shared/Resources/venv/bin/python',
				'/var/www/html/teitok/shared/Resources/venv/bin/python3',
				'/var/www/html/teitok/Resources/venv/bin/python',
				'/var/www/html/teitok/Resources/venv/bin/python3',
				'/opt/venv/bin/python',
				'/opt/venv/bin/python3',
			);
			foreach ( $candidates as $py ) {
				if ( !is_file($py) || !is_executable($py) ) continue;
				$probe = shell_exec(escapeshellarg($py) . " -c 'import flexicorp' 2>/dev/null && echo OK");
				if ( trim((string) $probe) === 'OK' ) {
					return escapeshellarg($py) . " -m flexicorp";
				}
			}
			foreach ( array('python3', 'python') as $pycmd ) {
				$path = trim((string) shell_exec("command -v " . escapeshellarg($pycmd) . " 2>/dev/null"));
				if ( $path === '' ) continue;
				$probe = shell_exec(escapeshellarg($path) . " -c 'import flexicorp' 2>/dev/null && echo OK");
				if ( trim((string) $probe) === 'OK' ) {
					return escapeshellarg($path) . " -m flexicorp";
				}
			}
			return '';
		}
	}

	if ( !function_exists('tt_fqsb_fqs_bin') ) {
		function tt_fqsb_fqs_bin() {
			$bin = '';
			if ( function_exists('findapp') ) {
				$bin = trim((string) findapp('fqs'));
			}
			if ( $bin === '' ) {
				$probe = trim((string) shell_exec('command -v fqs 2>/dev/null'));
				if ( $probe !== '' ) {
					$bin = $probe;
				}
			}
			return $bin;
		}
	}

	if ( !function_exists('tt_fqsb_decode_corpora_list') ) {
		function tt_fqsb_decode_corpora_list( $raw ) {
			$dec = is_string($raw) ? json_decode($raw, true) : null;
			if ( !is_array($dec) ) return array();
			if ( isset($dec['corpora']) && is_array($dec['corpora']) ) return $dec['corpora'];
			if ( array_keys($dec) === range(0, count($dec) - 1) ) return $dec;
			return array();
		}
	}

	if ( !function_exists('tt_fqsb_decode_json_any') ) {
		function tt_fqsb_decode_json_any( $raw ) {
			if ( !is_string($raw) ) return null;
			$dec = json_decode($raw, true);
			if ( is_array($dec) ) return $dec;
			$lines = preg_split('/\r\n|\r|\n/', $raw);
			if ( !is_array($lines) ) return null;
			for ( $i = count($lines) - 1; $i >= 0; $i-- ) {
				$line = trim((string)$lines[$i]);
				if ( $line === '' ) continue;
				if ( $line[0] !== '{' && $line[0] !== '[' ) continue;
				$try = json_decode($line, true);
				if ( is_array($try) ) return $try;
			}
			return null;
		}
	}

	if ( !function_exists('tt_fqsb_fqs_db_path_from_runtime_file') ) {
		function tt_fqsb_fqs_db_path_from_runtime_file( $runtimeDir = '' ) {
			$candidates = array();
			$ehj = trim((string) getenv('FQS_HTTP_JSON'));
			if ( $ehj !== '' ) $candidates[] = $ehj;
			$dbp = trim((string) getenv('FQS_DB_PATH'));
			if ( $dbp !== '' ) {
				$candidates[] = rtrim(str_replace('\\', '/', dirname($dbp)), '/') . '/fqs-http.json';
			}
			if ( trim((string)$runtimeDir) !== '' ) {
				$candidates[] = rtrim(str_replace('\\', '/', (string)$runtimeDir), '/') . '/fqs-http.json';
			}
			$candidates[] = '/usr/local/var/fqs/fqs-http.json';
			$candidates[] = '/var/lib/fqs/fqs-http.json';
			$seen = array();
			foreach ( $candidates as $p ) {
				$p = trim((string)$p);
				if ( $p === '' || isset($seen[$p]) ) continue;
				$seen[$p] = true;
				if ( !is_file($p) ) continue;
				$raw = @file_get_contents($p);
				if ( !is_string($raw) || trim($raw) === '' ) continue;
				$dec = json_decode($raw, true);
				if ( !is_array($dec) ) continue;
				$runtimeDb = trim((string)($dec['db_path'] ?? ''));
				if ( $runtimeDb !== '' ) return $runtimeDb;
			}
			return '';
		}
	}

	if ( !function_exists('tt_fqsb_fqs_get_corpus_row') ) {
		function tt_fqsb_fqs_get_corpus_row( $fqsbin, $selectedCorpus, $targetRoot, $dbPath = '' ) {
			$fqsbin = trim((string)$fqsbin);
			if ( $fqsbin === '' ) return array();
			$dbPath = trim((string)$dbPath);
			if ( $dbPath === '' && function_exists('tt_fqsb_fqs_db_path_from_runtime_file') ) {
				$dbPath = tt_fqsb_fqs_db_path_from_runtime_file();
			}
			$dbArg = $dbPath !== '' ? (' --db ' . escapeshellarg($dbPath)) : '';
			$selectedCorpus = trim((string)$selectedCorpus);
			if ( $selectedCorpus !== '' ) {
				$showRaw = (string) shell_exec(escapeshellarg($fqsbin) . ' corpora show' . $dbArg . ' --id ' . escapeshellarg($selectedCorpus) . ' 2>&1');
				$showDec = tt_fqsb_decode_json_any($showRaw);
				if ( is_array($showDec) && !empty($showDec['id']) ) return $showDec;
			}
			$listRaw = (string) shell_exec(escapeshellarg($fqsbin) . ' corpora list' . $dbArg . ' 2>&1');
			$list = tt_fqsb_decode_corpora_list($listRaw);
			$rootNorm = rtrim(str_replace('\\', '/', (string)$targetRoot), '/');
			foreach ( $list as $row ) {
				if ( !is_array($row) ) continue;
				$pr = rtrim(str_replace('\\', '/', (string)($row['project_root'] ?? '')), '/');
				if ( $pr !== '' && $pr === $rootNorm ) return $row;
			}
			return array();
		}
	}

	if ( !function_exists('tt_fqsb_kontext_detect_corpus_name') ) {
		function tt_fqsb_kontext_detect_corpus_name( $targetRoot, $selectedCorpus, $fqsbin ) {
			$selectedCorpus = trim((string)$selectedCorpus);
			$row = tt_fqsb_fqs_get_corpus_row($fqsbin, $selectedCorpus, $targetRoot);
			if ( is_array($row) ) {
				$settings = isset($row['settings']) && is_array($row['settings']) ? $row['settings'] : array();
				$kontext = isset($settings['kontext']) && is_array($settings['kontext']) ? $settings['kontext'] : array();
				$cid = trim((string)($kontext['corpus_id'] ?? ''));
				if ( $cid !== '' ) return $cid;
				$cname = trim((string)($settings['corpus_name'] ?? ''));
				if ( $cname !== '' ) return $cname;
			}
			$settingsXml = rtrim((string)$targetRoot, '/\\') . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'settings.xml';
			if ( is_file($settingsXml) && function_exists('simplexml_load_file') ) {
				$xml = @simplexml_load_file($settingsXml);
				if ( $xml !== false && isset($xml->cqp) ) {
					$attrs = $xml->cqp->attributes();
					$c = trim((string)($attrs['corpus'] ?? ''));
					if ( $c !== '' ) return strtolower($c);
				}
			}
			if ( $selectedCorpus !== '' ) return $selectedCorpus;
			return basename(rtrim(str_replace('\\', '/', (string)$targetRoot), '/'));
		}
	}

	if ( !function_exists('tt_fqsb_kontext_conf_path') ) {
		function tt_fqsb_kontext_conf_path( $cfg ) {
			$candidates = array(
				trim((string)tt_fqsb_cfg_get($cfg, array('kontext', 'config_xml'), '')),
				trim((string)getenv('KONTEXT_CONF')),
				'/opt/kontext/conf/config.xml',
				'/etc/kontext/config.xml',
			);
			foreach ( $candidates as $cand ) {
				$cand = trim((string)$cand);
				if ( $cand !== '' && is_file($cand) ) return $cand;
			}
			return '';
		}
	}

	if ( !function_exists('tt_fqsb_kontext_corpus_meta') ) {
		function tt_fqsb_kontext_corpus_meta( $cfg, $corpusId ) {
			$cid = trim((string)$corpusId);
			$out = array(
				'id' => $cid,
				'name' => '',
				'found' => false,
			);
			if ( $cid === '' || !function_exists('simplexml_load_file') ) {
				return $out;
			}

			$kontextConf = tt_fqsb_kontext_conf_path($cfg);
			if ( $kontextConf === '' || !is_file($kontextConf) ) {
				return $out;
			}
			$xml = @simplexml_load_file($kontextConf);
			if ( $xml === false ) {
				return $out;
			}
			$corplistNode = $xml->xpath('.//plugins/default_corparch/corplist');
			if ( !is_array($corplistNode) || empty($corplistNode) ) {
				$corplistNode = @$xml->xpath(
					'//*[local-name()="corplist"][ancestor::*[local-name()="default_corparch"]]'
				);
			}
			$corplistPath = '';
			if ( is_array($corplistNode) && !empty($corplistNode) ) {
				$corplistPath = trim((string)$corplistNode[0]);
			}
			if ( $corplistPath === '' ) {
				$corplistPath = dirname($kontextConf) . DIRECTORY_SEPARATOR . 'corplist.xml';
			}
			if ( !preg_match('#^/#', $corplistPath) ) {
				$corplistPath = dirname($kontextConf) . DIRECTORY_SEPARATOR . $corplistPath;
			}
			if ( !is_file($corplistPath) ) {
				return $out;
			}
			$cpRoot = @simplexml_load_file($corplistPath);
			if ( $cpRoot === false || !($cpRoot instanceof SimpleXMLElement) ) {
				return $out;
			}
			$corplist = $cpRoot->corplist;
			if ( !($corplist instanceof SimpleXMLElement) ) {
				return $out;
			}
			foreach ( $corplist->corpus as $node ) {
				if ( trim((string)$node['ident']) !== $cid ) {
					continue;
				}
				$out['found'] = true;
				$name = '';
				foreach ( array('name', 'label', 'title') as $attr ) {
					$v = trim((string)$node[$attr]);
					if ( $v !== '' ) {
						$name = $v;
						break;
					}
				}
				if ( $name === '' ) {
					foreach ( array('name', 'label', 'title') as $child ) {
						if ( isset($node->{$child}) ) {
							$v = trim((string)$node->{$child});
							if ( $v !== '' ) {
								$name = $v;
								break;
							}
						}
					}
				}
				$out['name'] = $name;
				break;
			}
			return $out;
		}
	}

	if ( !function_exists( 'tt_fqsb_kontext_xml_manatee_registry_dir' ) ) {
		/**
		 * Read manatee_registry directory from KonText config.xml.
		 * Matches flexicorp/env_adapters + backends/manatee_backend.py (default_corparch first, then legacy corpora/).
		 * Namespaced config.xml files often need the local-name() fallback.
		 */
		function tt_fqsb_kontext_xml_manatee_registry_dir( SimpleXMLElement $xml ) {
			$xpaths = array(
				'.//plugins/default_corparch/manatee_registry',
				'.//corpora/manatee_registry',
			);
			foreach ( $xpaths as $xp ) {
				$nodes = @$xml->xpath( $xp );
				if ( is_array( $nodes ) && ! empty( $nodes ) ) {
					$t = trim( (string) $nodes[0] );
					if ( $t !== '' ) {
						return $t;
					}
				}
			}
			$nodes = @$xml->xpath(
				'//*[local-name()="manatee_registry"][ancestor::*[local-name()="default_corparch"]]'
			);
			if ( is_array( $nodes ) && ! empty( $nodes ) ) {
				$t = trim( (string) $nodes[0] );
				if ( $t !== '' ) {
					return $t;
				}
			}
			$nodes = @$xml->xpath(
				'//*[local-name()="manatee_registry"][ancestor::*[local-name()="corpora"]]'
			);
			if ( is_array( $nodes ) && ! empty( $nodes ) ) {
				$t = trim( (string) $nodes[0] );
				if ( $t !== '' ) {
					return $t;
				}
			}
			return '';
		}
	}

	if ( !function_exists('tt_fqsb_kontext_registry_docstructure') ) {
		function tt_fqsb_kontext_registry_docstructure( $targetRoot, $corpusName ) {
			$path = rtrim( (string) $targetRoot, '/\\' ) . DIRECTORY_SEPARATOR . 'manatee' . DIRECTORY_SEPARATOR . trim( (string) $corpusName );
			if ( ! is_file( $path ) ) {
				return '';
			}
			$lines = @file( $path, FILE_IGNORE_NEW_LINES );
			if ( ! is_array( $lines ) ) {
				return '';
			}
			foreach ( $lines as $ln ) {
				$ln = trim( (string) $ln );
				if ( preg_match( '/^DOCSTRUCTURE\s+(\S+)/i', $ln, $m ) ) {
					return trim( $m[1] );
				}
			}
			return '';
		}
	}

	if ( ! function_exists( 'tt_fqsb_kontext_registry_sentence_struct_guess' ) ) {
		/**
		 * KonText corplist sentence_struct must name a *sentence* region in STRUCTLIST (usually "s"), not DOCSTRUCTURE (often "text").
		 */
		function tt_fqsb_kontext_registry_sentence_struct_guess( $targetRoot, $corpusName ) {
			$path = rtrim( (string) $targetRoot, '/\\' ) . DIRECTORY_SEPARATOR . 'manatee' . DIRECTORY_SEPARATOR . trim( (string) $corpusName );
			if ( ! is_file( $path ) ) {
				return 's';
			}
			$lines = @file( $path, FILE_IGNORE_NEW_LINES );
			if ( ! is_array( $lines ) ) {
				return 's';
			}
			$structs = array();
			foreach ( $lines as $ln ) {
				$ln = trim( (string) $ln );
				if ( preg_match( '/^STRUCTLIST\s+(.+)/i', $ln, $m ) ) {
					foreach ( preg_split( '/\s+/', trim( $m[1] ) ) as $tok ) {
						$tok = trim( $tok );
						if ( $tok !== '' ) {
							$structs[] = $tok;
						}
					}
					break;
				}
			}
			foreach ( array( 's', 'seg' ) as $cand ) {
				if ( in_array( $cand, $structs, true ) ) {
					return $cand;
				}
			}
			return 's';
		}
	}

	if ( ! function_exists( 'tt_fqsb_kontext_redis_parse_corpora_list' ) ) {
		function tt_fqsb_kontext_redis_parse_corpora_list( $raw ) {
			$list = array();
			$raw = trim( (string) $raw );
			if ( $raw === '' ) {
				return $list;
			}
			$dec = json_decode( $raw, true );
			if ( is_array( $dec ) ) {
				foreach ( $dec as $v ) {
					$t = trim( (string) $v );
					if ( $t !== '' && ! in_array( $t, $list, true ) ) {
						$list[] = $t;
					}
				}
			}
			return $list;
		}
	}

	if ( ! function_exists( 'tt_fqsb_kontext_redis_merge_anon_corpora' ) ) {
		/**
		 * Update KonText default_auth Redis key user:<anon>:corpora (JSON list of ids). Tries redis-cli, php redis ext, then python3 redis.
		 *
		 * @return array{ok:bool,steps:array<int,string>,via?:string,error?:string}
		 */
		function tt_fqsb_kontext_redis_merge_anon_corpora(
			$redisHost,
			$redisPort,
			$redisDb,
			$anonId,
			$corpusName,
			$anonVis
		) {
			$out = array(
				'ok'    => false,
				'steps' => array(),
			);
			$key   = 'user:' . $anonId . ':corpora';
			$pjson = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

			$merge = function ( array $list ) use ( $corpusName, $anonVis ) {
				if ( $anonVis ) {
					if ( ! in_array( $corpusName, $list, true ) ) {
						$list[] = $corpusName;
					}
				} else {
					$filtered = array();
					foreach ( $list as $x ) {
						if ( strcasecmp( (string) $x, $corpusName ) !== 0 ) {
							$filtered[] = $x;
						}
					}
					$list = $filtered;
				}
				return $list;
			};

			$redisCli = trim( (string) shell_exec( 'command -v redis-cli 2>/dev/null' ) );
			if ( $redisCli !== '' ) {
				$getCmd = escapeshellarg( $redisCli ) . ' -h ' . escapeshellarg( $redisHost )
					. ' -p ' . intval( $redisPort ) . ' -n ' . intval( $redisDb )
					. ' GET ' . escapeshellarg( $key ) . ' 2>/dev/null';
				$raw    = trim( (string) shell_exec( $getCmd ) );
				$list   = $merge( tt_fqsb_kontext_redis_parse_corpora_list( $raw ) );
				$setCmd = escapeshellarg( $redisCli ) . ' -h ' . escapeshellarg( $redisHost )
					. ' -p ' . intval( $redisPort ) . ' -n ' . intval( $redisDb )
					. ' SET ' . escapeshellarg( $key ) . ' ' . escapeshellarg( json_encode( $list, $pjson ) ) . ' 2>/dev/null';
				@shell_exec( $setCmd );
				$out['ok']    = true;
				$out['via']   = 'redis-cli';
				$out['steps'][] = $anonVis
					? ( 'anonymous ACL: added ' . $corpusName . ' to ' . $key . ' (redis-cli)' )
					: ( 'anonymous ACL: removed ' . $corpusName . ' from ' . $key . ' (redis-cli)' );
				return $out;
			}

			if ( class_exists( 'Redis', false ) ) {
				try {
					$r = new Redis();
					if ( @$r->connect( $redisHost, intval( $redisPort ), 2.0 ) ) {
						$r->select( intval( $redisDb ) );
						$raw  = $r->get( $key );
						$raws = ( $raw === false ? '' : (string) $raw );
						$list = $merge( tt_fqsb_kontext_redis_parse_corpora_list( $raws ) );
						$r->set( $key, json_encode( $list, $pjson ) );
						$out['ok']      = true;
						$out['via']     = 'php-redis';
						$out['steps'][] = $anonVis
							? ( 'anonymous ACL: added ' . $corpusName . ' to ' . $key . ' (php-redis)' )
							: ( 'anonymous ACL: removed ' . $corpusName . ' from ' . $key . ' (php-redis)' );
						return $out;
					}
				} catch ( Throwable $e ) {
					$out['steps'][] = 'php-redis: ' . $e->getMessage();
				}
			}

			$pyCandidates = array();
			$envPy = trim( (string) getenv( 'FQSB_KONTEXT_REDIS_PYTHON' ) );
			if ( $envPy !== '' && is_file( $envPy ) && is_executable( $envPy ) ) {
				$pyCandidates[] = $envPy;
			}
			$p3 = trim( (string) shell_exec( 'command -v python3 2>/dev/null' ) );
			if ( $p3 !== '' ) {
				$pyCandidates[] = $p3;
			}
			foreach ( array(
				'/opt/flexicorp/Resources/venv/bin/python3',
				'/opt/flexicorp/Resources/venv/bin/python',
				'/var/www/html/teitok/shared/Resources/venv/bin/python3',
				'/var/www/html/teitok/shared/Resources/venv/bin/python',
				'/var/www/html/teitok/Resources/venv/bin/python3',
				'/var/www/html/teitok/Resources/venv/bin/python',
			) as $cand ) {
				if ( is_file( $cand ) && is_executable( $cand ) ) {
					$pyCandidates[] = $cand;
				}
			}
			$pyCandidates = array_values( array_unique( $pyCandidates ) );

			$payload = array(
				'h' => $redisHost,
				'p' => intval( $redisPort ),
				'd' => intval( $redisDb ),
				'k' => $key,
				'c' => $corpusName,
				'a' => (bool) $anonVis,
			);
			$code    = 'import json,sys
try:
 import redis
except ImportError:
 sys.exit(3)
P=json.loads(sys.stdin.read())
r=redis.Redis(host=P["h"],port=P["p"],db=P["d"],decode_responses=True)
k=P["k"]
raw=r.get(k) or ""
L=[]
if raw:
 try:
  L=list(json.loads(raw))
  if not isinstance(L,list): L=[]
 except Exception:
  L=[]
cn=P["c"]
if P["a"]:
 if cn not in L: L.append(cn)
else:
 L=[x for x in L if str(x).lower()!=cn.lower()]
r.set(k,json.dumps(L))
';
			$pyLastErr = '';
			foreach ( $pyCandidates as $py ) {
				$proc = proc_open(
					escapeshellarg( $py ) . ' -c ' . escapeshellarg( $code ),
					array(
						0 => array( 'pipe', 'r' ),
						1 => array( 'pipe', 'w' ),
						2 => array( 'pipe', 'w' ),
					),
					$pipes
				);
				if ( ! is_resource( $proc ) ) {
					continue;
				}
				fwrite( $pipes[0], json_encode( $payload, $pjson ) );
				fclose( $pipes[0] );
				$stderr = (string) stream_get_contents( $pipes[2] );
				fclose( $pipes[2] );
				fclose( $pipes[1] );
				$exit = proc_close( $proc );
				if ( $exit === 0 ) {
					$label = basename( $py );
					$out['ok']        = true;
					$out['via']       = 'python-redis';
					$out['steps'][]   = $anonVis
						? ( 'anonymous ACL: added ' . $corpusName . ' to ' . $key . ' (' . $label . ' + redis)' )
						: ( 'anonymous ACL: removed ' . $corpusName . ' from ' . $key . ' (' . $label . ' + redis)' );
					return $out;
				}
				if ( $exit === 3 ) {
					$out['steps'][] = 'skip ' . $py . ': no redis module (pip install redis in that env)';
					continue;
				}
				if ( $stderr !== '' ) {
					$pyLastErr = trim( $stderr );
				}
			}
			if ( $pyLastErr !== '' ) {
				$out['steps'][] = 'python redis (last error): ' . $pyLastErr;
			}

			$out['steps'][] = 'Could not update Redis ACL (install redis-cli, php-redis, or python3+redis on the **web server user** PATH / '
				. 'flexicorp venv / TEITOK venv; optional env FQSB_KONTEXT_REDIS_PYTHON=/path/to/python; ensure reachability to '
				. $redisHost . ':' . intval( $redisPort ) . ' db ' . intval( $redisDb ) . ')';
			return $out;
		}
	}

	if ( !function_exists('tt_fqsb_kontext_apply_to_server' ) ) {
		/**
		 * Push TEITOK Manatee registry + corplist entry (+ optional Redis ACL) to the KonText server
		 * described by kontext.config_xml / flexicorp env-config.
		 *
		 * $opts keys:
		 * - anonymous_visible (bool)  add/remove corpus id in user:<anon>:corpora when redis_db is used
		 * - sentence_struct (string)  default_corparch "sentence_struct"; falls back to STRUCTLIST (prefer s/seg) then "s"
		 * - requestable (string|null) "true", "false", or null to leave attribute unchanged
		 */
		function tt_fqsb_kontext_apply_to_server( $targetRoot, $cfg, $selectedCorpus, $fqsbin, $opts = array() ) {
			$out = array( 'ok' => false, 'steps' => array(), 'message' => '' );
			$opts = is_array( $opts ) ? $opts : array();
			$anonVis = array_key_exists( 'anonymous_visible', $opts ) ? ! empty( $opts['anonymous_visible'] ) : true;
			$sentIn = isset( $opts['sentence_struct'] ) ? trim( (string) $opts['sentence_struct'] ) : '';
			$req = array_key_exists( 'requestable', $opts ) ? $opts['requestable'] : null;

			$targetRoot = rtrim( (string) $targetRoot, '/\\' );
			if ( $targetRoot === '' || ! is_dir( $targetRoot ) ) {
				$out['message'] = 'Invalid target root.';
				return $out;
			}
			$corpusName = trim( (string) tt_fqsb_kontext_detect_corpus_name( $targetRoot, $selectedCorpus, $fqsbin ) );
			if ( $corpusName === '' ) {
				$out['message'] = 'Could not determine KonText corpus id.';
				return $out;
			}
			$sentenceStruct = $sentIn;
			if ( $sentenceStruct === '' ) {
				$sentenceStruct = tt_fqsb_kontext_registry_sentence_struct_guess( $targetRoot, $corpusName );
			}

			$out['steps'][] = 'target corpus id: ' . $corpusName;
			$kontextConf = tt_fqsb_kontext_conf_path( $cfg );
			if ( $kontextConf === '' ) {
				$out['message'] = 'KonText config.xml not found.';
				return $out;
			}
			$out['steps'][] = 'kontext config: ' . $kontextConf;
			if ( ! function_exists( 'simplexml_load_file' ) ) {
				$out['message'] = 'PHP SimpleXML extension is required.';
				return $out;
			}
			$xml = @simplexml_load_file( $kontextConf );
			if ( $xml === false ) {
				$out['message'] = 'Could not parse KonText config.xml.';
				return $out;
			}
			$registryDir = tt_fqsb_kontext_xml_manatee_registry_dir( $xml );
			$corplistNode = $xml->xpath( './/plugins/default_corparch/corplist' );
			if ( ! is_array( $corplistNode ) || empty( $corplistNode ) ) {
				$corplistNode = @$xml->xpath(
					'//*[local-name()="corplist"][ancestor::*[local-name()="default_corparch"]]'
				);
			}
			$corplistPath = '';
			if ( is_array( $corplistNode ) && ! empty( $corplistNode ) ) {
				$corplistPath = trim( (string) $corplistNode[0] );
			}
			if ( $registryDir === '' ) {
				$out['message'] = 'KonText manatee_registry missing in config.xml '
					. '(expected plugins/default_corparch/manatee_registry or corpora/manatee_registry, or namespaced equivalents).';
				return $out;
			}
			if ( $corplistPath === '' ) {
				$corplistPath = dirname( $kontextConf ) . DIRECTORY_SEPARATOR . 'corplist.xml';
			}
			if ( ! preg_match( '#^/#', $corplistPath ) ) {
				$corplistPath = dirname( $kontextConf ) . DIRECTORY_SEPARATOR . $corplistPath;
			}

			$sourceReg = $targetRoot . DIRECTORY_SEPARATOR . 'manatee' . DIRECTORY_SEPARATOR . $corpusName;
			$targetReg = rtrim( $registryDir, '/\\' ) . DIRECTORY_SEPARATOR . $corpusName;
			if ( ! is_file( $sourceReg ) ) {
				$out['message'] = 'Source Manatee registry file missing: ' . $sourceReg;
				return $out;
			}
			if ( ! is_dir( $registryDir ) ) {
				@mkdir( $registryDir, 0775, true );
			}
			if ( ! @copy( $sourceReg, $targetReg ) ) {
				$out['message'] = 'Could not copy registry file to KonText path.';
				return $out;
			}
			$out['steps'][] = 'copied registry: ' . $targetReg;

			$cpRoot = is_file( $corplistPath ) ? @simplexml_load_file( $corplistPath ) : false;
			if ( $cpRoot === false || ! ( $cpRoot instanceof SimpleXMLElement ) ) {
				$cpRoot = new SimpleXMLElement( '<kontext/>' );
			}
			$corplist = $cpRoot->corplist;
			if ( ! ( $corplist instanceof SimpleXMLElement ) ) {
				$corplist = $cpRoot->addChild( 'corplist' );
			}
			$corpNode = null;
			foreach ( $corplist->corpus as $node ) {
				if ( trim( (string) $node['ident'] ) === $corpusName ) {
					$corpNode = $node;
					break;
				}
			}
			if ( $corpNode === null ) {
				$corpNode = $corplist->addChild( 'corpus' );
				$corpNode->addAttribute( 'ident', $corpusName );
			}
			$corpNode['sentence_struct'] = $sentenceStruct;
			if ( is_string( $req ) && ( $req === 'true' || $req === 'false' ) ) {
				$corpNode['requestable'] = $req;
			}

			$okCp = @file_put_contents( $corplistPath, $cpRoot->asXML() );
			if ( $okCp === false ) {
				$out['message'] = 'Could not update corplist.xml: ' . $corplistPath;
				return $out;
			}
			$out['steps'][] = 'updated corplist: ' . $corplistPath . ' (sentence_struct=' . $sentenceStruct . ')';

			$dbNode       = $xml->xpath( './plugins/db' );
			if ( ! is_array( $dbNode ) || empty( $dbNode ) ) {
				$dbNode = @$xml->xpath( '//*[local-name()="db"][ancestor::*[local-name()="plugins"]]' );
			}
			$authAnonNode = $xml->xpath( './plugins/auth/anonymous_user_id' );
			if ( ! is_array( $authAnonNode ) || empty( $authAnonNode ) ) {
				$authAnonNode = @$xml->xpath(
					'//*[local-name()="anonymous_user_id"][ancestor::*[local-name()="auth"]][ancestor::*[local-name()="plugins"]]'
				);
			}
			$redisHost = '';
			$redisPort = '6379';
			$redisDb   = '1';
			$anonId    = '0';
			if ( is_array( $dbNode ) && ! empty( $dbNode ) ) {
				$db     = $dbNode[0];
				$module = trim( (string) $db->module );
				if ( $module === 'redis_db' ) {
					$redisHost = trim( (string) $db->host );
					$redisPort = trim( (string) $db->port ) !== '' ? trim( (string) $db->port ) : '6379';
					$redisDb   = trim( (string) $db->id ) !== '' ? trim( (string) $db->id ) : '1';
				}
			}
			if ( is_array( $authAnonNode ) && ! empty( $authAnonNode ) ) {
				$anonId = trim( (string) $authAnonNode[0] );
				if ( $anonId === '' ) {
					$anonId = '0';
				}
			}
			// KonText config.xml may use a Docker-only hostname; TEITOK PHP may need published host/port (optional env-config overrides).
			if ( $redisHost !== '' ) {
				$ovh = trim( (string) tt_fqsb_cfg_get( $cfg, array( 'kontext', 'redis_acl_host' ), '' ) );
				if ( $ovh !== '' ) {
					$redisHost = $ovh;
				}
				$ovp = trim( (string) tt_fqsb_cfg_get( $cfg, array( 'kontext', 'redis_acl_port' ), '' ) );
				if ( $ovp !== '' && ctype_digit( $ovp ) ) {
					$redisPort = $ovp;
				}
				$ovn = trim( (string) tt_fqsb_cfg_get( $cfg, array( 'kontext', 'redis_acl_db' ), '' ) );
				if ( $ovn !== '' && ctype_digit( $ovn ) ) {
					$redisDb = $ovn;
				}
			}
			if ( $redisHost !== '' ) {
				$rmerge = tt_fqsb_kontext_redis_merge_anon_corpora(
					$redisHost,
					$redisPort,
					$redisDb,
					$anonId,
					$corpusName,
					$anonVis
				);
				foreach ( $rmerge['steps'] as $st ) {
					$out['steps'][] = $st;
				}
			}
			$out['ok']      = true;
			$out['message'] = 'KonText server settings applied.';
			return $out;
		}
	}

	if ( !function_exists('tt_fqsb_kontext_repair') ) {
		function tt_fqsb_kontext_repair( $targetRoot, $cfg, $selectedCorpus, $fqsbin ) {
			return tt_fqsb_kontext_apply_to_server( $targetRoot, $cfg, $selectedCorpus, $fqsbin, array(
				'anonymous_visible' => true,
			) );
		}
	}

	if ( !function_exists('tt_fqsb_fqs_reindex_overview') ) {
		function tt_fqsb_fqs_reindex_overview( $fqsbin, $limitJobs = 60, $limitHistory = 200, $dbPath = '', $corpusId = '' ) {
			$out = array(
				'ok' => false,
				'queue' => array(),
				'history' => array(),
				'last_indexed' => array(),
				'last_indexed_by_backend' => array(),
				'error' => '',
				'raw_queue' => '',
				'raw_history' => '',
			);
			$fqsbin = trim((string)$fqsbin);
			if ( $fqsbin === '' ) {
				$out['error'] = 'fqs binary not found.';
				return $out;
			}
			$dbArg = '';
			$dbPath = trim((string)$dbPath);
			if ( $dbPath !== '' ) {
				$dbArg = ' --db ' . escapeshellarg($dbPath);
			}
			$corpusArg = '';
			$corpusId = trim((string)$corpusId);
			if ( $corpusId !== '' ) {
				$corpusArg = ' --corpus ' . escapeshellarg($corpusId);
			}
			$qCmd = escapeshellarg($fqsbin) . ' reindex queue' . $dbArg . $corpusArg . ' --status active --limit ' . intval($limitJobs) . ' 2>&1';
			$hCmd = escapeshellarg($fqsbin) . ' reindex history' . $dbArg . $corpusArg . ' --limit ' . intval($limitHistory) . ' 2>&1';
			$rawQ = (string) shell_exec($qCmd);
			$rawH = (string) shell_exec($hCmd);
			$out['raw_queue'] = $rawQ;
			$out['raw_history'] = $rawH;
			$decQ = tt_fqsb_decode_json_any($rawQ);
			$decH = tt_fqsb_decode_json_any($rawH);
			$queue = is_array($decQ) && array_keys($decQ) === range(0, count($decQ)-1) ? $decQ : array();
			$hist = is_array($decH) && array_keys($decH) === range(0, count($decH)-1) ? $decH : array();
			$out['queue'] = $queue;
			$out['history'] = $hist;
			$extractBackends = function( $row ) {
				$backs = array();
				if ( !is_array($row) ) return $backs;
				if ( isset($row['requested_backends']) && is_array($row['requested_backends']) ) {
					$backs = $row['requested_backends'];
				}
				$details = isset($row['details']) && is_array($row['details']) ? $row['details'] : array();
				if ( count($backs) === 0 ) {
					if ( isset($details['requested_backends']) && is_array($details['requested_backends']) ) {
						$backs = $details['requested_backends'];
					} elseif ( isset($details['reindex_backends']) && is_array($details['reindex_backends']) ) {
						$backs = $details['reindex_backends'];
					}
				}
				$norm = array();
				foreach ( $backs as $b ) {
					$t = trim((string)$b);
					if ( $t !== '' && !in_array($t, $norm, true) ) $norm[] = $t;
				}
				return $norm;
			};
			$jobBackends = array();
			foreach ( $hist as $ev ) {
				if ( !is_array($ev) ) continue;
				$jobId = trim((string)($ev['job_id'] ?? ''));
				if ( $jobId === '' ) continue;
				$backs = $extractBackends($ev);
				if ( count($backs) === 0 ) continue;
				if ( !isset($jobBackends[$jobId]) || !is_array($jobBackends[$jobId]) ) {
					$jobBackends[$jobId] = $backs;
					continue;
				}
				foreach ( $backs as $b ) {
					if ( !in_array($b, $jobBackends[$jobId], true) ) $jobBackends[$jobId][] = $b;
				}
			}
			$last = array();
			$lastByBackend = array();
			foreach ( $hist as $ev ) {
				if ( !is_array($ev) ) continue;
				$event = isset($ev['event']) ? trim((string)$ev['event']) : '';
				if ( $event !== 'indexed' ) continue;
				$cid = isset($ev['corpus_id']) ? trim((string)$ev['corpus_id']) : '';
				if ( $cid === '' ) continue;
				$at = isset($ev['at']) ? (string)$ev['at'] : '';
				if ( !isset($last[$cid]) ) {
					$jobId = isset($ev['job_id']) ? (string)$ev['job_id'] : '';
					$backs = $extractBackends($ev);
					if ( count($backs) === 0 && $jobId !== '' && isset($jobBackends[$jobId]) && is_array($jobBackends[$jobId]) ) {
						$backs = $jobBackends[$jobId];
					}
					$last[$cid] = array(
						'at' => $at,
						'job_id' => $jobId,
						'backends' => $backs,
						'details' => isset($ev['details']) && is_array($ev['details']) ? $ev['details'] : array(),
					);
				}
				foreach ( $backs as $bk ) {
					$bk = trim((string)$bk);
					if ( $bk === '' ) continue;
					if ( !isset($lastByBackend[$bk]) ) {
						$lastByBackend[$bk] = array(
							'at' => $at,
							'job_id' => $jobId,
							'corpus_id' => $cid,
							'details' => isset($ev['details']) && is_array($ev['details']) ? $ev['details'] : array(),
						);
					}
				}
			}
			$out['last_indexed'] = $last;
			$out['last_indexed_by_backend'] = $lastByBackend;
			$out['ok'] = true;
			return $out;
		}
	}

	if ( !function_exists('tt_fqsb_frontend_live_url') ) {
		function tt_fqsb_frontend_live_url( $name, $row, $envCfg = array(), $selectedCorpus = '' ) {
			if ( !is_array($row) ) return '';
			$name = strtolower(trim((string) $name));
			$checks = isset($row['checks']) && is_array($row['checks']) ? $row['checks'] : array();
			$linksCfg = is_array($envCfg) && isset($envCfg['links']) && is_array($envCfg['links']) ? $envCfg['links'] : array();
			$publicOverride = '';
			if ( $name === 'kontext' ) {
				$publicOverride = trim((string)(isset($linksCfg['kontext_live_url']) ? $linksCfg['kontext_live_url'] : ''));
			} elseif ( $name === 'fcs' ) {
				$publicOverride = trim((string)(isset($linksCfg['fcs_live_url']) ? $linksCfg['fcs_live_url'] : ''));
			}

			$requestHostRaw = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
			$requestScheme = ( !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' ) ? 'https' : 'http';
			$requestHost = $requestHostRaw;
			$requestPort = null;
			if ( strpos($requestHostRaw, ':') !== false ) {
				list($rh, $rp) = explode(':', $requestHostRaw, 2);
				$requestHost = $rh;
				if ( ctype_digit($rp) ) $requestPort = intval($rp);
			}

			$portMap = array();
			if ( isset($linksCfg['port_map']) && is_array($linksCfg['port_map']) ) {
				foreach ( $linksCfg['port_map'] as $k => $v ) {
					$ks = trim((string)$k);
					$vs = trim((string)$v);
					if ( ctype_digit($ks) && ctype_digit($vs) ) {
						$portMap[$ks] = intval($vs);
					}
				}
			}

			$rewritePublic = function( $url ) use ( $requestHost, $requestPort, $requestScheme, $portMap ) {
				$u = trim((string)$url);
				if ( $u === '' || !preg_match('#^https?://#i', $u) ) return '';
				$parts = @parse_url($u);
				if ( !is_array($parts) ) return $u;
				$host = isset($parts['host']) ? strtolower((string)$parts['host']) : '';
				$port = isset($parts['port']) ? intval($parts['port']) : null;
				$isInternal = in_array($host, array('127.0.0.1', 'localhost', 'kontext', 'fqs', 'blacklab', 'clickhouse', 'teitok'), true);

				$scheme = isset($parts['scheme']) ? (string)$parts['scheme'] : 'http';
				$newHost = isset($parts['host']) ? (string)$parts['host'] : '';
				$newPort = $port;
				if ( $isInternal && $requestHost !== '' ) {
					$scheme = $requestScheme;
					$newHost = $requestHost;
					if ( $port !== null && isset($portMap[(string)$port]) ) {
						$newPort = intval($portMap[(string)$port]);
					} elseif ( $port === null && $requestPort !== null ) {
						$newPort = $requestPort;
					}
				}
				$path = isset($parts['path']) ? (string)$parts['path'] : '';
				$query = isset($parts['query']) ? ('?' . (string)$parts['query']) : '';
				$frag = isset($parts['fragment']) ? ('#' . (string)$parts['fragment']) : '';
				$auth = '';
				if ( isset($parts['user']) ) {
					$auth = (string)$parts['user'];
					if ( isset($parts['pass']) ) $auth .= ':' . (string)$parts['pass'];
					$auth .= '@';
				}
				$portTxt = '';
				if ( $newPort !== null ) {
					$isDefault = ( $scheme === 'http' && intval($newPort) === 80 ) || ( $scheme === 'https' && intval($newPort) === 443 );
					if ( !$isDefault ) $portTxt = ':' . intval($newPort);
				}
				return $scheme . '://' . $auth . $newHost . $portTxt . $path . $query . $frag;
			};

			if ( $name === 'kontext' ) {
				$probe = isset($checks['http_corplist']) && is_array($checks['http_corplist']) ? $checks['http_corplist'] : array();
				$url = $publicOverride;
				if ( $url === '' ) $url = isset($probe['url']) ? trim((string)$probe['url']) : '';
				if ( $url === '' && isset($probe['candidates']) && is_array($probe['candidates']) && !empty($probe['candidates']) ) {
					$url = trim((string) $probe['candidates'][0]);
				}
				if ( $url !== '' ) {
					$url = preg_replace('#/corpora/corplist/?$#', '', $url);
				}
				$url = $rewritePublic($url);
				if ( preg_match('#^https?://#i', (string) $url) ) {
					$targetCorpus = trim((string)$selectedCorpus);
					$tc = isset($checks['corplist_target_corpus']) && is_array($checks['corplist_target_corpus'])
						? $checks['corplist_target_corpus'] : array();
					$candidates = isset($tc['candidate_ids']) && is_array($tc['candidate_ids']) ? $tc['candidate_ids'] : array();
					$candidatesNorm = array();
					foreach ( $candidates as $cid ) {
						$cid = trim((string)$cid);
						if ( $cid === '' ) continue;
						$candidatesNorm[] = $cid;
					}
					// In non-shared TEITOK pages selected corpus often equals folder name
					// (e.g. infoveillance), while KonText corpus ident can be different
					// (e.g. tt_infov). Prefer explicit Kontext/corplist candidates when present.
					if ( !empty($candidatesNorm) ) {
						$selNorm = strtolower($targetCorpus);
						$match = false;
						if ( $selNorm !== '' ) {
							foreach ( $candidatesNorm as $cid ) {
								if ( strtolower($cid) === $selNorm ) {
									$match = true;
									break;
								}
							}
						}
						if ( !$match ) {
							$targetCorpus = trim((string)$candidatesNorm[0]);
						}
					}
					if ( $targetCorpus === '' ) {
						$targetCorpus = trim((string)(isset($tc['requested_corpus_id']) ? $tc['requested_corpus_id'] : ''));
					}
					if ( $targetCorpus !== '' ) {
						$pattern = trim((string)(isset($linksCfg['kontext_corpus_url_pattern']) ? $linksCfg['kontext_corpus_url_pattern'] : ''));
						if ( $pattern !== '' ) {
							$resolved = str_replace('{id}', rawurlencode($targetCorpus), $pattern);
							if ( preg_match('#^https?://#i', (string)$resolved) ) return (string)$resolved;
							$url = rtrim((string)$url, '/') . '/' . ltrim((string)$resolved, '/');
						} else {
							$url = rtrim((string)$url, '/') . '/query?corpname=' . rawurlencode($targetCorpus);
						}
					}
					return (string) $url;
				}
				return '';
			}

			if ( $name === 'fcs' ) {
				$probe = isset($checks['fcs_explain']) && is_array($checks['fcs_explain']) ? $checks['fcs_explain'] : array();
				$url = $publicOverride;
				if ( $url === '' ) $url = isset($probe['url']) ? trim((string)$probe['url']) : '';
				$url = $rewritePublic($url);
				if ( preg_match('#^https?://#i', (string) $url) ) {
					$targetCorpus = trim((string)$selectedCorpus);
					if ( $targetCorpus === '' ) {
						$tc = isset($checks['fcs_target_corpus']) && is_array($checks['fcs_target_corpus'])
							? $checks['fcs_target_corpus'] : array();
						$targetCorpus = trim((string)(isset($tc['id']) ? $tc['id'] : ''));
					}
					if ( $targetCorpus !== '' ) {
						$pattern = trim((string)(isset($linksCfg['fcs_corpus_url_pattern']) ? $linksCfg['fcs_corpus_url_pattern'] : ''));
						if ( $pattern === '' ) {
							$pattern = '/fcs?operation=searchRetrieve&x-fcs-context={id}&query=%5Bword%3D%22.%2A%22%5D';
						}
						$resolved = str_replace('{id}', rawurlencode($targetCorpus), $pattern);
						if ( preg_match('#^https?://#i', (string)$resolved) ) return (string)$resolved;
						$url = rtrim((string)$url, '/') . '/' . ltrim((string)$resolved, '/');
					}
					return (string) $url;
				}
				return '';
			}

			return '';
		}
	}

	if ( !function_exists('tt_fqsb_env_config_candidates') ) {
		function tt_fqsb_env_config_candidates() {
			// Same order as flexicorp/env_config.py default_env_config_candidates().
			$out = array();
			$envPath = trim((string) getenv('FLEXICORP_ENV_CONFIG'));
			if ( $envPath !== '' ) {
				$out[] = $envPath;
			}
			$out[] = '/etc/flexicorp/env-config.json';
			$home = trim((string) getenv('HOME'));
			if ( $home !== '' ) {
				$out[] = rtrim($home, '/\\') . '/.config/flexicorp/env-config.json';
				$out[] = rtrim($home, '/\\') . '/.flexicorp/env-config.json';
			}
			$out[] = '/tmp/flexicorp/env-config.json';
			$uniq = array();
			foreach ( $out as $p ) {
				$p = trim((string) $p);
				if ( $p === '' ) continue;
				if ( !in_array($p, $uniq, true) ) {
					$uniq[] = $p;
				}
			}
			return $uniq;
		}
	}

	if ( !function_exists('tt_fqsb_resolve_env_config_path') ) {
		function tt_fqsb_resolve_env_config_path( $explicit = '' ) {
			$explicit = trim((string) $explicit);
			if ( $explicit !== '' ) {
				return $explicit;
			}
			$candidates = tt_fqsb_env_config_candidates();
			// Prefer an existing writable file for editor operations.
			foreach ( $candidates as $cand ) {
				if ( is_file($cand) && is_writable($cand) ) {
					return $cand;
				}
			}
			// Otherwise, use any existing file (read-only still useful to inspect).
			foreach ( $candidates as $cand ) {
				if ( is_file($cand) ) {
					return $cand;
				}
			}
			// Pick the first candidate whose parent directory is writable (create /tmp/flexicorp if needed).
			foreach ( $candidates as $cand ) {
				$parent = dirname($cand);
				if ( !is_dir($parent) && strpos($cand, '/tmp/flexicorp/') === 0 ) {
					@mkdir($parent, 0775, true);
				}
				if ( is_dir($parent) && is_writable($parent) ) {
					return $cand;
				}
			}
			// Final fallback: must be one of the candidates (never a path outside the list).
			foreach ( $candidates as $cand ) {
				if ( $cand !== '' ) {
					return $cand;
				}
			}
			return '/tmp/flexicorp/env-config.json';
		}
	}

	if ( !function_exists('tt_fqsb_read_env_config_file') ) {
		function tt_fqsb_read_env_config_file( $path ) {
			$path = trim((string) $path);
			if ( $path === '' || !is_file($path) ) return array();
			$raw = @file_get_contents($path);
			if ( !is_string($raw) || $raw === '' ) return array();
			$dec = json_decode($raw, true);
			return is_array($dec) ? $dec : array();
		}
	}

	if ( !function_exists('tt_fqsb_cfg_get') ) {
		function tt_fqsb_cfg_get( $cfg, $path, $default = '' ) {
			$cur = $cfg;
			if ( !is_array($path) ) return $default;
			foreach ( $path as $seg ) {
				if ( !is_array($cur) || !array_key_exists($seg, $cur) ) return $default;
				$cur = $cur[$seg];
			}
			return $cur;
		}
	}

	if ( !function_exists('tt_fqsb_cfg_set') ) {
		function tt_fqsb_cfg_set( &$cfg, $path, $value ) {
			$ref = &$cfg;
			$n = count($path);
			for ( $i = 0; $i < $n; $i++ ) {
				$key = $path[$i];
				if ( $i === $n - 1 ) {
					$ref[$key] = $value;
				} else {
					if ( !isset($ref[$key]) || !is_array($ref[$key]) ) $ref[$key] = array();
					$ref = &$ref[$key];
				}
			}
		}
	}

	if ( !function_exists('tt_fqsb_cfg_unset') ) {
		function tt_fqsb_cfg_unset( &$cfg, $path ) {
			$ref = &$cfg;
			$n = count($path);
			for ( $i = 0; $i < $n - 1; $i++ ) {
				$key = $path[$i];
				if ( !isset($ref[$key]) || !is_array($ref[$key]) ) return;
				$ref = &$ref[$key];
			}
			$last = $path[$n - 1];
			if ( is_array($ref) && array_key_exists($last, $ref) ) unset($ref[$last]);
		}
	}

	if ( !function_exists('tt_fqsb_cfg_prune') ) {
		function tt_fqsb_cfg_prune( $node ) {
			if ( !is_array($node) ) return $node;
			$out = array();
			foreach ( $node as $k => $v ) {
				if ( is_array($v) ) {
					$pv = tt_fqsb_cfg_prune($v);
					if ( is_array($pv) && empty($pv) ) continue;
					$out[$k] = $pv;
				} else {
					$out[$k] = $v;
				}
			}
			return $out;
		}
	}

	if ( !function_exists('tt_fqsb_parse_port_map_text') ) {
		function tt_fqsb_parse_port_map_text( $raw ) {
			$out = array();
			$raw = trim((string)$raw);
			if ( $raw === '' ) return $out;
			$parts = preg_split('/\s*,\s*/', $raw);
			if ( !is_array($parts) ) return $out;
			foreach ( $parts as $piece ) {
				$piece = trim((string)$piece);
				if ( $piece === '' ) continue;
				$kv = preg_split('/\s*[:=]\s*/', $piece, 2);
				if ( !is_array($kv) || count($kv) !== 2 ) continue;
				$k = trim((string)$kv[0]);
				$v = trim((string)$kv[1]);
				if ( ctype_digit($k) && ctype_digit($v) ) {
					$out[$k] = intval($v);
				}
			}
			return $out;
		}
	}

	if ( !function_exists('tt_fqsb_pid_alive') ) {
		function tt_fqsb_pid_alive( $pid ) {
			$pid = intval($pid);
			if ( $pid <= 1 ) return false;
			if ( function_exists('posix_kill') ) {
				return @posix_kill($pid, 0);
			}
			$out = trim((string) shell_exec('ps -p ' . $pid . ' -o pid= 2>/dev/null'));
			return ( $out !== '' );
		}
	}

	if ( !function_exists('tt_fqsb_http_get_json') ) {
		function tt_fqsb_http_get_json( $url, $timeout = 1.2 ) {
			$ctx = stream_context_create(array(
				'http' => array(
					'method' => 'GET',
					'timeout' => max(0.3, floatval($timeout)),
					'ignore_errors' => true,
					'header' => "Accept: application/json\r\nConnection: close\r\n",
				),
			));
			$raw = @file_get_contents((string)$url, false, $ctx);
			$dec = is_string($raw) ? json_decode($raw, true) : null;
			return is_array($dec) ? $dec : null;
		}
	}

	if ( !function_exists('tt_fqsb_kontext_normalize_base_url' ) ) {
		function tt_fqsb_kontext_normalize_base_url( $url ) {
			$u = trim( (string) $url );
			if ( $u === '' ) {
				return '';
			}
			$u = rtrim( $u, '/' );
			if ( preg_match( '#/corpora/corplist$#i', $u ) ) {
				$u = preg_replace( '#/corpora/corplist$#i', '', $u );
			}
			return rtrim( $u, '/' );
		}
	}

	/**
	 * True when env-config points at a KonText base URL that answers HTTP (same idea as flexicorp kontext http_corplist).
	 */
	if ( !function_exists( 'tt_fqsb_kontext_server_running' ) ) {
		function tt_fqsb_kontext_server_running( $cfg ) {
			$cfg = is_array( $cfg ) ? $cfg : array();
			$seen = array();
			$candidates = array();
			foreach ( array( array( 'kontext', 'url' ), array( 'links', 'kontext_live_url' ) ) as $path ) {
				$u = trim( (string) tt_fqsb_cfg_get( $cfg, $path, '' ) );
				if ( $u === '' || isset( $seen[ $u ] ) ) {
					continue;
				}
				$seen[ $u ] = true;
				$candidates[] = $u;
			}
			foreach ( $candidates as $cand ) {
				$base = tt_fqsb_kontext_normalize_base_url( $cand );
				if ( $base === '' || ! preg_match( '#^https?://#i', $base ) ) {
					continue;
				}
				$probe = $base . '/corpora/corplist';
				$ctx = stream_context_create( array(
					'http' => array(
						'timeout' => 2.0,
						'ignore_errors' => true,
					),
					'ssl' => array(
						'verify_peer' => false,
						'verify_peer_name' => false,
					),
				) );
				$h = @get_headers( $probe, 0, $ctx );
				if ( is_array( $h ) && isset( $h[0] ) && preg_match( '#^HTTP/\S+\s+[0-9]{3}\b#', (string) $h[0] ) ) {
					return true;
				}
			}
			return false;
		}
	}

	if ( !function_exists('tt_fqsb_fqs_runtime_dir') ) {
		function tt_fqsb_fqs_runtime_dir( $projectRoot ) {
			$root = trim((string)$projectRoot);
			if ( $root === '' ) $root = getcwd();
			$baseTmp = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'flexicorp-fqs';
			if ( !is_dir($baseTmp) ) @mkdir($baseTmp, 0775, true);
			if ( is_dir($baseTmp) && is_writable($baseTmp) ) return $baseTmp;
			$fallback = '/tmp/flexicorp-fqs';
			if ( !is_dir($fallback) ) @mkdir($fallback, 0775, true);
			return $fallback;
		}
	}

	if ( !function_exists('tt_fqsb_fqs_discover_url') ) {
		function tt_fqsb_fqs_discover_url( $cfg, $runtimeDir = '' ) {
			$url = trim((string) tt_fqsb_cfg_get($cfg, array('fqs', 'url'), ''));
			$host = trim((string) tt_fqsb_cfg_get($cfg, array('fqs', 'host'), ''));
			$port = trim((string) tt_fqsb_cfg_get($cfg, array('fqs', 'port'), ''));
			if ( $url === '' && $host !== '' && ctype_digit($port) ) {
				$url = 'http://' . $host . ':' . intval($port);
			}
			$candidates = array();
			$ehj = trim((string) getenv('FQS_HTTP_JSON'));
			if ( $ehj !== '' ) $candidates[] = $ehj;
			$dbp = trim((string) getenv('FQS_DB_PATH'));
			if ( $dbp !== '' ) {
				$candidates[] = rtrim(str_replace('\\', '/', dirname($dbp)), '/') . '/fqs-http.json';
			}
			if ( $runtimeDir !== '' ) {
				$candidates[] = rtrim(str_replace('\\', '/', $runtimeDir), '/') . '/fqs-http.json';
			}
			$candidates[] = '/usr/local/var/fqs/fqs-http.json';
			$candidates[] = '/var/lib/fqs/fqs-http.json';
			foreach ( $candidates as $p ) {
				$p = trim((string)$p);
				if ( $p === '' || !is_file($p) ) continue;
				$raw = @file_get_contents($p);
				if ( !is_string($raw) || trim($raw) === '' ) continue;
				$dec = json_decode($raw, true);
				if ( !is_array($dec) ) continue;
				$u = trim((string)($dec['url'] ?? ''));
				if ( preg_match('#^https?://#i', $u) ) {
					$url = $u;
					break;
				}
			}
			if ( $url === '' ) $url = 'http://127.0.0.1:8787';
			return $url;
		}
	}

	if ( !function_exists('tt_fqsb_fqs_probe_runtime') ) {
		function tt_fqsb_fqs_probe_runtime( $fqsbin, $cfg, $projectRoot ) {
			$runtimeDir = tt_fqsb_fqs_runtime_dir($projectRoot);
			$pidFile = rtrim($runtimeDir, '/\\') . DIRECTORY_SEPARATOR . 'fqs.pid';
			$url = tt_fqsb_fqs_discover_url($cfg, $runtimeDir);
			$healthUrl = rtrim((string)$url, '/') . '/health';
			$health = tt_fqsb_http_get_json($healthUrl, 1.0);
			$running = is_array($health) && isset($health['ok']) ? !!$health['ok'] : ( is_array($health) );
			$pid = 0;
			if ( is_file($pidFile) ) {
				$rawPid = trim((string) @file_get_contents($pidFile));
				if ( ctype_digit($rawPid) ) $pid = intval($rawPid);
			}
			$pidAlive = tt_fqsb_pid_alive($pid);
			$parts = @parse_url($url);
			$host = is_array($parts) && isset($parts['host']) ? (string)$parts['host'] : '127.0.0.1';
			$port = is_array($parts) && isset($parts['port']) ? intval($parts['port']) : 8787;
			return array(
				'fqs_bin' => trim((string)$fqsbin),
				'url' => $url,
				'health_url' => $healthUrl,
				'running' => $running,
				'health' => $health,
				'runtime_dir' => $runtimeDir,
				'pid_file' => $pidFile,
				'managed_pid' => $pid,
				'managed_pid_alive' => $pidAlive,
				'host' => $host,
				'port' => $port,
			);
		}
	}

	if ( !function_exists('tt_fqsb_fqs_get_corpus_row') ) {
		function tt_fqsb_fqs_get_corpus_row( $fqsbin, $selectedCorpus, $targetRoot, $dbPath = '' ) {
			$fqsbin = trim((string)$fqsbin);
			if ( $fqsbin === '' ) return array();
			$dbPath = trim((string)$dbPath);
			if ( $dbPath === '' && function_exists('tt_fqsb_fqs_db_path_from_runtime_file') ) {
				$dbPath = tt_fqsb_fqs_db_path_from_runtime_file();
			}
			$dbArg = $dbPath !== '' ? (' --db ' . escapeshellarg($dbPath)) : '';
			$selectedCorpus = trim((string)$selectedCorpus);
			if ( $selectedCorpus !== '' ) {
				$showRaw = (string) shell_exec(escapeshellarg($fqsbin) . ' corpora show' . $dbArg . ' --id ' . escapeshellarg($selectedCorpus) . ' 2>&1');
				$showDec = tt_fqsb_decode_json_any($showRaw);
				if ( is_array($showDec) && !empty($showDec['id']) ) return $showDec;
			}
			$listRaw = (string) shell_exec(escapeshellarg($fqsbin) . ' corpora list' . $dbArg . ' 2>&1');
			$list = tt_fqsb_decode_corpora_list($listRaw);
			$rootNorm = rtrim(str_replace('\\', '/', (string)$targetRoot), '/');
			foreach ( $list as $row ) {
				if ( !is_array($row) ) continue;
				$pr = rtrim(str_replace('\\', '/', (string)($row['project_root'] ?? '')), '/');
				if ( $pr !== '' && $pr === $rootNorm ) return $row;
			}
			return array();
		}
	}

	if ( !function_exists('tt_fqsb_kontext_detect_corpus_name') ) {
		function tt_fqsb_kontext_detect_corpus_name( $targetRoot, $selectedCorpus, $fqsbin ) {
			$selectedCorpus = trim((string)$selectedCorpus);
			$row = tt_fqsb_fqs_get_corpus_row($fqsbin, $selectedCorpus, $targetRoot);
			if ( is_array($row) ) {
				$settings = isset($row['settings']) && is_array($row['settings']) ? $row['settings'] : array();
				$kontext = isset($settings['kontext']) && is_array($settings['kontext']) ? $settings['kontext'] : array();
				$cid = trim((string)($kontext['corpus_id'] ?? ''));
				if ( $cid !== '' ) return $cid;
				$cname = trim((string)($settings['corpus_name'] ?? ''));
				if ( $cname !== '' ) return $cname;
			}
			$settingsXml = rtrim((string)$targetRoot, '/\\') . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'settings.xml';
			if ( is_file($settingsXml) && function_exists('simplexml_load_file') ) {
				$xml = @simplexml_load_file($settingsXml);
				if ( $xml !== false && isset($xml->cqp) ) {
					$attrs = $xml->cqp->attributes();
					$c = trim((string)($attrs['corpus'] ?? ''));
					if ( $c !== '' ) return strtolower($c);
				}
			}
			if ( $selectedCorpus !== '' ) return $selectedCorpus;
			return basename(rtrim(str_replace('\\', '/', (string)$targetRoot), '/'));
		}
	}

	if ( !function_exists('tt_fqsb_kontext_conf_path') ) {
		function tt_fqsb_kontext_conf_path( $cfg ) {
			$candidates = array(
				trim((string)tt_fqsb_cfg_get($cfg, array('kontext', 'config_xml'), '')),
				trim((string)getenv('KONTEXT_CONF')),
				'/opt/kontext/conf/config.xml',
				'/etc/kontext/config.xml',
			);
			foreach ( $candidates as $cand ) {
				$cand = trim((string)$cand);
				if ( $cand !== '' && is_file($cand) ) return $cand;
			}
			return '';
		}
	}

	if ( !function_exists('tt_fqsb_log_last_nonempty_line') ) {
		function tt_fqsb_log_last_nonempty_line( $path ) {
			$path = trim((string)$path);
			if ( $path === '' || !is_file($path) ) return '';
			$lines = @file($path, FILE_IGNORE_NEW_LINES);
			if ( !is_array($lines) || empty($lines) ) return '';
			for ( $i = count($lines) - 1; $i >= 0; $i-- ) {
				$line = trim((string)$lines[$i]);
				if ( $line !== '' ) return $line;
			}
			return '';
		}
	}

	if ( !function_exists('tt_fqsb_tail_file') ) {
		function tt_fqsb_tail_file( $path, $maxLines = 120 ) {
			$path = trim((string)$path);
			if ( $path === '' || !is_file($path) ) return '';
			$lines = @file($path, FILE_IGNORE_NEW_LINES);
			if ( !is_array($lines) || empty($lines) ) return '';
			$maxLines = intval($maxLines);
			if ( $maxLines <= 0 ) $maxLines = 120;
			$tail = array_slice($lines, -$maxLines);
			return implode("\n", $tail);
		}
	}

	if ( !function_exists('tt_fqsb_fqs_start') ) {
		function tt_fqsb_fqs_start( $fqsbin, $cfg, $projectRoot ) {
			$state = tt_fqsb_fqs_probe_runtime($fqsbin, $cfg, $projectRoot);
			if ( $state['fqs_bin'] === '' ) {
				return array('ok' => false, 'message' => 'Cannot start FQS: binary not found in PATH/findapp.');
			}
			if ( !is_executable($state['fqs_bin']) ) {
				return array('ok' => false, 'message' => 'Cannot start FQS: binary is not executable: ' . $state['fqs_bin']);
			}
			if ( !empty($state['running']) ) {
				return array('ok' => true, 'message' => 'FQS is already running at ' . $state['url'] . '.');
			}
			$runtimeDir = (string)$state['runtime_dir'];
			$logFile = $runtimeDir . DIRECTORY_SEPARATOR . 'fqs-serve.log';
			$httpJson = $runtimeDir . DIRECTORY_SEPARATOR . 'fqs-http.json';
			$dbPath = trim((string) tt_fqsb_cfg_get($cfg, array('fqs', 'db_path'), ''));
			if ( $dbPath === '' ) {
				$envDb = trim((string) getenv('FQS_DB_PATH'));
				$dbPath = ( $envDb !== '' ) ? $envDb : ($runtimeDir . DIRECTORY_SEPARATOR . 'fqs.db');
			}
			$host = (string)$state['host'];
			$port = intval($state['port']);
			if ( $port <= 0 ) $port = 8787;
			$setsid = trim((string) shell_exec('command -v setsid 2>/dev/null'));
			$launcher = ( $setsid !== '' ) ? (escapeshellarg($setsid) . ' ') : '';
			$cmd = $launcher . 'env '
				. 'FQS_DB_PATH=' . escapeshellarg($dbPath) . ' '
				. 'FQS_HTTP_JSON=' . escapeshellarg($httpJson) . ' '
				. escapeshellarg($state['fqs_bin']) . ' serve '
				. '--host ' . escapeshellarg($host) . ' '
				. '--port ' . intval($port)
				. ' < /dev/null >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
			$pidRaw = trim((string) shell_exec($cmd));
			$pid = ctype_digit($pidRaw) ? intval($pidRaw) : 0;
			if ( $pid > 1 ) {
				@file_put_contents($state['pid_file'], (string)$pid . "\n");
			}
			$ok = false;
			for ( $i = 0; $i < 8; $i++ ) {
				usleep(350000);
				$check = tt_fqsb_fqs_probe_runtime($fqsbin, $cfg, $projectRoot);
				if ( !empty($check['running']) ) { $ok = true; break; }
			}
			if ( !$ok ) {
				if ( $pid > 1 && !tt_fqsb_pid_alive($pid) && is_file($state['pid_file']) ) {
					@unlink($state['pid_file']);
				}
				$errLine = tt_fqsb_log_last_nonempty_line($logFile);
				$msg = 'Started FQS process but health check is not yet reachable at ' . $state['url'] . '. Check log: ' . $logFile;
				if ( $errLine !== '' ) {
					$msg .= ' Last log line: ' . $errLine;
				}
				return array('ok' => false, 'message' => $msg);
			}
			return array('ok' => true, 'message' => 'FQS started and healthy at ' . $state['url'] . '.');
		}
	}

	if ( !function_exists('tt_fqsb_fqs_stop') ) {
		function tt_fqsb_fqs_stop( $fqsbin, $cfg, $projectRoot ) {
			$state = tt_fqsb_fqs_probe_runtime($fqsbin, $cfg, $projectRoot);
			$stopped = false;
			$killed = array();
			if ( !empty($state['managed_pid']) && !empty($state['managed_pid_alive']) ) {
				$pid = intval($state['managed_pid']);
				@shell_exec('kill -TERM ' . $pid . ' 2>/dev/null');
				for ( $i = 0; $i < 6; $i++ ) {
					usleep(300000);
					if ( !tt_fqsb_pid_alive($pid) ) { $stopped = true; break; }
				}
				if ( !$stopped ) {
					@shell_exec('kill -KILL ' . $pid . ' 2>/dev/null');
					usleep(250000);
					$stopped = !tt_fqsb_pid_alive($pid);
				}
				if ( $stopped ) $killed[] = $pid;
			}
			if ( !$stopped && !empty($state['running']) && intval($state['port']) > 0 ) {
				$lsof = (string) shell_exec('lsof -nP -iTCP:' . intval($state['port']) . ' -sTCP:LISTEN -t 2>/dev/null');
				$lines = preg_split('/\r\n|\r|\n/', $lsof);
				if ( is_array($lines) ) {
					foreach ( $lines as $line ) {
						$p = trim((string)$line);
						if ( !ctype_digit($p) ) continue;
						$pid = intval($p);
						if ( $pid <= 1 ) continue;
						$cmd = (string) shell_exec('ps -p ' . $pid . ' -o command= 2>/dev/null');
						$lc = strtolower(trim($cmd));
						if ( $lc === '' ) continue;
						if ( strpos($lc, 'fqs') === false || strpos($lc, ' serve') === false ) continue;
						@shell_exec('kill -TERM ' . $pid . ' 2>/dev/null');
						usleep(250000);
						if ( !tt_fqsb_pid_alive($pid) ) {
							$stopped = true;
							$killed[] = $pid;
						}
					}
				}
			}
			if ( is_file($state['pid_file']) ) @unlink($state['pid_file']);
			$after = tt_fqsb_fqs_probe_runtime($fqsbin, $cfg, $projectRoot);
			if ( !empty($after['running']) ) {
				return array('ok' => false, 'message' => 'FQS still responds at ' . $after['url'] . '. Stop failed or another FQS process is active.');
			}
			if ( !empty($killed) ) {
				return array('ok' => true, 'message' => 'Stopped FQS process(es): ' . implode(', ', $killed) . '.');
			}
			return array('ok' => true, 'message' => 'FQS is not running.');
		}
	}

	$envAction = isset($_GET['mode']) ? trim((string) $_GET['mode']) : 'status';
	if ( !in_array($envAction, array('status', 'check-corpus', 'list'), true) ) {
		$envAction = 'status';
	}
	// $screenAct set at top (TEITOK $act).
	$isEditAct = ( $screenAct === 'edit' );
	$isLogsAct = ( $screenAct === 'logs' );
	$isKontextAct = ( $screenAct === 'kontext' );
	$isStatusAct = ( ! $isEditAct && ! $isLogsAct && ! $isKontextAct );
	$debugEnabled = ( !empty($debug) || (isset($_REQUEST['debug']) && trim((string)$_REQUEST['debug']) !== '') );
	// ClickHouse is deprecated and disabled unless the setting flexicorp/enable_clickhouse is on
	// (same switch as tt_flexicorp_clickhouse_enabled() in flexicorp.php).
	$clickhouseEnabled = function_exists('getset')
		&& in_array( strtolower( trim( (string) getset( 'flexicorp/enable_clickhouse', '' ) ) ), array( '1', 'true', 'yes', 'on' ), true );
	$backendMeta = array(
		'teitok' => array('kind' => 'frontend', 'depends_on' => array(), 'depends_hint' => 'auto-detected from project settings'),
		'fcs' => array('kind' => 'frontend', 'depends_on' => array(), 'depends_hint' => 'auto-detected from FQS FCS-enabled corpora'),
		'pando' => array('kind' => 'backend', 'depends_on' => array()),
		'cqp' => array('kind' => 'backend', 'depends_on' => array()),
		'manatee' => array('kind' => 'backend', 'depends_on' => array()),
		'blacklab' => array('kind' => 'backend', 'depends_on' => array()),
		'kontext' => array('kind' => 'frontend', 'depends_on' => array('manatee')),
		'pmltq' => array('kind' => 'backend', 'depends_on' => array(), 'depends_hint' => $clickhouseEnabled ? 'PML-TQ→ClickHouse (same DB as ClickQL) vs native PML-TQ HTTP (PostgreSQL); independent' : 'native PML-TQ HTTP server (PostgreSQL)'),
	);
	if ( !empty($debug) ) {
		if ( $clickhouseEnabled ) $backendMeta['clickhouse'] = array('kind' => 'backend', 'depends_on' => array(), 'depends_hint' => 'debug-only backend adapter');
		$backendMeta['teitokxml'] = array('kind' => 'backend', 'depends_on' => array(), 'depends_hint' => 'debug-only (mainly EasyCorp)');
	}
	$backendOptions = array_keys($backendMeta);
	$selectedBackends = array();
	foreach ( $backendOptions as $bkOpt ) {
		if ( isset($_GET['backend_' . $bkOpt]) ) {
			$selectedBackends[] = $bkOpt;
		}
	}
	if ( empty($selectedBackends) ) {
		$selectedBackends = $backendOptions;
	}
	$envBackends = implode(',', $selectedBackends);
	$envConfigInput = isset($_REQUEST['env_config']) ? trim((string) $_REQUEST['env_config']) : '';
	// Display / editor default: same resolved path as the env-config editor (trying list includes /tmp/...).
	$envConfigPath = $envConfigInput !== '' ? $envConfigInput : tt_fqsb_resolve_env_config_path('');
	$cfgEditorPath = tt_fqsb_resolve_env_config_path($envConfigPath);
	$cfgEditorData = tt_fqsb_read_env_config_file($cfgEditorPath);
	$cfgEditorNotice = '';
	$cfgEditorError = '';

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_env_config']) ) {
		$cfgEditorPath = tt_fqsb_resolve_env_config_path(isset($_POST['env_config_editor_path']) ? $_POST['env_config_editor_path'] : $cfgEditorPath);
		$newCfg = is_array($cfgEditorData) ? $cfgEditorData : array();
		$fields = array(
			array('key' => 'cfg_kontext_url', 'path' => array('kontext', 'url')),
			array('key' => 'cfg_kontext_config_xml', 'path' => array('kontext', 'config_xml')),
			array('key' => 'cfg_fcs_url', 'path' => array('fcs', 'url')),
			array('key' => 'cfg_fcs_host', 'path' => array('fcs', 'host')),
			array('key' => 'cfg_blacklab_url', 'path' => array('blacklab', 'url')),
			array('key' => 'cfg_fqs_bin', 'path' => array('fqs', 'bin')),
			array('key' => 'cfg_clickhouse_host', 'path' => array('clickhouse', 'host')),
			array('key' => 'cfg_clickhouse_database', 'path' => array('clickhouse', 'database')),
			array('key' => 'cfg_pmltq_server_url', 'path' => array('pmltq', 'server', 'url')),
			array('key' => 'cfg_links_kontext_live_url', 'path' => array('links', 'kontext_live_url')),
			array('key' => 'cfg_links_fcs_live_url', 'path' => array('links', 'fcs_live_url')),
			array('key' => 'cfg_links_kontext_corpus_url_pattern', 'path' => array('links', 'kontext_corpus_url_pattern')),
			array('key' => 'cfg_links_fcs_corpus_url_pattern', 'path' => array('links', 'fcs_corpus_url_pattern')),
		);
		foreach ( $fields as $f ) {
			if ( !$clickhouseEnabled && $f['path'][0] === 'clickhouse' ) continue; // not on the form: keep what is stored
			$val = isset($_POST[$f['key']]) ? trim((string) $_POST[$f['key']]) : '';
			if ( $val === '' ) {
				tt_fqsb_cfg_unset($newCfg, $f['path']);
			} else {
				tt_fqsb_cfg_set($newCfg, $f['path'], $val);
			}
		}
		$portFields = array(
			array('key' => 'cfg_fcs_port', 'path' => array('fcs', 'port')),
			array('key' => 'cfg_clickhouse_port', 'path' => array('clickhouse', 'port')),
		);
		foreach ( $portFields as $f ) {
			if ( !$clickhouseEnabled && $f['path'][0] === 'clickhouse' ) continue;
			$raw = isset($_POST[$f['key']]) ? trim((string) $_POST[$f['key']]) : '';
			if ( $raw === '' ) {
				tt_fqsb_cfg_unset($newCfg, $f['path']);
			} elseif ( ctype_digit($raw) ) {
				tt_fqsb_cfg_set($newCfg, $f['path'], intval($raw));
			} else {
				$cfgEditorError .= "Invalid port for " . htmlspecialchars((string)$f['key'], ENT_QUOTES, 'UTF-8') . ". ";
			}
		}
		$linksPortMapRaw = isset($_POST['cfg_links_port_map']) ? trim((string)$_POST['cfg_links_port_map']) : '';
		$linksPortMap = tt_fqsb_parse_port_map_text($linksPortMapRaw);
		if ( $linksPortMapRaw === '' ) {
			tt_fqsb_cfg_unset($newCfg, array('links', 'port_map'));
		} else {
			tt_fqsb_cfg_set($newCfg, array('links', 'port_map'), $linksPortMap);
		}
		if ( ! empty( $_POST['cfg_kontext_demo_mode'] ) ) {
			tt_fqsb_cfg_set( $newCfg, array( 'kontext', 'demo_mode' ), true );
		} else {
			tt_fqsb_cfg_unset( $newCfg, array( 'kontext', 'demo_mode' ) );
		}
		$newCfg = tt_fqsb_cfg_prune($newCfg);
		if ( $cfgEditorError === '' ) {
			$dir = dirname($cfgEditorPath);
			if ( !is_dir($dir) ) @mkdir($dir, 0775, true);
			$jsonOut = json_encode($newCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ( is_string($jsonOut) ) $jsonOut .= "\n";
			$okWrite = is_string($jsonOut) ? @file_put_contents($cfgEditorPath, $jsonOut) : false;
			if ( $okWrite === false ) {
				$ioErr = error_get_last();
				$ioMsg = is_array($ioErr) && isset($ioErr['message']) ? (string)$ioErr['message'] : '';
				$isPermIssue = false;
				if ( is_file($cfgEditorPath) ) {
					$isPermIssue = !is_writable($cfgEditorPath);
				} else {
					$isPermIssue = !is_writable($dir);
				}
				$cfgEditorError = "Could not write env-config file: " . htmlspecialchars($cfgEditorPath, ENT_QUOTES, 'UTF-8');
				if ( $isPermIssue ) {
					$cfgEditorError .= " (permission denied: web server user cannot write this location)";
				}
				if ( $ioMsg !== '' ) {
					$cfgEditorError .= ". " . htmlspecialchars($ioMsg, ENT_QUOTES, 'UTF-8');
				}
			} else {
				$cfgEditorNotice = "Saved env-config to <code>" . htmlspecialchars($cfgEditorPath, ENT_QUOTES, 'UTF-8') . "</code>.";
				$cfgEditorData = $newCfg;
				$envConfigPath = $cfgEditorPath;
			}
		}
	}

	$targetRoot = getcwd();
	$selectedCorpus = isset($_GET['corpus']) ? trim((string) $_GET['corpus']) : '';
	$currentCorpusOnly = empty($isshared);
	if ( $currentCorpusOnly ) {
		// Non-shared corpus pages may only inspect/control the current corpus context.
		$selectedCorpus = basename(rtrim(str_replace('\\', '/', (string)$targetRoot), '/'));
	}
	$availableCorpora = array();
	$fqsapp = tt_fqsb_fqs_bin();
	$fqsCfgBin = trim((string) tt_fqsb_cfg_get($cfgEditorData, array('fqs', 'bin'), ''));
	if ( $fqsapp === '' && $fqsCfgBin !== '' && is_file($fqsCfgBin) && is_executable($fqsCfgBin) ) {
		$fqsapp = $fqsCfgBin;
	}
	if ( !empty($isshared) && $fqsapp !== '' ) {
		$runtimeDbPath = function_exists('tt_fqsb_fqs_db_path_from_runtime_file') ? tt_fqsb_fqs_db_path_from_runtime_file() : '';
		$dbArg = trim((string)$runtimeDbPath) !== '' ? (' --db ' . escapeshellarg($runtimeDbPath)) : '';
		$listRaw = shell_exec(escapeshellarg($fqsapp) . ' corpora list' . $dbArg . ' 2>&1');
		$availableCorpora = tt_fqsb_decode_corpora_list($listRaw);
		if ( $selectedCorpus !== '' ) {
			$showRaw = shell_exec(escapeshellarg($fqsapp) . ' corpora show' . $dbArg . ' --id ' . escapeshellarg($selectedCorpus) . ' 2>&1');
			$showDec = is_string($showRaw) ? json_decode($showRaw, true) : null;
			if ( is_array($showDec) && !empty($showDec['project_root']) ) {
				$targetRoot = (string) $showDec['project_root'];
			}
		}
	}
	$fqsCtlNotice = '';
	$fqsCtlError = '';
	$kontextFixNotice = '';
	$kontextFixError = '';
	$kontextFixSteps = array();
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fqs_control_action']) ) {
		$ctl = trim((string) $_POST['fqs_control_action']);
		if ( $ctl === 'start' ) {
			$res = tt_fqsb_fqs_start($fqsapp, $cfgEditorData, $targetRoot);
			if ( !empty($res['ok']) ) $fqsCtlNotice = (string)($res['message'] ?? 'FQS started.');
			else $fqsCtlError = (string)($res['message'] ?? 'Could not start FQS.');
		} elseif ( $ctl === 'stop' ) {
			$res = tt_fqsb_fqs_stop($fqsapp, $cfgEditorData, $targetRoot);
			if ( !empty($res['ok']) ) $fqsCtlNotice = (string)($res['message'] ?? 'FQS stopped.');
			else $fqsCtlError = (string)($res['message'] ?? 'Could not stop FQS.');
		}
	}
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['fqs_kontext_apply'] )
		&& isset( $_POST['act'] ) && trim( (string) $_POST['act'] ) === 'kontext' ) {
		$ktOpts = array(
			'anonymous_visible' => ! empty( $_POST['kt_anonymous_visible'] ),
			'sentence_struct' => isset( $_POST['kt_sentence_struct'] ) ? trim( (string) $_POST['kt_sentence_struct'] ) : '',
		);
		$rq = isset( $_POST['kt_requestable'] ) ? trim( (string) $_POST['kt_requestable'] ) : '';
		if ( $rq === 'true' || $rq === 'false' ) {
			$ktOpts['requestable'] = $rq;
		} else {
			$ktOpts['requestable'] = null;
		}
		$resFix = tt_fqsb_kontext_apply_to_server( $targetRoot, $cfgEditorData, $selectedCorpus, $fqsapp, $ktOpts );
		$kontextFixSteps = isset( $resFix['steps'] ) && is_array( $resFix['steps'] ) ? $resFix['steps'] : array();
		if ( ! empty( $resFix['ok'] ) ) {
			$kontextFixNotice = (string) ( $resFix['message'] ?? 'KonText server settings applied.' );
		} else {
			$kontextFixError = (string) ( $resFix['message'] ?? 'KonText apply failed.' );
		}
	}
	$fqsRuntime = tt_fqsb_fqs_probe_runtime( $fqsapp, $cfgEditorData, $targetRoot );
	$fqsCliVersion = '';
	$fqsServerVersion = '';
	$fqsStatusVersionMismatch = false;
	if ( isset($fqsRuntime['health']) && is_array($fqsRuntime['health']) ) {
		$fqsServerVersion = trim((string)($fqsRuntime['health']['version'] ?? ''));
	}
	if ( $fqsapp !== '' ) {
		$fqsVerRaw = trim((string) shell_exec(escapeshellarg($fqsapp) . ' --version 2>/dev/null'));
		if ( preg_match('/\\b([0-9]+\\.[0-9]+\\.[0-9]+(?:[-+][A-Za-z0-9._-]+)?)\\b/', $fqsVerRaw, $mVer) ) {
			$fqsCliVersion = (string)$mVer[1];
		}
	}
	$fqsStatusVersionMismatch = (
		$fqsCliVersion !== ''
		&& $fqsServerVersion !== ''
		&& $fqsCliVersion !== $fqsServerVersion
	);

	$ktCorpusIdUi = trim( (string) tt_fqsb_kontext_detect_corpus_name( $targetRoot, $selectedCorpus, $fqsapp ) );
	$ktSsGuess = tt_fqsb_kontext_registry_sentence_struct_guess( $targetRoot, $ktCorpusIdUi );
	$ktCorpusMetaUi = tt_fqsb_kontext_corpus_meta( $cfgEditorData, $ktCorpusIdUi );
	$ktCorpusHeadingUi = $ktCorpusIdUi !== '' ? $ktCorpusIdUi : 'unknown';
	$ktCorpusNameUi = trim((string)($ktCorpusMetaUi['name'] ?? ''));
	if ( $ktCorpusNameUi !== '' && strcasecmp($ktCorpusNameUi, $ktCorpusHeadingUi) !== 0 ) {
		$ktCorpusHeadingUi .= ' (' . $ktCorpusNameUi . ')';
	}

	$fqsbNavCommon = array_filter(
		array(
			'mode'       => $envAction,
			'env_config' => $envConfigPath !== '' ? $envConfigPath : null,
			'corpus'     => $selectedCorpus !== '' ? $selectedCorpus : null,
			'debug'      => $debugEnabled ? '1' : null,
		)
	);
	$urlChecks   = htmlspecialchars( tt_fqsb_module_url( $fqsbBackendsAction, array_merge( $fqsbNavCommon, array( 'act' => 'status' ) ) ), ENT_QUOTES, 'UTF-8' );
	$urlLogs     = htmlspecialchars( tt_fqsb_module_url( $fqsbBackendsAction, array_merge( $fqsbNavCommon, array( 'act' => 'logs' ) ) ), ENT_QUOTES, 'UTF-8' );
	$urlEditEnv  = htmlspecialchars( tt_fqsb_module_url( $fqsbBackendsAction, array_merge( $fqsbNavCommon, array( 'act' => 'edit' ) ) ), ENT_QUOTES, 'UTF-8' );
	$ktModuleUrl = htmlspecialchars(
		tt_fqsb_module_url( $fqsbBackendsAction, array_merge( $fqsbNavCommon, array( 'act' => 'kontext' ) ) ),
		ENT_QUOTES,
		'UTF-8'
	);
	$postActHidden = 'status';
	if ( $isLogsAct ) {
		$postActHidden = 'logs';
	} elseif ( $isKontextAct ) {
		$postActHidden = 'kontext';
	}
	$formSameModuleQuery = array();
	if ( $debugEnabled ) {
		$formSameModuleQuery['debug'] = '1';
	}
	if ( $isKontextAct ) {
		$formSameModuleQuery['act'] = 'kontext';
	}
	$formSameModuleUrl = htmlspecialchars(
		tt_fqsb_module_url( (string) $action, $formSameModuleQuery ),
		ENT_QUOTES,
		'UTF-8'
	);
	$formEnvEditUrl = htmlspecialchars(
		tt_fqsb_module_url( (string) $action, array_merge( $fqsbNavCommon, array( 'act' => 'edit' ) ) ),
		ENT_QUOTES,
		'UTF-8'
	);

	$ktServerRunning = tt_fqsb_kontext_server_running( $cfgEditorData );
	$ktDemoModeUi = ! empty( tt_fqsb_cfg_get( $cfgEditorData, array( 'kontext', 'demo_mode' ), false ) );
	$maintext .= "<p><small>"
		. "<a href='" . $urlChecks . "'>Checks</a> | "
		. "<a href='" . $urlLogs . "'>Logs</a> | "
		. "<a href='" . $urlEditEnv . "'>Edit env-config</a>";
	if ( $ktServerRunning && ! $isKontextAct ) {
		$maintext .= " | <a href='" . $ktModuleUrl . "'>KonText server</a>";
	}
	if ( $isKontextAct ) {
		$maintext .= " | <strong>KonText server</strong>";
	}
	$maintext .= "</small></p>";
	if ( $isKontextAct ) {
		if ( $ktServerRunning ) {
			$ktIntro = 'Configure <code>kontext.url</code> / <code>kontext.config_xml</code> under Backend environments → Edit env-config, then use <strong>Apply KonText server settings</strong> below.';
			$maintext .= "<p><small>Making a corpus usable in <strong>KonText</strong> is controlled on the <strong>KonText host</strong>: copy the Manatee registry into <code>manatee_registry</code>, set <code>corplist.xml</code> (e.g. <code>sentence_struct</code>, optional <code>requestable</code>), and if KonText uses <code>redis_db</code>, maintain the anonymous user corpus list. " . $ktIntro . " "
				. ( $ktDemoModeUi
					? "<em>Demo mode</em> is on: <code>flexicorp env</code> treats anonymous HTTP/ACL visibility as a hard check."
					: "Restricted corpora (not listed for anonymous users) are normal in production; keep <em>demo mode</em> off in env-config unless this is a public demo stack." )
				. " This is independent of the FQS catalogue.</small></p>";
		} else {
			$maintext .= "<p><small>KonText server shortcut appears here after a server is detected (HTTP probe to <code>kontext.url</code> or <code>links.kontext_live_url</code> → <code>/corpora/corplist</code>). Set one of those in <strong>Edit env-config</strong> to match your running KonText instance.</small></p>";
		}
	}

	if ( !$isEditAct ) {
	$maintext .= "<h3>FQS service control</h3>";
	if ( $fqsCtlNotice !== '' ) {
		$maintext .= "<p class='ok'>" . htmlspecialchars($fqsCtlNotice, ENT_QUOTES, 'UTF-8') . "</p>";
	}
	if ( $fqsCtlError !== '' ) {
		$maintext .= "<p class='warning'>" . htmlspecialchars($fqsCtlError, ENT_QUOTES, 'UTF-8') . "</p>";
	}
	if ( $isKontextAct ) {
	if ( $kontextFixNotice !== '' ) {
		$maintext .= "<p class='ok'>" . htmlspecialchars($kontextFixNotice, ENT_QUOTES, 'UTF-8') . "</p>";
	}
	if ( $kontextFixError !== '' ) {
		$maintext .= "<p class='warning'>" . htmlspecialchars($kontextFixError, ENT_QUOTES, 'UTF-8') . "</p>";
	}
	if ( !empty($kontextFixSteps) ) {
		$maintext .= "<ul>";
		foreach ( $kontextFixSteps as $st ) {
			$maintext .= "<li><small>" . htmlspecialchars((string)$st, ENT_QUOTES, 'UTF-8') . "</small></li>";
		}
		$maintext .= "</ul>";
	}
	}
	$fqsBinaryTxt = trim((string)($fqsRuntime['fqs_bin'] ?? ''));
	$fqsUrlTxt = (string)($fqsRuntime['url'] ?? 'http://127.0.0.1:8787');
	$fqsRunning = !empty($fqsRuntime['running']);
	$fqsPidTxt = !empty($fqsRuntime['managed_pid']) ? (' pid=' . intval($fqsRuntime['managed_pid'])) : '';
	$maintext .= "<p><small>Binary: " . ( $fqsBinaryTxt !== '' ? "<code>" . htmlspecialchars($fqsBinaryTxt, ENT_QUOTES, 'UTF-8') . "</code>" : "not found" )
		. " | URL: <code>" . htmlspecialchars($fqsUrlTxt, ENT_QUOTES, 'UTF-8') . "</code>"
		. " | Status: " . ( $fqsRunning ? "<span class='ok'>running</span>" : "<span class='warning'>not running</span>" )
		. htmlspecialchars($fqsPidTxt, ENT_QUOTES, 'UTF-8')
		. "</small></p>";
	$maintext .= "<p><small>FQS versions: server <code>"
		. htmlspecialchars($fqsServerVersion !== '' ? $fqsServerVersion : 'unknown', ENT_QUOTES, 'UTF-8')
		. "</code> | cli <code>"
		. htmlspecialchars($fqsCliVersion !== '' ? $fqsCliVersion : 'unknown', ENT_QUOTES, 'UTF-8')
		. "</code>"
		. ( $fqsStatusVersionMismatch ? " <span class='warning'>(mismatch)</span>" : "" )
		. "</small></p>";
	$maintext .= "<form method='post' action='" . $formSameModuleUrl . "' style='margin:0 0 1em 0'>"
		. "<input type='hidden' name='action' value='" . htmlspecialchars((string)$action, ENT_QUOTES, 'UTF-8') . "' />"
		. "<input type='hidden' name='act' value='" . htmlspecialchars((string) $postActHidden, ENT_QUOTES, 'UTF-8') . "' />"
		. "<input type='hidden' name='mode' value='" . htmlspecialchars((string)$envAction, ENT_QUOTES, 'UTF-8') . "' />"
		. "<input type='hidden' name='env_config' value='" . htmlspecialchars((string)$envConfigPath, ENT_QUOTES, 'UTF-8') . "' />";
	foreach ( $selectedBackends as $bkOpt ) {
		$maintext .= "<input type='hidden' name='backend_" . htmlspecialchars((string)$bkOpt, ENT_QUOTES, 'UTF-8') . "' value='1' />";
	}
	if ( $selectedCorpus !== '' ) {
		$maintext .= "<input type='hidden' name='corpus' value='" . htmlspecialchars((string)$selectedCorpus, ENT_QUOTES, 'UTF-8') . "' />";
	}
	if ( $debugEnabled ) {
		$maintext .= "<input type='hidden' name='debug' value='1' />";
	}
	$startDisabled = ( $fqsBinaryTxt === '' || $fqsRunning ) ? " disabled='disabled'" : '';
	$stopDisabled = $fqsRunning ? "" : " disabled='disabled'";
	$maintext .= "<button type='submit' name='fqs_control_action' value='start'" . $startDisabled . ">Start FQS</button> "
		. "<button type='submit' name='fqs_control_action' value='stop'" . $stopDisabled . ">Stop FQS</button> "
		. "<small>Starts <code>fqs serve</code> as the web-server user for quick local workflow tests.</small>"
		. "</form>";
	if ( $isKontextAct ) {
	$maintext .= "<h4>KonText server — " . htmlspecialchars($ktCorpusHeadingUi, ENT_QUOTES, 'UTF-8') . "</h4>"
		. "<form method='post' action='" . $formSameModuleUrl . "' style='margin:0 0 1em 0'>"
		. "<input type='hidden' name='action' value='" . htmlspecialchars((string)$action, ENT_QUOTES, 'UTF-8') . "' />"
		. "<input type='hidden' name='act' value='" . htmlspecialchars((string) $postActHidden, ENT_QUOTES, 'UTF-8') . "' />"
		. "<input type='hidden' name='mode' value='" . htmlspecialchars((string)$envAction, ENT_QUOTES, 'UTF-8') . "' />"
		. "<input type='hidden' name='env_config' value='" . htmlspecialchars((string)$envConfigPath, ENT_QUOTES, 'UTF-8') . "' />";
	foreach ( $selectedBackends as $bkOpt ) {
		$maintext .= "<input type='hidden' name='backend_" . htmlspecialchars((string)$bkOpt, ENT_QUOTES, 'UTF-8') . "' value='1' />";
	}
	if ( $selectedCorpus !== '' ) {
		$maintext .= "<input type='hidden' name='corpus' value='" . htmlspecialchars((string)$selectedCorpus, ENT_QUOTES, 'UTF-8') . "' />";
	}
	if ( $debugEnabled ) {
		$maintext .= "<input type='hidden' name='debug' value='1' />";
	}
	$maintext .= "<table cellpadding='6'><tr><th align='left'>KonText corpus id</th><td><code>" . htmlspecialchars( $ktCorpusIdUi, ENT_QUOTES, 'UTF-8' ) . "</code></td></tr>"
		. "<tr><th align='left'><code>sentence_struct</code></th><td><input type='text' name='kt_sentence_struct' size='12' value='" . htmlspecialchars( $ktSsGuess, ENT_QUOTES, 'UTF-8' ) . "' /> "
		. "<small>KonText sentence unit: usually <code>s</code> or <code>seg</code> from your registry <code>STRUCTLIST</code> (not <code>DOCSTRUCTURE</code>).</small></td></tr>"
		. "<tr><th align='left'><code>requestable</code></th><td><select name='kt_requestable'>"
		. "<option value='' selected='selected'>leave unchanged</option>"
		. "<option value='true'>true</option>"
		. "<option value='false'>false</option>"
		. "</select> <small>KonText optional attribute on the <code>&lt;corpus&gt;</code> element.</small></td></tr>"
		. "<tr><th align='left'>Anonymous users</th><td><label><input type='checkbox' name='kt_anonymous_visible' value='1' checked='checked' /> Include this corpus in the Redis anonymous ACL (<code>user:&lt;anon&gt;:corpora</code>) when KonText uses <code>redis_db</code></label>"
		. "<br/><small>Uncheck for corpora that should not appear for anonymous users (normal for many production setups). In <em>demo mode</em>, flexicorp env checks expect anonymous visibility.</small></td></tr>"
		. "<tr><td></td><td><button type='submit' name='fqs_kontext_apply' value='1'>Apply KonText server settings</button> "
		. "<small>Pushes registry + <code>corplist.xml</code> + Redis ACL per this form.</small></td></tr></table>"
		. "</form>";
	}
	if ( $isStatusAct ) {
	$maintext .= "<form method='get' action='" . $formSameModuleUrl . "' style='margin:0 0 1em 0'>"
		. "<input type='hidden' name='action' value='" . htmlspecialchars((string)$action, ENT_QUOTES, 'UTF-8') . "' />"
		. "<input type='hidden' name='act' value='status' />"
		. ( $debugEnabled ? "<input type='hidden' name='debug' value='1' />" : "" )
		. "<table cellpadding='6'>"
		. "<tr><th align='left'>Mode</th><td><select name='mode'>";
	foreach ( array('status' => 'status', 'check-corpus' => 'check-corpus', 'list' => 'list') as $k => $v ) {
		$sel = ( $envAction === $k ) ? " selected='selected'" : '';
		$maintext .= "<option value='" . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . "'" . $sel . ">"
			. htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . "</option>";
	}
	$maintext .= "</select></td></tr>"
		. "<tr><th align='left' style='vertical-align:top'>Backends</th><td>";
	foreach ( array('backend' => 'Backends', 'frontend' => 'Frontends') as $kindKey => $kindLabel ) {
		$maintext .= "<div style='margin-bottom:0.35em'><strong>" . htmlspecialchars($kindLabel, ENT_QUOTES, 'UTF-8') . ":</strong> ";
		foreach ( $backendOptions as $bkOpt ) {
			$meta = isset($backendMeta[$bkOpt]) && is_array($backendMeta[$bkOpt]) ? $backendMeta[$bkOpt] : array();
			$bkKind = isset($meta['kind']) ? (string) $meta['kind'] : 'backend';
			if ( $bkKind !== $kindKey ) continue;
			$checked = in_array($bkOpt, $selectedBackends, true) ? " checked='checked'" : '';
			$deps = isset($meta['depends_on']) && is_array($meta['depends_on']) ? $meta['depends_on'] : array();
			$depHint = isset($meta['depends_hint']) ? trim((string)$meta['depends_hint']) : '';
			$depTxt = empty($deps) ? '' : 'uses: ' . implode(', ', $deps);
			$titleParts = array();
			if ( $depTxt !== '' ) $titleParts[] = $depTxt;
			if ( $depHint !== '' ) $titleParts[] = $depHint;
			$titleAttr = empty($titleParts) ? '' : " title='" . htmlspecialchars(implode(" | ", $titleParts), ENT_QUOTES, 'UTF-8') . "'";
			$maintext .= "<label style='display:inline-block;margin-right:1em'" . $titleAttr . "><input type='checkbox' name='backend_" 
				. htmlspecialchars($bkOpt, ENT_QUOTES, 'UTF-8') . "' value='1'" . $checked . " /> "
				. htmlspecialchars($bkOpt, ENT_QUOTES, 'UTF-8')
				. "</label>";
		}
		$maintext .= "</div>";
	}
	$maintext .= "</td></tr>"
		. "<tr><th align='left'>Env config</th><td><input type='text' name='env_config' size='60' value='"
		. htmlspecialchars($envConfigPath, ENT_QUOTES, 'UTF-8')
		. "' placeholder='/etc/flexicorp/env-config.json' /></td></tr>";

	if ( !empty($isshared) ) {
		$maintext .= "<tr><th align='left'>Corpus</th><td><select name='corpus'><option value=''>Current folder</option>";
		foreach ( $availableCorpora as $row ) {
			if ( !is_array($row) ) continue;
			$id = isset($row['id']) ? (string) $row['id'] : '';
			if ( $id === '' ) continue;
			$lab = isset($row['label']) ? (string) $row['label'] : $id;
			$sel = ( $selectedCorpus !== '' && $selectedCorpus === $id ) ? " selected='selected'" : '';
			$maintext .= "<option value='" . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . "'" . $sel . ">"
				. htmlspecialchars($id . " - " . $lab, ENT_QUOTES, 'UTF-8') . "</option>";
		}
		$maintext .= "</select> <small>shared/global mode can inspect any registered corpus</small></td></tr>";
	} else {
		$maintext .= "<tr><th align='left'>Corpus</th><td><code>"
			. htmlspecialchars((string)$selectedCorpus, ENT_QUOTES, 'UTF-8')
			. "</code> <small>non-shared mode is limited to the current corpus only</small></td></tr>";
	}

	$maintext .= "<tr><td></td><td><button type='submit'>Run checks</button></td></tr></table></form>";
	}
	}
	if ( $isEditAct && $cfgEditorNotice !== '' ) {
		$maintext .= "<p class='ok'>" . $cfgEditorNotice . "</p>";
	}
	if ( $isEditAct && $cfgEditorError !== '' ) {
		$maintext .= "<p class='warning'>" . $cfgEditorError . "</p>";
	}
	$cfgKontextUrl = (string) tt_fqsb_cfg_get($cfgEditorData, array('kontext', 'url'), '');
	$cfgKontextXml = (string) tt_fqsb_cfg_get($cfgEditorData, array('kontext', 'config_xml'), '');
	$cfgKontextDemo = ! empty( tt_fqsb_cfg_get( $cfgEditorData, array( 'kontext', 'demo_mode' ), false ) );
	$cfgFcsUrl = (string) tt_fqsb_cfg_get($cfgEditorData, array('fcs', 'url'), '');
	$cfgFcsHost = (string) tt_fqsb_cfg_get($cfgEditorData, array('fcs', 'host'), '');
	$cfgFcsPort = (string) tt_fqsb_cfg_get($cfgEditorData, array('fcs', 'port'), '');
	$cfgBlacklabUrl = (string) tt_fqsb_cfg_get($cfgEditorData, array('blacklab', 'url'), '');
	$cfgFqsBin = (string) tt_fqsb_cfg_get($cfgEditorData, array('fqs', 'bin'), '');
	$cfgClickHost = (string) tt_fqsb_cfg_get($cfgEditorData, array('clickhouse', 'host'), '');
	$cfgClickPort = (string) tt_fqsb_cfg_get($cfgEditorData, array('clickhouse', 'port'), '');
	$cfgClickDb = (string) tt_fqsb_cfg_get($cfgEditorData, array('clickhouse', 'database'), '');
	$cfgPmlServerUrl = (string) tt_fqsb_cfg_get($cfgEditorData, array('pmltq', 'server', 'url'), '');
	$cfgLinksKontextUrl = (string) tt_fqsb_cfg_get($cfgEditorData, array('links', 'kontext_live_url'), '');
	$cfgLinksFcsUrl = (string) tt_fqsb_cfg_get($cfgEditorData, array('links', 'fcs_live_url'), '');
	$cfgLinksPortMap = tt_fqsb_cfg_get($cfgEditorData, array('links', 'port_map'), array());
	$cfgLinksPortMapText = '';
	if ( is_array($cfgLinksPortMap) ) {
		$parts = array();
		foreach ( $cfgLinksPortMap as $k => $v ) {
			$ks = trim((string)$k);
			$vs = trim((string)$v);
			if ( ctype_digit($ks) && ctype_digit($vs) ) $parts[] = $ks . ':' . $vs;
		}
		$cfgLinksPortMapText = implode(', ', $parts);
	}
	if ( $isEditAct ) {
	$maintext .= "<h3>Env config editor</h3>"
		. "<form method='post' action='" . $formEnvEditUrl . "' style='margin:0 0 1em 0'>"
		. "<input type='hidden' name='action' value='" . htmlspecialchars((string)$action, ENT_QUOTES, 'UTF-8') . "' />"
		. "<input type='hidden' name='act' value='edit' />"
		. ( $debugEnabled ? "<input type='hidden' name='debug' value='1' />" : "" )
		. "<input type='hidden' name='mode' value='" . htmlspecialchars((string)$envAction, ENT_QUOTES, 'UTF-8') . "' />"
		. "<table cellpadding='6'>"
		. "<tr><th align='left'>Config file</th><td><input type='text' name='env_config_editor_path' size='70' value='"
		. htmlspecialchars($cfgEditorPath, ENT_QUOTES, 'UTF-8') . "' /></td></tr>"
		. "<tr id='kontext-server'><th align='left' style='vertical-align:top'>KonText server</th><td>"
		. "<strong>Base URL</strong> (for <code>flexicorp env</code> HTTP checks) "
		. "<input type='text' name='cfg_kontext_url' size='40' value='" . htmlspecialchars($cfgKontextUrl, ENT_QUOTES, 'UTF-8') . "' placeholder='http://127.0.0.1:8080' /><br/>"
		. "<strong>config.xml</strong> on the KonText host (contains <code>manatee_registry</code>, corplist path, <code>plugins/db</code> / Redis for anonymous ACL) "
		. "<input type='text' name='cfg_kontext_config_xml' size='70' value='" . htmlspecialchars($cfgKontextXml, ENT_QUOTES, 'UTF-8') . "' placeholder='/opt/kontext/conf/config.xml' />"
		. "<br/><label><input type='checkbox' name='cfg_kontext_demo_mode' value='1'" . ( $cfgKontextDemo ? " checked='checked'" : "" ) . " /> <strong>Demo mode</strong></label> "
		. "<small>When enabled, <code>flexicorp env</code> requires HTTP/Redis anonymous visibility checks to pass (stack for public demos). Leave off for real deployments where restricted corpora are expected.</small>"
		. "<br/><small>After saving, open <a href='" . $ktModuleUrl . "'>KonText server</a> (<code>act=kontext</code>) and use <strong>Apply KonText server settings</strong> to push this TEITOK corpus to that KonText (registry + corplist + optional Redis ACL).</small>"
		. "</td></tr>"
		. "<tr><th align='left'>FCS</th><td>"
		. "URL <input type='text' name='cfg_fcs_url' size='34' value='" . htmlspecialchars($cfgFcsUrl, ENT_QUOTES, 'UTF-8') . "' /> "
		. "host <input type='text' name='cfg_fcs_host' size='20' value='" . htmlspecialchars($cfgFcsHost, ENT_QUOTES, 'UTF-8') . "' /> "
		. "port <input type='text' name='cfg_fcs_port' size='6' value='" . htmlspecialchars($cfgFcsPort, ENT_QUOTES, 'UTF-8') . "' />"
		. "</td></tr>"
		. "<tr><th align='left'>BlackLab</th><td>"
		. "URL <input type='text' name='cfg_blacklab_url' size='52' value='" . htmlspecialchars($cfgBlacklabUrl, ENT_QUOTES, 'UTF-8') . "' />"
		. "</td></tr>"
		. "<tr><th align='left'>FQS</th><td>"
		. "binary <input type='text' name='cfg_fqs_bin' size='52' value='" . htmlspecialchars($cfgFqsBin, ENT_QUOTES, 'UTF-8') . "' />"
		. "</td></tr>"
		. ( !$clickhouseEnabled ? '' : "<tr><th align='left'>ClickHouse</th><td>"
		. "host <input type='text' name='cfg_clickhouse_host' size='20' value='" . htmlspecialchars($cfgClickHost, ENT_QUOTES, 'UTF-8') . "' /> "
		. "port <input type='text' name='cfg_clickhouse_port' size='6' value='" . htmlspecialchars($cfgClickPort, ENT_QUOTES, 'UTF-8') . "' /> "
		. "database <input type='text' name='cfg_clickhouse_database' size='18' value='" . htmlspecialchars($cfgClickDb, ENT_QUOTES, 'UTF-8') . "' />"
		. "<br/><small>Same database for ClickQL, direct SQL, and PML-TQ→SQL translation (only the query language differs).</small>"
		. "</td></tr>" )
		. "<tr><th align='left'>PML-TQ (native HTTP)</th><td>"
		. "server URL <input type='text' name='cfg_pmltq_server_url' size='52' value='" . htmlspecialchars($cfgPmlServerUrl, ENT_QUOTES, 'UTF-8') . "' placeholder='http://127.0.0.1:19100' /> "
		. "<small>Native PML-TQ API (PostgreSQL). Expected treebank id comes from the TEITOK corpus" . ( $clickhouseEnabled ? " / ClickHouse DB name" : "" ) . " (same as other backends), not from here.</small>"
		. "</td></tr>"
		. "<tr><th align='left'>Live link mapping</th><td>"
		. "KonText URL <input type='text' name='cfg_links_kontext_live_url' size='36' value='" . htmlspecialchars($cfgLinksKontextUrl, ENT_QUOTES, 'UTF-8') . "' /> "
		. "FCS URL <input type='text' name='cfg_links_fcs_live_url' size='36' value='" . htmlspecialchars($cfgLinksFcsUrl, ENT_QUOTES, 'UTF-8') . "' /><br/>"
		. "KonText corpus pattern <input type='text' name='cfg_links_kontext_corpus_url_pattern' size='36' value='" . htmlspecialchars((string) tt_fqsb_cfg_get($cfgEditorData, array('links', 'kontext_corpus_url_pattern'), ''), ENT_QUOTES, 'UTF-8') . "' placeholder='/query?corpname={id}' /> "
		. "FCS corpus pattern <input type='text' name='cfg_links_fcs_corpus_url_pattern' size='36' value='" . htmlspecialchars((string) tt_fqsb_cfg_get($cfgEditorData, array('links', 'fcs_corpus_url_pattern'), ''), ENT_QUOTES, 'UTF-8') . "' placeholder='/fcs?operation=searchRetrieve&x-fcs-context={id}&query=...' /> "
		. "<small>Use {id} placeholder for corpus id.</small><br/>"
		. "Port map <input type='text' name='cfg_links_port_map' size='34' value='" . htmlspecialchars($cfgLinksPortMapText, ENT_QUOTES, 'UTF-8') . "' placeholder='8080:18080,8787:18787' /> "
		. "<small>Map internal probe ports to public ports; used for auto link rewriting.</small>"
		. "</td></tr>"
		. "<tr><td></td><td><button type='submit' name='save_env_config' value='1'>Save env-config.json</button> <small>Leave fields empty to unset known keys.</small></td></tr>"
		. "</table></form>";
	}
	if ( !empty($debug) ) {
		$maintext .= "<p><small>Env config search order (same as <code>flexicorp</code> <code>load_env_config</code>): "
			. "<code>FLEXICORP_ENV_CONFIG</code>, <code>/etc/flexicorp/env-config.json</code>, "
			. "<code>~/.config/flexicorp/env-config.json</code>, <code>~/.flexicorp/env-config.json</code>, "
			. "<code>/tmp/flexicorp/env-config.json</code>.</small></p>";
		$maintext .= "<p><small>Debug mode enabled: extra backend adapters are visible (e.g. " . ( $clickhouseEnabled ? "<code>clickhouse</code>, " : "" ) . "<code>teitokxml</code>).</small></p>";
	}

	if ( $isStatusAct ) {
	$flexicorpCmd = tt_fqsb_flexicorp_cmd();
	if ( $flexicorpCmd === '' ) {
		$maintext .= "<p class=warning>Could not locate runnable <code>flexicorp</code> (binary or <code>python -m flexicorp</code> in known venvs).</p>";
		return;
	}

	$parts = array();
	$parts[] = $flexicorpCmd;
	$parts[] = 'env';
	$parts[] = escapeshellarg($envAction);
	$parts[] = '--api';
	$parts[] = '--project-root';
	$parts[] = escapeshellarg((string)$targetRoot);
	$parts[] = '--folder';
	$parts[] = escapeshellarg((string)$targetRoot);
	$parts[] = '--teitok';
	$parts[] = 'yes';
	$parts[] = '--env-backends';
	$parts[] = escapeshellarg($envBackends);
	// Match flexicorp load_env_config(): if the user left the field blank, pass --env-config only
	// for the first *existing* file in the candidate list; if none exist, omit --env-config so the
	// CLI searches the full list (including /tmp/flexicorp/env-config.json).
	$envConfigCli = '';
	if ( $envConfigInput !== '' ) {
		$envConfigCli = $envConfigPath;
	} else {
		foreach ( tt_fqsb_env_config_candidates() as $cand ) {
			if ( is_file($cand) ) {
				$envConfigCli = $cand;
				break;
			}
		}
	}
	if ( $envConfigCli !== '' ) {
		$parts[] = '--env-config';
		$parts[] = escapeshellarg($envConfigCli);
	}
	if ( $envAction === 'check-corpus' && $selectedCorpus !== '' ) {
		$parts[] = '--corpus-id';
		$parts[] = escapeshellarg($selectedCorpus);
	}
	$cmd = implode(' ', $parts) . ' 2>&1';
	$raw = shell_exec($cmd);
	$env = is_string($raw) ? json_decode($raw, true) : null;
	$res = ( is_array($env) && isset($env['done']['result']) && is_array($env['done']['result']) ) ? $env['done']['result'] : null;

	if ( !empty($debug) ) {
		$maintext .= "<h3>Debug details</h3>";
		$maintext .= "<p><small><code>" . htmlspecialchars($cmd, ENT_QUOTES, 'UTF-8') . "</code></small></p>";
		if ( isset($res['env_config']) && is_array($res['env_config']) ) {
			$cfgLoaded = !empty($res['env_config']['loaded']);
			$cfgPath = isset($res['env_config']['path']) ? (string) $res['env_config']['path'] : '';
			$maintext .= "<p><small>Env config: " . ( $cfgLoaded ? ("loaded from <code>" . htmlspecialchars($cfgPath, ENT_QUOTES, 'UTF-8') . "</code>") : "not found (using defaults)") . "</small></p>";
		}
		$maintext .= "<p><strong>Target root:</strong> <code>" . htmlspecialchars((string)$targetRoot, ENT_QUOTES, 'UTF-8') . "</code></p>";
	}

	if ( !is_array($res) ) {
		$maintext .= "<p class=warning>Could not parse flexicorp env response.</p>";
		if ( !empty($debug) ) {
			$maintext .= "<pre style='white-space:pre-wrap;max-height:24em;overflow:auto;background:#f8f8f8;padding:8px;border:1px solid #ccc'>"
				. htmlspecialchars((string)$raw, ENT_QUOTES, 'UTF-8') . "</pre>";
		}
		return;
	}

	$ok = !empty($res['ok']);
	$fails = isset($res['failed_backends']) && is_array($res['failed_backends']) ? implode(', ', $res['failed_backends']) : '';
	if ( !empty($debug) ) {
		$maintext .= "<p>" . ( $ok ? "<span class='ok'>All selected backend environments look available.</span>" : "<span class='warning'>Some backend environments need attention.</span>" ) . "</p>";
		if ( $fails !== '' ) {
			$maintext .= "<p><small>Unavailable: " . htmlspecialchars($fails, ENT_QUOTES, 'UTF-8') . "</small></p>";
		}
	}

	$rows = isset($res['backends']) && is_array($res['backends']) ? $res['backends'] : array();
	if ( !empty($rows) ) {
		foreach ( array('backend' => 'Backends', 'frontend' => 'Frontends') as $kindKey => $kindLabel ) {
			$bucket = array();
			foreach ( $rows as $name => $row ) {
				$rowKind = is_array($row) && isset($row['kind']) ? (string) $row['kind'] : '';
				if ( $rowKind === '' && isset($backendMeta[$name]['kind']) ) {
					$rowKind = (string) $backendMeta[$name]['kind'];
				}
				if ( $rowKind === $kindKey ) {
					$bucket[$name] = $row;
				}
			}
			if ( empty($bucket) ) continue;
			$maintext .= "<h3>" . htmlspecialchars($kindLabel, ENT_QUOTES, 'UTF-8') . "</h3>";
			$showDeps = ( $kindKey === 'frontend' );
			$maintext .= "<table><tr><th>Name<th>Available";
			if ( $showDeps ) {
				$maintext .= "<th>Depends on backend(s)";
			}
			$maintext .= "<th>Reason<th>Checks</tr>";
			foreach ( $bucket as $name => $row ) {
				$avail = ( is_array($row) && !empty($row['available']) ) ? 'yes' : 'no';
				$reason = is_array($row) && isset($row['reason']) ? (string) $row['reason'] : '';
				$deps = is_array($row) && isset($row['depends_on_backends']) && is_array($row['depends_on_backends']) ? $row['depends_on_backends'] : array();
				$depTxt = empty($deps) ? '-' : implode(', ', $deps);
				$nameText = (string) $name;
				$nameHtml = htmlspecialchars($nameText, ENT_QUOTES, 'UTF-8');
				if ( $kindKey === 'frontend' && strtolower($nameText) !== 'teitok' ) {
					$liveUrl = tt_fqsb_frontend_live_url($nameText, $row, $cfgEditorData, $selectedCorpus);
					if ( $liveUrl !== '' ) {
						$nameHtml = "<a href='" . htmlspecialchars($liveUrl, ENT_QUOTES, 'UTF-8')
							. "' target='_blank' rel='noopener noreferrer' title='Open live frontend'>"
							. $nameHtml . "</a>";
					}
				}
				$checkText = '';
				if ( is_array($row) && isset($row['checks']) && is_array($row['checks']) ) {
					$pairs = array();
					foreach ( $row['checks'] as $ck => $cv ) {
						if ( is_array($cv) && isset($cv['ok']) ) {
							$pairs[] = $ck . '=' . ( $cv['ok'] ? 'ok' : 'fail' );
						}
					}
					$checkText = implode(', ', $pairs);
				}
				$maintext .= "<tr><td>" . $nameHtml . "</td>"
					. "<td>" . htmlspecialchars($avail, ENT_QUOTES, 'UTF-8') . "</td>"
					. ( $showDeps ? ("<td>" . htmlspecialchars($depTxt, ENT_QUOTES, 'UTF-8') . "</td>") : "" )
					. "<td>" . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "</td>"
					. "<td><small>" . htmlspecialchars($checkText, ENT_QUOTES, 'UTF-8') . "</small></td></tr>";
			}
			$maintext .= "</table>";
		}
	}

	if ( !empty($debug) ) {
		$maintext .= "<h3>Raw response</h3><pre style='white-space:pre-wrap;max-height:28em;overflow:auto;background:#f8f8f8;padding:8px;border:1px solid #ccc'>"
			. htmlspecialchars(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8')
			. "</pre>";
	}
	}

	if ( $isLogsAct ) {
		$fqsDbPath = '';
		if ( isset($fqsRuntime['health']) && is_array($fqsRuntime['health']) ) {
			$fqsDbPath = trim((string)($fqsRuntime['health']['db_path'] ?? ''));
		}
		// Public /health no longer exposes db_path; prefer fqs-http.json sidecar.
		if ( $fqsDbPath === '' && function_exists('tt_fqsb_fqs_db_path_from_runtime_file') ) {
			$fqsDbPath = trim((string) tt_fqsb_fqs_db_path_from_runtime_file());
		}
		$reidxOverview = tt_fqsb_fqs_reindex_overview($fqsapp, 60, 200, $fqsDbPath, $selectedCorpus);
		$maintext .= "<h3>FQS reindex queue/history</h3>";
		if ( $fqsDbPath !== '' ) {
			$maintext .= "<p><small>Using FQS DB <code>" . htmlspecialchars($fqsDbPath, ENT_QUOTES, 'UTF-8') . "</code> (from FQS /health or fqs-http.json).</small></p>";
		}
		if ( empty($reidxOverview['ok']) ) {
			$maintext .= "<p class='warning'>Could not read FQS reindex queue/history: "
				. htmlspecialchars((string)$reidxOverview['error'], ENT_QUOTES, 'UTF-8') . "</p>";
		} else {
			$qRows = isset($reidxOverview['queue']) && is_array($reidxOverview['queue']) ? $reidxOverview['queue'] : array();
			$lastIx = isset($reidxOverview['last_indexed']) && is_array($reidxOverview['last_indexed']) ? $reidxOverview['last_indexed'] : array();
			$lastIxByBackend = isset($reidxOverview['last_indexed_by_backend']) && is_array($reidxOverview['last_indexed_by_backend'])
				? $reidxOverview['last_indexed_by_backend'] : array();
			$maintext .= "<h4>Active/queued jobs</h4>";
			if ( empty($qRows) ) {
				$maintext .= "<p><small>No jobs in FQS queue.</small></p>";
			} else {
				$maintext .= "<table><tr><th>Job<th>Corpus<th>Status<th>Priority<th>Backends<th>Worker<th>Requested<th>Started<th>Finished</tr>";
				foreach ( $qRows as $rj ) {
					if ( !is_array($rj) ) continue;
					$jobId = (string)($rj['job_id'] ?? '');
					$cid = (string)($rj['corpus_id'] ?? '');
					$status = (string)($rj['status'] ?? '');
					$prio = (string)($rj['priority'] ?? '');
					$backs = isset($rj['requested_backends']) && is_array($rj['requested_backends']) ? implode(', ', $rj['requested_backends']) : '';
					$wid = (string)($rj['worker_id'] ?? '');
					$reqAt = (string)($rj['requested_at'] ?? '');
					$staAt = (string)($rj['started_at'] ?? '');
					$finAt = (string)($rj['finished_at'] ?? '');
					$maintext .= "<tr><td><small>" . htmlspecialchars($jobId, ENT_QUOTES, 'UTF-8') . "</small></td>"
						. "<td>" . htmlspecialchars($cid, ENT_QUOTES, 'UTF-8') . "</td>"
						. "<td>" . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . "</td>"
						. "<td>" . htmlspecialchars($prio, ENT_QUOTES, 'UTF-8') . "</td>"
						. "<td><small>" . htmlspecialchars($backs, ENT_QUOTES, 'UTF-8') . "</small></td>"
						. "<td><small>" . htmlspecialchars($wid, ENT_QUOTES, 'UTF-8') . "</small></td>"
						. "<td><small>" . htmlspecialchars($reqAt, ENT_QUOTES, 'UTF-8') . "</small></td>"
						. "<td><small>" . htmlspecialchars($staAt, ENT_QUOTES, 'UTF-8') . "</small></td>"
						. "<td><small>" . htmlspecialchars($finAt, ENT_QUOTES, 'UTF-8') . "</small></td></tr>";
				}
				$maintext .= "</table>";
			}

			if ( !empty($isshared) ) {
				$maintext .= "<h4>Last indexed per corpus</h4>";
				if ( empty($lastIx) ) {
					$maintext .= "<p><small>No <code>indexed</code> events yet in FQS history.</small></p>";
				} else {
					ksort($lastIx);
					$maintext .= "<table><tr><th>Corpus<th>Last indexed at<th>Job<th>Backends</tr>";
					foreach ( $lastIx as $cid => $li ) {
						if ( !is_array($li) ) continue;
						$backs = isset($li['backends']) && is_array($li['backends']) ? implode(', ', $li['backends']) : '';
						$maintext .= "<tr><td>" . htmlspecialchars((string)$cid, ENT_QUOTES, 'UTF-8') . "</td>"
							. "<td><small>" . htmlspecialchars((string)($li['at'] ?? ''), ENT_QUOTES, 'UTF-8') . "</small></td>"
							. "<td><small>" . htmlspecialchars((string)($li['job_id'] ?? ''), ENT_QUOTES, 'UTF-8') . "</small></td>"
							. "<td><small>" . htmlspecialchars($backs, ENT_QUOTES, 'UTF-8') . "</small></td></tr>";
					}
					$maintext .= "</table>";
				}
			} else {
				$maintext .= "<h4>Last indexed per backend</h4>";
				if ( empty($lastIxByBackend) ) {
					$maintext .= "<p><small>No <code>indexed</code> events yet in FQS history.</small></p>";
				} else {
					ksort($lastIxByBackend);
					$maintext .= "<table><tr><th>Backend<th>Last indexed at<th>Job<th>Corpus</tr>";
					foreach ( $lastIxByBackend as $bk => $li ) {
						if ( !is_array($li) ) continue;
						$maintext .= "<tr><td>" . htmlspecialchars((string)$bk, ENT_QUOTES, 'UTF-8') . "</td>"
							. "<td><small>" . htmlspecialchars((string)($li['at'] ?? ''), ENT_QUOTES, 'UTF-8') . "</small></td>"
							. "<td><small>" . htmlspecialchars((string)($li['job_id'] ?? ''), ENT_QUOTES, 'UTF-8') . "</small></td>"
							. "<td><small>" . htmlspecialchars((string)($li['corpus_id'] ?? ''), ENT_QUOTES, 'UTF-8') . "</small></td></tr>";
					}
					$maintext .= "</table>";
				}
			}
		}

		$runtimeDir = isset($fqsRuntime['runtime_dir']) ? (string)$fqsRuntime['runtime_dir'] : tt_fqsb_fqs_runtime_dir($targetRoot);
		$fqsServeLog = rtrim($runtimeDir, '/\\') . DIRECTORY_SEPARATOR . 'fqs-serve.log';
		$reindexLog = rtrim((string)$targetRoot, '/\\') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'flexicorp_reindex.log';
		$fqsRequestLog = '';
		if ( isset($fqsRuntime['health']) && is_array($fqsRuntime['health']) ) {
			$fqsRequestLog = trim((string)($fqsRuntime['health']['log_file'] ?? ''));
		}
		if ( $fqsRequestLog === '' ) {
			$fqsRequestLog = '/usr/local/var/log/fqs/fqs.log';
		}
		$maintext .= "<h3>Service logs</h3>";
		$maintext .= "<h4>FQS serve log</h4>";
		$fqsTail = tt_fqsb_tail_file($fqsServeLog, 120);
		if ( $fqsTail === '' ) {
			$maintext .= "<p><small>No FQS serve log yet at <code>" . htmlspecialchars($fqsServeLog, ENT_QUOTES, 'UTF-8') . "</code>.</small></p>";
		} else {
			$maintext .= "<p><small>Tail of <code>" . htmlspecialchars($fqsServeLog, ENT_QUOTES, 'UTF-8') . "</code></small></p>";
			$maintext .= "<pre style='white-space:pre-wrap;max-height:22em;overflow:auto;background:#f8f8f8;padding:8px;border:1px solid #ccc'>"
				. htmlspecialchars($fqsTail, ENT_QUOTES, 'UTF-8') . "</pre>";
		}
		$maintext .= "<h4>FQS request log</h4>";
		$fqsReqTail = tt_fqsb_tail_file($fqsRequestLog, 120);
		if ( $fqsReqTail === '' ) {
			$maintext .= "<p><small>No FQS request log yet at <code>" . htmlspecialchars($fqsRequestLog, ENT_QUOTES, 'UTF-8') . "</code>.</small></p>";
		} else {
			$maintext .= "<p><small>Tail of <code>" . htmlspecialchars($fqsRequestLog, ENT_QUOTES, 'UTF-8') . "</code></small></p>";
			$maintext .= "<pre style='white-space:pre-wrap;max-height:22em;overflow:auto;background:#f8f8f8;padding:8px;border:1px solid #ccc'>"
				. htmlspecialchars($fqsReqTail, ENT_QUOTES, 'UTF-8') . "</pre>";
		}
		$maintext .= "<h4>flexicorp reindex log</h4>";
		$reidxTail = tt_fqsb_tail_file($reindexLog, 120);
		if ( $reidxTail === '' ) {
			$maintext .= "<p><small>No reindex log yet at <code>" . htmlspecialchars($reindexLog, ENT_QUOTES, 'UTF-8') . "</code>.</small></p>";
		} else {
			$maintext .= "<p><small>Tail of <code>" . htmlspecialchars($reindexLog, ENT_QUOTES, 'UTF-8') . "</code></small></p>";
			$maintext .= "<pre style='white-space:pre-wrap;max-height:22em;overflow:auto;background:#f8f8f8;padding:8px;border:1px solid #ccc'>"
				. htmlspecialchars($reidxTail, ENT_QUOTES, 'UTF-8') . "</pre>";
		}
	}

?>
