<?php
/*********************************
	Magento Z-Ray Extension
	Version: 1.02
**********************************/
class Magento {
	
	/**
	 * @var array
	 */
	private $eventTargets = array();
	private $requests = array();
	private $zray = null;
    
    public function setZRay($zray) {
        $this->zray = $zray;
    }
    
    /**
     * @return \ZRayExtension
     */
    public function getZRay() {
        return $this->zray;
    }
    
	/**
	 * @param array $context
	 * @param array $storage
	 */
	public function mageAppExit($context, &$storage){
        $this->requests = (array)Mage::app()->getRequest();
		
        // Now that we got our requests, we can untrace 'Mage::app' (for performance reasons)
        $this->getZRay()->untraceFunction("Mage::app");
	}
	
	/**
	 * @param array $context
	 * @param array $storage
	 */
	public function mageRunExit($context, &$storage){
		$storage['modules'] = array();
		$this->storeModules($storage['modules']);
		
		//Observers / Events
		$storage['observers'] = array();
		$this->storeObservers($storage['observers']);
		
		//Requests
		$finalRequests = (array)Mage::app()->getRequest();
		
		foreach($this->requests as $key=>$value) {
			$finalVal = !array_key_exists($key,$finalRequests) ? '[NULL]' : $finalRequests[$key];
			$storage['request'][] = array('property' => $key, 
                                          'Init Value' => is_array($value) ? print_r($value,true) : $value, 'Final Value'=>is_array($finalVal) ? print_r($finalVal,true) : $finalVal);
		}

		//Handles
		$storage['handles'] = array_map(function($handle){
			return array('name' => $handle);
		}, Mage::app()->getLayout()->getUpdate()->getHandles());
		
		//Blocks
		$storage['blocks'][] = $this->getBlocks($this->getRootBlock());
		
		//Overview
		$storage['overview'] = $this->getOverview();
	}
	
	
	private function getOverview(){
		$_website = Mage::app()->getWebsite();
		$_store = Mage::app()->getStore();
        $cacheMethod = explode('_',get_class(Mage::app()->getCache()->getBackend()));
        $cacheMethod = end($cacheMethod);
        $controllerClassReflection = new ReflectionClass(get_class(Mage::app()->getFrontController()->getAction()));
        
		$overview = array(
	            'Website ID'      => (method_exists($_website,'getId')) ? $_website->getId() : '',
	            'Website Name'    => (method_exists($_website,'getName')) ? $_website->getName() : '',
	            'Store Id'        => (method_exists($_store,'getGroupId')) ? $_store->getGroupId() : '',
	            'Store Name'      => (method_exists($_store,'getGroup') && method_exists($_store->getGroup(),'getName')) ? $_store->getGroup()->getName() : '',
	            'Store View Id'   => (method_exists($_store,'getId')) ? $_store->getId() : '',
	            'Store View Code' => (method_exists($_store,'getCode')) ? $_store->getCode() : '',
	            'Store View Name' => (method_exists($_store,'getName')) ? $_store->getName() : '',
	            'Cache Backend'    => $cacheMethod,
				'Version'              => Mage::getVersion(),
				'Edition'              => Mage::helper('core')->isModuleEnabled('Enterprise_Enterprise') ? 'enterprise' : 'community',
				'Controller Class Name' => get_class(Mage::app()->getFrontController()->getAction()),
				'Controller Class Path' => str_replace(Mage::getBaseDir(),'',str_replace("'",'',$controllerClassReflection->getFileName())),
				'Module Name'           => Mage::app()->getRequest()->getRouteName(),
				'Controller Name'       => Mage::app()->getRequest()->getControllerName(),
				'Action Name'           => Mage::app()->getRequest()->getActionName(),
				'Path Info'		=> Mage::app()->getRequest()->getPathInfo(),
				'Current Package'       => Mage::getDesign()->getPackageName(),
				'Current Theme'         => Mage::getDesign()->getTheme(''),
				'Template Path'         => str_replace(Mage::getBaseDir(),'',Mage::getDesign()->getTemplateFilename('')),
				'Layout Path'           => str_replace(Mage::getBaseDir(),'',Mage::getDesign()->getLayoutFilename('')),
				'Translation Path'      => str_replace(Mage::getBaseDir(),'',Mage::getDesign()->getLocaleBaseDir(array())),
				'Skin Path'             => str_replace(Mage::getBaseDir(),'',Mage::getDesign()->getSkinBaseDir(array()))			
				
	        );
		$arr = array();
		foreach($overview as $k => $v){
			$arr[]=array('Key'=>$k,'Value'=>$v);
		}
		return $arr;
	}

	
	/**
	 * @param array $context
	 */
	public function mageDispatchEvent($context) {
		/// collect event targets for events collector
		$event = $context['functionArgs'][0];
		$args = isset($context['functionArgs'][1]) ? $context['functionArgs'][1] : array();
		$intersection = array_intersect(array('object', 'resource', 'collection', 'front', 'controller_action'), array_keys($args));
		$key = array_shift($intersection);
		if(isset($args[$key])){
			$this->eventTargets[$event] = $args[$key];
		}
	}
	
