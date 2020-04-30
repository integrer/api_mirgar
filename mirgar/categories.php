<?php
$path = JPATH_ADMINISTRATOR . "/components/com_djclassifieds/lib/djcategory.php";
if (!file_exists($path)) {
	throw new RuntimeException("File $path not found!");
}

require_once $path;

class MirgarApiResourceCategories extends ApiResource
{
	private const ICON_TEMPLATE = '/images/imgcat/0/%1$d_%1$d_ths.jpg';
	
	public function get()
	{
		$result = new \stdClass;		
		$result->template = self::ICON_TEMPLATE;		
		$result->groups = self::loadCategoriesFromDB();
		 
		$this->plugin->setResponse($result);
	}
	
	protected static function loadCategoriesFromDB() {
		$categoriesGroupedByParentId = 
			DJClassifiedsCategory::getCategoriesSortParent(1, "id");

		$result = array();
		foreach($categoriesGroupedByParentId as $parentId => $categories) {
			$group = new \stdClass;
			$group->parentId = $parentId;			
			$group->items = array_map("self::mapCategory", $categories);
			$result[] = $group;
		}

		return $result;
	}

	protected static function mapCategory($in)
	{
		$mapped = new \stdClass;
		$mapped->id = intval($in->id);
		$mapped->name = $in->name;
		$mapped->hasIcon =
			file_exists(JPATH_ROOT . sprintf(self::ICON_TEMPLATE, $in->id));
		return $mapped;
	}
    
	public function post()
	{
		// Add your code here
		
		$this->plugin->setResponse( $result );
	}
}