<?php
class GetListComponent extends Component {
	
	public $controllerDetails = array();	//detailed controller list
	public $controllers = array();		//simplified controller list
	public $pluginList = array();		//generated plugin list
	public $controller = null;			//initialized controller

	public $settings = array();
    protected $_defaults = array(
		'cache'						=> true,					//must use especially if order sorting is enabled
		'log'						=> true,					//if enabled, it'll log it, everytime new cache created
    	'set_disable'				=> false,					//enable to pass list as a variable to the view
    	'set_variable' 				=> 'controllersList',		//variable name for getting controller lists in views
		'exclude_currentController'	=> false,					//exclude current controller
    	'exclude_appControllers'	=> true,					//exclude AppController and PluginAppControllers
        'exclude'					=> array(),					//for excluding some extra controller names
        'include'					=> array(),					//for including some extra controller names
		'order_sort'				=> 'desc',					//desc or asc sorting
		'order_by'					=> 'name',					//sort by name or order
		'order_disabled'			=> false,					//for using this sorting you can add a "$order" property in your controller. enabling cache for this deadly recommended.
    	'plugins_disable'			=> false,					//don't scan plugins
    	'plugins_exclude'			=> array(),					//for excluding some extra plugin's controller
        'plugins_include'			=> array()					//for including some extra plugin's controller
    );

    public function initialize(Controller $controller) {
    	$this->controller = $controller;
		$this->settings = $this->settings + $this->_defaults;

		$return = $this->_checkOrMake();
		
		extract($this->settings);
		$this->controller->{$set_variable} = $this->controllers;
		
		if (!$set_disable) {
			$this->controller->set($set_variable, $this->controllers);
		}
		
		return $return;
    }

    public function reset($flushCache = false) {
		if ($flushCache) { $this->_flushCache(); }
		return is_array($this->initialize($this->controller));
	}

    public function getList() {
    	return $this->controllers;
    }

    protected function _exclude(Array $objects, Array $excludes) {
		if (empty($excludes) || empty($objects)) {
			return $objects;
		}

		$objects = array_filter($objects, function($object) use ($excludes) {
			return empty($object['Controller']['key']) ? !in_array($object, $excludes) : !in_array($object['Controller']['key'], $excludes);
		});
		
		return $objects;
    }

    protected function _include(Array $objects, Array $includes, $type = 'controller') {

    	if (empty($includes['include'])) {
			return $objects;
		}
		$includes = $includes['include'];

		if ($type === 'controller') {
			foreach($includes as $key => $include) {
				$splitted = pluginSplit($include);
				$baseName = $this->_baseName($splitted[1]);
				$includes[$key] = array(
					'Controller' => array(
						'key' => strtolower($baseName),
						'properties' => array(
							'name'			=> $this->_humanize($baseName),
							'controller'	=> $splitted[1],
							'plugin'		=> $splitted[0]
						),
						'importName' => 'Controller'
					),
				);
			}
		}
		return array_replace_recursive($objects, $includes);
    }

    protected function _order(Array $controllers) {
    	foreach($controllers as $key => $controller) {
			
			$controllers[$key]['Controller']['order'] = 0;
			
			if (empty($controller['Controller'])) { continue; }
			
			$importName = $controller['Controller']['importName'];
			extract($controller['Controller']['properties']);
			

			App::uses($controller, $importName);
			if (class_exists($controller)) {
				$controllers[$key]['Controller']['order'] = isset($controller::$order) ? intval($controller::$order) : 0;
			}
			
    	}
		
		extract($this->settings);
		$order_by = $order_by == 'name' ? 'key' : $order_by;
    	return $this->controllersList = Set::sort($controllers, "{n}.Controller.{$order_by}", $order_sort);
    }