	/**
	 * @param array $context
	 * @param array $storage
	 */
	public function appCallObserverMethod($context, & $storage){

		$method = $context['functionArgs'][1];
		$observerData = $context['functionArgs'][2]->getData();
		$eventArgs = $observerData['event']->getData();
		$event = $observerData['event']->getName();
		$object = get_class($context['functionArgs'][0]);

		//Events
		if(isset($this->eventTargets[$event])){
			$storage['events'][] = array('event' => $event,
										'class' => $object,
										'method' => $method,
										'duration' => $context['durationInclusive'], 
										'target' => get_class($this->eventTargets[$event])
										);
		}
	}
	
	/**
	 * @param array $storage
	 */
	private function storeModules(& $storage) {
		$modules = Mage::getConfig()->getNode('modules')->children();
		foreach($modules as $moduleName => $module){
			$storage[] = array(
				'Name'=>$moduleName,
				'Active'=>(bool)$module->active,
				'Code Pool'=>(string)$module->codePool,
				'Version'=>(string)$module->version,
			);
		}
	}
	
	/**
	 * @param array $storage
	 */
	private function storeObservers(& $storage) {
		foreach (array('global', 'adminhtml', 'frontend') as $eventArea) {
			$eventConfig = $this->getEventAreaEventConfigs($eventArea);
			if (! ($eventConfig instanceof Mage_Core_Model_Config_Element)) {
				continue;
			}
			
			$events = $eventConfig->children();
			$this->processEventObservers($events, $eventArea, $storage);
		}
	}
	
	/**
	 * @param string $eventArea
	 * @return Mage_Core_Model_Config_Element|null
	 */
	private function getEventAreaEventConfigs($eventArea) {
		return Mage::app()->getConfig()->getNode(sprintf('%s/events', $eventArea));
	}
	
	/**
	 * @param array $areaEvents
	 * @param string $eventArea
	 * @param array $storage
	 */
	private function processEventObservers($areaEvents, $eventArea, & $storage) {
		foreach ($areaEvents as $eventName => $event) {
			foreach ($event->observers->children() as $observerName => $observer) {
				$observerData = array(
						'area' => $eventArea,
						'event' => $eventName,
						'name' => $observerName,
                        'type' => (string)$observer->type ? (string)$observer->type : 'singleton',
						'class' => Mage::app()->getConfig()->getModelClassName($observer->class),
						'method' => (string)$observer->method
				);
				$storage[] = $observerData;
			}
		}
	}
	
    private function getRootBlock()
    {
        return Mage::app()->getLayout()->getBlock('root');     
    }

    private function getBlocks($block)
    {
		$blocks = array();
		if($block && $block->getChild()){
			$sortedChildren = $block->getSortedChildren();
			foreach ($sortedChildren as $childname) {
				$child = $block->getChild($childname);
                if (!$child){
                  continue;
                }
				$hasChildren =  $child->getChild() ? true : false;
				if($hasChildren){
					$blocks[$child->getNameInLayout()]=$this->getBlocks($child);
				}else{
					$blocks[$child->getNameInLayout()]=array(
						'Class'=>get_class($child),
						'Template'=>$child->getTemplateFile() ? $child->getTemplateFile() : $child->getTemplate()
						);
				}
			}
		}
		return $blocks;
    }
    
}


$zrayMagento = new Magento();
$zrayMagento->setZRay(new ZRayExtension('magento'));

$zrayMagento->getZRay()->setMetadata(array(
    'logo' => __DIR__ . DIRECTORY_SEPARATOR . 'logo.png',
));

$zrayMagento->getZRay()->setEnabledAfter('Mage::run');
$zrayMagento->getZRay()->traceFunction('Mage::app', function(){}, array($zrayMagento, 'mageAppExit'));
$zrayMagento->getZRay()->traceFunction('Mage::run', function(){}, array($zrayMagento, 'mageRunExit'));
$zrayMagento->getZRay()->traceFunction('Mage_Core_Model_App::_callObserverMethod', function(){}, array($zrayMagento, 'appCallObserverMethod'));
$zrayMagento->getZRay()->traceFunction('Mage::dispatchEvent', array($zrayMagento, 'mageDispatchEvent'), function(){});	
