<?php

App::uses('CakeLog', 'Log');
App::uses('CakeLogInterface', 'Log');
App::uses('DebugTimer', 'DebugKit.Lib');
App::uses('DebugMemory', 'DebugKit.Lib');
App::uses('HelperCollection', 'View');

/**
 * DebugKit DebugToolbar Component
 *
 * Copyright 2005-2010, Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2005-2010, Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org
 * @package       debug_kit
 * @subpackage    debug_kit.controllers.components
 * @since         DebugKit 0.1
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class ToolbarComponent extends Component {
/**
 * Settings for the Component
 *
 * - forceEnable - Force the toolbar to display even if debug == 0. Default = false
 * - autoRun - Automatically display the toolbar. If set to false, toolbar display can be triggered by adding
 *    `?debug=true` to your URL.
 *
 * @var array
 */
	public $settings = array(
		'forceEnable' => false,
		'autoRun' => true
	);

/**
 * Controller instance reference
 *
 * @var object
 */
	public $controller;

/**
 * Components used by DebugToolbar
 *
 * @var array
 */
	public $components = array('RequestHandler', 'Session');

/**
 * The default panels the toolbar uses.
 * which panels are used can be configured when attaching the component
 *
 * @var array
 */
	protected $_defaultPanels = array(
		'DebugKit.History',
		'DebugKit.Session',
		'DebugKit.Request',
		'DebugKit.SqlLog',
		'DebugKit.Timer',
		'DebugKit.Log',
		'DebugKit.Variables',
		'DebugKit.Include'
	);

/**
 * Loaded panel objects.
 *
 * @var array
 */
	public $panels = array();

/**
 * javascript files component will be using
 *
 * @var array
 */
	public $javascript = array(
		'jquery' => '/debug_kit/js/jquery',
		'libs' => '/debug_kit/js/js_debug_toolbar'
	);

/**
 * CacheKey used for the cache file.
 *
 * @var string
 */
	public $cacheKey = 'toolbar_cache';

/**
 * Duration of the debug kit history cache
 *
 * @var string
 */
	public $cacheDuration = '+4 hours';

/**
 * Status whether component is enable or disable
 *
 * @var boolean
 */
	public $enabled = true;

/**
 * Constructor
 *
 * If debug is off the component will be disabled and not do any further time tracking
 * or load the toolbar helper.
 *
 * @return bool
 */
	public function __construct(ComponentCollection $collection, $settings = array()) {
		$settings = array_merge((array)Configure::read('DebugKit'), $settings);
		$panels = $this->_defaultPanels;
		if (isset($settings['panels'])) {
			$panels = $this->_makePanelList($settings['panels']);
			unset($settings['panels']);
		}
		$this->controller = $collection->getController();

		parent::__construct($collection, array_merge($this->settings, (array)$settings));

		if (!Configure::read('debug') && empty($this->settings['forceEnable'])) {
			$this->enabled = false;
			return false;
		}
		if ($this->settings['autoRun'] == false && !isset($this->controller->request->query['debug'])) {
			$this->enabled = false;
			return false;
		}

		DebugMemory::record(__d('debug_kit', 'Component initialization'));
		DebugTimer::start('componentInit', __d('debug_kit', 'Component initialization and startup'));

		$this->cacheKey .= $this->Session->read('Config.userAgent');
		if (in_array('history', $panels) || (isset($settings['history']) && $settings['history'] !== false)) {
			$this->_createCacheConfig();
		}

		$this->_loadPanels($panels, $settings);

		return false;
	}

/**
 * Initialize callback.
 * If automatically disabled, tell component collection about the state.
 *
 * @return bool
 */
	public function initialize(Controller $controller) {
		if (!$this->enabled) {
			$this->_Collection->disable('Toolbar');
		}
	}

/**
 * Go through user panels and remove default panels as indicated.
 *
 * @param array $userPanels The list of panels ther user has added removed.
 * @return array Array of panels to use.
 */
	protected function _makePanelList($userPanels) {
		$panels = $this->_defaultPanels;
		foreach ($userPanels as $key => $value) {
			if (is_numeric($key)) {
				$panels[] = $value;
			}
			if (is_string($key) && $value === false) {
				$index = array_search($key, $panels);
				if ($index !== false) {
					unset($panels[$index]);
				}
			}
		}
		return $panels;
	}

/**
 * Component Startup
 *
 * @return bool
 */
	public function startup(Controller $controller) {
		$currentViewClass = $controller->viewClass;
		$this->_makeViewClass($currentViewClass);
		$controller->viewClass = 'DebugKit.Debug';
		$isHtml = (!isset($controller->request->params['ext']) || $controller->request->params['ext'] === 'html');

		if (!$controller->request->is('ajax') && $isHtml) {
			$format = 'Html';
		} else {
			$format = 'FirePhp';
		}
		$controller->helpers['DebugKit.Toolbar'] = array(
			'output' => sprintf('DebugKit.%sToolbar', $format),
			'cacheKey' => $this->cacheKey,
			'cacheConfig' => 'debug_kit',
			'forceEnable' => $this->settings['forceEnable'],
		);
		$panels = array_keys($this->panels);
		foreach ($panels as $panelName) {
			$this->panels[$panelName]->startup($controller);
		}
		DebugTimer::stop('componentInit');
		DebugTimer::start('controllerAction', __d('debug_kit', 'Controller action'));
		DebugMemory::record(__d('debug_kit', 'Controller action start'));
	}

