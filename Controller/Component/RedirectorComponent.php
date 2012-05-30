<?php
class RedirectorComponent extends Component {
	
	protected $_controller = false;
	protected $_defaults = array(
		'disabled'	=> true,
		'layout'	=> 'ajax',
		'render'	=> 'ClientRedirect./Redirects/javascript'
	);
	
	public function initialize(Controller $controller) {
		$this->settings = $this->_defaults + $this->settings;
		return $this->controller = $controller;
    }
	
	public function enable() { return !$this->settings['disabled'] = false; }
	public function disable() { return $this->settings['disabled'] = true; }
	
	protected function _run($url) {
		extract($this->settings);
		
		if(!$disabled) {
			$url = Router::url($url, true);
			$this->controller->set('clientRedirect', $url);
			return $this->controller->render($render, $layout) ? true : false;
		}
		
		return false;
	}
	
	public function beforeRedirect(Controller $controller, $url = false, $status = null, $exit = true) {
		$this->controller = $controller;
		if($url !== false && $this->_run($url)) { return false; }
		return parent::beforeRedirect($controller, $url, $status, $exit);
	}
}