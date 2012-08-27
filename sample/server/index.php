<?php
	error_reporting(E_ALL | E_STRICT);
	set_exception_handler('onError');
//	define('DEV', 'TRUE');
	define('HTTP_OK', '200 OK');
	define('HTTP_ERROR_CODE', '500 Internal Server Error');
	define('UPDATE_TOKEN', 'someEspecialTokenYouWantToUse');
	
	$actions = array ('default' => 'performRead',
					'update' => 'performUpdate',
					'read' => 'performRead'
			);
	$sources = array (
				array('http://www.nasa.gov/rss/image_of_the_day.rss', NULL, 'niod'),
				array('http://apod.nasa.gov/apod.rss', '__callbackApod', 'apod')
				
				);
				
	dispatchRequest();
	
	function dispatchRequest() {
		$scriptName = explode('/',$_SERVER['SCRIPT_NAME']);
		$uri = explode("?",$_SERVER["REQUEST_URI"]);
		$uri = explode('/', $uri[0]);
		$count = sizeof($scriptName);
		for($i= 0;$i < $count;$i++) {
      		if ( in_array($uri[$i], $scriptName) ) {
    	        unset($uri[$i]);
            }
    	}
		executeRequest(array_values($uri), array_merge($_GET, $_POST));
	}

	function executeRequest($uri, $data) {
		global $actions;
		if (sizeof($uri) === 0) {
			$uri = array ('default');
		}
		$action = $uri[0];
		if ( array_key_exists($action, $actions) ) {
			try {
				call_user_func($actions[$action], $data);
			} catch (Exception $ex) {
				onError($ex);
			}
		} else {
			onError(array ('message' => 'No such method ' . $action,
				'request' => array ('action' => $action, 'data' => $data)));
		}

	}
	
	function openDb() {
		$dbName = 'nasa.db';
		if (defined('DEV')) {
			$dbName = 'test.db';
		}
		$db = new PDO('sqlite:' . $dbName);
		$db->setAttribute(PDO::ATTR_ERRMODE, 
                            PDO::ERRMODE_EXCEPTION);
		$db->exec("CREATE TABLE IF NOT EXISTS images (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, 
                    url TEXT UNIQUE, 
                    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
                    source TEXT)");
		return $db;
	}

	function onError($error) {
		sendResponse(HTTP_ERROR_CODE, array( 'error' => TRUE, 'errorResult' => $error ));
	}
	
	function sendResponse($code, $response) {
		header('HTTP/1.1 ' . $code);
		header('Content-type: application/json');
		$json = json_encode($response);
		print $json;
		$exitCode = 0;
		if ($code === HTTP_ERROR_CODE) {
			$exitCode = 1;
		}
		exit($exitCode);
	}
	
	function performRead($data) {
		$db = openDb();
		$afterId = 0;
		$limit = 20;
		if (isset($data['afterId'])) {
			$afterId = (int) $data['afterId'];
		}
		if (isset($data['limit'])) {
			$limit = (int) $data['limit'];
		}
		$statement = $db->prepare('SELECT * FROM images WHERE id > :afterId ORDER BY id LIMIT :limit');
		$statement->bindParam(':afterId', $afterId);
		$statement->bindParam(':limit', $limit);
		$statement->execute();
		$response = array ('images' => $statement->fetchAll(PDO::FETCH_ASSOC));
		$db = NULL;
		$statement = NULL;
		sendResponse(HTTP_OK, $response);
	}
	
	function performUpdate($data) {
		if ( !array_key_exists('token', $data) || !isset($data['token'])
			|| $data['token'] != UPDATE_TOKEN) {
			onError(array('message' => 'No valid token found'));
		}
		
		global $sources;
		$count = 0;
		foreach($sources as $source) {
			$count = $count + _updateFrom($source[0], $source[1], $source[2]);
		}
		
		$response = array ('updated' => $count > 0 ? TRUE : FALSE, 'count' => $count);
		
		sendResponse(HTTP_OK, $response);
	}
	
	function _updateFrom($feed, $callbackFunction, $sourceName) {
		$count = 0;
		$feedContent = file_get_contents($feed);
		$rss = simplexml_load_string($feedContent);
		if (isset($rss->channel->item)) {
			$images = array();
			foreach($rss->channel->item as $item) {
				try {
					$imageFound = FALSE;
					if (isset($item->enclosure)) {
						$attributes = $item->enclosure->attributes();
						if (isset($attributes->type) && isset($attributes->url)
							&& startsWith($attributes->type, 'image')) {
								$imageFound = TRUE;
								$url = (string) $attributes->url;
								array_push($images, $url);
							}
					} 
					if (!$imageFound && isset($item->link) && isset($callbackFunction)) { // We gotta scrap the source
						$url = _getAndFindFirst((string) $item->link, $callbackFunction);
						if (strlen($url) != 0) {
							array_push($images, $url);
						}
					}
				} catch (Exception $e) { // Pokemon catch 'em all, we want to skip those which fail
					error_log('Unable to process item e: ' . $e->getMessage());
				}
	        }
	        $feedContent = NULL;
	        $rss = NULL;
	        
	        $count = sizeof($images);
	        $insert = "INSERT INTO images (url, source) 
                VALUES (:url, :source)";
            
            $db = openDb();
            $statement = $db->prepare($insert);
            $statement->bindParam(':url', $imageUrl);
            $statement->bindParam(':source', $sourceName);
            foreach ($images as $imageUrl) {
            	try {
					$statement->execute();
				} catch (Exception $e) { // Pokemon catch 'em all, we want to skip those which fail
					error_log('Unable to insert ' . $imageUrl . ' e: ' . $e->getMessage());
				}
            }
            $statement = NULL;
			$db = NULL;
		}
		return $count;
	}
	
	function _getAndFindFirst($url, $callbackFunction) {
		$doc = new DOMDocument(); 
		$doc->loadHTML(file_get_contents($url));
		return call_user_func($callbackFunction, $doc, $url);
	}
	
	function startsWith($haystack, $needle) {
	    $length = strlen($needle);
    	return (substr($haystack, 0, $length) === $needle);
	}

	function __callbackApod($doc, $url) {
		require_once('Net/URL2.php');
		$baseUrl = new Net_URL2( $url );
		
		$elements = $doc->getElementsByTagName('img');
		$firstElement = $elements->item(0);
		if (isset($firstElement)) {
			$href = (string) $elements->item(0)->getAttribute('src');
			return (string) $baseUrl->resolve($href);
		}
		return NULL;
	}

?>