/**
 * beforeRedirect callback
 *
 * @return void
 */
	public function beforeRedirect(Controller $controller, $url, $status = null, $exit = true) {
		if (!class_exists('DebugTimer')) {
			return null;
		}
		DebugTimer::stop('controllerAction');
		$vars = $this->_gatherVars($controller);
		$this->_saveState($controller, $vars);
	}

/**
 * beforeRender callback
 *
 * Calls beforeRender on all the panels and set the aggregate to the controller.
 *
 * @return void
 */
	public function beforeRender(Controller $controller) {
		if (!class_exists('DebugTimer')) {
			return null;
		}
		DebugTimer::stop('controllerAction');
		$vars = $this->_gatherVars($controller);
		$this->_saveState($controller, $vars);

		$controller->set(array('debugToolbarPanels' => $vars, 'debugToolbarJavascript' => $this->javascript));
		DebugTimer::start('controllerRender', __d('debug_kit', 'Render Controller Action'));
		DebugMemory::record(__d('debug_kit', 'Controller render start'));
	}

/**
 * Load a toolbar state from cache
 *
 * @param int $key
 * @return array
 */
	public function loadState($key) {
		$history = Cache::read($this->cacheKey, 'debug_kit');
		if (isset($history[$key])) {
			return $history[$key];
		}
		return array();
	}

/**
 * Create the cache config for the history
 *
 * @return void
 */
	protected function _createCacheConfig() {
		if (Configure::read('Cache.disable') !== true) {
			Cache::config('debug_kit', array(
				'duration' => $this->cacheDuration,
				'engine' => 'File',
				'path' => CACHE
			));
		}
	}

/**
 * collects the panel contents
 *
 * @return array Array of all panel beforeRender()
 */
	protected function _gatherVars(Controller $controller) {
		$vars = array();
		$panels = array_keys($this->panels);

		foreach ($panels as $panelName) {
			$panel = $this->panels[$panelName];
			$panelName = Inflector::underscore($panelName);
			$vars[$panelName]['content'] = $panel->beforeRender($controller);
			$elementName = Inflector::underscore($panelName) . '_panel';
			if (isset($panel->elementName)) {
				$elementName = $panel->elementName;
			}
			$vars[$panelName]['elementName'] = $elementName;
			$vars[$panelName]['plugin'] = $panel->plugin;
			$vars[$panelName]['title'] = $panel->title;
			$vars[$panelName]['disableTimer'] = true;
		}
		return $vars;
	}

/**
 * Load Panels used in the debug toolbar
 *
 * @return 	void
 */
	protected function _loadPanels($panels, $settings) {
		foreach ($panels as $panel) {
			$className = ucfirst($panel) . 'Panel';
			list($plugin, $className) = pluginSplit($className, true);

			App::uses($className, $plugin . 'Panel');
			if (!class_exists($className)) {
				trigger_error(__d('debug_kit', 'Could not load DebugToolbar panel %s', $panel), E_USER_WARNING);
				continue;
			}
			$panelObj = new $className($settings);
			if (is_subclass_of($panelObj, 'DebugPanel') || is_subclass_of($panelObj, 'debugpanel')) {
				list(, $panel) = pluginSplit($panel);
				$this->panels[$panel] = $panelObj;
			}
		}
	}
/**
 * Makes the DoppleGangerView class if it doesn't already exist.
 * This allows DebugView to be compatible with all view classes.
 *
 * @param string $baseClassName
 * @return void
 */
	protected function _makeViewClass($baseClassName) {
		if (!class_exists('DoppelGangerView')) {
			$plugin = false;
			if (strpos($baseClassName, '.') !== false) {
				list($plugin, $baseClassName) = pluginSplit($baseClassName, true);
			}
			if (strpos($baseClassName, 'View') === false) {
				$baseClassName .= 'View';
			}
			App::uses($baseClassName, $plugin . 'View');
			$class = "class DoppelGangerView extends $baseClassName {}";
			$this->_eval($class);
		}
	}

/**
 * Method wrapper for eval() for testing uses.
 *
 * @return void
 */
	protected function _eval($code) {
		eval($code);
	}

/**
 * Save the current state of the toolbar varibles to the cache file.
 *
 * @param object $controller Controller instance
 * @param array $vars Vars to save.
 * @return void
 */
	protected function _saveState(Controller $controller, $vars) {
		$config = Cache::config('debug_kit');
		if (empty($config) || !isset($this->panels['history'])) {
			return;
		}
		$history = Cache::read($this->cacheKey, 'debug_kit');
		if (empty($history)) {
			$history = array();
		}
		if (count($history) == $this->panels['history']->history) {
			array_pop($history);
		}
		unset($vars['history']);
		array_unshift($history, $vars);
		Cache::write($this->cacheKey, $history, 'debug_kit');
	}
}