    protected function _humanize($name = '') { return Inflector::humanize($this->_underscore($name)); }
    protected function _underscore($name = '') { return Inflector::underscore($name); }
	protected function _baseName($name = '') { return $name === 'AppController' ? $this->_humanize($name) : str_replace(array('Controller', 'App'), '', $name); }
	
	protected function _make($resetCache = false) {
		if ($resetCache) { $this->_flushCache(); }
		
		extract($this->settings);

		$controllers = App::objects('Controller', null, false);
		foreach($controllers as $key => $controller) {
			$baseName = $this->_baseName($controller);
			$controllers[$key] = array(
				'Controller' => array(
					'key'			=> $baseName,
					'properties'		=> array(
						'name'			=> $this->_humanize($baseName),
						'controller'	=> $this->_underscore($baseName),
					),
					'importName'	=> 'Controller'
				)
			);
		}
		if (!$plugins_disable) {
			$this->pluginList = $plugins = $this->_exclude(CakePlugin::loaded(), $plugins_exclude);
			$this->pluginList = $plugins = $this->_include($plugins, $plugins_include);
			
			foreach($plugins as $plugin) {
				
				$pluginControllers = App::objects("{$plugin}.Controller", null, false);
				
				if (empty($pluginControllers)) { continue; }

				foreach($pluginControllers as $key => $pluginController) {
					$baseName = $this->_baseName($pluginController);
					$pluginControllers[$key] = array(
						'Controller' => array(
							'key'			=> $baseName,
							'properties'			=> array(
								'name'			=> $this->_humanize($baseName),
								'controller'	=> $baseName,
								'plugin'		=> $plugin
							),
							'importName'	=> "{$plugin}.Controller"
						)
					);

					if ($exclude_appControllers && preg_match('/App/', $pluginController, $matches, PREG_OFFSET_CAPTURE, 3)) {
						$exclude[] = $pluginControllers[$key]['Controller']['key'];
					}
				}

				$controllers = array_replace_recursive($controllers, $pluginControllers);
			}
		}

		if ($exclude_currentController) { $exclude[] = $this->controller->name; }
		if ($exclude_appControllers) { $exclude[] = 'App Controller'; }
		

		$controllers = $this->_exclude($controllers, $exclude);
		$controllers = $this->_include($controllers, $include);

        $this->controllersList = $this->settings['order_disabled'] ? $controllers : $this->_order($controllers);
		$this->controllers = Set::combine($this->controllersList, '{n}.Controller.key', '{n}.Controller.properties');
		$resetCache = $resetCache ? ($this->_writeCache($this->controllers, $this->controllersList, $this->settings) ? true : false) : true;
		return (!empty($this->controllers) && $resetCache) ? $this->controllers : false;
	}

	protected function _flushCache() { return (Cache::delete('ControllersListPlugin') === true); }

	protected function _writeCache(Array $controllers, Array $controllersList, Array $settings) {
		return (Cache::write('ControllersListPlugin', array(
			'controllers'		=> $controllers,
			'controllersList'	=> $controllersList,
			'settings'			=> $settings
		)) === true);
	}

	protected function _checkOrMake() {
		if ($this->settings['cache'] !== false) {
			$cached = Cache::read('ControllersListPlugin');
			
			if (empty($cached)) {
				if ($this->settings['log']) {
					$this->controller->log(array(
						'ControllersListPlugin' => 'no cache found, creating cache'
					), 'debug');
				}
				
				return $this->_make(true);
			}
			
			if ($cached['settings'] !== $this->settings) {
				
				if ($this->settings['log']) {
					$this->controller->log(array(
						'ControllersListPlugin'	=> 'settings changed re-creating cache',
						'cachedSettings'		=> $cached['settings'],
						'runtimeSettings'		=> $this->settings
					), 'debug');
				}
				
				return $this->_make(true);
			}
				
			$this->settings = $cached['settings'];
			$this->controllersList = $cached['controllersList'];
			return $this->controllers = $cached['controllers'];
		}
		return $this->_make();
	}
}
