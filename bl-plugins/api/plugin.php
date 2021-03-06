<?php


class pluginAPI extends Plugin {

	public function init()
	{
		global $Security;

		// This key is used for request such as get the list of all posts and pages
		$token = md5($Security->key1().time().DOMAIN);

		$this->dbFields = array(
			'ping'=>0,		// 0 = false, 1 = true
			'token'=>$token,	// Private key
			'showAllAmount'=>15,	// Amount of posts and pages for return
			'authentication'=>1	// Authentication required
		);
	}

	public function form()
	{
		$html = '';

		$html .= '<div>';
		$html .= '<input type="hidden" name="ping" value="0">';
		$html .= '<input name="ping" id="jsping" type="checkbox" value="1" '.($this->getDbField('ping')?'checked':'').'>';
		$html .= '<label class="forCheckbox" for="jsping">Ping Bludit.com</label>';
		$html .= '<div class="tip">Enable this feature to share your posts and pages with Bludit.com.</div>';
		$html .= '</div>';

		$html .= '<div>';
		$html .= '<p><b>Authorization Key:</b> '.$this->getDbField('token').'</p>';
		$html .= '<div class="tip">This key is private, do not share it with anyone.</div>';
		$html .= '</div>';

		$html .= '<div>';
		$html .= '<p><b>Show all posts:</b> <a href="'.DOMAIN_BASE.'api/show/all/posts/'.$this->getDbField('token').'">'.DOMAIN_BASE.'api/show/all/posts/'.$this->getDbField('token').'</a></p>';
		$html .= '<div class="tip">Get all posts from this site.</div>';
		$html .= '</div>';

		$html .= '<div>';
		$html .= '<p><b>Show all pages:</b> <a href="'.DOMAIN_BASE.'api/show/all/pages/'.$this->getDbField('token').'">'.DOMAIN_BASE.'api/show/all/pages/'.$this->getDbField('token').'</a></p>';
		$html .= '<div class="tip">Get all pages from this site.</div>';
		$html .= '</div>';

		$html .= '<div>';
		$html .= '<p><b>Show post:</b> <a href="'.DOMAIN_BASE.'api/show/post/{POST-NAME}">'.DOMAIN_BASE.'api/show/post/{POST-NAME}</a></p>';
		$html .= '<div class="tip">Get a particular post, change the {POST-NAME} with the post friendly url.</div>';
		$html .= '</div>';

		$html .= '<div>';
		$html .= '<p><b>Show page:</b> <a href="'.DOMAIN_BASE.'api/show/page/{PAGE-NAME}">'.DOMAIN_BASE.'api/show/page/{PAGE-NAME}</a></p>';
		$html .= '<div class="tip">Get a particular page, change the {PAGE-NAME} with the page friendly url.</div>';
		$html .= '</div>';

		return $html;
	}

	public function install($position=0)
	{
		parent::install($position);

		$this->ping();
	}


// API HOOKS
// ----------------------------------------------------------------------------

	public function afterFormSave()
	{
		$this->ping();
	}

