<?php 

class Magento_Layoutviewer extends Varien_Object{
	
	public function __construct(){
		$this->setLayout(Mage::app()->getFrontController()->getAction()->getLayout());
		$this->setUpdate($this->getLayout()->getUpdate());		
	}
	
	public function getPackageLayout(){
		$layoutUpdateModel = new Magento_Layoutviewer_Layout_Update();
		return (string) $layoutUpdateModel->getPackageLayout()->asXML();
	}
	
	public function getPageLayout(){
        $pageLayout = $this->getLayout();
		return (string) $pageLayout->getNode()->asXML();
	}
	
	public function getLayoutFiles()
	{
		$files = array();
		$nodes = Mage::app()->getConfig()->getNode('frontend/layout/updates');
		$base = Mage::getBaseDir('design');
		foreach($nodes->children() as $node)
		{
			$files[] = $this->_findFilePath((string)$node->file,$base);
		}
		$files[] = $this->_findFilePath('local.xml',$base);
		return $files;
	}

	protected function _findFilePath($file,$base)
	{
		$file = Mage::getDesign()->getLayoutFilename($file);
		$file = trim(str_replace($base, '',$file),'/');
		return $file;
	}	
	
}

class Magento_Layoutviewer_Layout_Update extends Mage_Core_Model_Layout_Update {
	public function getPackageLayout() {
		$this->fetchFileLayoutUpdates();
		return $this->_packageLayout;
	}
}