	public function beforeRulesLoad()
	{
		global $Url;
		global $dbPosts;
		global $dbPages;

		// Check if the URI start with /api/
		$startString = HTML_PATH_ROOT.'api/';
		$URI = $Url->uri();
		$length = mb_strlen($startString, CHARSET);
		if( mb_substr($URI, 0, $length)!=$startString ) {
			return false;
		}

		// Remove the first part of the URI
		$URI = mb_substr($URI, $length);

		// METHODS
		// ------------------------------------------------------------
		// GET
		// POST
		// PUT
		// DELETE

		$method = $_SERVER['REQUEST_METHOD'];

		// INPUTS
		// ------------------------------------------------------------
		// token 	| authentication token

		$inputs = json_decode(file_get_contents('php://input'),true);

		if( empty($inputs) ) {
			// Default variables for $input
			$inputs = array(
				'token'=>''
			);
		}
		else {
			// Sanitize inputs
			foreach( $inputs as $key=>$value ) {
				if(empty($value)) {
					$this->response(array(
						'status'=>'1',
						'message'=>'Invalid input.'
					));
				} else {
					$inputs[$key] = Sanitize::html($value);
				}
			}
		}

		// PARAMETERS
		// ------------------------------------------------------------
		// /api/posts 		| GET  | returns all posts
		// /api/posts/{slug}	| GET  | returns the post with the {slug}
		// /api/pages 		| GET  | returns all pages
		// /api/pages/{slug}	| GET  | returns the page with the {slug}
		// /api/cli/regenerate 	| POST | check for new posts and pages

		$parameters = explode('/', $URI);

		// Sanitize parameters
		foreach( $parameters as $key=>$value ) {
			if(empty($value)) {
				$this->response(array(
					'status'=>'1',
					'message'=>'Invalid parameter.'
				));
			} else {
				$parameters[$key] = Sanitize::html($value);
			}
		}

		// Check authentication
		if( $this->getDbField('authentication')==1 ) {
			if( $inputs['token']!=$this->getDbField('token') ) {
				$this->response(array(
					'status'=>'1',
					'message'=>'Invalid token.'
				));
			}
		}

		// /api/posts
		if( ($method==='GET') && ($parameters[0]==='posts') && empty($parameters[1]) ) {
			$data = $this->getAllPosts();
			$this->response($data);
		}
		// /api/pages
		elseif( ($method==='GET') && ($parameters[0]==='pages') && empty($parameters[1]) ) {
			$data = $this->getAllPages();
			$this->response($data);
		}
		// /api/posts/{slug}
		elseif( ($method==='GET') && ($parameters[0]==='posts') && !empty($parameters[1]) ) {
			$data = $this->getPost($parameters[1]);
			$this->response($data);
		}
		// /api/pages/{slug}
		elseif( ($method==='GET') && ($parameters[0]==='pages') && !empty($parameters[1]) ) {
			$data = $this->getPage($parameters[1]);
			$this->response($data);
		}
		// /api/cli/regenerate
		elseif( ($method==='POST') && ($parameters[0]==='cli') && ($parameters[1]==='regenerate') ) {

			// Regenerate posts
			if( $dbPosts->cliMode() ) {
				reIndexTagsPosts();
			}

			// Regenerate pages
			$dbPages->cliMode();

			$this->response(array(
				'status'=>'0',
				'message'=>'Pages and post regenerated.'
			));
		}
	}

// FUNCTIONS
// ----------------------------------------------------------------------------

	private function response($data=array())
	{
		$json = json_encode($data);

		header('Content-Type: application/json');
		exit($json);
	}

	private function ping()
	{
		if($this->getDbField('ping')) {

			// Get the authentication key
			$token = $this->getDbField('token');

			$url = 'https://api.bludit.com/ping?token='.$token.'&url='.DOMAIN_BASE;

			// Check if curl is installed
			if( function_exists('curl_version') ) {

                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, $url);
                                curl_setopt($ch, CURLOPT_HEADER, false);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				$out = curl_exec($ch);

				if($out === false) {
					Log::set('Plugin API : '.'Curl error: '.curl_error($ch));
				}

				curl_close($ch);
			}
			else {
				$options = array(
					"ssl"=>array(
						"verify_peer"=>false,
						"verify_peer_name"=>false
					)
				);

				$stream = stream_context_create($options);
				$out = file_get_contents($url, false, $stream);
			}
		}
	}

	private function getPost($key)
	{
		// Generate the object Post
		$Post = buildPost($key);

		if(!$Post) {
			return array(
				'status'=>'1',
				'message'=>'Post not found.'
			);
		}

		$data['status'] = '0';
		$data['message'] = '';
		$data['data'] = $Post->json( $returnsArray=true );

		return $data;
	}

	private function getAllPosts()
	{
		$posts = buildPostsForPage(0, $this->getDbField('showAllAmount'), true, false);

		$tmp = array(
			'status'=>'0',
			'message'=>'',
			'data'=>array()
		);

		foreach($posts as $Post) {
			array_push($tmp['data'], $Post->json( $returnsArray=true ));
		}

		return $tmp;
	}

	private function getPage($key)
	{
		// Generate the object Page
		$Page = buildPage($key);

		if(!$Page) {
			return array(
				'status'=>'1',
				'message'=>'Page not found.'
			);
		}

		$data['status'] = '0';
		$data['message'] = '';
		$data['data'] = $Page->json( $returnsArray=true );

		return $data;
	}

	private function getAllPages()
	{
		$pages = buildAllPages();

		$tmp = array(
			'status'=>'0',
			'message'=>'',
			'data'=>array()
		);

		foreach($pages as $Page) {
			if($Page->published()) {
				array_push($tmp['data'], $Page->json( $returnsArray=true ));
			}
		}

		return $tmp;
	}

}